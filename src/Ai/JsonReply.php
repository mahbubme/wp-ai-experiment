<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Ai;

use Mahbub\WpAiExperiment\ErrorCodes;
use WP_Error;

/**
 * Decodes the JSON a model returns when the prompt asked for JSON.
 *
 * `as_json_response()` sets an output MIME type and a schema, but that is a
 * request rather than a guarantee: the terminal call is still `generate_text()`
 * and still hands back a string, and models routinely wrap that string in a
 * markdown fence or prefix it with a sentence of prose. Treating the reply as
 * untrusted text is therefore the normal case, not defensive programming.
 *
 * A failure carries the offending reply in the error data. Re-running to see what
 * came back would cost another paid request, so the evidence is captured the
 * first time.
 */
final class JsonReply
{
    /**
     * How much of a bad reply to keep for debugging. Long enough to see where
     * the JSON went wrong, short enough not to bloat a REST error response.
     */
    private const ERROR_EXCERPT_CHARS = 500;

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function toArray(string $raw): array|WP_Error
    {
        $json = $this->unwrap($raw);

        if ($json === '') {
            return new WP_Error(
                ErrorCodes::AI_EMPTY_REPLY,
                __('The model returned an empty response.', 'wp-ai-experiment')
            );
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return $this->invalid($raw, __('The model did not return JSON.', 'wp-ai-experiment'));
        }

        $values = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                // A JSON array decodes to integer keys, which means the model
                // returned a list where an object was asked for.
                return $this->invalid(
                    $raw,
                    __('The model returned a JSON list instead of an object.', 'wp-ai-experiment')
                );
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Strips a markdown code fence, with or without a language tag, and any prose
     * around it, by falling back to the outermost brace pair.
     */
    private function unwrap(string $raw): string
    {
        $text = trim($raw);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return $text;
        }

        return substr($text, $start, $end - $start + 1);
    }

    private function invalid(string $raw, string $message): WP_Error
    {
        return new WP_Error(
            ErrorCodes::INVALID_AI_REPLY,
            $message,
            [
                'reply' => mb_substr(trim($raw), 0, self::ERROR_EXCERPT_CHARS),
                'json_error' => json_last_error_msg(),
            ]
        );
    }
}
