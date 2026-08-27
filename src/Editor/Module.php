<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Editor;

use Inpsyde\Modularity\Module\ExecutableModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Package;
use Inpsyde\Modularity\Properties\Properties;
use Mahbub\WpAiExperiment\Abilities\DraftExcerptAbility;
use Mahbub\WpAiExperiment\Abilities\UpdateExcerptAbility;
use Mahbub\WpAiExperiment\Ai\PromptFactory;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Puts the excerpt abilities in front of an editor, not just a terminal.
 *
 * The bundle is a *classic* script rather than a script module even though the
 * thing it talks to - `@wordpress/abilities` - is a module. That is deliberate
 * and it is how the official AI plugin does it: `@wordpress/plugins`,
 * `@wordpress/editor` and friends are still classic scripts, so a module entry
 * point would have to reach them through `window.wp` and lose the dependency
 * extraction that keeps the enqueued handles honest. Core closed this gap in 7.0
 * with `module_dependencies`, which lets a classic script declare the modules it
 * will `import()` at runtime so the import map is printed alongside it.
 */
final class Module implements ExecutableModule
{
    use ModuleClassNameIdTrait;

    private const HANDLE = 'wp-ai-experiment-editor';

    /**
     * Core refuses to print the import map for a classic script that could be
     * evaluated before it. Both flags together are belt and braces: either one
     * satisfies the check, and neither costs anything here.
     */
    private const SCRIPT_ARGS = [
        'in_footer' => true,
        'strategy' => 'defer',
        'module_dependencies' => ['@wordpress/abilities', '@wordpress/core-abilities'],
    ];

    /**
     * The block editor lives on exactly these two screens. Anything else - the
     * list table, the widgets screen, a settings page - has no post to draft an
     * excerpt for.
     */
    private const EDITOR_SCREENS = ['post.php', 'post-new.php'];

    public function run(ContainerInterface $container): bool
    {
        add_action(
            'admin_enqueue_scripts',
            static function (string $hookSuffix) use ($container): void {
                self::enqueue($hookSuffix, $container);
            }
        );

        return true;
    }

    private static function enqueue(string $hookSuffix, ContainerInterface $container): void
    {
        if (!in_array($hookSuffix, self::EDITOR_SCREENS, true)) {
            return;
        }

        $screen = get_current_screen();

        // A post type that does not support excerpts has no field for the
        // suggestion to land in, so the panel would be a dead end.
        if (!$screen || !post_type_supports($screen->post_type, 'excerpt')) {
            return;
        }

        $properties = self::properties($container);
        $asset = self::assetData($properties->basePath());

        // Bailing quietly rather than fataling: a checkout that has not been
        // built yet should still open the editor, just without this panel.
        if ($asset === null) {
            return;
        }

        $baseUrl = $properties->baseUrl();

        if ($baseUrl === null) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            $baseUrl . 'assets/editor.js',
            $asset['dependencies'],
            $asset['version'],
            self::SCRIPT_ARGS
        );

        wp_set_script_translations(self::HANDLE, 'wp-ai-experiment');

        /*
         * Deliberately not `wp_localize_script()`: that casts every scalar to a
         * string, so the word bounds would reach the panel as "30" rather than
         * 30 and the excerpt request would fail the ability's `integer` schema
         * on the client before it ever left the browser. Encoding the payload
         * as JSON keeps the types intact.
         */
        wp_add_inline_script(
            self::HANDLE,
            sprintf(
                'window.wpAiExperimentEditor = %s;',
                wp_json_encode(self::config($container))
            ),
            'before'
        );

