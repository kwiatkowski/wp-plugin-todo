<?php

declare(strict_types=1);

namespace MKTodo\App;

use MKTodo\Cron\DoneCleanup;
use MKTodo\Plugin;
use WP;

/**
 * Serves the Vue app under /todo/ (and every deeper path – the app has its own router) – or, with its own host
 * (TODO_APP_HOST, e.g. todo.kwiatkowski.co: a subdomain pointing at the same WordPress), at that host's root;
 * the old /todo/ addresses then lead there (301). The login cookies must then be the whole domain's (COOKIE_DOMAIN).
 *
 * The built index.html is read from disk and gets the runtime config injected (REST URL, nonce, user),
 * so the API works with the normal WordPress login cookie and no secret ever lands in the JS bundle.
 * Logged-out visitors are sent to the login page and come back here afterwards.
 */
final class AppShell
{
    public function register(): void
    {
        add_action('parse_request', [$this, 'maybeServe'], 0);
        add_action('admin_bar_menu', [$this, 'adminBar'], 80);

        if (self::host() !== null) {
            // the login and logout on the site come back to the app's host
            add_filter('allowed_redirect_hosts', static fn (array $hosts): array => [...$hosts, (string) self::host()]);
        }
    }

    /** the app's own host – TODO_APP_HOST (a constant, or the environment); null: under /todo/ of the site */
    public static function host(): ?string
    {
        $host = defined('TODO_APP_HOST') ? (string) constant('TODO_APP_HOST') : (string) ($_ENV['TODO_APP_HOST'] ?? $_SERVER['TODO_APP_HOST'] ?? getenv('TODO_APP_HOST') ?: '');
        $host = strtolower(trim($host));

        return $host !== '' ? $host : null;
    }

    /** whether this request came to the app's own host */
    private static function onHost(): bool
    {
        return self::host() !== null && strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) === self::host();
    }

    /** a URL of the site on the app's own host – when the page is shown there (the REST API and the nonce, same origin) */
    private static function local(string $url): string
    {
        return self::onHost() ? (string) preg_replace('#^https?://[^/]+#i', 'https://' . self::host(), $url) : $url;
    }

    public static function path(): string
    {
        return trim((string) apply_filters('wp_plugin_todo/path', 'todo'), '/');
    }

    public static function url(): string
    {
        return self::host() !== null ? 'https://' . self::host() . '/' : home_url('/' . self::path() . '/');
    }

    public function maybeServe(WP $wp): void
    {
        $path = self::path();

        // the app's own host: the app at every path but the REST API's
        if (self::onHost()) {
            if (isset($wp->query_vars['rest_route']) || str_starts_with($wp->request, 'wp-json')) {
                return;
            }
        } elseif ($wp->request !== $path && !str_starts_with($wp->request, $path . '/')) {
            return;
        } elseif (self::host() !== null) {
            // the old address – to the same place on the app's host
            $rest = ltrim(substr($wp->request, strlen($path)), '/');
            $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
            wp_redirect(self::url() . $rest . ($query !== '' ? '?' . $query : ''), 301);
            exit;
        }

        if (!is_user_logged_in()) {
            auth_redirect();
        }

        if (!Plugin::canUse()) {
            wp_die(esc_html__('Brak dostępu do aplikacji Todo.', 'wp-plugin-todo'), '', ['response' => 403]);
        }

        $index = (string) apply_filters('wp_plugin_todo/app_index', dirname(WP_CONTENT_DIR) . '/' . $path . '/web/index.html');

        if (!is_readable($index)) {
            wp_die(esc_html__('Aplikacja nie jest zbudowana – uruchom „npm run build” w jej katalogu.', 'wp-plugin-todo'), '', ['response' => 500]);
        }

        $html = (string) file_get_contents($index);
        $config = '<script>window.__TODO__ = ' . wp_json_encode($this->config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';

        // something printed before us (e.g. a plugin's PHP notice in development) – too late for headers,
        // but the app itself still works, so don't add warnings of our own on top
        if (!headers_sent()) {
            nocache_headers();
            status_header(200);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: same-origin');
        }

        echo preg_replace('#</head>#i', $config . "\n</head>", $html, 1);
        exit;
    }

    public function adminBar(\WP_Admin_Bar $bar): void
    {
        if (!Plugin::canUse()) {
            return;
        }

        $bar->add_node([
            'id' => 'wp-plugin-todo',
            'title' => '<span class="ab-icon dashicons dashicons-yes-alt" style="top:2px"></span>' . esc_html__('Todo', 'wp-plugin-todo'),
            'href' => self::url(),
        ]);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $user = wp_get_current_user();

        return [
            'restUrl' => esc_url_raw(self::local(rest_url('todo/v1/'))),
            'nonce' => wp_create_nonce('wp_rest'),
            // core endpoint that hands out a fresh REST nonce – used when the tab stays open past the nonce lifetime
            'nonceUrl' => self::local(admin_url('admin-ajax.php?action=rest-nonce')),
            'basePath' => (string) wp_parse_url(self::url(), PHP_URL_PATH),
            'user' => [
                'name' => $user->display_name,
                'avatar' => get_avatar_url($user->ID, ['size' => 64]),
            ],
            'logoutUrl' => html_entity_decode(wp_logout_url(self::host() !== null ? self::url() : home_url('/')), ENT_QUOTES, 'UTF-8'),
            'adminUrl' => admin_url(),
            // the list of projects, when its plugin is here – the account menu leads there (one family of apps)
            'projectsUrl' => class_exists(\MKProjects\App\AppShell::class) ? \MKProjects\App\AppShell::url() : null,
            'version' => WP_PLUGIN_TODO_VERSION,
            // finished tasks are trashed this many days after (0 = kept)
            'doneRetentionDays' => DoneCleanup::retentionDays(),
        ];
    }
}
