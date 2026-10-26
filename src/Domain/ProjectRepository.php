<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use MKTodo\ContentTypes;
use WP_Error;
use WP_Term;

/**
 * Projects (terms of the `todo_project` taxonomy) as plain arrays:
 * { id: int, name: string, color: string, areaId: int|null, position: int|null }
 *
 * `position` is the user's order within the project's area; projects never placed (null) come last, by name.
 */
final class ProjectRepository
{
    public const DEFAULT_COLOR = '#7a9a3a';

    public function __construct(private readonly AreaRepository $areas)
    {
    }

    /** @return array<int, array{id: int, name: string, color: string, areaId: int|null, position: int|null}> */
    public function all(): array
    {
        $terms = get_terms([
            'taxonomy' => ContentTypes::PROJECT,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        $projects = is_array($terms) ? array_map([$this, 'toArray'], $terms) : [];

        // sorted here – term order plugins override `orderby`
        $collator = class_exists(\Collator::class) ? new \Collator(get_locale()) : null;
        usort($projects, static fn (array $a, array $b): int => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX)
            ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strnatcasecmp($a['name'], $b['name'])));

        return $projects;
    }

    public function exists(int $id): bool
    {
        return get_term($id, ContentTypes::PROJECT) instanceof WP_Term;
    }

    /** @return array{id: int, name: string, color: string, areaId: int|null, position: int|null}|WP_Error */
    public function create(string $name, ?string $color, ?int $areaId = null): array|WP_Error
    {
        if ($error = $this->validateArea($areaId)) {
            return $error;
        }

        $result = wp_insert_term(wp_slash($name), ContentTypes::PROJECT);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        update_term_meta($result['term_id'], ContentTypes::META_COLOR, $color ?: self::DEFAULT_COLOR);

        // the area's current order (also of projects never placed) becomes explicit, the new one goes last
        $siblings = array_column(array_filter($this->all(), static fn (array $project): bool => $project['areaId'] === ($areaId ?: null)), 'id');

        if ($areaId) {
            update_term_meta($result['term_id'], ContentTypes::META_AREA, $areaId);
        }

        foreach ([...array_diff($siblings, [$result['term_id']]), $result['term_id']] as $position => $id) {
            update_term_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->find($result['term_id']);
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
            $result = wp_update_term($id, ContentTypes::PROJECT, ['name' => wp_slash($changes['name'])]);

            if (is_wp_error($result)) {
                return $this->error($result);
            }
        }

        if (isset($changes['color'])) {
            update_term_meta($id, ContentTypes::META_COLOR, $changes['color']);
        }

        if (array_key_exists('areaId', $changes)) {
            $changes['areaId']
                ? update_term_meta($id, ContentTypes::META_AREA, (int) $changes['areaId'])
                : delete_term_meta($id, ContentTypes::META_AREA);
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
            update_term_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->all();
    }

    /**
     * A copy of `$project` in `$areaId` (null = no category) – same colour and place, same name unless `$name`
     * is given. Project names are unique per slug, not per name, so the copy gets its own slug.
     *
     * @param array{id: int, name: string, color: string, areaId: int|null, position: int|null} $project
     * @return array{id: int, name: string, color: string, areaId: int|null, position: int|null}|WP_Error
     */
    public function duplicate(array $project, ?int $areaId, ?string $name = null): array|WP_Error
    {
        $name ??= $project['name'];
        $slug = wp_unique_term_slug(sanitize_title($name), (object) ['taxonomy' => ContentTypes::PROJECT, 'parent' => 0]);
        $result = wp_insert_term(wp_slash($name), ContentTypes::PROJECT, ['slug' => $slug]);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        update_term_meta($result['term_id'], ContentTypes::META_COLOR, $project['color']);

        if ($areaId) {
            update_term_meta($result['term_id'], ContentTypes::META_AREA, $areaId);
        }

        if ($project['position'] !== null) {
            update_term_meta($result['term_id'], ContentTypes::META_POSITION, $project['position']);
        }

        return $this->find($result['term_id']);
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

    /** Deletes the project; its tasks stay and land in the inbox (no project). */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        $result = wp_delete_term($id, ContentTypes::PROJECT);

        return is_wp_error($result) ? $this->error($result) : true;
    }

    /** @return array{id: int, name: string, color: string, areaId: int|null, position: int|null}|WP_Error */
    public function find(int $id): array|WP_Error
    {
        $term = get_term($id, ContentTypes::PROJECT);

        return $term instanceof WP_Term ? $this->toArray($term) : $this->notFound();
    }

    /** @return array{id: int, name: string, color: string, areaId: int|null, position: int|null} */
    private function toArray(WP_Term $term): array
    {
        return [
            'id' => $term->term_id,
            'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
            'color' => (string) get_term_meta($term->term_id, ContentTypes::META_COLOR, true) ?: self::DEFAULT_COLOR,
            'areaId' => (int) get_term_meta($term->term_id, ContentTypes::META_AREA, true) ?: null,
            'position' => is_numeric($position = get_term_meta($term->term_id, ContentTypes::META_POSITION, true)) ? (int) $position : null,
        ];
    }

    private function validateArea(mixed $areaId): ?WP_Error
    {
        if ($areaId && !$this->areas->exists((int) $areaId)) {
            return new WP_Error('todo_project_invalid_area', __('Nie ma takiej kategorii.', 'wp-plugin-todo'), ['status' => 400]);
        }

        return null;
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('todo_project_not_found', __('Nie ma takiego projektu.', 'wp-plugin-todo'), ['status' => 404]);
    }

    private function error(WP_Error $error): WP_Error
    {
        $message = $error->get_error_code() === 'term_exists'
            ? __('Projekt o tej nazwie już istnieje.', 'wp-plugin-todo')
            : $error->get_error_message();

        return new WP_Error('todo_project_invalid', $message, ['status' => 400]);
    }
}
