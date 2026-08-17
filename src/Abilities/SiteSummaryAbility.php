<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

use Mahbub\WpAiExperiment\Site\SiteSnapshot;

/**
 * "What is this site?" - the zero-argument overview ability.
 *
 * Abilities that answer a high-frequency question with no input are unusually
 * high-leverage: there is no room for an agent to get the call wrong.
 */
final class SiteSummaryAbility implements Ability
{
    public function __construct(private readonly SiteSnapshot $snapshot)
    {
    }

    public function name(): string
    {
        return 'wp-ai-experiment/get-site-summary';
    }

    public function label(): string
    {
        return __('Get site summary', 'wp-ai-experiment');
    }

    public function description(): string
    {
        return __(
            'Returns an overview of this WordPress site: name, tagline, URL, language, WordPress version, active theme and optional content counts.',
            'wp-ai-experiment'
        );
    }

    /**
     * The top-level `default` is what makes a zero-argument call legal.
     *
     * `WP_Ability::normalize_input()` substitutes it when the caller passes null,
     * so `$ability->execute()` validates against this object-typed schema instead
     * of failing on `null`. It is cast to an object so it serializes as `{}`
     * rather than as an empty JSON array.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'default' => (object) [],
            'properties' => [
                'include_counts' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => __(
                        'Whether to include published post, page, comment and user counts.',
                        'wp-ai-experiment'
                    ),
                ],
            ],
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
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'language' => ['type' => 'string'],
                'wordpress_version' => ['type' => 'string'],
                'active_theme' => ['type' => 'string'],
                'counts' => [
                    'type' => 'object',
                    'properties' => [
                        'posts' => ['type' => 'integer'],
                        'pages' => ['type' => 'integer'],
                        'comments' => ['type' => 'integer'],
                        'users' => ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['name', 'url', 'wordpress_version', 'active_theme'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    public function isAllowed(mixed $input = null): bool
    {
        // The summary reveals the WordPress version, so it is not public data.
        return current_user_can('edit_posts');
    }

    public function execute(mixed $input = null): mixed
    {
        $values = AbilityInput::fromMixed($input);

        // The schema's `default` is documentation only - core never injects
        // property-level defaults, so the fallback is reapplied here.
        return $this->snapshot->summary($values->boolValue('include_counts', true));
    }
}
