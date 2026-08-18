<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

/**
 * Registers this plugin's ability category and its abilities.
 *
 * Both registration calls must happen on their own core hook -
 * `wp_abilities_api_categories_init` before `wp_abilities_api_init`, because an
 * ability naming an unregistered category is rejected. Calling either function
 * outside its hook triggers `_doing_it_wrong()` and returns null, so this class
 * exposes the two halves separately rather than doing both at once.
 *
 * The `wp_register_ability()` argument array is built in exactly one place so the
 * `meta` shape cannot drift between abilities.
 */
final class Registrar
{
    public const CATEGORY = 'wp-ai-experiment';

    /** @var list<Ability> */
    private array $abilities;

    public function __construct(Ability ...$abilities)
    {
        // Assigned rather than promoted: PHP cannot declare a variadic promoted
        // property. Re-indexed because named arguments can give a variadic
        // string keys, which would stop it being a list.
        $this->abilities = array_values($abilities);
    }

    public function registerCategory(): void
    {
        wp_register_ability_category(
            self::CATEGORY,
            [
                'label' => __('AI Experiment', 'wp-ai-experiment'),
                'description' => __(
                    'Abilities for inspecting and editing content on this site.',
                    'wp-ai-experiment'
                ),
            ]
        );
    }

    /**
     * The MCP adapter needs the names on their own, without the rest of the
     * registration payload, so they are derived here rather than restated in a
     * second list that could drift.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn (Ability $ability): string => $ability->name(),
            $this->abilities
        );
    }

    public function registerAbilities(): void
    {
        foreach ($this->abilities as $ability) {
            $this->register($ability);
        }
    }

    private function register(Ability $ability): void
    {
        wp_register_ability(
            $ability->name(),
            [
                'label' => $ability->label(),
                'description' => $ability->description(),
                'category' => self::CATEGORY,
                'input_schema' => $ability->inputSchema(),
                'output_schema' => $ability->outputSchema(),
                'execute_callback' => [$ability, 'execute'],
                'permission_callback' => [$ability, 'isAllowed'],
                'meta' => [
                    // Without this the ability is invisible on wp-abilities/v1
                    // and its run route answers 404 rather than 403.
                    'show_in_rest' => true,
                    // The MCP adapter only turns an ability into an MCP tool when it
                    // opts in here; without it the ability stays REST-only.
                    'mcp' => ['public' => true],
                    'annotations' => $ability->annotations(),
                ],
            ]
        );
    }
}
