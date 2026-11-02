<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use MKTodo\App\AppShell;

/**
 * Today's tasks for a glance from outside the app (the Home Assistant card): the counts of the plan's tabs and the
 * "Dziś" tab's tasks grouped as the list groups them – the same rules as the app (src/stores/tasks.ts, utils/tasks.ts):
 * a task belongs to today when its first day (start, else due) is today or earlier – the overdue and the running too.
 *
 * { date, url, newUrl, counts: { today, overdue, doing, tomorrow, week },
 *   groups: [{ project, area, color, tasks: [{ id, title, status, priority, comments, overdue, today, when, progress, url }] }] }
 */
final class TaskSummary
{
    private const MONTHS = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ProjectRepository $projects,
        private readonly AreaRepository $areas,
    ) {
    }

    /** @return array<string, mixed> */
    public function today(): array
    {
        $day = wp_date('Y-m-d');
        $tomorrow = self::addDays($day, 1);
        $sunday = self::addDays($day, 7 - (int) wp_date('N'));
        $url = AppShell::url();
        $counts = ['today' => 0, 'overdue' => 0, 'doing' => 0, 'tomorrow' => 0, 'week' => 0];
        $today = [];

        foreach ($this->tasks->all() as $task) {
            if ($task['done']) {
                continue;
            }

            $first = $task['start'] ?? $task['due'];

            if ($first !== null && $first <= $day) {
                $counts['today']++;
                $counts['overdue'] += $task['due'] < $day ? 1 : 0;
                $counts['doing'] += $task['status'] === 'doing' ? 1 : 0;
                $today[] = $task;
            }

            if ($first !== null && $first <= $tomorrow && $tomorrow <= $task['due']) {
                $counts['tomorrow']++;
            }

            if ($first !== null && $first <= $sunday) {
                $counts['week']++;
            }
        }

        return [
            'date' => $day,
            'url' => $url,
            'newUrl' => add_query_arg('nowe', '', $url),
            'counts' => $counts,
            'groups' => $this->groups($today, $day, $url),
        ];
    }

    /**
     * As the list shows them: the areas in their order, their projects in theirs, then the projects of no area and the
     * inbox; in a group the ones in progress first, then the user's order.
     *
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $tasks, string $day, string $url): array
    {
        $areaOrder = [];
        $areaNames = [];

        foreach ($this->areas->all() as $index => $area) {
            $areaOrder[$area['id']] = $index;
            $areaNames[$area['id']] = $area['name'];
        }

        $projects = [];

        foreach ($this->projects->all() as $index => $project) {
            $projects[$project['id']] = $project + ['order' => [$areaOrder[$project['areaId']] ?? PHP_INT_MAX, $index]];
        }

        $groups = [];

        foreach ($tasks as $task) {
            $project = $projects[$task['projectId']] ?? null;
            $key = $project ? $project['id'] : 0;
            $groups[$key] ??= [
                'project' => $project ? $project['name'] : 'Do posortowania',
                'area' => $project ? ($areaNames[$project['areaId']] ?? null) : null,
                'color' => $project ? $project['color'] : null,
                'order' => $project ? $project['order'] : [PHP_INT_MAX, PHP_INT_MAX],
                'tasks' => [],
            ];
            $groups[$key]['tasks'][] = $task;
        }

        uasort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_values(array_map(function (array $group) use ($day, $url): array {
            usort($group['tasks'], static fn (array $a, array $b): int => (int) ($b['status'] === 'doing') <=> (int) ($a['status'] === 'doing')
                ?: self::sortKey($a) <=> self::sortKey($b)
                ?: $a['id'] <=> $b['id']);
            unset($group['order']);
            $group['tasks'] = array_map(fn (array $task): array => $this->task($task, $day, $url), $group['tasks']);

            return $group;
        }, $groups));
    }

    /** @param array<string, mixed> $task */
    private function task(array $task, string $day, string $url): array
    {
        $due = (string) $task['due'];
        $start = $task['start'];
        $overdue = $due < $day;

        // the right-hand column of the app's row: the span's end, a past day, the time, or "dziś"
        if ($overdue) {
            $when = $due === self::addDays($day, -1) ? 'wczoraj' : self::day($due);
        } elseif ($start !== null && $due !== $day) {
            $when = 'do ' . self::day($due);
        } elseif ($task['time'] !== null) {
            $when = self::range((string) $task['time'], $task['duration']);
        } else {
            $when = 'dziś';
        }

        return [
            'id' => $task['id'],
            'title' => $task['title'],
            'status' => $task['status'],
            'priority' => $task['priority'],
            'comments' => $task['comments'],
            'overdue' => $overdue,
            'today' => !$overdue && ($start === null || $due === $day),
            'when' => $when,
            // several days: how far along, as the app's row shows it
            'progress' => $start !== null && !$overdue
                ? sprintf('dzień %d z %d', self::days($start, $day), self::days($start, $due))
                : null,
            'url' => add_query_arg('zadanie', $task['id'], $url),
        ];
    }

    /** the app's manual order (utils/order.ts): the position, else the creation time in seconds */
    private static function sortKey(array $task): float
    {
        return $task['position'] ?? (float) strtotime((string) $task['createdAt']);
    }

    private static function addDays(string $day, int $days): string
    {
        return gmdate('Y-m-d', (int) strtotime("$day $days days UTC"));
    }

    /** "28 paź" */
    private static function day(string $day): string
    {
        [, $month, $date] = array_map('intval', explode('-', $day));

        return $date . ' ' . self::MONTHS[$month - 1];
    }

    /** inclusive: the first day is day 1 */
    private static function days(string $from, string $to): int
    {
        return (int) round((strtotime("$to UTC") - strtotime("$from UTC")) / DAY_IN_SECONDS) + 1;
    }

    /** "10:00–10:30" – 30 minutes when no duration was set, as in the app */
    private static function range(string $time, ?int $duration): string
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        $end = min($hours * 60 + $minutes + ($duration ?? 30), 24 * 60 - 1);

        return sprintf('%s–%02d:%02d', $time, intdiv($end, 60), $end % 60);
    }
}
