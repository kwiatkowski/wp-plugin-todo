<?php

declare(strict_types=1);

namespace MKTodo\Rest;

use MKTodo\Domain\Priority;
use MKTodo\Domain\Status;
use MKTodo\Domain\TaskRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET    /todo/v1/tasks
 * POST   /todo/v1/tasks        { title, notes?, done?, status?, start?, due?, time?, duration?, priority?, projectId?, position? }
 * PATCH  /todo/v1/tasks/{id}   any of the above; null clears `start` / `due` / `time` / `duration` / `projectId` / `position`,
 *                              clearing `due` clears `time` and `duration` too; `status` wins over `done`
 * DELETE /todo/v1/tasks/{id}   – to the trash
 */
final class TasksController extends Controller
{
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/tasks', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn () => $this->respond($this->tasks->all()),
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'permission'],
                'args' => $this->args(true),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/tasks/(?P<id>\d+)', [
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'permission'],
                'args' => $this->args(false),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'permission'],
            ],
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond($this->tasks->create($this->sent($request, TaskRepository::FIELDS)), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond($this->tasks->update((int) $request['id'], $this->sent($request, TaskRepository::FIELDS)));
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->tasks->delete((int) $request['id']);

        return $this->respond($result === true ? ['deleted' => true] : $result);
    }

    /**
     * Text is stored as typed (a title like "Fix <header>" must survive) – it is never rendered as HTML:
     * the app escapes it, wp-admin escapes post titles. Only invalid UTF-8 and control characters go.
     */
    public static function plainText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", wp_check_invalid_utf8($value, true));

        return trim((string) preg_replace('/[^\P{C}\n\t]/u', '', $value));
    }

    public static function plainLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::plainText($value)));
    }

    private function args(bool $creating): array
    {
        return [
            'title' => $this->arg(['type' => 'string', 'required' => $creating, 'minLength' => 1, 'maxLength' => 200], [self::class, 'plainLine']),
            'notes' => $this->arg(['type' => 'string', 'maxLength' => 10000], [self::class, 'plainText']),
            'done' => $this->arg(['type' => 'boolean']),
            'status' => $this->arg(['type' => 'string', 'enum' => Status::values()]),
            'due' => $this->arg(['type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$']),
            // first day of a task spanning several days, up to `due`
            'start' => $this->arg(['type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$']),
            'time' => $this->arg(['type' => ['string', 'null'], 'pattern' => '^([01]\d|2[0-3]):[0-5]\d$']),
            'duration' => $this->arg(['type' => ['integer', 'null'], 'minimum' => 5, 'maximum' => 1440]),
            'position' => $this->arg(['type' => ['number', 'null']]),
            'priority' => $this->arg(['type' => 'string', 'enum' => Priority::values()]),
            'projectId' => $this->arg(['type' => ['integer', 'null'], 'minimum' => 1]),
        ];
    }
}
