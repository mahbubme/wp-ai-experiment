<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

/**
 * Typed, immutable view over the `mixed` input an ability callback receives.
 *
 * Two jobs, both load-bearing:
 *
 * 1. The Abilities API enforces `type` and `required` from the input schema but
 *    never populates a property's declared `default`. Every reader here takes an
 *    explicit fallback and applies it when the key is absent *or* explicitly
 *    null, since some serializers emit null for omitted optional fields.
 * 2. It is the single place where `mixed` becomes a concrete scalar, which keeps
 *    that narrowing auditable instead of scattered across every callback.
 */
final class AbilityInput
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromMixed(mixed $input): self
    {
        if (!is_array($input)) {
            return new self([]);
        }

        $values = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * Whether the key was supplied with a non-null value.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null;
    }

    /**
     * Numeric strings are accepted because that is what the schema validator
     * lets through for an `integer` property.
     */
    public function intValue(string $key, int $fallback): int
    {
        if (!$this->has($key)) {
            return $fallback;
        }

        $value = $this->values[$key];

        return is_numeric($value) ? (int) $value : $fallback;
    }

    public function stringValue(string $key, string $fallback): string
    {
        if (!$this->has($key)) {
            return $fallback;
        }

        $value = $this->values[$key];

        return is_string($value) ? $value : $fallback;
    }

    public function boolValue(string $key, bool $fallback): bool
    {
        if (!$this->has($key)) {
            return $fallback;
        }

        return (bool) $this->values[$key];
    }
}
