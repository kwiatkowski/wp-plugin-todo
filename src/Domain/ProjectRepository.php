<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use MKTodo\ContentTypes;
use WP_Error;
use WP_Post;

/**
 * Projects as Todo sees them – posts of `mk_project` (wp-plugin-projects) with `_project_in_todo` – as plain arrays:
 * { id: int, name: string, color: string, areaId: int|null, position: int|null }
 *
 * The projects themselves belong to wp-plugin-projects; Todo adds its own fields to them (colour, category, order)
 * and decides nothing about their existence: "deleting" a project here only takes it out of Todo.
 * `position` is the user's order within the project's area; projects never placed (null) come last, by name.
 */
final class ProjectRepository
{
    public const DEFAULT_COLOR = '#7a9a3a';

    public function __construct(private readonly AreaRepository $areas)
    {
    }

    /** wp-plugin-projects is there – without it Todo works with tasks only. */
    public static function available(): bool
    {
        return post_type_exists(ContentTypes::PROJECT);
    }

    /** @return array<int, array{id: int, name: string, color: string, areaId: int|null, position: int|null}> */
    public function all(): array
    {
        if (!self::available()) {
            return [];
        }

        $posts = get_posts([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'no_found_rows' => true,
            'meta_query' => [['key' => ContentTypes::META_IN_TODO, 'value' => '1']],
        ]);

        $projects = array_map([$this, 'toArray'], $posts);

        $collator = class_exists(\Collator::class) ? new \Collator(get_locale()) : null;
        usort($projects, static fn (array $a, array $b): int => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX)
            ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strnatcasecmp($a['name'], $b['name'])));

        return $projects;
    }

    /** Shown in Todo. */
    public function exists(int $id): bool
    {
        return $this->post($id) !== null && (bool) get_post_meta($id, ContentTypes::META_IN_TODO, true);
    }

    /** A project of the list that is just not shown in Todo – its tasks are hidden with it, not orphaned. */
    public function isHidden(int $id): bool
    {
        return $this->post($id) !== null && !get_post_meta($id, ContentTypes::META_IN_TODO, true);
    }

    /** @return array{id: int, name: string, color: string, areaId: int|null, position: int|null}|WP_Error */
    public function create(string $name, ?string $color, ?int $areaId = null): array|WP_Error
    {
        if ($error = $this->unavailable() ?? $this->validateArea($areaId)) {
            return $error;
        }

        $id = $this->insert($name);

        if (is_wp_error($id)) {
            return $id;
        }

        update_post_meta($id, ContentTypes::META_COLOR, $color ?: self::DEFAULT_COLOR);

        // the area's current order (also of projects never placed) becomes explicit, the new one goes last
        $siblings = array_column(array_filter($this->all(), static fn (array $project): bool => $project['areaId'] === ($areaId ?: null)), 'id');

        if ($areaId) {
            update_post_meta($id, ContentTypes::META_AREA, $areaId);
        }

        foreach ([...array_diff($siblings, [$id]), $id] as $position => $sibling) {
            update_post_meta($sibling, ContentTypes::META_POSITION, $position);
        }

        return $this->find($id);
    }

    /**
     * @param array{name?: string, color?: string, areaId?: int|null} $changes null `areaId` clears it
     * @return array{id: int, name: string, color: string, areaId: int|null, position: int|null}|WP_Error
     */
    public function update(int $id, array $changes): array|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        if (array_key_exists('areaId', $changes) && ($error = $this->validateArea($changes['areaId']))) {
            return $error;
        }

        if (isset($changes['name'])) {
            $result = wp_update_post(wp_slash(['ID' => $id, 'post_title' => $changes['name']]), true);

            if (is_wp_error($result)) {
                return $this->error($result);
            }
        }

        if (isset($changes['color'])) {
            update_post_meta($id, ContentTypes::META_COLOR, $changes['color']);
        }

        if (array_key_exists('areaId', $changes)) {
            $changes['areaId']
                ? update_post_meta($id, ContentTypes::META_AREA, (int) $changes['areaId'])
                : delete_post_meta($id, ContentTypes::META_AREA);
        }

        return $this->find($id);
    }

    /**
     * Sets the order of the given projects – usually one area's, as they stand in the sidebar.
     *
     * @param int[] $ids
     * @return array<int, array{id: int, name: string, color: string, areaId: int|null, position: int|null}>|WP_Error all projects
     */
    public function reorder(array $ids): array|WP_Error
    {
        $ids = array_map('intval', $ids);

        if (!$ids || count($ids) !== count(array_unique($ids)) || array_diff($ids, array_column($this->all(), 'id'))) {
            return new WP_Error('todo_project_invalid_order', __('Nieprawidłowa kolejność projektów.', 'wp-plugin-todo'), ['status' => 400]);
        }

        foreach ($ids as $position => $id) {
            update_post_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->all();
    }

    /**
     * A copy of `$project` in `$areaId` (null = no category) – same colour and place, same name unless `$name`
     * is given. A new project of the list – only with Todo's fields, not the site's.
     *
     * @param array{id: int, name: string, color: string, areaId: int|null, position: int|null} $project
     * @return array{id: int, name: string, color: string, areaId: int|null, position: int|null}|WP_Error
     */
    public function duplicate(array $project, ?int $areaId, ?string $name = null): array|WP_Error
    {
        $id = $this->insert($name ?? $project['name']);

        if (is_wp_error($id)) {
            return $id;
        }

        update_post_meta($id, ContentTypes::META_COLOR, $project['color']);

        if ($areaId) {
            update_post_meta($id, ContentTypes::META_AREA, $areaId);
        }

        if ($project['position'] !== null) {
            update_post_meta($id, ContentTypes::META_POSITION, $project['position']);
        }

        return $this->find($id);
    }

    /** "Strona (kopia)", then "Strona (kopia 2)", … – the first name not taken yet. */
    public function copyName(string $name): string
    {
        $taken = array_column($this->all(), 'name');

        for ($number = 1; ; $number++) {
            $candidate = $number === 1
                ? sprintf(__('%s (kopia)', 'wp-plugin-todo'), $name)
                : sprintf(__('%1$s (kopia %2$d)', 'wp-plugin-todo'), $name, $number);

            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * Takes the project out of Todo – it stays in the list of projects, its tasks keep it and are hidden with it
     * until it is shown in Todo again (the projects app). Deleting the project itself is done there.
     */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        update_post_meta($id, ContentTypes::META_IN_TODO, 0);

        return true;
    }

    /** @return array{id: int, name: string, color: string, areaId: int|null, position: int|null}|WP_Error */
    public function find(int $id): array|WP_Error
    {
        $post = $this->exists($id) ? $this->post($id) : null;

        return $post ? $this->toArray($post) : $this->notFound();
    }

    /** A new project of the list, shown in Todo. */
    private function insert(string $name): int|WP_Error
    {
        $id = wp_insert_post(wp_slash([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'post_title' => $name,
        ]), true);

        if (is_wp_error($id)) {
            return $this->error($id);
        }

        update_post_meta($id, ContentTypes::META_IN_TODO, 1);

        return $id;
    }

    private function post(int $id): ?WP_Post
    {
        $post = $id > 0 ? get_post($id) : null;

        return $post instanceof WP_Post && $post->post_type === ContentTypes::PROJECT && $post->post_status === 'publish' ? $post : null;
    }

    /** @return array{id: int, name: string, color: string, areaId: int|null, position: int|null} */
    private function toArray(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'name' => html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8'),
            'color' => (string) get_post_meta($post->ID, ContentTypes::META_COLOR, true) ?: self::DEFAULT_COLOR,
            'areaId' => (int) get_post_meta($post->ID, ContentTypes::META_AREA, true) ?: null,
            'position' => is_numeric($position = get_post_meta($post->ID, ContentTypes::META_POSITION, true)) ? (int) $position : null,
        ];
    }

    private function validateArea(mixed $areaId): ?WP_Error
    {
        if ($areaId && !$this->areas->exists((int) $areaId)) {
            return new WP_Error('todo_project_invalid_area', __('Nie ma takiej kategorii.', 'wp-plugin-todo'), ['status' => 400]);
        }

        return null;
    }

    private function unavailable(): ?WP_Error
    {
        return self::available() ? null : new WP_Error('todo_projects_unavailable', __('Projekty wymagają wtyczki wp-plugin-projects.', 'wp-plugin-todo'), ['status' => 503]);
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('todo_project_not_found', __('Nie ma takiego projektu.', 'wp-plugin-todo'), ['status' => 404]);
    }

    private function error(WP_Error $error): WP_Error
    {
        return new WP_Error('todo_project_invalid', $error->get_error_message(), ['status' => 400]);
    }
}
