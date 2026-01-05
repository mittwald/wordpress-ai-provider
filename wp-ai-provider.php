<?php

/**
 * Plugin Name: mittwald AI Provider
 * Plugin URI: https://github.com/mittwald/wordpress-ai-provider
 * Description: Adds mittwald AI hosting to the available AI providers
 * Version: 1.0
 * Author: Lukas Fritze
 * Author URI: https://www.mittwald.de/blog/autoren/lukas-fritze
 * License: GPL-2.0-or-later
 * Requires Plugins: ai/ai.php
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check for dependencies on activation and during admin init.
 */
function wp_ai_provider_check_dependencies() {
    $dependency = 'ai/ai.php';

    if ( ! is_plugin_active( $dependency ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );

        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }

        add_action( 'admin_notices', 'wp_ai_provider_dependency_notice' );
    }
}
add_action( 'admin_init', 'wp_ai_provider_check_dependencies' );

/**
 * Display an admin notice if the dependency is missing.
 */
function wp_ai_provider_dependency_notice() {
    ?>
    <div class="error">
        <p><?php _e( '<b>mittwald AI Provider</b> requires the <a href="https://wordpress.org/plugins/ai/"><b>AI Experiments</b></a> plugin to be installed and active.', 'wp-ai-provider' ); ?></p>
    </div>
    <?php
}

add_filter('plugin_action_links_'. plugin_basename(__FILE__), 'add_action_links');

function add_action_links( $links ) {
    $settings_link = sprintf(
            '<a href="%1$s">%2$s</a>',
            admin_url( 'options-general.php?page=wp-ai-client' ),
            esc_html__( 'Settings', 'ai' )
    );

    array_unshift( $links, $settings_link );

    return $links;
}

add_action('wp_loaded', function () {
    $dep_autoload = WP_PLUGIN_DIR . '/ai/vendor/autoload.php';
    if ( file_exists( $dep_autoload ) && ! class_exists( '\WordPress\AiClient\AiClient', false ) ) {
        require_once $dep_autoload;
    }

    $my_autoload = __DIR__ . '/vendor/autoload.php';
    if ( file_exists( $my_autoload ) ) {
        require_once $my_autoload;
    }

    $registry = \WordPress\AiClient\AiClient::defaultRegistry();
    $registry->registerProvider(\Mittwald\AiProvider\MittwaldAIProvider::class);

    (new \WordPress\AI_Client\API_Credentials\API_Credentials_Manager())->initialize();
});
