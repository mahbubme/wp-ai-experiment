<?php

/**
 * Plugin Name: wp-ai-experiment
 * Plugin URI:  https://github.com/inpsyde
 * Description: WordPress AI experiment plugin
 * Version:     1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author:      Syde GmbH
 * Author URI:  https://syde.com/
 * Update URI:  false
 * License:     GPL-2.0-or-later
 * Text Domain: wp-ai-experiment
 * Domain Path: /languages
 * Network:     true
 */

declare(strict_types=1);

namespace Mahbub\WpAiExperiment;

// phpcs:disable PSR1.Files.SideEffects

use Inpsyde\Modularity\Package;
use Inpsyde\Modularity\Properties\PluginProperties;
use Mahbub\WpAiExperiment\Example\ExampleModule;
use Throwable;

/**
 * Display an error message in the WP admin.
 *
 * @param string $message The message content
 *
 * @return void
 */
function errorNotice(string $message): void
{
    add_action(
        'all_admin_notices',
        static function () use ($message) {
            $class = 'notice notice-error';
            printf(
                '<div class="%1$s"><p>%2$s</p></div>',
                esc_attr($class),
                wp_kses_post($message)
            );
        }
    );
}

/**
 * Handle any exception that might occur during plugin setup.
 *
 * @param Throwable $throwable The Exception
 *
 * @return void
 */
function handleException(Throwable $throwable): void
{
    do_action('inpsyde.wp-ai-experiment.critical', $throwable);

    errorNotice(
        sprintf(
            '<strong>Error:</strong> %s <br><pre>%s</pre>',
            $throwable->getMessage(),
            $throwable->getTraceAsString()
        )
    );
}

/**
 * Provide the plugin instance.
 *
 * @link https://github.com/inpsyde/modularity#access-from-external
 */
function plugin(): Package
{
    static $package;
    if (!$package) {
        $properties = PluginProperties::new(__FILE__);
        $package = Package::new($properties);
    }

    return $package;
}

/**
 * Initialize all the plugin things.
 *
 * @throws Throwable
 */
function initialize(): void
{
    try {
        if (is_readable(__DIR__ . '/vendor/autoload.php')) {
            /* @noinspection PhpIncludeInspection */
            include_once __DIR__ . '/vendor/autoload.php';
        }

        plugin()
            ->addModule(new ExampleModule())
            ->addModule(new Translation\Module())
            ->addModule(new Abilities\Module())
            ->boot();
    } catch (Throwable $throwable) {
        handleException($throwable);
    }
}

add_action('plugins_loaded', __NAMESPACE__ . '\\initialize');
