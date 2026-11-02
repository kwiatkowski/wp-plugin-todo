<?php

declare(strict_types=1);

namespace MKTodo\Rest;

use MKTodo\Domain\TaskSummary;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET /todo/v1/summary – today's tasks for outside the app (the Home Assistant card), read only.
 * Its own token instead of a login: `X-Todo-Token: <token>` (or `Authorization: Bearer <token>`); only its SHA-256 is
 * kept (option wp_plugin_todo_summary_token). A new token: `wp todo summary-token` – the old one stops working.
 */
final class SummaryController extends Controller
{
    public const OPTION = 'wp_plugin_todo_summary_token';

    public function __construct(private readonly TaskSummary $summary)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/summary', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn () => $this->respond($this->summary->today()),
            'permission_callback' => [$this, 'tokenPermission'],
        ]);
    }

    public function tokenPermission(WP_REST_Request $request): true|WP_Error
    {
        $hash = (string) get_option(self::OPTION, '');
        $token = (string) ($request->get_header('x_todo_token') ?: preg_replace('/^Bearer\s+/i', '', (string) $request->get_header('authorization')));

        if ($hash !== '' && $token !== '' && hash_equals($hash, hash('sha256', $token))) {
            return true;
        }

        return new WP_Error('todo_summary_forbidden', __('Nieprawidłowy token.', 'wp-plugin-todo'), ['status' => 401]);
    }

    /** a new random token; only its hash is kept – shown once */
    public static function newToken(): string
    {
        $token = bin2hex(random_bytes(24));
        update_option(self::OPTION, hash('sha256', $token), false);

        return $token;
    }
}
