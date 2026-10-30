<?php
/*
 * Plugin Name: Todo
 * Description: Zadania z podziałem na projekty – REST API (todo/v1) i powłoka aplikacji Vue pod /todo/.
 * Author: Marcin Kwiatkowski
 * Author URI: https://kwiatkowski.co
 * Text Domain: wp-plugin-todo
 * Version: 2026.10.30.1
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WP_PLUGIN_TODO_VERSION', '2026.10.30.1');
define('WP_PLUGIN_TODO_FILE', __FILE__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MKTodo\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

add_action('plugins_loaded', [MKTodo\Plugin::class, 'boot']);
