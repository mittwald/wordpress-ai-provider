<?php
/**
 * Plugin Name: AI Provider for mittwald
 * Plugin URI: https://github.com/mittwald/wordpress-ai-provider
 * Description: Adds mittwald AI hosting to the available AI providers
 * Version: trunk
 * Author: mittwald, Lukas Fritze, Martin Helmich
 * Author URI: https://www.mittwald.de/
 * License: GPL-2.0-or-later
 */

namespace Mittwald\AiProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether the current WordPress version is supported.
 *
 * This plugin supports WordPress 7.0+, including 7.0 pre-releases.
 *
 * @param string $version WordPress version.
 */
function is_supported_wordpress_version( string $version ): bool {
	return version_compare( $version, '7.0', '>=' ) || str_starts_with( $version, '7.0-' );
}

/**
 * Display admin notice about unsupported WordPress version.
 */
function display_unsupported_wordpress_version_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: current WordPress version */
				esc_html__( 'The AI Provider for mittwald plugin requires WordPress 7.0 or newer, including WordPress 7.0 pre-releases. Current version: %s.', 'mittwald-ai-provider' ),
				'<code>' . esc_html( wp_get_wp_version() ) . '</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Display admin notice about missing required AI client.
 *
 * WordPress 7.0+ ships with the AI client in core. If the client class is still
 * unavailable, this notice informs the user about the missing dependency.
 */
function display_missing_ai_plugin_notice(): void {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'The AI Provider for mittwald plugin requires the WordPress AI client available in WordPress 7.0 and newer (including 7.0 pre-releases).', 'mittwald-ai-provider' ); ?></p>
	</div>
	<?php
}

/**
 * Display admin notice about missing Composer dependencies.
 *
 * We always ship this plugin with its Composer dependencies installed, so notice
 * should never actually appear. However, in case someone installs the plugin
 * from source (e.g. from GitHub) without running `composer install`, this notice
 * will inform them about the issue.
 */
function display_composer_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %1$s: composer install command, %2$s: plugin directory path */
				esc_html__( 'Your installation of the mittwald AI provider plugin is incomplete. Please run %1$s in the %2$s directory.', 'mittwald-ai-provider' ),
				'<code>composer install --no-dev</code>',
				'<code>' . esc_html( plugin_dir_path( __FILE__ ) ) . '</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Add settings link to plugin actions.
 *
 * NOTE: This plugin does not actually have its own settings page; instead,
 * we piggyback on the settings page of the main `ai` plugin.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( array $links ): array {
		$settings_page_url = 'options-connectors.php';

		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( $settings_page_url ),
			esc_html__( 'Settings', 'mittwald-ai-provider' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
);

add_action(
	'plugins_loaded',
	function () {
		$wp_version = wp_get_wp_version();
		if ( ! is_supported_wordpress_version( $wp_version ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\display_unsupported_wordpress_version_notice' );
			return;
		}

		// This plugin requires the WordPress AI client, which is part of WordPress core
		// starting with WordPress 7.0. If unavailable, show an admin notice.
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\display_missing_ai_plugin_notice' );
			return;
		}

		// vendor/autoload.php *should* always be present, since we ship the plugin with its
		// dependencies installed. However, in development setups (e.g. when cloning the plugin
		// from GitHub), it's possible for the plugin to be missing its dependencies if
		// `composer install` hasn't been run. In that case, we display an admin notice about
		// the missing dependencies.
		$my_autoload = __DIR__ . '/vendor/autoload.php';
		if ( ! file_exists( $my_autoload ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\display_composer_notice' );
			return;
		}

		require_once $my_autoload;
	},
	20
);

add_action(
	'init',
	function () {
		// Guard against unexpected load-order issues.
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			return;
		}

		if ( ! class_exists( \Mittwald\AiProvider\MittwaldAIProvider::class ) ) {
			return;
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( MittwaldAIProvider::class ) ) {
			$registry->registerProvider( \Mittwald\AiProvider\MittwaldAIProvider::class );
		}
	}
);
