<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Content;

use Mahbub\WpAiExperiment\ErrorCodes;
use WP_Error;
use WP_Post;

/**
 * Rewrites a single post's excerpt - one state transition, nothing else.
 */
final class ExcerptWriter
{
    /**
     * @return array<string, mixed>|WP_Error
     */
    public function rewrite(int $postId, string $excerpt): array|WP_Error
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return new WP_Error(
                ErrorCodes::INVALID_POST_ID,
                __('No post exists for the given post_id.', 'wp-ai-experiment')
            );
        }

        $previous = $post->post_excerpt;

        $result = wp_update_post(
            [
                'ID' => $postId,
                'post_excerpt' => $excerpt,
            ],
            true
        );

        if ($result instanceof WP_Error) {
            return $result;
        }

        // A falsy integer is the other documented failure mode of wp_update_post().
        if ($result === 0) {
            return new WP_Error(
                ErrorCodes::POST_UPDATE_FAILED,
                __('WordPress refused to update the post excerpt.', 'wp-ai-experiment')
            );
        }

        return [
            'post_id' => $postId,
            'excerpt' => $excerpt,
            'previous_excerpt' => $previous,
            'updated' => $previous !== $excerpt,
        ];
    }
}
