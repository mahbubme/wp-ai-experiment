<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Ai;

use WP_Error;

/**
 * Workflow two: a structured editorial review of one post.
 *
 * Where `ExcerptDrafter` asks for prose, this asks for data - `as_json_response()`
 * hands the model a JSON Schema and requests `application/json` back. That is the
 * more interesting half of the Prompt Builder for tooling, because the result can
 * be branched on instead of read.
 *
 * The schema is a request, not a contract: the terminal call is still
 * `generate_text()` returning a string, so the reply is decoded by `JsonReply`
 * and then normalised field by field. Anything missing degrades to an empty
 * value rather than failing the whole analysis - a review with four of five
 * fields is still useful, and a hard failure would waste the call that produced it.
 *
 * @phpstan-import-type PostContextData from PostContext
 * @phpstan-type ReplySchema array{
 *     type: string,
 *     properties: array<string, mixed>,
 *     required: list<string>,
 *     additionalProperties: bool
 * }
 */
final class ContentAnalyzer
{
    /**
     * Near-deterministic: this is classification, and a creative temperature
     * makes the same post drift between reading levels between runs.
     */
    private const TEMPERATURE = 0.1;

    /**
     * Generous for the same reason as in `ExcerptDrafter`: a reasoning model
     * spends this budget on thinking before it emits any JSON, and a JSON reply
     * cut off halfway is unparseable rather than merely short.
     */
    private const MAX_TOKENS = 4000;

    private const TONES = ['informative', 'conversational', 'promotional', 'technical', 'neutral'];

    private const READING_LEVELS = ['beginner', 'intermediate', 'advanced'];

    private const SEVERITIES = ['low', 'medium', 'high'];

    private const MAX_ISSUES = 5;

    public function __construct(
        private readonly PromptFactory $prompts,
        private readonly PostContext $context,
        private readonly JsonReply $reply
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function analyze(int $postId, int $maxTags): array|WP_Error
    {
        if (!$this->prompts->isAvailable()) {
            return $this->prompts->unsupported();
        }

        $context = $this->context->forPost($postId);
        if ($context instanceof WP_Error) {
            return $context;
        }

        $builder = $this->prompts->create($this->userPrompt($context, $maxTags))
            ->using_system_instruction($this->systemInstruction())
            ->using_temperature(self::TEMPERATURE)
            ->using_max_tokens(self::MAX_TOKENS)
            ->as_json_response($this->replySchema());

        if (!$builder->is_supported_for_text_generation()) {
            return $this->prompts->unsupported();
        }

        $raw = $builder->generate_text();
        if ($raw instanceof WP_Error) {
            return $raw;
        }

        $decoded = $this->reply->toArray($raw);
        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        return $this->normalize($postId, $decoded, $maxTags);
    }

    /**
     * The shape handed to the model, mirrored by the ability's `outputSchema()`.
     *
     * Enums are spelled out so the values can be branched on. Without them a
     * model will happily answer "quite informative", which no caller can match.
     *
     * Deliberately a portable subset of JSON Schema, because each provider
     * forwards this differently: the OpenAI provider sends it with
     * `'strict' => true`, which rejects count and length keywords such as
     * `maxItems` outright, while the Google provider has to strip
     * `additionalProperties` before Gemini will accept it. Sticking to types,
     * enums and `required` is what keeps one schema working on every provider.
     * Counts are asked for in the prompt and enforced in PHP afterwards, which
     * is the only place they can be guaranteed anyway.
     *
     * @return ReplySchema
     */
    public function replySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'tone' => ['type' => 'string', 'enum' => self::TONES],
                'reading_level' => ['type' => 'string', 'enum' => self::READING_LEVELS],
                'seo_issues' => [
                    'type' => 'array',
                    'items' => $this->issueSchema(),
                ],
                'suggested_tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['summary', 'tone', 'reading_level', 'seo_issues', 'suggested_tags'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issueSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue' => ['type' => 'string'],
                'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
                'recommendation' => ['type' => 'string'],
            ],
            'required' => ['issue', 'severity', 'recommendation'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Not translated: this configures the model rather than addressing a user.
     * The summary's language is steered by the locale instead.
     */
    private function systemInstruction(): string
    {
        return 'You are an editorial reviewer for WordPress content. '
            . 'Reply with a single JSON object matching the requested schema, '
            . 'and nothing else - no markdown fence and no commentary. '
            . 'Base every judgement only on the post text you are given. '
            . 'Report at most ' . self::MAX_ISSUES . ' SEO issues, most severe first, '
            . 'and report none rather than inventing filler. '
            . sprintf(
                'Write the summary in the language of the locale code %s.',
                determine_locale()
            );
    }

    /**
     * @param PostContextData $context
     */
    private function userPrompt(array $context, int $maxTags): string
    {
        $lines = [
            sprintf('Post type: %s', $context['type']),
            sprintf('Status: %s', $context['status']),
            sprintf('Title: %s', $context['title']),
        ];

        if ($context['excerpt'] !== '') {
            $lines[] = sprintf('Current excerpt: %s', $context['excerpt']);
        }

        // The count lives here rather than in the schema, which cannot carry it
        // portably. PHP still trims the list, so this is a hint, not a contract.
        $lines[] = sprintf('Suggest at most %d tags.', $maxTags);
        $lines[] = 'Post body follows.';
        $lines[] = $context['content'];

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalize(int $postId, array $decoded, int $maxTags): array
    {
        return [
            'post_id' => $postId,
            'summary' => $this->stringField($decoded, 'summary'),
            'tone' => $this->enumField($decoded, 'tone', self::TONES, 'neutral'),
            'reading_level' => $this->enumField(
                $decoded,
                'reading_level',
                self::READING_LEVELS,
                'intermediate'
            ),
            'seo_issues' => $this->issues($decoded),
            'suggested_tags' => $this->tags($decoded, $maxTags),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stringField(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * An off-schema answer is replaced by an explicit neutral value rather than
     * passed through, so a caller can trust the enum without re-validating it.
     * The fallback is named per field because there is no one neutral value: an
     * unknown severity is treated as `medium`, not as the least urgent option.
     *
     * @param array<string, mixed> $values
     * @param list<string> $allowed
     */
    private function enumField(array $values, string $key, array $allowed, string $fallback): string
    {
        $value = strtolower($this->stringField($values, $key));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $values
     * @return list<array{issue: string, severity: string, recommendation: string}>
     */
    private function issues(array $values): array
    {
        $raw = $values['seo_issues'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $issues = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $issue = $this->issue($item);
            if ($issue !== null) {
                $issues[] = $issue;
            }

            if (count($issues) === self::MAX_ISSUES) {
                break;
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed> $item
     * @return array{issue: string, severity: string, recommendation: string}|null
     */
    private function issue(array $item): ?array
    {
        $values = [];
        foreach ($item as $key => $value) {
            if (is_string($key)) {
                $values[$key] = $value;
            }
        }

        $text = $this->stringField($values, 'issue');
        if ($text === '') {
            return null;
        }

        return [
            'issue' => $text,
            'severity' => $this->enumField($values, 'severity', self::SEVERITIES, 'medium'),
            'recommendation' => $this->stringField($values, 'recommendation'),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function tags(array $values, int $maxTags): array
    {
        $raw = $values['suggested_tags'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $tag = trim($tag);
            // Deduplicated case-insensitively: models repeat "AI" and "ai".
            if ($tag === '' || in_array(strtolower($tag), array_map('strtolower', $tags), true)) {
                continue;
            }

            $tags[] = $tag;

            if (count($tags) === $maxTags) {
                break;
            }
        }

        return $tags;
    }
}
