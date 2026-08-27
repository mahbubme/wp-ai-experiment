<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Ai;

use Mahbub\WpAiExperiment\ErrorCodes;
use WP_Error;
use WP_Post;

/**
 * Turns one post into the bounded plain text an AI workflow can prompt with.
 *
 * Block markup, shortcodes and HTML are stripped rather than sent verbatim: they
 * cost tokens, they invite the model to answer in markup, and block comment
 * delimiters read as instructions to some models. Core's own excerpt pipeline is
 * reused for this instead of hand-rolled regexes, so a post that renders cleanly
 * in `the_excerpt()` reads cleanly here too.
 *
 * `MAX_CHARS` is the cost guard. Every provider bills by token, so an unbounded
 * post body is an unbounded bill; truncating at a fixed budget keeps the worst
 * case predictable and still leaves far more context than an excerpt needs.
 *
 * @phpstan-type PostContextData array{
 *     title: string,
 *     type: string,
 *     status: string,
 *     excerpt: string,
 *     content: string
 * }
 */
final class PostContext
{
    private const MAX_CHARS = 6000;

    /**
     * @return PostContextData|WP_Error
     */
    public function forPost(int $postId): array|WP_Error
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return new WP_Error(
                ErrorCodes::INVALID_POST_ID,
                __('No post exists for the given post_id.', 'wp-ai-experiment')
            );
        }

        $title = $this->plainText($post->post_title);
        $content = $this->bodyText($post->post_content);

        // Both empty means there is nothing to reason about. Bailing here rather
        // than in each workflow keeps a paid API call from being spent on a post
        // that could only produce an invented answer.
        if ($title === '' && $content === '') {
            return new WP_Error(
                ErrorCodes::EMPTY_POST_CONTENT,
                __(
                    'The post has no title or body text to work from.',
                    'wp-ai-experiment'
                )
            );
        }

        return [
            'title' => $title,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'excerpt' => $this->plainText($post->post_excerpt),
            'content' => $content,
        ];
    }

    /**
     * Block removal runs first: `strip_shortcodes()` and `wp_strip_all_tags()`
     * would otherwise leave the block comment delimiters behind as stray text.
     */
    private function bodyText(string $value): string
    {
        $text = $this->plainText(excerpt_remove_blocks($value));

        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        // Cut on the last space inside the budget so the prompt never ends
        // mid-word, which reads to a model as a typo worth reproducing.
        $clipped = mb_substr($text, 0, self::MAX_CHARS);
        $lastSpace = mb_strrpos($clipped, ' ');

        return $lastSpace === false ? $clipped : rtrim(mb_substr($clipped, 0, $lastSpace));
    }

    private function plainText(string $value): string
    {
        $text = wp_strip_all_tags(strip_shortcodes($value));

        // Collapsed to single spaces because block editor output is full of
        // newline runs that spend tokens without carrying meaning.
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
