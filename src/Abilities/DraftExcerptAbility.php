<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

use Mahbub\WpAiExperiment\Ai\ExcerptDrafter;
use Mahbub\WpAiExperiment\ErrorCodes;
use WP_Error;

/**
 * The AI generation workflow: proposes an excerpt, and stops there.
 *
 * Annotated `readonly: true` because it is true - nothing about the site changes.
 * Applying the suggestion stays a separate, explicit call to
 * `wp-ai-experiment/update-post-excerpt`, so a human or agent sees the text
 * before it reaches a published post, and this ability never grows a second copy
 * of that write.
 *
 * Two consequences of that annotation worth knowing. Core's REST run controller
 * derives the verb from the annotations and checks `readonly` first, so the run
 * route for this ability is GET-only; every input here is a scalar, so they
 * travel fine as query parameters. And MCP clients read `readOnlyHint` as
 * "safe to call without asking", which is the right answer for a suggestion even
 * though the call does spend the site's API credit.
 *
 * `idempotent: false` because a model does not return the same sentence twice.
 */
final class DraftExcerptAbility implements Ability
{
    public const NAME = 'wp-ai-experiment/draft-post-excerpt';

    private const DEFAULT_MAX_WORDS = 30;

    private const DEFAULT_TONE = 'neutral';

    public function __construct(private readonly ExcerptDrafter $drafter)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return __('Draft post excerpt', 'wp-ai-experiment');
    }

    public function description(): string
    {
        return __(
            'Uses AI to draft an excerpt for a single post or page and returns it as a suggestion without saving it. Apply it with update-post-excerpt.',
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
                        'ID of the post or page to draft an excerpt for.',
                        'wp-ai-experiment'
                    ),
                ],
                'max_words' => [
                    'type' => 'integer',
                    'minimum' => 10,
                    'maximum' => 60,
                    'default' => self::DEFAULT_MAX_WORDS,
                    'description' => __(
                        'Upper bound on the excerpt length in words. Defaults to 30.',
                        'wp-ai-experiment'
                    ),
                ],
                'tone' => [
                    'type' => 'string',
                    'enum' => ['neutral', 'informative', 'conversational', 'promotional'],
                    'default' => self::DEFAULT_TONE,
                    'description' => __(
                        'Register the excerpt should be written in. Defaults to neutral.',
                        'wp-ai-experiment'
                    ),
                ],
            ],
            'required' => ['post_id'],
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
                'current_excerpt' => ['type' => 'string'],
                'word_limit' => ['type' => 'integer'],
                'tone' => ['type' => 'string'],
            ],
            'required' => ['post_id', 'excerpt', 'current_excerpt', 'word_limit', 'tone'],
        ];
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
     * `edit_post` rather than `read`: the prompt carries the full body of a post
     * that may still be a draft, and each call spends the site owner's API
     * credit, so a reader-level user should not be able to trigger it.
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
                    'A post_id of 1 or greater is required to draft an excerpt.',
                    'wp-ai-experiment'
                )
            );
        }

        // Fallbacks are passed explicitly: core validates the schema but never
        // applies a property's declared `default`.
        return $this->drafter->draft(
            $postId,
            $values->intValue('max_words', self::DEFAULT_MAX_WORDS),
            $values->stringValue('tone', self::DEFAULT_TONE)
        );
    }
}
