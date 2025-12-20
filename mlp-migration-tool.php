<?php
/**
 * Plugin Name: MLP Migration Tool (PoC)
 * Description: Proof of concept for migrating Polylang or WPML sites to Multisite + MLP.
 * Version: 0.5.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Inpsyde\MultilingualPress\Framework\Api\SiteRelations;
use Inpsyde\MultilingualPress\Framework\Api\ContentRelations;
use function Inpsyde\MultilingualPress\resolve;

add_action( 'admin_menu', function () {
    add_menu_page(
        'MLP Migration Tool',
        'MLP Migration',
        'manage_options',
        'mlp-migration',
        'mlp_render_admin_page',
        'dashicons-migrate',
        80
    );
});

function mlp_log_action( $message, $type = 'info' ) {
    $class = 'notice notice-' . $type;
    echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
}

function mlp_render_admin_page() {
    if ( isset( $_POST['mlp_migration_poc'] ) ) {
        check_admin_referer( 'mlp_migration_poc_action', 'mlp_migration_poc_nonce' );
        do_action( 'mlp_run_poc_action' );
    }

    ?>
    <div class="wrap">
        <h1>MLP Migration Tool (PoC)</h1>
        <p>This will create subsites for Polylang languages, migrate content, and link them with MLP.</p>
        <form method="post">
            <?php wp_nonce_field( 'mlp_migration_poc_action', 'mlp_migration_poc_nonce' ); ?>
            <?php submit_button( 'Run PoC Migration', 'primary', 'mlp_migration_poc' ); ?>
        </form>
    </div>
    <?php
}

add_action( 'mlp_run_poc_action', function () {

    if ( ! function_exists( 'pll_the_languages' ) ) {
        mlp_log_action( '❌ Polylang not detected.', 'error' );
        return;
    }

    $languages = pll_the_languages( [ 'raw' => 1 ] );
    if ( empty( $languages ) ) {
        mlp_log_action( '⚠️ Polylang detected but no languages found.', 'warning' );
        return;
    }

    $slugs = array_keys( $languages );
    mlp_log_action( '✅ Found Polylang languages: ' . implode( ', ', $slugs ), 'success' );

    try {
        $siteRelations    = resolve( SiteRelations::class );
        $contentRelations = resolve( ContentRelations::class );
        mlp_log_action( '✅ Loaded MLP services.', 'success' );
    } catch ( Exception $e ) {
        mlp_log_action( '❌ Could not load MLP services: ' . $e->getMessage(), 'error' );
        return;
    }

    $site_map = []; // lang => site_id

    foreach ( $slugs as $lang ) {
        $default_lang = function_exists( 'pll_default_language' ) ? pll_default_language() : '';
        if ( $lang === $default_lang ) {
            $site_map[$lang] = get_main_site_id();
            continue;
        }

        $site_domain = preg_replace( '#^https?://#', '', network_site_url() );
        $path        = "/$lang/";

        $site_id = wpmu_create_blog(
            $site_domain,
            $path,
            strtoupper( $lang ) . ' Site',
            get_current_user_id(),
            [ 'public' => 1 ]
        );

        if ( ! is_wp_error( $site_id ) ) {
            try {
                $siteSettingsRepository = resolve(\Inpsyde\MultilingualPress\Core\Admin\SiteSettingsRepository::class);
                $siteSettingsRepository->updateLanguage($lang, $site_id);
        
                mlp_log_action("🌐 Assigned MLP language '{$lang}' to site {$site_id}", 'success');
            } catch (Exception $e) {
                mlp_log_action("❌ Failed to set language for site {$site_id}: " . $e->getMessage(), 'error');
            }
        }
        

        $site_map[$lang] = $site_id;
        mlp_log_action( "✅ Created site for {$lang} (ID: {$site_id})", 'success' );

        try {
            $siteRelations->insertRelations( get_main_site_id(), [ $site_id ] );
            mlp_log_action( "🔗 Related main site with {$site_id}", 'info' );
        } catch ( Exception $e ) {
            mlp_log_action( "❌ Failed to relate site: " . $e->getMessage(), 'error' );
        }
    }

    // Now migrate posts/pages per language
    $post_types = [ 'post', 'page' ];
    foreach ( $post_types as $post_type ) {
        foreach ( $slugs as $lang ) {
            switch_to_blog( get_main_site_id() );
            $items = get_posts( [
                'numberposts' => -1,
                'post_type'   => $post_type,
                'post_status' => 'publish',
                'lang'        => $lang,
            ] );
            restore_current_blog();

            if ( $items && isset( $site_map[$lang] ) ) {
                foreach ( $items as $item ) {
                    $target_site = $site_map[$lang];
                    if ( $target_site === get_main_site_id() ) {
                        continue; // skip default, it's already there
                    }

                    switch_to_blog( $target_site );
                    add_filter( 'pll_get_the_post_types', '__return_empty_array' );
                    $new_id = wp_insert_post([
                        'post_title'   => $item->post_title,
                        'post_content' => $item->post_content,
                        'post_status'  => $item->post_status,
                        'post_type'    => $item->post_type,
                    ]);
                    remove_filter( 'pll_get_the_post_types', '__return_empty_array' );
                    restore_current_blog();

                    if ( $new_id ) {
                        mlp_log_action( "➡️ Copied {$post_type} '{$item->post_title}' to site {$target_site} (New ID: {$new_id})", 'success' );

                        // Use Polylang to fetch translations of this post
                        $translations = pll_get_post_translations( $item->ID );
                        $relation_map = [];

                        foreach ( $translations as $t_lang => $t_post_id ) {
                            if ( isset( $site_map[$t_lang] ) ) {
                                $relation_map[$site_map[$t_lang]] = ( $t_lang === $lang )
                                    ? $new_id
                                    : $t_post_id;
                            }
                        }

                        if ( count( $relation_map ) > 1 ) {
                            try {
                                $relationId = $contentRelations->createRelationship( $relation_map, $post_type );
                                mlp_log_action( "🔗 Linked {$post_type} '{$item->post_title}' across sites (Relation ID: {$relationId})", 'info' );
                            } catch ( Exception $e ) {
                                mlp_log_action( "❌ Failed to link '{$item->post_title}': " . $e->getMessage(), 'error' );
                            }
                        }
                    }
                }
            }
        }
    }
});
