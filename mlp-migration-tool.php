<?php
/**
 * Plugin Name: MultilingualPress Migration Tool
 * Plugin URI:  https://example.com
 * Description: Migrate site and post relationships from MultilingualPress 2 to MultilingualPress 5 with an admin UI and optional WP-CLI.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * Network:     true
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mlp-migration-tool
 */

if (!defined('ABSPATH')) { exit; }

// Paths
define('MLP_MIGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLP_MIGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));

// Composer autoload (PSR-4: "Migration\\" -> "src/Migration/")
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

// Multisite check
add_action('plugins_loaded', function () {
    if (!is_multisite()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>MLP Migration Tool</strong> requires WordPress Multisite.</p></div>';
        });
    }
});

/**
 * Network Admin menu
 */
add_action('network_admin_menu', function () {
    if (!current_user_can('manage_network')) {
        return;
    }
    add_menu_page(
        __('MLP Migration', 'mlp-migration-tool'),
        __('MLP Migration', 'mlp-migration-tool'),
        'manage_network',
        'mlp-migration',
        function () {
            // Very simple placeholder UI; replace with your Admin\MigrationAdminPage later
            echo '<div class="wrap"><h1>MLP Migration</h1>';
            echo '<p>This is the migration tool. Use Export / Import tabs (coming soon).</p>';
            echo '<div id="mlp-migration-app"></div></div>';
        },
        MLP_MIGRATION_PLUGIN_URL . 'assets/img/icon.png',
        56
    );
});

/**
 * Enqueue assets for the page
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_mlp-migration') {
        return;
    }
    wp_enqueue_style(
        'mlp-migration-admin',
        MLP_MIGRATION_PLUGIN_URL . 'assets/css/admin-migration.css',
        [],
        '1.0.0'
    );
    wp_enqueue_script(
        'mlp-migration-admin',
        MLP_MIGRATION_PLUGIN_URL . 'assets/js/admin-migration.js',
        ['jquery'],
        '1.0.0',
        true
    );
    wp_localize_script('mlp-migration-admin', 'MLPMigration', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('mlp_migration_nonce'),
    ]);
});

/**
 * AJAX endpoints (stubs) — replace with \Migration\Admin\AjaxHandler later
 */
function mlp_migration_check_permission() {
    if (!current_user_can('manage_network')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!check_ajax_referer('mlp_migration_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Bad nonce'], 400);
    }
}

add_action('wp_ajax_mlp_migration_preview', function () {
    mlp_migration_check_permission();
    wp_send_json_success(['ok' => true, 'step' => 'preview', 'message' => 'Preview stub']);
});

add_action('wp_ajax_mlp_migration_run', function () {
    mlp_migration_check_permission();
    wp_send_json_success(['ok' => true, 'step' => 'run', 'message' => 'Run stub']);
});

add_action('wp_ajax_mlp_migration_export', function () {
    mlp_migration_check_permission();
    wp_send_json_success(['ok' => true, 'step' => 'export', 'message' => 'Export stub']);
});

/**
 * (Optional) WP-CLI registration — wire up later to your CLI class
 */
// if (defined('WP_CLI') && WP_CLI) {
//     WP_CLI::add_command('mlp migrate', \Migration\CLI\MigrationCommands::class);
// }
