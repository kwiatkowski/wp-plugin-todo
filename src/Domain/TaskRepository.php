<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use MKTodo\ContentTypes;
use WP_Error;
use WP_Post;

/**
 * Tasks (private posts of the `todo_task` type) as plain arrays:
 * { id, title, notes, done, status, completedAt, due, time, duration, priority, projectId, position, createdAt, updatedAt }
 *
 * Dates: `due` is a day (Y-m-d), `time` the start on that day (H:i, 24h), `duration` minutes,
 * the rest are ISO 8601 timestamps in UTC. `done` is always `status === 'done'`.
 */
final class TaskRepository
{
    public const FIELDS = ['title', 'notes', 'done', 'status', 'due', 'time', 'duration', 'priority', 'projectId', 'position'];

    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $posts = get_posts([
            'post_type' => ContentTypes::TASK,
            'post_status' => 'private',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);

        // one query for all project relations instead of one per task
        update_object_term_cache(wp_list_pluck($posts, 'ID'), ContentTypes::TASK);

        return array_map([$this, 'toArray'], $posts);
    }

    /**
     * @param array<string, mixed> $data fields from self::FIELDS, `title` required
     * @return array<string, mixed>|WP_Error
     */
    public function create(array $data): array|WP_Error
    {
        if ($error = $this->validate($data)) {
            return $error;
        }

        $id = wp_insert_post(wp_slash([
            'post_type' => ContentTypes::TASK,
            'post_status' => 'private',
            'post_title' => $data['title'],
            'post_content' => $data['notes'] ?? '',
        ]), true);

        if (is_wp_error($id)) {
            return new WP_Error('todo_task_invalid', $id->get_error_message(), ['status' => 400]);
        }

        if (!array_key_exists('status', $data) && !array_key_exists('done', $data)) {
            $data['status'] = Status::Todo->value;
        }

        $this->saveFields($id, $data + ['priority' => Priority::None->value]);

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $changes any subset of self::FIELDS
     * @return array<string, mixed>|WP_Error
     */
    public function update(int $id, array $changes): array|WP_Error
    {
        $post = $this->post($id);

        if (!$post) {
            return $this->notFound();
        }

        if ($error = $this->validate($changes)) {
            return $error;
        }

        $postData = array_filter([
            'post_title' => $changes['title'] ?? null,
            'post_content' => $changes['notes'] ?? null,
        ], static fn ($value) => $value !== null);

        if ($postData) {
            $result = wp_update_post(wp_slash(['ID' => $id] + $postData), true);

            if (is_wp_error($result)) {
                return new WP_Error('todo_task_invalid', $result->get_error_message(), ['status' => 400]);
            }
        }

        $this->saveFields($id, $changes);

        // bump the modified date also when only meta changed
        if (!$postData) {
            wp_update_post(['ID' => $id]);
        }

        clean_post_cache($id);

        return $this->find($id);
    }

    /**
     * Copies the open tasks of one project into another – with notes, status, dates, priority and order.
     * Done tasks are history and stay where they are.
     */
    public function copyOpen(int $fromProject, int $toProject): true|WP_Error
    {
        foreach ($this->all() as $task) {
            if ($task['done'] || $task['projectId'] !== $fromProject) {
                continue;
            }

            $fields = array_intersect_key($task, array_flip(['title', 'notes', 'status', 'due', 'time', 'duration', 'priority', 'position']));
            $result = $this->create(['projectId' => $toProject] + array_filter($fields, static fn ($value) => $value !== null));

            if (is_wp_error($result)) {
                return $result;
            }
        }

        return true;
    }

    /**
     * Moves to the trash the tasks finished before `$cutoff` (ISO 8601 UTC, the format completedAt is stored in,
     * so the strings compare in time order). Open tasks are never touched.
     *
     * @return int how many were moved
     */
    public function trashDoneBefore(string $cutoff): int
    {
        $ids = get_posts([
            'post_type' => ContentTypes::TASK,
            'post_status' => 'private',
            'numberposts' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                ['key' => ContentTypes::META_DONE, 'value' => '1'],
                ['key' => ContentTypes::META_COMPLETED_AT, 'value' => $cutoff, 'compare' => '<'],
            ],
        ]);

        return count(array_filter($ids, static fn (int $id): bool => (bool) wp_trash_post($id)));
    }

