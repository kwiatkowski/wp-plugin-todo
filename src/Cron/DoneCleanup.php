<?php

declare(strict_types=1);

namespace MKTodo\Cron;

use MKTodo\Domain\TaskRepository;

/**
 * Once a day moves to the trash the tasks finished more than N days ago (default 30, filter
 * `wp_plugin_todo/done_retention_days`; 0 turns it off). WordPress empties the trash on its own after
 * EMPTY_TRASH_DAYS (30 by default), so until then a task can still be restored in wp-admin.
 */
final class DoneCleanup
{
    public const HOOK = 'wp_plugin_todo/cleanup_done';

    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public static function retentionDays(): int
    {
        return max(0, (int) apply_filters('wp_plugin_todo/done_retention_days', 30));
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);

        // a must-use plugin has no activation hook – schedule on the first request that finds it missing
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
            }
        });
    }

    /** @return int how many tasks went to the trash */
    public function run(): int
    {
        $days = self::retentionDays();

        if ($days === 0) {
            return 0;
        }

        return $this->tasks->trashDoneBefore(gmdate(DATE_ATOM, time() - $days * DAY_IN_SECONDS));
    }
}
