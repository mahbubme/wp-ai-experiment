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
     * Generates text, retrying once without the tuning options if the model
     * refuses one of them.
     *
     * Switching providers is expected to just work, and `temperature` is where
     * that expectation breaks first. OpenAI's reasoning models reject the
     * parameter outright, and Anthropic's only accept it at 1 while extended
     * thinking is on - yet the OpenAI provider's own metadata still advertises
     * `temperature` for every one of those models, so asking the registry what
     * the model supports answers yes and the request fails anyway.
     *
     * The builder is taken as a callable rather than an object because a
     * rejected option cannot be unset after the fact: the retry has to build a
     * second prompt without it. `$tuned` is false on that second pass.
     *
     * @param callable(bool): WP_AI_Client_Prompt_Builder $build
     */
    public function generateText(callable $build): string|WP_Error
    {
        if (!$this->isAvailable()) {
            return $this->unsupported();
        }

        $builder = $build(true);

        // Asked before generating so an unconfigured site gets an actionable
        // error instead of a provider-shaped one. This is a network-backed
        // lookup, which is why it lives here and not in a permission callback.
        if (!$builder->is_supported_for_text_generation()) {
            return $this->unsupported();
        }

        $text = $builder->generate_text();

        if (!$this->rejectsTuning($text)) {
            return $text;
        }

        return $build(false)->generate_text();
    }

    /**
     * Whether a failure is the provider objecting to a tuning option.
     *
     * Deliberately narrow. Only a 400 qualifies - a 429 or a 503 says nothing
     * about the parameters - and the message has to actually name the option,
     * so an unrelated bad request is not retried into a second bill. There is
     * no structured signal to match on instead: core flattens every provider
     * exception to `prompt_client_error` with an HTTP status, so the option
     * name in the message is the only thing that distinguishes this case.
     */
    private function rejectsTuning(mixed $result): bool
    {
        if (!$result instanceof WP_Error) {
            return false;
        }

        $data = $result->get_error_data();

        if (!is_array($data) || ($data['status'] ?? null) !== 400) {
            return false;
        }

        return stripos($result->get_error_message(), 'temperature') !== false;
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
