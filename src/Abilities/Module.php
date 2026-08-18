<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

use Inpsyde\Modularity\Module\ExecutableModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Module\ServiceModule;
use Mahbub\WpAiExperiment\Content\ContentQuery;
use Mahbub\WpAiExperiment\Content\ExcerptWriter;
use Mahbub\WpAiExperiment\Site\SiteSnapshot;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class Module implements ExecutableModule, ServiceModule
{
    use ModuleClassNameIdTrait;

    /**
     * Note the deliberate absence of a `@return` annotation: the interface
     * documents `array<string, Service>`, and `Service` is a file-local
     * `@phpstan-type` alias over there. Repeating it here would resolve to a
     * non-existent local class, so the inherited annotation is used instead.
     */
    public function services(): array
    {
        return [
            SiteSnapshot::class => static fn (): SiteSnapshot => new SiteSnapshot(),
            ContentQuery::class => static fn (): ContentQuery => new ContentQuery(),
            ExcerptWriter::class => static fn (): ExcerptWriter => new ExcerptWriter(),
            Registrar::class => static function (ContainerInterface $psr): Registrar {
                return self::registrar($psr);
            },
        ];
    }

    public function run(ContainerInterface $container): bool
    {
        add_action(
            'wp_abilities_api_categories_init',
            static function () use ($container): void {
                self::service($container, Registrar::class)->registerCategory();
            }
        );

        add_action(
            'wp_abilities_api_init',
            static function () use ($container): void {
                self::service($container, Registrar::class)->registerAbilities();
            }
        );

        // The adapter's default server ships three generic discovery tools and
        // never promotes an ability on its own, so without this an agent would
        // only reach ours through a second hop. Listing them here puts each one
        // in the tool list under its own name and schema, which is what the
        // descriptions and enums were written for.
        add_filter(
            'mcp_adapter_default_server_config',
            static function (mixed $config) use ($container): mixed {
                return self::withAbilityTools($config, $container);
            }
        );

        return true;
    }

    /**
     * Kept defensive rather than typed `array $config`: this filter runs after
     * every other subscriber, and a badly behaved one returning a non-array
     * would otherwise fatal here instead of falling back to the adapter's own
     * `is_array()` guard.
     */
    private static function withAbilityTools(mixed $config, ContainerInterface $container): mixed
    {
        if (!is_array($config)) {
            return $config;
        }

        $tools = $config['tools'] ?? [];

        if (!is_array($tools)) {
            return $config;
        }

        $config['tools'] = array_values(
            array_unique(
                array_merge($tools, self::service($container, Registrar::class)->names())
            )
        );

        return $config;
    }

    private static function registrar(ContainerInterface $psr): Registrar
    {
        return new Registrar(
            new SiteSummaryAbility(self::service($psr, SiteSnapshot::class)),
            new FindContentAbility(self::service($psr, ContentQuery::class)),
            new UpdateExcerptAbility(self::service($psr, ExcerptWriter::class))
        );
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
}
