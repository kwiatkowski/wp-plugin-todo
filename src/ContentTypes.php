<?php

declare(strict_types=1);

namespace MKTodo;

/**
 * Storage: tasks are a private post type; projects are posts of `mk_project` from wp-plugin-projects (one list of
 * projects for the whole site – Todo shows those with `_project_in_todo`), a task points to one with `_todo_project`.
 * Areas are a taxonomy on tasks used for storage only – projects point to them with post meta.
 * Nothing is public on the front end; the admin screens are only a read-only backup view.
 *
 * The `todo_project` taxonomy is where projects lived before – kept registered only for the one-time migration
 * (ProjectsMigration).
 */
final class ContentTypes
{
    public const TASK = 'todo_task';
    public const PROJECT = 'mk_project';
    public const PROJECT_TAXONOMY = 'todo_project';
    public const AREA = 'todo_area';

    /** task → project (post ID) */
    public const META_PROJECT = '_todo_project';
    /** project: shown in Todo – the field `inTodo` of wp-plugin-projects */
    public const META_IN_TODO = '_project_in_todo';

    public const META_DONE = '_todo_done';
    public const META_COMPLETED_AT = '_todo_completed_at';
    public const META_DUE = '_todo_due';
    public const META_PRIORITY = '_todo_priority';
    public const META_STATUS = '_todo_status';
    public const META_TIME = '_todo_time';
    public const META_DURATION = '_todo_duration';
    public const META_START = '_todo_start';
    public const META_COLOR = '_todo_color';
    public const META_AREA = '_todo_area';
    public const META_POSITION = '_todo_position';
    public const META_ICON = '_todo_icon';

    public function register(): void
    {
        add_action('init', [$this, 'registerTypes']);
    }

    public function registerTypes(): void
    {
        $capability = Plugin::capability();

        register_post_type(self::TASK, [
            'labels' => [
                'name' => __('Zadania', 'wp-plugin-todo'),
                'singular_name' => __('Zadanie', 'wp-plugin-todo'),
                'menu_name' => __('Todo', 'wp-plugin-todo'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false, // own endpoints in todo/v1
            'menu_icon' => 'dashicons-yes-alt',
            'menu_position' => 6,
            'supports' => ['title', 'editor'],
            'capabilities' => [
                'edit_post' => $capability,
                'read_post' => $capability,
                'delete_post' => $capability,
                'edit_posts' => $capability,
                'edit_others_posts' => $capability,
                'publish_posts' => $capability,
                'read_private_posts' => $capability,
                'create_posts' => $capability,
                'delete_posts' => $capability,
            ],
            'rewrite' => false,
            'query_var' => false,
        ]);

        // before projects moved to wp-plugin-projects – read once by ProjectsMigration, otherwise unused
        register_taxonomy(self::PROJECT_TAXONOMY, [self::TASK], [
            'labels' => [
                'name' => __('Projekty (stare)', 'wp-plugin-todo'),
                'singular_name' => __('Projekt', 'wp-plugin-todo'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_admin_column' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'capabilities' => [
                'manage_terms' => $capability,
                'edit_terms' => $capability,
                'delete_terms' => $capability,
                'assign_terms' => $capability,
            ],
            'rewrite' => false,
            'query_var' => false,
        ]);

        register_taxonomy(self::AREA, [self::TASK], [
            'labels' => [
                'name' => __('Kategorie', 'wp-plugin-todo'),
                'singular_name' => __('Kategoria', 'wp-plugin-todo'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'capabilities' => [
                'manage_terms' => $capability,
                'edit_terms' => $capability,
                'delete_terms' => $capability,
                'assign_terms' => $capability,
            ],
            'rewrite' => false,
            'query_var' => false,
        ]);

        $meta = [
            self::META_DONE => 'boolean',
            self::META_COMPLETED_AT => 'string',
            self::META_DUE => 'string',
            self::META_PRIORITY => 'string',
            self::META_STATUS => 'string',
            self::META_TIME => 'string',
            self::META_DURATION => 'integer',
            self::META_START => 'string',
            self::META_POSITION => 'number', // also the order of areas – term meta, a separate table
            self::META_PROJECT => 'integer',
        ];

        foreach ($meta as $key => $type) {
            register_post_meta(self::TASK, $key, [
                'type' => $type,
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => [Plugin::class, 'canUse'],
            ]);
        }

        // Todo's own fields of a project, on the project post of wp-plugin-projects
        foreach ([self::META_COLOR => 'string', self::META_AREA => 'integer', self::META_POSITION => 'integer'] as $key => $type) {
            register_post_meta(self::PROJECT, $key, [
                'type' => $type,
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => [Plugin::class, 'canUse'],
            ]);
        }

        register_term_meta(self::AREA, self::META_POSITION, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => [Plugin::class, 'canUse'],
        ]);

        register_term_meta(self::AREA, self::META_ICON, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => [Plugin::class, 'canUse'],
        ]);
    }
}
