<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use MKTodo\ContentTypes;
use WP_Error;
use WP_Term;

/**
 * Areas ("kategorie", terms of the `todo_area` taxonomy) group projects, e.g. "Praca", "Dom":
 * { id: int, name: string, position: int|null, icon: string|null }
 *
 * `icon` is a key from the app's icon set (the app falls back to a guess from the name when it's null).
 * The order is the user's own (`_todo_position` term meta); areas never placed (position null) come last, by name.
 *
 * Tasks are never assigned to an area – projects point to it with the `_todo_area` term meta.
 */
final class AreaRepository
{
    public const SEEDED_OPTION = 'wp_plugin_todo_areas_seeded';

    /** @return array<int, array{id: int, name: string, position: int|null, icon: string|null}> */
    public function all(): array
    {
        $this->seed();

        $terms = get_terms([
            'taxonomy' => ContentTypes::AREA,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        $areas = is_array($terms) ? array_map([$this, 'toArray'], $terms) : [];

        // sorted here – term order plugins override `orderby`
        $collator = class_exists(\Collator::class) ? new \Collator(get_locale()) : null;
        usort($areas, static fn (array $a, array $b): int => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX)
            ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strnatcasecmp($a['name'], $b['name'])));

        return $areas;
    }

    public function exists(int $id): bool
    {
        return get_term($id, ContentTypes::AREA) instanceof WP_Term;
    }

    /**
     * A new area goes to the end of the list.
     *
     * @return array{id: int, name: string, position: int|null, icon: string|null}|WP_Error
     */
    public function create(string $name, ?string $icon = null): array|WP_Error
    {
        $existing = array_column($this->all(), 'id');
        $result = wp_insert_term(wp_slash($name), ContentTypes::AREA);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        if ($icon) {
            update_term_meta($result['term_id'], ContentTypes::META_ICON, $icon);
        }

        // the current order (also of areas never placed) becomes explicit, the new one goes after it
        foreach ([...$existing, $result['term_id']] as $position => $id) {
            update_term_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->find($result['term_id']);
    }

    /**
     * Sets the order: `$ids` must list every area exactly once.
     *
     * @param int[] $ids
     * @return array<int, array{id: int, name: string, position: int|null, icon: string|null}>|WP_Error the areas in the new order
     */
    public function reorder(array $ids): array|WP_Error
    {
        $ids = array_map('intval', $ids);
        $known = array_column($this->all(), 'id');

        if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $known) || array_diff($known, $ids)) {
            return new WP_Error('todo_area_invalid_order', __('Kolejność musi zawierać każdą kategorię dokładnie raz.', 'wp-plugin-todo'), ['status' => 400]);
        }

        foreach ($ids as $position => $id) {
            update_term_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->all();
    }

    /**
     * @param array{name?: string, icon?: string|null} $changes null `icon` clears it
     * @return array{id: int, name: string, position: int|null, icon: string|null}|WP_Error
     */
    public function update(int $id, array $changes): array|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        if (isset($changes['name'])) {
            $result = wp_update_term($id, ContentTypes::AREA, ['name' => wp_slash($changes['name'])]);

            if (is_wp_error($result)) {
                return $this->error($result);
            }
        }

        if (array_key_exists('icon', $changes)) {
            $changes['icon']
                ? update_term_meta($id, ContentTypes::META_ICON, $changes['icon'])
                : delete_term_meta($id, ContentTypes::META_ICON);
        }

        return $this->find($id);
    }

    /** Deletes the area; its projects stay, without an area. */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        $result = wp_delete_term($id, ContentTypes::AREA);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        // projects are posts of wp-plugin-projects – the area is their post meta
        delete_metadata('post', 0, ContentTypes::META_AREA, (string) $id, true);

        return true;
    }

    /** @return array{id: int, name: string, position: int|null, icon: string|null}|WP_Error */
    public function find(int $id): array|WP_Error
    {
        $term = get_term($id, ContentTypes::AREA);

        return $term instanceof WP_Term ? $this->toArray($term) : $this->notFound();
    }

    /** Creates the default areas once – deleting them later is the user's choice. */
    private function seed(): void
    {
        if (get_option(self::SEEDED_OPTION)) {
            return;
        }

        foreach ([__('Praca', 'wp-plugin-todo'), __('Dom', 'wp-plugin-todo')] as $name) {
            if (!term_exists($name, ContentTypes::AREA)) {
                wp_insert_term($name, ContentTypes::AREA);
            }
        }

        update_option(self::SEEDED_OPTION, 1, false);
    }

    /** @return array{id: int, name: string, position: int|null, icon: string|null} */
    private function toArray(WP_Term $term): array
    {
        $position = get_term_meta($term->term_id, ContentTypes::META_POSITION, true);

        return [
            'id' => $term->term_id,
            'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
            'position' => $position === '' ? null : (int) $position,
            'icon' => (string) get_term_meta($term->term_id, ContentTypes::META_ICON, true) ?: null,
        ];
    }

    /** "Praca (kopia)", then "Praca (kopia 2)", … – the first name not taken yet. */
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

    private function notFound(): WP_Error
    {
        return new WP_Error('todo_area_not_found', __('Nie ma takiej kategorii.', 'wp-plugin-todo'), ['status' => 404]);
    }

    private function error(WP_Error $error): WP_Error
    {
        $message = $error->get_error_code() === 'term_exists'
            ? __('Kategoria o tej nazwie już istnieje.', 'wp-plugin-todo')
            : $error->get_error_message();

        return new WP_Error('todo_area_invalid', $message, ['status' => 400]);
    }
}
