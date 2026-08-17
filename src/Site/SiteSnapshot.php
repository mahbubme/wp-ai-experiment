<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Site;

/**
 * Assembles a compact overview of the site.
 *
 * Kept as a service rather than inlined in the ability so a REST controller, a
 * CLI command or an admin widget can answer the same question identically.
 */
final class SiteSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function summary(bool $includeCounts): array
    {
        $themeName = wp_get_theme()->get('Name');

        $summary = [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url(),
            'language' => get_bloginfo('language'),
            'wordpress_version' => get_bloginfo('version'),
            'active_theme' => is_string($themeName) ? $themeName : '',
        ];

        if ($includeCounts) {
            $summary['counts'] = $this->counts();
        }

        return $summary;
    }

    /**
     * `wp_count_posts()` and `wp_count_comments()` both return a bare stdClass
     * with no declared shape, so the buckets are read through an array cast
     * instead of as properties.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $posts = (array) wp_count_posts('post');
        $pages = (array) wp_count_posts('page');
        $comments = (array) wp_count_comments();
        $users = count_users();

        return [
            'posts' => (int) ($posts['publish'] ?? 0),
            'pages' => (int) ($pages['publish'] ?? 0),
            'comments' => (int) ($comments['approved'] ?? 0),
            'users' => (int) ($users['total_users'] ?? 0),
        ];
    }
}
