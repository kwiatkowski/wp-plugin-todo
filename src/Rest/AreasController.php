<?php

declare(strict_types=1);

namespace MKTodo\Rest;

use MKTodo\Domain\AreaCopier;
use MKTodo\Domain\AreaRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET    /todo/v1/areas        – "Praca" and "Dom" are created on the first call
 * POST   /todo/v1/areas        { name, icon? }
 * PATCH  /todo/v1/areas/{id}   { name?, icon? } – null `icon` clears it
 * DELETE /todo/v1/areas/{id}   – projects stay, without an area
 * POST   /todo/v1/areas/{id}/copy { tasks?: bool } – "Name (kopia)" below the original, with its projects
 *                                   (and their open tasks); returns the new area
 * POST   /todo/v1/areas/order  { ids: [3, 1, 2] } – every area once, in the new order; returns the list
 */
final class AreasController extends Controller
{
    public function __construct(
        private readonly AreaRepository $areas,
        private readonly AreaCopier $copier,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/areas', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn () => $this->respond($this->areas->all()),
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'permission'],
                'args' => $this->args(true),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/areas/order', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond($this->areas->reorder($request['ids'])),
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'ids' => $this->arg(['type' => 'array', 'required' => true, 'items' => ['type' => 'integer', 'minimum' => 1]]),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/areas/(?P<id>\d+)/copy', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond($this->copier->copy((int) $request['id'], (bool) $request['tasks']), 201),
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'tasks' => $this->arg(['type' => 'boolean', 'default' => false]),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/areas/(?P<id>\d+)', [
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
        return $this->respond($this->areas->create($request['name'], $request['icon'] ?? null), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond($this->areas->update((int) $request['id'], $this->sent($request, ['name', 'icon'])));
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->areas->delete((int) $request['id']);

        return $this->respond($result === true ? ['deleted' => true] : $result);
    }

    private function args(bool $creating): array
    {
        return [
            'name' => $this->arg(['type' => 'string', 'required' => $creating, 'minLength' => 1, 'maxLength' => 100], static fn ($value) => sanitize_text_field($value)),
            // a key from the app's icon set, e.g. "briefcase"
            'icon' => $this->arg(['type' => ['string', 'null'], 'pattern' => '^[a-z0-9-]{1,40}$']),
        ];
    }
}
