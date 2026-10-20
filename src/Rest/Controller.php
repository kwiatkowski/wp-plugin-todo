<?php

declare(strict_types=1);

namespace MKTodo\Rest;

use MKTodo\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

abstract class Controller
{
    public const NAMESPACE = 'todo/v1';

    abstract public function registerRoutes(): void;

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function permission(): true|WP_Error
    {
        if (Plugin::canUse()) {
            return true;
        }

        return new WP_Error('todo_forbidden', __('Brak dostępu do aplikacji Todo.', 'wp-plugin-todo'), ['status' => rest_authorization_required_code()]);
    }

    /** Only the fields actually sent – lets PATCH change a single field and clear others with null. */
    protected function sent(WP_REST_Request $request, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            if ($request->has_param($field)) {
                $data[$field] = $request->get_param($field);
            }
        }

        return $data;
    }

    protected function respond(mixed $result, int $status = 200): WP_REST_Response|WP_Error
    {
        return is_wp_error($result) ? $result : new WP_REST_Response($result, $status);
    }

    /** Validation from the schema plus a sanitizer for strings. */
    protected function arg(array $schema, ?callable $sanitize = null): array
    {
        return $schema + [
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => $sanitize ?? 'rest_sanitize_request_arg',
        ];
    }
}
