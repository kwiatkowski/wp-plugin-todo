<?php

declare(strict_types=1);

namespace MKTodo\Domain;

use MKTodo\ContentTypes;
use WP_Error;
use WP_Term;

/**
 * A task's comments and history – rows of its post meta (one row each, JSON), so they go with the task to the trash
 * and back and nothing shows up in wp-admin's comments.
 *
 * Comment: { id, text, createdAt, author }
 * Event:   { id, type: created|changed, createdAt, author, changes: [{ field, from, to }] } – `notes` only says it
 *          changed (from and to null), `projectId` keeps the project's name as it was then.
 */
final class TaskActivity
{
    /** what the history follows – the order of the task's fields; a moved task (position) isn't a change worth a line */
    private const TRACKED = ['title', 'status', 'projectId', 'priority', 'start', 'due', 'time', 'duration', 'notes'];

    /** @return array{comments: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>} */
    public function of(int $taskId): array
    {
        $activity = ['comments' => [], 'history' => []];

        foreach ($this->rows($taskId) as $row) {
            $data = json_decode((string) $row->meta_value, true);

            if (!is_array($data)) {
                continue;
            }

            $base = [
                'id' => (int) $row->meta_id,
                'createdAt' => (string) ($data['at'] ?? ''),
                'author' => $this->author((int) ($data['user'] ?? 0)),
            ];

            if ($row->meta_key === ContentTypes::META_COMMENT) {
                $activity['comments'][] = $base + ['text' => (string) ($data['text'] ?? '')];
            } else {
                $activity['history'][] = $base + [
                    'type' => (string) ($data['type'] ?? 'changed'),
                    'changes' => array_values((array) ($data['changes'] ?? [])),
                ];
            }
        }

        return $activity;
    }

    /** @return array<string, mixed>|WP_Error the comment as of() gives it */
    public function comment(int $taskId, string $text): array|WP_Error
    {
        if ($text === '') {
            return new WP_Error('todo_comment_empty', __('Komentarz nie może być pusty.', 'wp-plugin-todo'), ['status' => 400]);
        }

        $id = $this->add($taskId, ContentTypes::META_COMMENT, ['text' => $text]);

        if (!$id) {
            return new WP_Error('todo_comment_failed', __('Nie udało się dodać komentarza.', 'wp-plugin-todo'), ['status' => 500]);
        }

        foreach ($this->of($taskId)['comments'] as $comment) {
            if ($comment['id'] === $id) {
                return $comment;
            }
        }

        return new WP_Error('todo_comment_failed', __('Nie udało się dodać komentarza.', 'wp-plugin-todo'), ['status' => 500]);
    }

    public function removeComment(int $taskId, int $commentId): true|WP_Error
    {
        $mine = array_filter($this->rows($taskId), static fn (object $row): bool => (int) $row->meta_id === $commentId && $row->meta_key === ContentTypes::META_COMMENT);

        if (!$mine) {
            return new WP_Error('todo_comment_not_found', __('Nie ma takiego komentarza.', 'wp-plugin-todo'), ['status' => 404]);
        }

        return delete_metadata_by_mid('post', $commentId) ? true : new WP_Error('todo_comment_delete_failed', __('Nie udało się usunąć komentarza.', 'wp-plugin-todo'), ['status' => 500]);
    }

    public function created(int $taskId): void
    {
        $this->add($taskId, ContentTypes::META_EVENT, ['type' => 'created']);
    }

    /**
     * One line of history for one save – what changed between the task before and after it; nothing when only its
     * place in the list did.
     *
     * @param array<string, mixed> $before the task as TaskRepository gives it
     * @param array<string, mixed> $after
     */
    public function changed(int $taskId, array $before, array $after): void
    {
        $changes = [];

        foreach (self::TRACKED as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;

            if ($from === $to) {
                continue;
            }

            $changes[] = match ($field) {
                'notes' => ['field' => $field, 'from' => null, 'to' => null],
                'projectId' => ['field' => $field, 'from' => $this->projectName($from), 'to' => $this->projectName($to)],
                default => ['field' => $field, 'from' => $from, 'to' => $to],
            };
        }

        if ($changes) {
            $this->add($taskId, ContentTypes::META_EVENT, ['type' => 'changed', 'changes' => $changes]);
        }
    }

    /** @param array<string, mixed> $data */
    private function add(int $taskId, string $key, array $data): int
    {
        $row = ['at' => gmdate(DATE_ATOM), 'user' => get_current_user_id()] + $data;

        return (int) add_post_meta($taskId, $key, wp_slash((string) wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    /** @return array<int, object{meta_id: string, meta_key: string, meta_value: string}> oldest first */
    private function rows(int $taskId): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s) ORDER BY meta_id",
            $taskId,
            ContentTypes::META_COMMENT,
            ContentTypes::META_EVENT,
        ));
    }

    private function projectName(mixed $id): ?string
    {
        $term = $id ? get_term((int) $id, ContentTypes::PROJECT) : null;

        return $term instanceof WP_Term ? html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') : null;
    }

    private function author(int $userId): ?string
    {
        static $names = [];

        if (!$userId) {
            return null;
        }

        $names[$userId] ??= ($user = get_userdata($userId)) ? $user->display_name : null;

        return $names[$userId];
    }
}
