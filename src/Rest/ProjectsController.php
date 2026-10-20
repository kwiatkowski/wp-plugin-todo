<?php

declare(strict_types=1);

namespace MKTodo\Rest;

use MKTodo\Domain\ProjectCopier;
use MKTodo\Domain\ProjectRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET    /todo/v1/projects
 * POST   /todo/v1/projects        { name, color?, areaId? }
 * PATCH  /todo/v1/projects/{id}   { name?, color?, areaId? } – null `areaId` clears the area
 * DELETE /todo/v1/projects/{id}   – tasks move to the inbox
 * POST   /todo/v1/projects/{id}/copy { name?, tasks?: bool } – a copy below the original, same area and colour,
 *                                      named `name` or "Name (kopia)" (and its open tasks); returns the new project
 * POST   /todo/v1/projects/order  { ids: [5, 2, 9] } – these projects in this order (one area); returns all projects
 */
final class ProjectsController extends Controller
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectCopier $copier,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/projects', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn () => $this->respond($this->projects->all()),
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'permission'],
                'args' => $this->args(true),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/projects/order', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond($this->projects->reorder($request['ids'])),
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'ids' => $this->arg(['type' => 'array', 'required' => true, 'minItems' => 1, 'items' => ['type' => 'integer', 'minimum' => 1]]),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/projects/(?P<id>\d+)/copy', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond($this->copier->copy((int) $request['id'], (bool) $request['tasks'], $request['name'] ?: null), 201),
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'tasks' => $this->arg(['type' => 'boolean', 'default' => false]),
                'name' => $this->arg(['type' => 'string', 'maxLength' => 100], static fn ($value) => sanitize_text_field($value)),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/projects/(?P<id>\d+)', [
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
        return $this->respond($this->projects->create($request['name'], $request['color'] ?? null, $request['areaId'] ?? null), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond($this->projects->update((int) $request['id'], $this->sent($request, ['name', 'color', 'areaId'])));
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->projects->delete((int) $request['id']);

        return $this->respond($result === true ? ['deleted' => true] : $result);
    }

    private function args(bool $creating): array
    {
        return [
            'name' => $this->arg(['type' => 'string', 'required' => $creating, 'minLength' => 1, 'maxLength' => 100], static fn ($value) => sanitize_text_field($value)),
            'color' => $this->arg(['type' => 'string', 'format' => 'hex-color']),
            'areaId' => $this->arg(['type' => ['integer', 'null'], 'minimum' => 1]),
        ];
    }
}
