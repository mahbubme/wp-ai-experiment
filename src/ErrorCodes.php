<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment;

/**
 * Machine-readable `WP_Error` codes shared by this plugin's abilities and the
 * services behind them.
 *
 * Codes read `<plugin>_<action>_<field>` so an agent can branch on them without
 * parsing messages: `missing_*` and `invalid_*` mean "fix the input and retry",
 * `*_failed` means the write did not land. Messages are translatable; codes are
 * stable machine identifiers and never are.
 *
 * The constants are deliberately untyped - typed class constants are PHP 8.3 and
 * this package supports 8.2.
 */
final class ErrorCodes
{
    public const MISSING_POST_ID = 'wp_ai_experiment_missing_post_id';
    public const MISSING_EXCERPT = 'wp_ai_experiment_missing_excerpt';
    public const INVALID_POST_ID = 'wp_ai_experiment_invalid_post_id';
    public const POST_UPDATE_FAILED = 'wp_ai_experiment_post_update_failed';
}