    /** Moves the task to the trash – it can still be restored in wp-admin. */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
        }

        return wp_trash_post($id) ? true : new WP_Error('todo_task_delete_failed', __('Nie udało się usunąć zadania.', 'wp-plugin-todo'), ['status' => 500]);
    }

    /** @return array<string, mixed>|WP_Error */
    public function find(int $id): array|WP_Error
    {
        $post = $this->post($id);

        return $post ? $this->toArray($post) : $this->notFound();
    }

    private function post(int $id): ?WP_Post
    {
        $post = get_post($id);

        return $post instanceof WP_Post && $post->post_type === ContentTypes::TASK && $post->post_status === 'private' ? $post : null;
    }

    /** Checks what the REST schema can't: real calendar dates and existing projects. */
    private function validate(array $data): ?WP_Error
    {
        // the schema checks length before sanitizing, so "   " gets here as ""
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            return new WP_Error('todo_task_invalid_title', __('Zadanie musi mieć tytuł.', 'wp-plugin-todo'), ['status' => 400]);
        }

        if (!empty($data['due'])) {
            [$year, $month, $day] = array_map('intval', explode('-', (string) $data['due']) + [0, 0, 0]);

            if (!checkdate($month, $day, $year)) {
                return new WP_Error('todo_task_invalid_due', __('Nieprawidłowa data.', 'wp-plugin-todo'), ['status' => 400]);
            }
        }

        if (!empty($data['projectId']) && !$this->projects->exists((int) $data['projectId'])) {
            return new WP_Error('todo_task_invalid_project', __('Nie ma takiego projektu.', 'wp-plugin-todo'), ['status' => 400]);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function saveFields(int $id, array $data): void
    {
        $status = $this->sentStatus($data);

        if ($status) {
            $this->saveStatus($id, $status);
        }

        if (array_key_exists('time', $data)) {
            $data['time']
                ? update_post_meta($id, ContentTypes::META_TIME, $data['time'])
                : delete_post_meta($id, ContentTypes::META_TIME);
        }

        if (array_key_exists('duration', $data)) {
            $data['duration'] !== null && $data['duration'] !== ''
                ? update_post_meta($id, ContentTypes::META_DURATION, (int) $data['duration'])
                : delete_post_meta($id, ContentTypes::META_DURATION);
        }

        // the user's order: any number, the app puts a moved task between its new neighbours
        if (array_key_exists('position', $data)) {
            $data['position'] !== null && $data['position'] !== ''
                ? update_post_meta($id, ContentTypes::META_POSITION, (float) $data['position'])
                : delete_post_meta($id, ContentTypes::META_POSITION);
        }

        if (array_key_exists('due', $data)) {
            if ($data['due']) {
                update_post_meta($id, ContentTypes::META_DUE, $data['due']);
            } else {
                // a time without a day makes no sense
                delete_post_meta($id, ContentTypes::META_DUE);
                delete_post_meta($id, ContentTypes::META_TIME);
                delete_post_meta($id, ContentTypes::META_DURATION);
            }
        }

        if (array_key_exists('priority', $data)) {
            update_post_meta($id, ContentTypes::META_PRIORITY, $data['priority']);
        }

        if (array_key_exists('projectId', $data)) {
            wp_set_object_terms($id, $data['projectId'] ? [(int) $data['projectId']] : [], ContentTypes::PROJECT);
        }
    }

    /** `status` wins over `done` when both are sent. */
    private function sentStatus(array $data): ?Status
    {
        if (array_key_exists('status', $data)) {
            return Status::tryFrom((string) $data['status']);
        }

        if (array_key_exists('done', $data)) {
            return $data['done'] ? Status::Done : Status::Todo;
        }

        return null;
    }

    /** Keeps `done` in step with the status and stamps `completedAt` when the task gets done. */
    private function saveStatus(int $id, Status $status): void
    {
        $done = $status === Status::Done;
        $wasDone = (bool) get_post_meta($id, ContentTypes::META_DONE, true);

        update_post_meta($id, ContentTypes::META_STATUS, $status->value);
        update_post_meta($id, ContentTypes::META_DONE, $done);

        if ($done && !$wasDone) {
            update_post_meta($id, ContentTypes::META_COMPLETED_AT, gmdate(DATE_ATOM));
        } elseif (!$done) {
            delete_post_meta($id, ContentTypes::META_COMPLETED_AT);
        }
    }

    /** Status from meta, falling back to `done` for tasks saved before statuses existed. */
    private function status(int $id, bool $done): Status
    {
        if ($done) {
            return Status::Done;
        }

        $status = Status::tryFrom((string) get_post_meta($id, ContentTypes::META_STATUS, true));

        return $status && $status !== Status::Done ? $status : Status::Todo;
    }

    /** @return array<string, mixed> */
    private function toArray(WP_Post $post): array
    {
        $terms = get_the_terms($post, ContentTypes::PROJECT);
        $priority = Priority::tryFrom((string) get_post_meta($post->ID, ContentTypes::META_PRIORITY, true)) ?? Priority::None;
        $done = (bool) get_post_meta($post->ID, ContentTypes::META_DONE, true);
        $duration = get_post_meta($post->ID, ContentTypes::META_DURATION, true);
        $position = get_post_meta($post->ID, ContentTypes::META_POSITION, true);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'notes' => $post->post_content,
            'done' => $done,
            'status' => $this->status($post->ID, $done)->value,
            'completedAt' => get_post_meta($post->ID, ContentTypes::META_COMPLETED_AT, true) ?: null,
            'due' => get_post_meta($post->ID, ContentTypes::META_DUE, true) ?: null,
            'time' => get_post_meta($post->ID, ContentTypes::META_TIME, true) ?: null,
            'duration' => $duration !== '' && $duration !== false ? (int) $duration : null,
            'priority' => $priority->value,
            'projectId' => is_array($terms) && $terms ? $terms[0]->term_id : null,
            'position' => is_numeric($position) ? (float) $position : null,
            'createdAt' => $this->utc($post->post_date_gmt),
            'updatedAt' => $this->utc($post->post_modified_gmt),
        ];
    }

    private function utc(string $mysqlGmt): ?string
    {
        return $mysqlGmt && $mysqlGmt !== '0000-00-00 00:00:00' ? gmdate(DATE_ATOM, (int) strtotime($mysqlGmt . ' UTC')) : null;
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('todo_task_not_found', __('Nie ma takiego zadania.', 'wp-plugin-todo'), ['status' => 404]);
    }
}
