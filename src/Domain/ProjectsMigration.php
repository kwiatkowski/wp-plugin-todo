<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use MKTodo\ContentTypes;
use WP_Term;

/**
 * One-time move of Todo's projects from the `todo_project` taxonomy to the list of projects (wp-plugin-projects):
 * every term becomes a project post shown in Todo – or joins the one with the same name, if the list already has it –
 * with its colour, category and order, and its tasks (also those in the trash) point to it with `_todo_project`.
 *
 * Runs once, as soon as wp-plugin-projects is there; the terms stay untouched as a backup.
 */
final class ProjectsMigration
{
    public const OPTION = 'wp_plugin_todo_projects_migrated';

    public function register(): void
    {
        // after wp-plugin-projects registered its post type (init, 10)
        add_action('init', [$this, 'run'], 20);
    }

    public function run(): void
    {
        if (get_option(self::OPTION) || !ProjectRepository::available()) {
            return;
        }

        $terms = get_terms(['taxonomy' => ContentTypes::PROJECT_TAXONOMY, 'hide_empty' => false]);

        if (!is_array($terms)) {
            return;
        }

        $map = [];

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $id = $this->project(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'));

            if (!$id) {
                continue;
            }

            update_post_meta($id, ContentTypes::META_IN_TODO, 1);

            foreach ([ContentTypes::META_COLOR, ContentTypes::META_AREA, ContentTypes::META_POSITION] as $key) {
                $value = get_term_meta($term->term_id, $key, true);

                if ($value !== '' && $value !== false) {
                    update_post_meta($id, $key, $value);
                }
            }

            $tasks = get_posts([
                'post_type' => ContentTypes::TASK,
                'post_status' => ['private', 'trash'],
                'numberposts' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'tax_query' => [['taxonomy' => ContentTypes::PROJECT_TAXONOMY, 'field' => 'term_id', 'terms' => $term->term_id]],
            ]);

            foreach ($tasks as $task) {
                update_post_meta($task, ContentTypes::META_PROJECT, $id);
            }

            $map[$term->term_id] = ['project' => $id, 'tasks' => count($tasks)];
        }

        update_option(self::OPTION, ['at' => gmdate(DATE_ATOM), 'map' => $map], false);
    }

    /** The project of that name on the list, or a new one. */
    private function project(string $name): int
    {
        $existing = get_posts([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'title' => $name,
            'numberposts' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if ($existing) {
            return (int) $existing[0];
        }

        $id = wp_insert_post(wp_slash([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'post_title' => $name,
        ]), true);

        return is_wp_error($id) ? 0 : $id;
    }
}
