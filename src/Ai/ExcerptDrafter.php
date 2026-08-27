<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Ai;

use Mahbub\WpAiExperiment\ErrorCodes;
use WP_Error;

/**
 * Workflow one: drafts an excerpt for a post through the fluent Prompt Builder.
 *
 * Suggest-only by design. Nothing here writes to the post - the caller applies
 * the result through `wp-ai-experiment/update-post-excerpt`, which already owns
 * that single state transition. That split keeps model output reviewable before
 * it lands on a published post, and it means this workflow never duplicates the
 * write path.
 *
 * Because the caller is expected to hand the result straight to that ability, the
 * draft is capped at `MAX_CHARS` to match its `maxLength` - a suggestion the
 * write ability would reject is not a useful suggestion.
 *
 * @phpstan-import-type PostContextData from PostContext
 */
final class ExcerptDrafter
{
    /**
     * Mirrors the `maxLength` on `update-post-excerpt`'s `excerpt` property.
     */
    private const MAX_CHARS = 500;

    /**
     * Low but not zero: an excerpt is prose, and a fully deterministic setting
     * tends to parrot the post's opening sentence back verbatim.
     */
    private const TEMPERATURE = 0.4;

    /**
     * Deliberately far above what an excerpt needs. On a reasoning model the
     * token budget covers hidden thinking as well as the visible reply, so a cap
     * sized to the answer gets spent before the answer is written and the reply
     * arrives truncated mid-sentence. Length is enforced by `tidy()` instead,
     * which is the only place that can enforce it reliably anyway.
     */
    private const MAX_TOKENS = 2000;

    /**
     * Register the model is asked to write in, keyed by the `tone` input enum.
     */
    private const TONES = [
        'neutral' => 'plain and factual',
        'informative' => 'informative and explanatory',
        'conversational' => 'warm and conversational',
        'promotional' => 'lightly promotional, with no hype and no exclamation marks',
    ];

    public function __construct(
        private readonly PromptFactory $prompts,
        private readonly PostContext $context
    ) {
    }

    /**
     * @return array{
     *     post_id: int,
     *     excerpt: string,
     *     current_excerpt: string,
     *     word_limit: int,
     *     tone: string
     * }|WP_Error
     */
    public function draft(int $postId, int $maxWords, string $tone): array|WP_Error
    {
        if (!$this->prompts->isAvailable()) {
            return $this->prompts->unsupported();
        }

        $context = $this->context->forPost($postId);
        if ($context instanceof WP_Error) {
            return $context;
        }

        $builder = $this->prompts->create($this->userPrompt($context, $maxWords))
            ->using_system_instruction($this->systemInstruction($tone))
            ->using_temperature(self::TEMPERATURE)
            ->using_max_tokens(self::MAX_TOKENS);

        // Asked before generating so an unconfigured site gets an actionable
        // error instead of a provider-shaped one. This is a network-backed
        // lookup, which is why it lives here and not in a permission callback.
        if (!$builder->is_supported_for_text_generation()) {
            return $this->prompts->unsupported();
        }

        $text = $builder->generate_text();
        if ($text instanceof WP_Error) {
            return $text;
        }

        return $this->result($postId, $context, $this->tidy($text, $maxWords), $maxWords, $tone);
    }

    /**
     * @param PostContextData $context
     * @return array{
     *     post_id: int,
     *     excerpt: string,
     *     current_excerpt: string,
     *     word_limit: int,
     *     tone: string
     * }|WP_Error
     */
    private function result(
        int $postId,
        array $context,
        string $excerpt,
        int $maxWords,
        string $tone
    ): array|WP_Error {

        if ($excerpt === '') {
            return new WP_Error(
                ErrorCodes::AI_EMPTY_REPLY,
                __('The model returned no usable excerpt text.', 'wp-ai-experiment')
            );
        }

        return [
            'post_id' => $postId,
            'excerpt' => $excerpt,
            // Returned so a caller can see what applying this would replace.
            'current_excerpt' => $context['excerpt'],
            'word_limit' => $maxWords,
            'tone' => $tone,
        ];
    }

    /**
     * The instruction is deliberately not translated. It configures the model
     * rather than addressing a user, and running it through `__()` would let a
     * translator silently change generation behaviour per locale. The *output*
     * language is still the site's, because the locale is passed in below.
     */
    private function systemInstruction(string $tone): string
    {
        $register = self::TONES[$tone] ?? self::TONES['neutral'];

        return 'You write excerpts for WordPress posts. '
            . 'Reply with the excerpt text only: no title, no quotation marks, '
            . 'no markdown, and no preamble such as "Here is the excerpt". '
            . 'Write one or two complete sentences describing what the post '
            . 'actually says. Never state a fact that is absent from the post. '
            . sprintf('Write in the language of the locale code %s. ', determine_locale())
            . sprintf('Keep the register %s.', $register);
    }

    /**
     * @param PostContextData $context
     */
    private function userPrompt(array $context, int $maxWords): string
    {
        $lines = [
            sprintf('Post type: %s', $context['type']),
            sprintf('Title: %s', $context['title']),
            sprintf('Write at most %d words.', $maxWords),
        ];

        if ($context['excerpt'] !== '') {
            $lines[] = sprintf('Improve on this existing excerpt: %s', $context['excerpt']);
        }

        $lines[] = 'Post body follows.';
        $lines[] = $context['content'];

        return implode("\n", $lines);
    }

    /**
     * Enforces in code what the prompt only asks for. Instructions about length
     * and quoting are honoured most of the time, not all of the time, and the
     * write ability downstream has a hard schema limit.
     */
    private function tidy(string $text, int $maxWords): string
    {
        $excerpt = trim((string) preg_replace('/\s+/u', ' ', $text));

        // Straight and typographic quote pairs the model may wrap the reply in.
        $excerpt = (string) preg_replace(
            '/^["\x{201C}\x{2018}\']+|["\x{201D}\x{2019}\']+$/u',
            '',
            $excerpt
        );

        $excerpt = trim(wp_trim_words($excerpt, $maxWords, ''));

        if (mb_strlen($excerpt) <= self::MAX_CHARS) {
            return $excerpt;
        }

        $clipped = mb_substr($excerpt, 0, self::MAX_CHARS);
        $lastSpace = mb_strrpos($clipped, ' ');

        return $lastSpace === false ? $clipped : rtrim(mb_substr($clipped, 0, $lastSpace));
    }
}