        // `@wordpress/scripts` prefixes a stylesheet built from a `style.scss`
        // import with `style-`, and emits the RTL variant alongside it.
        if (is_readable($properties->basePath() . 'assets/style-editor.css')) {
            wp_enqueue_style(
                self::HANDLE,
                $baseUrl . 'assets/style-editor.css',
                [],
                $asset['version']
            );

            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        }
    }

    /**
     * `@wordpress/scripts` writes this file next to the bundle. Reading the
     * handles out of it rather than hardcoding them is what keeps the enqueued
     * dependencies in step with what the source actually imports.
     *
     * @return array{dependencies: list<non-empty-string>, version: string}|null
     */
    private static function assetData(string $basePath): ?array
    {
        $file = $basePath . 'assets/editor.asset.php';

        if (!is_readable($file)) {
            return null;
        }

        /** @var mixed $asset */
        $asset = require $file;

        if (!is_array($asset) || !is_array($asset['dependencies'] ?? null)) {
            return null;
        }

        return [
            'dependencies' => array_values(
                array_filter(
                    $asset['dependencies'],
                    static fn (mixed $handle): bool => is_string($handle) && $handle !== ''
                )
            ),
            'version' => is_string($asset['version'] ?? null) ? $asset['version'] : '',
        ];
    }

    /**
     * Built from the registered ability's own input schema instead of a second
     * copy of the same numbers. The tone list, the word bounds and their
     * defaults are all decisions `DraftExcerptAbility` already made; restating
     * them here would let the panel offer a tone the server rejects, or a word
     * count that fails validation on the way back in.
     *
     * @return array<string, mixed>
     */
    private static function config(ContainerInterface $container): array
    {
        $schema = self::draftInputSchema();
        $tone = self::schemaProperty($schema, 'tone');
        $maxWords = self::schemaProperty($schema, 'max_words');

        return [
            'draftAbility' => DraftExcerptAbility::NAME,
            'updateAbility' => UpdateExcerptAbility::NAME,
            // Gates the panel on the site actually having a provider wired up,
            // so an unconfigured site shows an explanation instead of a button
            // that fails on every press.
            'aiAvailable' => self::service($container, PromptFactory::class)->isAvailable(),
            'tones' => array_values(
                array_filter($tone['enum'] ?? [], 'is_string')
            ),
            'defaultTone' => is_string($tone['default'] ?? null) ? $tone['default'] : '',
            'minWords' => is_int($maxWords['minimum'] ?? null) ? $maxWords['minimum'] : 10,
            'maxWords' => is_int($maxWords['maximum'] ?? null) ? $maxWords['maximum'] : 60,
            'defaultWords' => is_int($maxWords['default'] ?? null) ? $maxWords['default'] : 30,
        ];
    }

    /**
     * The registry initializes itself on first access and fires
     * `wp_abilities_api_init` as it does, so asking for the ability here is
     * safe even though this runs long after the hook would normally have fired.
     *
     * @return array<string, mixed>
     */
    private static function draftInputSchema(): array
    {
        if (!function_exists('wp_get_ability')) {
            return [];
        }

        $ability = wp_get_ability(DraftExcerptAbility::NAME);

        return $ability ? $ability->get_input_schema() : [];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function schemaProperty(array $schema, string $name): array
    {
        $properties = $schema['properties'] ?? null;

        if (!is_array($properties) || !is_array($properties[$name] ?? null)) {
            return [];
        }

        return $properties[$name];
    }

    /**
     * Resolves a service and narrows PSR-11's `mixed` return to the requested
     * class, so a module overriding one of these IDs with the wrong type fails
     * loudly instead of producing a confusing error further downstream.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function service(ContainerInterface $psr, string $id): object
    {
        $service = $psr->get($id);

        if (!$service instanceof $id) {
            throw new RuntimeException(
                sprintf('Service "%s" was overridden with an unexpected type.', esc_html($id))
            );
        }

        return $service;
    }

    private static function properties(ContainerInterface $container): Properties
    {
        /** @var mixed $properties */
        $properties = $container->get(Package::PROPERTIES);

        if (!$properties instanceof Properties) {
            throw new RuntimeException(
                'Package properties were overridden with an unexpected type.'
            );
        }

        return $properties;
    }
}
