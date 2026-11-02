<?php

declare(strict_types=1);

namespace MKTodo;

use MKTodo\App\AppShell;
use MKTodo\Cron\DoneCleanup;
use MKTodo\Domain\AreaCopier;
use MKTodo\Domain\AreaRepository;
use MKTodo\Domain\ProjectCopier;
use MKTodo\Domain\ProjectRepository;
use MKTodo\Domain\TaskActivity;
use MKTodo\Domain\TaskRepository;
use MKTodo\Domain\TaskSummary;
use MKTodo\Rest\AreasController;
use MKTodo\Rest\ProjectsController;
use MKTodo\Rest\SummaryController;
use MKTodo\Rest\TasksController;

/**
 * Wires the plugin together.
 *
 * Filters:
 *  - `wp_plugin_todo/capability` (string) – who may use the app and the API, default "manage_options"
 *  - `wp_plugin_todo/path` (string)       – URL path of the app, default "todo"
 *  - `wp_plugin_todo/app_index` (string)  – built index.html of the Vue app, default {web root}/todo/web/index.html
 *  - `wp_plugin_todo/done_retention_days` (int) – finished tasks go to the trash this many days after, default 30, 0 = never
 */
final class Plugin
{
    public static function boot(): void
    {
        $areas = new AreaRepository();
        $projects = new ProjectRepository($areas);
        $activity = new TaskActivity();
        $tasks = new TaskRepository($projects, $activity);

        (new ContentTypes())->register();
        (new AreasController($areas, new AreaCopier($areas, $projects, $tasks)))->register();
        (new ProjectsController($projects, new ProjectCopier($projects, $tasks)))->register();
        (new TasksController($tasks, $activity))->register();
        // today's tasks for Home Assistant – read only, with a token of its own
        (new SummaryController(new TaskSummary($tasks, $projects, $areas)))->register();
        (new AppShell())->register();
        (new DoneCleanup($tasks))->register();

        add_filter('mk_core/dashboard/packages', [self::class, 'dashboardCard']);

        if (defined('WP_CLI') && WP_CLI) {
            // a new token for GET todo/v1/summary – printed once, the old one stops working
            \WP_CLI::add_command('todo summary-token', static function (): void {
                \WP_CLI::line(SummaryController::newToken());
            });
        }
    }

    public static function capability(): string
    {
        return (string) apply_filters('wp_plugin_todo/capability', 'manage_options');
    }

    public static function canUse(): bool
    {
        return current_user_can(self::capability());
    }

    /** Card in the MK Core dashboard widget, when MK Core is installed. */
    public static function dashboardCard(array $packages): array
    {
        if (class_exists(\MKCore\Support\Package::class)) {
            $packages[] = \MKCore\Support\Package::fromPluginFile(WP_PLUGIN_TODO_FILE, __('Aplikacja', 'wp-plugin-todo'), 'dashicons-yes-alt');
        }

        return array_filter($packages);
    }
}
