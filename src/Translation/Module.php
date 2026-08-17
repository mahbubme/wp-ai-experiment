<?php

namespace Mahbub\WpAiExperiment\Translation;

use Inpsyde\Modularity\Module\ExecutableModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Psr\Container\ContainerInterface;

class Module implements ExecutableModule
{
    use ModuleClassNameIdTrait;

    public function run(ContainerInterface $container): bool
    {
        $textdomainCallback = fn() => load_plugin_textdomain('wp-ai-experiment');

        if (did_action('init')) {
            $textdomainCallback();
            return true;
        }

        add_action('init', $textdomainCallback);

        return true;
    }
}
