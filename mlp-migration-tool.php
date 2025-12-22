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

    // Now migrate posts/pages per language
    $post_types = [ 'post', 'page' ];
    foreach ( $post_types as $post_type ) {
        foreach ( $slugs as $lang ) {
            switch_to_blog( get_main_site_id() );

            if ( $source === 'polylang' ) {
                $items = get_posts( [
                    'numberposts' => -1,
                    'post_type'   => $post_type,
                    'post_status' => 'publish',
                    'lang'        => $lang,
                ] );
            } else {
                // WPML: switch language context, query posts, then switch back.
                if ( $lang && $lang !== $default_lang ) {
                    do_action( 'wpml_switch_language', $lang );
                }

                $items = get_posts( [
                    'numberposts' => -1,
                    'post_type'   => $post_type,
                    'post_status' => 'publish',
                ] );

                if ( $default_lang ) {
                    do_action( 'wpml_switch_language', $default_lang );
                }
            }

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
                        // Remember which new post ID belongs to which site + original post.
                        $new_posts[ $target_site ][ $item->ID ] = $new_id;

                        mlp_log_action(
                            "➡️ Copied {$post_type} '{$item->post_title}' to site {$target_site} (New ID: {$new_id})",
                            'success'
                        );

                        // Fetch translations of this post (all on the main site) for the active source.
                        $translations = mlp_get_translations_for_post( $source, $item->ID, $post_type );
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
        }
    }
});

/**
 * Fetch translations for a given post from the active source plugin.
 *
 * @param string $source    'polylang' or 'wpml'.
 * @param int    $post_id   Original post ID on the main site.
 * @param string $post_type Post type (post, page, etc.).
 *
 * @return array<string,int> Map of language code => post ID.
 */
function mlp_get_translations_for_post( $source, $post_id, $post_type ) {
    if ( $source === 'polylang' && function_exists( 'pll_get_post_translations' ) ) {
        $translations = pll_get_post_translations( $post_id );

        return is_array( $translations ) ? $translations : [];
    }

    if ( $source === 'wpml' ) {
        $element_type = 'post_' . $post_type;
        $trid         = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );

        if ( ! $trid ) {
            return [];
        }

        $wpml_translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
        if ( ! is_array( $wpml_translations ) ) {
            return [];
        }

        $translations = [];
        foreach ( $wpml_translations as $lang_code => $t ) {
            if ( ! empty( $t->element_id ) ) {
                $translations[ $lang_code ] = (int) $t->element_id;
            }
        }

        return $translations;
    }

    return [];
}
