<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

use Mahbub\WpAiExperiment\Content\ExcerptWriter;
use Mahbub\WpAiExperiment\ErrorCodes;
use WP_Error;

/**
 * The write: exactly one state transition, so an agent can reason about it in
 * isolation and explain it to a user in one sentence.
 *
 * Annotated `destructive: false` deliberately. Core's REST run controller derives
 * the required HTTP verb from the annotations - `destructive` plus `idempotent`
 * would make this DELETE-only, which is the wrong contract for a scoped
 * single-field update. `destructive: false` keeps it on POST.
 */
final class UpdateExcerptAbility implements Ability
{
    public function __construct(private readonly ExcerptWriter $writer)
    {
    }

    public function name(): string
    {
        return 'wp-ai-experiment/update-post-excerpt';
    }

    public function label(): string
    {
        return __('Update post excerpt', 'wp-ai-experiment');
    }

    public function description(): string
    {
        return __(
            'Replaces the excerpt of a single post or page and reports the previous value.',
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
                        'ID of the post or page whose excerpt should be replaced.',
                        'wp-ai-experiment'
                    ),
                ],
                'excerpt' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'description' => __(
                        'The new excerpt. Pass an empty string to clear the existing one.',
                        'wp-ai-experiment'
                    ),
                ],
            ],
            'required' => ['post_id', 'excerpt'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer'],
                'excerpt' => ['type' => 'string'],
                'previous_excerpt' => ['type' => 'string'],
                'updated' => ['type' => 'boolean'],
            ],
            'required' => ['post_id', 'excerpt', 'previous_excerpt', 'updated'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    /**
     * Per-object capability check: `edit_post` is mapped against this specific
     * post, so an author who may edit their own drafts cannot rewrite someone
     * else's excerpt.
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
                    'A post_id of 1 or greater is required to update an excerpt.',
                    'wp-ai-experiment'
                )
            );
        }

        // Checked with has() rather than empty(): "0" and "" are both legal
        // excerpts, and an empty string is how a caller clears one.
        if (!$values->has('excerpt')) {
            return new WP_Error(
                ErrorCodes::MISSING_EXCERPT,
                __('An excerpt is required, even if empty.', 'wp-ai-experiment')
            );
        }

        return $this->writer->rewrite($postId, $values->stringValue('excerpt', ''));
    }
}
