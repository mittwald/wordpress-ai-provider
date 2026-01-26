<?php

/**
 * Plugin Name: mittwald AI provider
 * Plugin URI: https://github.com/mittwald/wordpress-ai-provider
 * Description: Adds mittwald AI hosting to the available AI providers
 * Version: 0.1
 * Author: mittwald, Lukas Fritze, Martin Helmich
 * Author URI: https://www.mittwald.de/
 * License: GPL-2.0-or-later
 * Requires Plugins: ai
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Display an admin notice if the dependency is missing.
 */
add_filter('plugin_action_links_'. plugin_basename(__FILE__),  function ( $links ) {
    $settings_link = sprintf(
            '<a href="%1$s">%2$s</a>',
            admin_url( 'options-general.php?page=wp-ai-client' ),
            // ues translation from required plugin `ai`
            esc_html__( 'Settings', 'ai' )
    );

    array_unshift( $links, $settings_link );

    return $links;
});

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
