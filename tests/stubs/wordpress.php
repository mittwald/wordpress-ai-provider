<?php
/**
 * Minimal WordPress function stubs for the unit test suite.
 *
 * Only the functions the plugin actually calls are stubbed. Hook registrations
 * are recorded in {@see \Mittwald\AiProvider\Tests\Includes\WordPressStubState}
 * so tests can assert on them and invoke the registered callbacks directly.
 *
 * All definitions are guarded, so the file stays harmless if the suite is ever
 * run inside a real WordPress test installation.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

use Mittwald\AiProvider\Tests\Includes\WordPressStubState;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records an action registration.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		WordPressStubState::record( 'action', $hook_name, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records a filter registration.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		WordPressStubState::record( 'filter', $hook_name, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'wp_get_wp_version' ) ) {
	/**
	 * Returns the WordPress version the current test is pretending to run on.
	 */
	function wp_get_wp_version(): string {
		return WordPressStubState::$wp_version;
	}
}

if ( ! function_exists( 'get_user_locale' ) ) {
	/**
	 * Returns the locale the current test is pretending the user has.
	 */
	function get_user_locale(): string {
		return WordPressStubState::$user_locale;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Passthrough translation stub.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.textFound
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escapes text for use in HTML.
	 *
	 * @param string $text Text to escape.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Translates and escapes text.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Translates, escapes and echoes text.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escapes a URL.
	 *
	 * @param string $url URL to escape.
	 */
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Builds an admin URL.
	 *
	 * @param string $path Path relative to the admin directory.
	 */
	function admin_url( string $path = '' ): string {
		return 'https://example.org/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	/**
	 * Returns the directory of a plugin file, with a trailing slash.
	 *
	 * @param string $file Plugin file.
	 */
	function plugin_dir_path( string $file ): string {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Returns a plugin-relative basename.
	 *
	 * @param string $file Plugin file.
	 */
	function plugin_basename( string $file ): string {
		// The directory the plugin lives in once installed, not the checkout it
		// is being tested from.
		return 'mittwald-ai-provider/' . basename( $file );
	}
}
