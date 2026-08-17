<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Content;

use WP_Post;
use WP_Query;

/**
 * Filtered content lookup.
 *
 * The criteria are described as a PHPStan array shape rather than a ten-argument
 * signature or a value object with ten accessors: it keeps every value concretely
 * typed at the call site without the ceremony.
 *
 * @phpstan-type Criteria array{
 *     search: string,
 *     post_type: string,
 *     status: string,
 *     author: int,
 *     after: string,
 *     before: string,
 *     per_page: int,
 *     page: int,
 *     orderby: string,
 *     order: string
 * }
 */
final class ContentQuery
{
    /**
     * @param Criteria $criteria
     * @return array<string, mixed>
     */
    public function find(array $criteria): array
    {
        $query = new WP_Query($this->queryArgs($criteria));

        $items = [];
        foreach ($query->posts ?? [] as $found) {
            if (!$found instanceof WP_Post) {
                continue;
            }

            $items[] = $this->item($found);
        }

        $perPage = max(1, $criteria['per_page']);

        return [
            'items' => $items,
            'total' => $query->found_posts,
            'total_pages' => (int) ceil($query->found_posts / $perPage),
            'page' => $criteria['page'],
            'per_page' => $perPage,
        ];
    }

    /**
     * @param Criteria $criteria
     * @return array<string, mixed>
     */
    private function queryArgs(array $criteria): array
    {
        $args = [
            'post_type' => $criteria['post_type'],
            'post_status' => $criteria['status'],
            'posts_per_page' => $criteria['per_page'],
            'paged' => $criteria['page'],
            'orderby' => $criteria['orderby'],
            'order' => $criteria['order'],
            'ignore_sticky_posts' => true,
        ];

        if ($criteria['search'] !== '') {
            $args['s'] = sanitize_text_field($criteria['search']);
        }

        if ($criteria['author'] > 0) {
            $args['author'] = $criteria['author'];
        }

        $dateQuery = $this->dateQuery($criteria);
        if ($dateQuery !== []) {
            $args['date_query'] = [$dateQuery];
        }

        return $args;
    }

    /**
     * @param Criteria $criteria
     * @return array<string, string>
     */
    private function dateQuery(array $criteria): array
    {
        $dateQuery = [];

        if ($criteria['after'] !== '') {
            $dateQuery['after'] = $criteria['after'];
        }

        if ($criteria['before'] !== '') {
            $dateQuery['before'] = $criteria['before'];
        }

        return $dateQuery;
    }

    /**
     * `url` and `date` are omitted rather than emitted empty when they cannot be
     * resolved: both declare a `format` in the output schema, and an empty string
     * fails it. The schema marks only the always-present fields as required.
     *
     * @return array<string, mixed>
     */
    private function item(WP_Post $post): array
    {
        $item = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'author' => (int) $post->post_author,
            'excerpt' => $this->excerpt($post),
        ];

        $url = get_permalink($post);
        if (is_string($url) && $url !== '') {
            $item['url'] = $url;
        }

        $publishedAt = $this->publishedAt($post);
        if ($publishedAt !== '') {
            $item['date'] = $publishedAt;
        }

        return $item;
    }

    /**
     * Publication time as RFC3339, or an empty string when it cannot be derived.
     *
     * Drafts leave `post_date_gmt` at `0000-00-00 00:00:00` so that the date is
     * refreshed on every save, and `mysql_to_rfc3339()` renders that as
     * `-0001-11-30T00:00:00`, which fails a `date-time` format check.
     * `WP_REST_Posts_Controller` shims the same case from `post_date`.
     */
    private function publishedAt(WP_Post $post): string
    {
        $dateGmt = $post->post_date_gmt;

        if ($dateGmt === '' || str_starts_with($dateGmt, '0000-00-00')) {
            $shimmed = get_gmt_from_date($post->post_date);
            $dateGmt = is_string($shimmed) ? $shimmed : '';
        }

        if ($dateGmt === '') {
            return '';
        }

        $formatted = mysql_to_rfc3339($dateGmt);

        return is_string($formatted) ? $formatted : '';
    }

    /**
     * Falls back to a trimmed body so agents always get something summarizable,
     * and reads the raw column rather than `get_the_excerpt()` to avoid running
     * theme filters against an agent-facing payload.
     */
    private function excerpt(WP_Post $post): string
    {
        if ($post->post_excerpt !== '') {
            return $post->post_excerpt;
        }

        return wp_trim_words(wp_strip_all_tags($post->post_content), 30);
    }
}
