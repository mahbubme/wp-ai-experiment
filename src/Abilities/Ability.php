<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

/**
 * Contract for a single ability registered with the WordPress Abilities API.
 *
 * Implementations own the ability's public contract - name, schemas,
 * annotations - and delegate all behavior to a domain service, so the same
 * logic stays reachable from a REST controller, a CLI command or an admin
 * screen without drifting.
 */
interface Ability
{
    /**
     * The namespaced ability name, for example `wp-ai-experiment/find-content`.
     *
     * Core validates this against `/^[a-z0-9-]+\/[a-z0-9-]+$/`, so exactly one
     * slash is allowed - not the "forward slashes" the docblock suggests.
     */
    public function name(): string;

    public function label(): string;

    public function description(): string;

    /**
     * JSON Schema for the accepted input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * JSON Schema for the returned output.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(): array;

    /**
     * Behavioral hints for agents and tooling.
     *
     * These are not merely documentation: the REST run controller derives the
     * required HTTP verb from them.
     *
     * @return array<string, bool>
     */
    public function annotations(): array;

    /**
     * Whether the current user may execute this ability for the given input.
     *
     * Returns a plain bool by design. `WP_Ability::execute()` discards a
     * `WP_Error` returned from a permission callback and additionally triggers
     * `_doing_it_wrong()`, so error detail belongs in `execute()` instead.
     */
    public function isAllowed(mixed $input = null): bool;

    /**
     * @return mixed The ability result, or a `WP_Error` on failure.
     */
    public function execute(mixed $input = null): mixed;
}
