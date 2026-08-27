<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Ai;

use Mahbub\WpAiExperiment\ErrorCodes;
use WP_AI_Client_Prompt_Builder;
use WP_Error;

/**
 * The single place in this plugin that touches WordPress' global AI entry points.
 *
 * `wp_ai_client_prompt()` builds on `AiClient::defaultRegistry()`, which is what
 * makes every workflow here provider-agnostic: the registry resolves whichever
 * connected model satisfies the requirements derived from the prompt, so nothing
 * below ever names a provider or a model. Core also converts each SDK exception
 * into a `WP_Error` carrying an HTTP `status`, which is exactly the convention
 * this plugin's services already return - so wrapping the raw SDK would only
 * duplicate work core has done.
 *
 * Both functions arrived in WordPress 7.0. `isAvailable()` exists so a site
 * running the plugin against an environment with AI switched off gets a clean
 * `WP_Error` instead of a fatal on an undefined function.
 */
final class PromptFactory
{
    public function isAvailable(): bool
    {
        return function_exists('wp_ai_client_prompt') && wp_supports_ai();
    }

    public function create(string $prompt): WP_AI_Client_Prompt_Builder
    {
        return wp_ai_client_prompt($prompt);
    }

    /**
     * The "no model can do this" error, produced here because this class already
     * owns the availability question - both workflows need the same answer, and
     * neither should invent its own wording for it.
     */
    public function unsupported(): WP_Error
    {
        return new WP_Error(
            ErrorCodes::AI_UNSUPPORTED,
            __(
                'No connected AI provider offers a suitable model. Configure an AI provider for this site, then retry.',
                'wp-ai-experiment'
            )
        );
    }
}
