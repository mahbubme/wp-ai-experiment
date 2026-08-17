<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Abilities;

use Mahbub\WpAiExperiment\Content\ContentQuery;

/**
 * One semantic-intent read ability for the whole content-lookup surface.
 *
 * Registering one ability with filter parameters beats registering one per
 * status or per post type: users think in questions ("which drafts mention
 * pricing?"), not in HTTP verbs, and an agent scans one tool instead of a dozen
 * near-identical names. The enums document the valid values in the schema, where
 * the agent can actually see them.
 */
final class FindContentAbility implements Ability
{
    public function __construct(private readonly ContentQuery $query)
    {
    }

    public function name(): string
    {
        return 'wp-ai-experiment/find-content';
    }

    public function label(): string
    {
        return __('Find content', 'wp-ai-experiment');
    }

    public function description(): string
    {
        return __(
            'Searches posts and pages, filtered by status, post type, author and date range, and returns a paginated list of matches.',
            'wp-ai-experiment'
        );
    }

    /**
     * Split across helpers because the whole literal would breach the 50-line
     * function limit once every property carries a description.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'default' => (object) [],
            'properties' => array_merge(
                $this->filterProperties(),
                $this->dateProperties(),
                $this->paginationProperties()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => $this->itemSchema(),
                ],
                'total' => ['type' => 'integer'],
                'total_pages' => ['type' => 'integer'],
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ],
            'required' => ['items', 'total', 'total_pages', 'page', 'per_page'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    /**
     * Input-dependent permission check: reading published content only needs
     * `read`, but anything unpublished is editorial data and needs `edit_posts`.
     *
     * A plain bool is returned rather than a descriptive `WP_Error` because
     * `WP_Ability::execute()` discards the latter and triggers
     * `_doing_it_wrong()` on top.
     */
    public function isAllowed(mixed $input = null): bool
    {
        $status = AbilityInput::fromMixed($input)->stringValue('status', 'publish');

        if ($status === 'publish') {
            return current_user_can('read');
        }

        return current_user_can('edit_posts');
    }

    public function execute(mixed $input = null): mixed
    {
        $values = AbilityInput::fromMixed($input);

        return $this->query->find(
            [
                'search' => $values->stringValue('search', ''),
                'post_type' => $values->stringValue('post_type', 'any'),
                'status' => $values->stringValue('status', 'publish'),
                'author' => $values->intValue('author', 0),
                'after' => $values->stringValue('after', ''),
                'before' => $values->stringValue('before', ''),
                'per_page' => $values->intValue('per_page', 10),
                'page' => $values->intValue('page', 1),
                'orderby' => $values->stringValue('orderby', 'date'),
                'order' => $values->stringValue('order', 'desc'),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filterProperties(): array
    {
        return [
            'search' => [
                'type' => 'string',
                'description' => __(
                    'Free-text term matched against post titles and content.',
                    'wp-ai-experiment'
                ),
            ],
            'post_type' => [
                'type' => 'string',
                'enum' => ['post', 'page', 'any'],
                'default' => 'any',
                'description' => __('Which post type to search.', 'wp-ai-experiment'),
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'future', 'draft', 'pending', 'private', 'any'],
                'default' => 'publish',
                'description' => __(
                    'Post status to filter by. Anything other than "publish" requires edit permissions.',
                    'wp-ai-experiment'
                ),
            ],
            'author' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => __(
                    'Restrict results to a single author ID. 0 means any author.',
                    'wp-ai-experiment'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dateProperties(): array
    {
        return [
            'after' => [
                'type' => 'string',
                'format' => 'date-time',
                'description' => __(
                    'Only return content published after this ISO 8601 date-time.',
                    'wp-ai-experiment'
                ),
            ],
            'before' => [
                'type' => 'string',
                'format' => 'date-time',
                'description' => __(
                    'Only return content published before this ISO 8601 date-time.',
                    'wp-ai-experiment'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationProperties(): array
    {
        return [
            'per_page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 50,
                'default' => 10,
                'description' => __('How many items to return per page.', 'wp-ai-experiment'),
            ],
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'default' => 1,
                'description' => __('Which page of results to return.', 'wp-ai-experiment'),
            ],
            'orderby' => [
                'type' => 'string',
                'enum' => ['date', 'title', 'modified', 'relevance'],
                'default' => 'date',
                'description' => __('Which field to sort by.', 'wp-ai-experiment'),
            ],
            'order' => [
                'type' => 'string',
                'enum' => ['asc', 'desc'],
                'default' => 'desc',
                'description' => __('Sort direction.', 'wp-ai-experiment'),
            ],
        ];
    }

    /**
     * `url` and `date` are intentionally absent from `required`: a draft has no
     * resolvable GMT publication date, and the service omits the key rather than
     * emit a value that would fail this schema's own `format` constraint.
     *
     * @return array<string, mixed>
     */
    private function itemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'type' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'author' => ['type' => 'integer'],
                'date' => ['type' => 'string', 'format' => 'date-time'],
                'excerpt' => ['type' => 'string'],
            ],
            'required' => ['id', 'title', 'type', 'status', 'author', 'excerpt'],
        ];
    }
}
