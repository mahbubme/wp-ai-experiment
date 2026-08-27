<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

use Mahbub\WpAiExperiment\Ai\ContentAnalyzer;
use Mahbub\WpAiExperiment\ErrorCodes;
use WP_Error;

/**
 * The AI analysis workflow: a structured editorial review of one post.
 *
 * The output schema is the point of this ability. Because the model is given the
 * same schema through `as_json_response()`, an agent can branch on
 * `reading_level` or on an issue's `severity` instead of parsing prose - which is
 * what makes analysis composable with the rest of the tool list.
 *
 * `readonly: true` for the same reason as the drafting ability, with the same
 * consequence: core's run route is GET-only for read-only abilities.
 * `idempotent: false` because the model's judgement varies between runs.
 *
 * @phpstan-import-type ReplySchema from ContentAnalyzer
 */
final class AnalyzeContentAbility implements Ability
{
    private const DEFAULT_MAX_TAGS = 5;

    public function __construct(private readonly ContentAnalyzer $analyzer)
    {
    }

    public function name(): string
    {
        return 'wp-ai-experiment/analyze-post-content';
    }

    public function label(): string
    {
        return __('Analyze post content', 'wp-ai-experiment');
    }

    public function description(): string
    {
        return __(
            'Uses AI to review a single post or page and returns a structured analysis: a summary, its tone and reading level, SEO issues with severities, and suggested tags.',
            'wp-ai-experiment'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => __(
                        'ID of the post or page to analyze.',
                        'wp-ai-experiment'
                    ),
                ],
                'max_tags' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 8,
                    'default' => self::DEFAULT_MAX_TAGS,
                    'description' => __(
                        'How many tags to suggest at most. Defaults to 5.',
                        'wp-ai-experiment'
                    ),
                ],
            ],
            'required' => ['post_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Mirrors the schema handed to the model, so the ability's declared contract
     * and the prompt's requested shape cannot drift apart.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        $schema = $this->analyzer->replySchema();

        // `post_id` is echoed back by the service but is not something the model
        // is asked for, so it is added here rather than to the reply schema.
        $schema['properties'] = array_merge(
            ['post_id' => ['type' => 'integer']],
            $schema['properties']
        );
        $schema['required'] = array_merge(['post_id'], $schema['required']);

        return $schema;
    }

    /**
     * `openWorldHint: true` is the fourth MCP tool annotation. Core does not
     * whitelist annotation keys, and the adapter passes this one straight
     * through to `tools/list`, so a client learns that this ability reaches a
     * third-party API rather than only this site's database - which is the one
     * thing that genuinely distinguishes the AI abilities from the other three.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => false,
            'openWorldHint' => true,
        ];
    }

    /**
     * `edit_post`, matching the drafting ability: the analysis is derived from the
     * full body of a post that may be private or unpublished.
     */
    public function isAllowed(mixed $input = null): bool
    {
        $postId = AbilityInput::fromMixed($input)->intValue('post_id', 0);

        if ($postId < 1) {
            return false;
        }

        return current_user_can('edit_post', $postId);
    }

    public function execute(mixed $input = null): mixed
    {
        $values = AbilityInput::fromMixed($input);
        $postId = $values->intValue('post_id', 0);

        if ($postId < 1) {
            return new WP_Error(
                ErrorCodes::MISSING_POST_ID,
                __(
                    'A post_id of 1 or greater is required to analyze content.',
                    'wp-ai-experiment'
                )
            );
        }

        return $this->analyzer->analyze(
            $postId,
            $values->intValue('max_tags', self::DEFAULT_MAX_TAGS)
        );
    }
}
