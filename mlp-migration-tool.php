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

function mlp_log_action( $message, $type = 'info' ) {
    if ( ! isset( $GLOBALS['mlp_migration_logs'] ) || ! is_array( $GLOBALS['mlp_migration_logs'] ) ) {
        $GLOBALS['mlp_migration_logs'] = [];
    }

    $GLOBALS['mlp_migration_logs'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function mlp_render_admin_page() {
    if ( isset( $_POST['mlp_migration_poc'] ) ) {
        check_admin_referer( 'mlp_migration_poc_action', 'mlp_migration_poc_nonce' );
        do_action( 'mlp_run_poc_action' );
    }

    ?>
    <div class="wrap">
        <h1>MLP Migration Tool (PoC)</h1>
        <p>This will create subsites for Polylang or WPML languages, migrate content, and link them with MLP.</p>

        <?php
        if ( ! empty( $GLOBALS['mlp_migration_logs'] ) && is_array( $GLOBALS['mlp_migration_logs'] ) ) {
            foreach ( $GLOBALS['mlp_migration_logs'] as $log ) {
                $type  = isset( $log['type'] ) ? $log['type'] : 'info';
                $class = 'notice notice-' . $type;
                echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $log['message'] ) . '</p></div>';
            }
        }
        ?>

        <form method="post">
            <?php wp_nonce_field( 'mlp_migration_poc_action', 'mlp_migration_poc_nonce' ); ?>
            <?php submit_button( 'Run PoC Migration', 'primary', 'mlp_migration_poc' ); ?>
        </form>
    </div>
    <?php
}

// Register the migration UI as a top-level page in the Network Admin.
add_action( 'network_admin_menu', function () {
    if ( ! is_multisite() ) {
        return;
    }

    add_menu_page(
        'MLP Migration Tool (PoC)', // Page title.
        'MLP Migration',            // Menu title.
        'manage_network_options',   // Capability.
        'mlp-migration',            // Menu slug (?page=mlp-migration).
        'mlp_render_admin_page',    // Callback.
        'dashicons-migrate',
        80
    );
} );

add_action( 'mlp_run_poc_action', function () {

    // Detect source plugin: Polylang or WPML.
    $source = null;

    $has_polylang = function_exists( 'pll_the_languages' );
    $has_wpml     = defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_object_id' );

    if ( $has_polylang && $has_wpml ) {
        mlp_log_action( '❌ Both Polylang and WPML detected. Please deactivate one source plugin before running the migration.', 'error' );
        return;
    }

    if ( $has_polylang ) {
        $source = 'polylang';
    } elseif ( $has_wpml ) {
        $source = 'wpml';
    }

    if ( ! $source ) {
        mlp_log_action( '❌ Neither Polylang nor WPML detected.', 'error' );
        return;
    }

    $pll_languages  = [];
    $wpml_languages = [];
    $slugs          = [];
    $default_lang   = '';

    if ( $source === 'polylang' ) {
        $pll_languages = pll_the_languages( [ 'raw' => 1 ] );
        if ( empty( $pll_languages ) ) {
            mlp_log_action( '⚠️ Polylang detected but no languages found.', 'warning' );
            return;
        }
        $slugs        = array_keys( $pll_languages );
        $default_lang = function_exists( 'pll_default_language' ) ? pll_default_language() : '';

        mlp_log_action( '✅ Found Polylang languages: ' . implode( ', ', $slugs ), 'success' );
    } else {
        // WPML.
        $wpml_languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
        if ( empty( $wpml_languages ) || ! is_array( $wpml_languages ) ) {
            mlp_log_action( '⚠️ WPML detected but no languages found.', 'warning' );
            return;
        }
        $slugs        = array_keys( $wpml_languages );
        $default_lang = apply_filters( 'wpml_default_language', null );

        mlp_log_action( '✅ Found WPML languages: ' . implode( ', ', $slugs ), 'success' );
    }

    try {
        $siteRelations    = resolve( SiteRelations::class );
        $contentRelations = resolve( ContentRelations::class );
        mlp_log_action( '✅ Loaded MLP services.', 'success' );
    } catch ( Exception $e ) {
        mlp_log_action( '❌ Could not load MLP services: ' . $e->getMessage(), 'error' );
        return;
    }

    $site_map  = []; // lang => site_id
    $new_posts = []; // [ site_id ][original_post_id ] = new_post_id

    foreach ( $slugs as $lang ) {
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

        if ( is_wp_error( $site_id ) ) {
            mlp_log_action(
                "❌ Failed to create site for {$lang}: " . $site_id->get_error_message(),
                'error'
            );
            continue; // skip this language
        }

        // Remove default content (Hello World, Sample Page, etc.) from the new site.
        switch_to_blog( $site_id );
        $default_items = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'any',
            'posts_per_page' => -1,
        ] );

        foreach ( $default_items as $default_item ) {
            wp_delete_post( $default_item->ID, true );
        }
        restore_current_blog();

        try {
            $siteSettingsRepository = resolve( \Inpsyde\MultilingualPress\Core\Admin\SiteSettingsRepository::class );

            // Use the source plugin's locale to set both the WordPress and MLP language.
            // This matches how MultilingualPress initializes site languages.
            $locale = '';

            if ( $source === 'polylang' && isset( $pll_languages[ $lang ]['locale'] ) ) {
                $locale = $pll_languages[ $lang ]['locale']; // e.g. en_US
            } elseif ( $source === 'wpml' && isset( $wpml_languages[ $lang ]['default_locale'] ) ) {
                $locale = $wpml_languages[ $lang ]['default_locale']; // e.g. en_US
            }

            if ( $locale ) {
                // Set the WordPress site language (WPLANG) for this site.
                update_blog_option( $site_id, 'WPLANG', $locale );

                // MLP expects a BCP-47 tag (e.g. en-US) in its site settings.
                $mlp_lang = str_replace( '_', '-', $locale );
            } else {
                // Fallback: treat language slug as BCP-47-ish tag.
                $mlp_lang = $lang;
            }

            $siteSettingsRepository->updateLanguage( $mlp_lang, $site_id );

            mlp_log_action(
                "🌐 Assigned MLP language '{$mlp_lang}' to site {$site_id}",
                'success'
            );
        } catch ( Exception $e ) {
            mlp_log_action(
                "❌ Failed to set language for site {$site_id}: " . $e->getMessage(),
                'error'
            );
        }

        $site_map[ $lang ] = $site_id;
        mlp_log_action(
            "✅ Created site for {$lang} (ID: {$site_id})",
            'success'
        );

       /* try {
            $siteRelations->insertRelations( get_main_site_id(), [ $site_id ] );
            mlp_log_action( "🔗 Related main site with {$site_id}", 'info' );
        } catch ( Exception $e ) {
            mlp_log_action( "❌ Failed to relate site: " . $e->getMessage(), 'error' );
        } */
    }

     // After all sites are created, relate each site to all others.
    $all_site_ids = array_values( $site_map );

    foreach ( $all_site_ids as $site_id ) {
        // All other sites except the current one.
        $related_site_ids = array_filter(
            $all_site_ids,
            function ( $id ) use ( $site_id ) {
                return (int) $id !== (int) $site_id;
            }
        );

        if ( ! $related_site_ids ) {
            continue;
        }

        try {
            $siteRelations->insertRelations( $site_id, $related_site_ids );
            mlp_log_action(
                '🔗 Related site ' . $site_id . ' with: ' . implode( ', ', $related_site_ids ),
                'info'
            );
        } catch ( Exception $e ) {
            mlp_log_action(
                '❌ Failed to relate site ' . $site_id . ': ' . $e->getMessage(),
                'error'
            );
        }
    }

    // AFter all sites are created, set up site relations
    $main_site_id = get_main_site_id();
    $all_site_ids = array_values( $site_map );

    // Related sites = all except the main site
    $related_site_ids = array_filter(
        $all_site_ids,
        function ($id) use ($main_site_id) {
            return (int) $id !== $main_site_id;
        }
    );

    if (count($related_site_ids) > 0) {
        try {
            $siteRelations->insertRelations( $main_site_id, $related_site_ids );
            mlp_log_action( "🔗 Related main site with sites: " . implode( ', ', $related_site_ids ), 'info' );
        } catch ( Exception $e ) {
            mlp_log_action( "❌ Failed to relate sites: " . $e->getMessage(), 'error' );
        }
    }

    // WPML: use translation groups from icl_translations and exit early.
    if ( $source === 'wpml' ) {
        global $wpdb;

        $post_types = [ 'post', 'page' ];
        $groups     = [];
        $seen_ids   = [];
        $table      = $wpdb->prefix . 'icl_translations';

        $element_types = [];
        foreach ( $post_types as $post_type ) {
            $element_types[] = 'post_' . $post_type;
        }

        $in_element_types = "'" . implode( "', '", array_map( 'esc_sql', $element_types ) ) . "'";

        $rows = $wpdb->get_results( "
            SELECT trid, element_id, language_code, element_type
            FROM {$table}
            WHERE element_type IN ( {$in_element_types} )
        " );

        if ( $rows ) {
            foreach ( $rows as $row ) {
                $trid         = (int) $row->trid;
                $post_id      = (int) $row->element_id;
                $lang_code    = (string) $row->language_code;
                $element_type = (string) $row->element_type;

                if ( ! $trid || ! $post_id || ! $lang_code ) {
                    continue;
                }
                if ( strpos( $element_type, 'post_' ) !== 0 ) {
                    continue;
                }

                $post_type = substr( $element_type, 5 ); // Strip "post_".

                if ( ! isset( $groups[ $post_type ] ) ) {
                    $groups[ $post_type ] = [];
                }
                if ( ! isset( $seen_ids[ $post_type ] ) ) {
                    $seen_ids[ $post_type ] = [];
                }

                $seen_ids[ $post_type ][ $post_id ] = true;
                if ( ! isset( $groups[ $post_type ][ $trid ] ) ) {
                    $groups[ $post_type ][ $trid ] = [];
                }

                // One post per language per TRID; last one wins for duplicates.
                $groups[ $post_type ][ $trid ][ $lang_code ] = $post_id;
            }
        }

        // Add default-language-only posts that are not in any WPML translation group.
        foreach ( $post_types as $post_type ) {
            $seen_for_type = isset( $seen_ids[ $post_type ] ) ? array_keys( $seen_ids[ $post_type ] ) : [];
            $seen_for_type = array_map( 'absint', $seen_for_type );

            $placeholders = '';
            if ( $seen_for_type ) {
                $placeholders = implode( ',', $seen_for_type );
            }

            $post_query = "
                SELECT ID
                FROM {$wpdb->posts}
                WHERE post_type = %s
                  AND post_status = 'publish'
            ";

            if ( $placeholders ) {
                $post_query .= " AND ID NOT IN ( {$placeholders} )";
            }

            $extra_posts = $wpdb->get_col( $wpdb->prepare( $post_query, $post_type ) );

            if ( $extra_posts ) {
                foreach ( $extra_posts as $post_id ) {
                    $post_id = (int) $post_id;

                    if ( ! isset( $groups[ $post_type ] ) ) {
                        $groups[ $post_type ] = [];
                    }

                    // Treat as default-language content with no translations.
                    $synthetic_trid = -1 * $post_id; // Unique negative ID per post.
                    $groups[ $post_type ][ $synthetic_trid ] = [
                        $default_lang => $post_id,
                    ];
                }
            }
        }

        foreach ( $post_types as $post_type ) {
            if ( empty( $groups[ $post_type ] ) ) {
                continue;
            }

            foreach ( $groups[ $post_type ] as $trid => $translations ) {
                $relation_map = [];

                if ( count( $translations ) > 1 ) {
                    // Real translation set: one post per language.
                    foreach ( $translations as $lang_code => $post_id ) {
                        if ( ! isset( $site_map[ $lang_code ] ) ) {
                            continue;
                        }

                        $site_id = $site_map[ $lang_code ];

                        // Main site keeps the original post.
                        if ( $site_id === get_main_site_id() ) {
                            $relation_map[ $site_id ] = $post_id;
                            continue;
                        }

                        // Copy the language-specific post to its language site.
                        switch_to_blog( get_main_site_id() );
                        $source_post = get_post( $post_id );
                        restore_current_blog();

                        if ( ! $source_post ) {
                            continue;
                        }

                        switch_to_blog( $site_id );
                        $new_id = wp_insert_post( [
                            'post_title'   => $source_post->post_title,
                            'post_content' => $source_post->post_content,
                            'post_status'  => $source_post->post_status,
                            'post_type'    => $source_post->post_type,
                        ] );
                        restore_current_blog();

                        if ( ! $new_id ) {
                            continue;
                        }

                        $relation_map[ $site_id ] = $new_id;

                        mlp_log_action(
                            "➡️ Copied {$post_type} '{$source_post->post_title}' ({$lang_code}) to site {$site_id} (New ID: {$new_id})",
                            'success'
                        );
                    }
                } else {
                    // Single-language content: duplicate to all language sites, but DO NOT link them in MLP.
                    $post_id = reset( $translations );

                    switch_to_blog( get_main_site_id() );
                    $source_post = get_post( $post_id );
                    restore_current_blog();

                    if ( ! $source_post ) {
                        continue;
                    }

                    foreach ( $slugs as $lang_code ) {
                        if ( ! isset( $site_map[ $lang_code ] ) ) {
                            continue;
                        }

                        $site_id = $site_map[ $lang_code ];

                        if ( $site_id === get_main_site_id() ) {
                            // Keep original on main site; no relation is created for this group.
                            continue;
                        }

                        switch_to_blog( $site_id );
                        $new_id = wp_insert_post( [
                            'post_title'   => $source_post->post_title,
                            'post_content' => $source_post->post_content,
                            'post_status'  => $source_post->post_status,
                            'post_type'    => $source_post->post_type,
                        ] );
                        restore_current_blog();

                        if ( ! $new_id ) {
                            continue;
                        }

                        mlp_log_action(
                            "➡️ Duplicated single-language {$post_type} '{$source_post->post_title}' to site {$site_id} (New ID: {$new_id})",
                            'success'
                        );
                    }
                }

                $content_type = ( $post_type === 'page' ) ? 'post' : $post_type;

                if ( count( $relation_map ) > 1 ) {
                    try {
                        $relationId = $contentRelations->createRelationship( $relation_map, $content_type );
                        mlp_log_action(
                            "🔗 Linked {$post_type} (TRID {$trid}) across sites (Relation ID: {$relationId})",
                            'info'
                        );
                    } catch ( Exception $e ) {
                        mlp_log_action(
                            "❌ Failed to link TRID {$trid}: " . $e->getMessage(),
                            'error'
                        );
                    }
                }
            }
        }

        return;
    }

    // Polylang: migrate posts/pages per language.
    // Behaviour mirrors the WPML branch:
    // - Posts with translations are copied only to their language site and linked via MLP.
    // - Single-language posts (no translations) are duplicated to all language sites, but NOT linked.
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

            if ( ! $items || ! isset( $site_map[ $lang ] ) ) {
                continue;
            }

            foreach ( $items as $item ) {
                // Determine Polylang translations on the source (main) site.
                $translations = function_exists( 'pll_get_post_translations' )
                    ? (array) pll_get_post_translations( $item->ID )
                    : [];

                // Single-language content: duplicate to all language sites, but DO NOT link in MLP.
                if ( count( $translations ) <= 1 ) {
                    foreach ( $slugs as $dup_lang ) {
                        if ( ! isset( $site_map[ $dup_lang ] ) ) {
                            continue;
                        }

                        $dup_site_id = $site_map[ $dup_lang ];

                        // Keep the original on the main site only.
                        if ( $dup_site_id === get_main_site_id() ) {
                            continue;
                        }

                        switch_to_blog( $dup_site_id );
                        add_filter( 'pll_get_the_post_types', '__return_empty_array' );
                        $dup_id = wp_insert_post( [
                            'post_title'   => $item->post_title,
                            'post_content' => $item->post_content,
                            'post_status'  => $item->post_status,
                            'post_type'    => $item->post_type,
                        ] );
                        remove_filter( 'pll_get_the_post_types', '__return_empty_array' );
                        restore_current_blog();

                        if ( $dup_id ) {
                            $new_posts[ $dup_site_id ][ $item->ID ] = $dup_id;

                            mlp_log_action(
                                "➡️ Duplicated single-language {$post_type} '{$item->post_title}' to site {$dup_site_id} (New ID: {$dup_id})",
                                'success'
                            );
                        }
                    }

                    // Nothing more to do for this single-language post.
                    continue;
                }

                $target_site = $site_map[ $lang ];
                if ( $target_site === get_main_site_id() ) {
                    // Default language content is already on the main site; translations
                    // will be copied from their respective language loops.
                    continue;
                }

                switch_to_blog( $target_site );
                add_filter( 'pll_get_the_post_types', '__return_empty_array' );
                $new_id = wp_insert_post( [
                    'post_title'   => $item->post_title,
                    'post_content' => $item->post_content,
                    'post_status'  => $item->post_status,
                    'post_type'    => $item->post_type,
                ] );
                remove_filter( 'pll_get_the_post_types', '__return_empty_array' );
                restore_current_blog();

                if ( ! $new_id ) {
                    continue;
                }

                // Remember which new post ID belongs to which site + original post.
                $new_posts[ $target_site ][ $item->ID ] = $new_id;

                mlp_log_action(
                    "➡️ Copied {$post_type} '{$item->post_title}' to site {$target_site} (New ID: {$new_id})",
                    'success'
                );

                // Use Polylang to fetch translations of this post (all on the main site).
                $relation_map = [];

                foreach ( $translations as $t_lang => $t_post_id ) {
                    if ( ! isset( $site_map[ $t_lang ] ) ) {
                        continue;
                    }

                    $t_site_id = $site_map[ $t_lang ];

                    // If we have already copied this translated post to its site, use that ID.
                    if ( isset( $new_posts[ $t_site_id ][ $t_post_id ] ) ) {
                        $relation_map[ $t_site_id ] = $new_posts[ $t_site_id ][ $t_post_id ];
                        continue;
                    }

                    // If this is the main site, use the original Polylang post ID as fallback.
                    if ( $t_site_id === get_main_site_id() ) {
                        $relation_map[ $t_site_id ] = $t_post_id;
                    }
                }

                // Normalize content type: treat pages as 'post' if needed.
                $content_type = ( $post_type === 'page' ) ? 'post' : $post_type;

                if ( count( $relation_map ) > 1 ) {
                    try {
                        $relationId = $contentRelations->createRelationship( $relation_map, $content_type );
                        mlp_log_action(
                            "🔗 Linked {$post_type} '{$item->post_title}' across sites (Relation ID: {$relationId})",
                            'info'
                        );
                    } catch ( Exception $e ) {
                        mlp_log_action(
                            "❌ Failed to link '{$item->post_title}': " . $e->getMessage(),
                            'error'
                        );
                    }
                }
            }
        }
    }
});
