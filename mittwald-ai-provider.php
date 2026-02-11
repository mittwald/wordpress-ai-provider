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

namespace Mittwald\AiProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display admin notice about missing required AI plugin.
 *
 * The mittwald AI provider plugin depends on the WordPress AI Client plugin, so
 * there *should* not be any way for a user to activate this plugin without having
 * the required plugin installed and activated. However, in case that does happen,
 * this notice will inform the user about the issue.
 */
function display_missing_ai_plugin_notice(): void {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'The mittwald AI provider plugin requires the WordPress AI Client plugin to be installed and activated.', 'mittwald-ai-provider' ); ?></p>
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
			/* translators: %s: composer install command */
				esc_html__( 'Your installation of the mittwald AI provider plugin is incomplete. Please run %s.', 'mittwald-ai-provider' ),
				'<code>composer install</code>'
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
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-general.php?page=wp-ai-client' ),
			// use translation from required plugin `ai`.
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			esc_html__( 'Settings', 'ai' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
);

add_action(
	'plugins_loaded',
	function () {
		// Note: We assume that the Composer autoloader of the "ai" plugin is already loaded,
		// because that plugin does so in the `plugins_loaded` action with default priority (10).
		// We use priority 20 to ensure our code runs *after* that.
		//
		// For this reason, there is no realistic way for this check to fail; this is just us
		// being defensive.
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\display_missing_ai_plugin_notice' );
			return;
		}

		$my_autoload = __DIR__ . '/vendor/autoload.php';
		if ( ! file_exists( $my_autoload ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\display_composer_notice' );
			return;
		}

		require_once $my_autoload;

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		$registry->registerProvider( \Mittwald\AiProvider\MittwaldAIProvider::class );
	},
	20
);
