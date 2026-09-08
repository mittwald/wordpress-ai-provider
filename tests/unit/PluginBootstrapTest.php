<?php
/**
 * Tests for the plugin bootstrap file.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use Mittwald\AiProvider\Tests\Includes\WordPressStubState;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\AiClient;

use function Mittwald\AiProvider\display_composer_notice;
use function Mittwald\AiProvider\display_missing_ai_plugin_notice;
use function Mittwald\AiProvider\display_unsupported_wordpress_version_notice;
use function Mittwald\AiProvider\is_supported_wordpress_version;

/**
 * Covers hook registration and the load-order guards in `mittwald-ai-provider.php`.
 */
final class PluginBootstrapTest extends TestCase {

	/**
	 * Hooks the plugin file registered when it was first loaded.
	 *
	 * The file can only be included once per process, so the registrations it
	 * makes at include time are captured here rather than in `setUp()`.
	 *
	 * @var array{actions: array<string, list<array{callback: callable, priority: int, accepted_args: int}>>, filters: array<string, list<array{callback: callable, priority: int, accepted_args: int}>>}
	 */
	private static array $bootstrap_hooks;

	/**
	 * Loads the plugin file once and snapshots what it registered.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		WordPressStubState::reset();

		require_once dirname( __DIR__, 2 ) . '/mittwald-ai-provider.php';

		self::$bootstrap_hooks = array(
			'actions' => WordPressStubState::$actions,
			'filters' => WordPressStubState::$filters,
		);
	}

	/**
	 * Returns the single callback registered at include time for a hook.
	 *
	 * @param 'actions'|'filters' $type Hook type.
	 * @param string              $hook Hook name.
	 *
	 * @return array{callback: callable, priority: int, accepted_args: int}
	 */
	private function bootstrap_hook( string $type, string $hook ): array {
		$this->assertArrayHasKey(
			$hook,
			self::$bootstrap_hooks[ $type ],
			"The plugin should register a callback on the {$hook} hook."
		);
		$this->assertCount( 1, self::$bootstrap_hooks[ $type ][ $hook ] );

		return self::$bootstrap_hooks[ $type ][ $hook ][0];
	}

	/**
	 * Dependencies are wired up on `plugins_loaded`, after the AI client itself.
	 */
	public function test_dependencies_are_loaded_on_plugins_loaded(): void {
		$hook = $this->bootstrap_hook( 'actions', 'plugins_loaded' );

		$this->assertSame(
			20,
			$hook['priority'],
			'The plugin must load after the AI client has had a chance to register itself.'
		);
	}

	/**
	 * The provider is registered on `init`.
	 */
	public function test_provider_is_registered_on_init(): void {
		$this->bootstrap_hook( 'actions', 'init' );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * The plugin list gets a settings link pointing at the connectors page.
	 */
	public function test_settings_link_is_added_to_the_plugin_actions(): void {
		$hook = $this->bootstrap_hook( 'filters', 'plugin_action_links_mittwald-ai-provider/mittwald-ai-provider.php' );

		$links = ( $hook['callback'] )( array( '<a href="#">Deactivate</a>' ) );

		$this->assertIsArray( $links );
		$this->assertCount( 2, $links );

		$settings_link = $links[0];
		$this->assertIsString( $settings_link );
		$this->assertIsString( $links[1] );

		$this->assertStringContainsString( 'options-connectors.php', $settings_link );
		$this->assertStringContainsString( 'Settings', $settings_link );
		$this->assertStringContainsString( 'Deactivate', $links[1] );
	}

	/**
	 * The supported-version check accepts WordPress 7.0 and newer.
	 *
	 * @param string $version   A WordPress version string.
	 * @param bool   $supported Whether the plugin should consider it supported.
	 *
	 * @dataProvider provide_wordpress_versions
	 */
	#[DataProvider( 'provide_wordpress_versions' )]
	public function test_supported_wordpress_versions( string $version, bool $supported ): void {
		$this->assertSame( $supported, is_supported_wordpress_version( $version ) );
	}

	/**
	 * WordPress versions and whether the plugin supports them.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function provide_wordpress_versions(): array {
		return array(
			'6.7'       => array( '6.7', false ),
			'6.8.2'     => array( '6.8.2', false ),
			'6.9'       => array( '6.9', false ),
			'7.0'       => array( '7.0', true ),
			'7.0.1'     => array( '7.0.1', true ),
			'7.1'       => array( '7.1', true ),
			'8.0'       => array( '8.0', true ),
			// Pre-releases of 7.0 sort below 7.0 for version_compare(), hence the
			// explicit prefix check in the plugin.
			'7.0-beta5' => array( '7.0-beta5', true ),
			'7.0-alpha' => array( '7.0-alpha', true ),
			'7.0-RC1'   => array( '7.0-RC1', true ),
			// A pre-release of a version that is supported on its own.
			'7.1-beta1' => array( '7.1-beta1', true ),
			// A pre-release of a version that is not.
			'6.9-beta1' => array( '6.9-beta1', false ),
		);
	}

	/**
	 * On an unsupported WordPress version the plugin shows a notice and stops.
	 */
	public function test_old_wordpress_versions_get_a_notice_instead_of_the_provider(): void {
		WordPressStubState::$wp_version = '6.8';

		( $this->bootstrap_hook( 'actions', 'plugins_loaded' )['callback'] )();

		$notices = WordPressStubState::registrations( 'action', 'admin_notices' );

		$this->assertCount( 1, $notices );
		$this->assertSame(
			'Mittwald\\AiProvider\\display_unsupported_wordpress_version_notice',
			$notices[0]['callback']
		);
	}

	/**
	 * On a supported version the plugin loads without complaining.
	 */
	public function test_supported_wordpress_versions_produce_no_notice(): void {
		WordPressStubState::$wp_version = '7.0';

		( $this->bootstrap_hook( 'actions', 'plugins_loaded' )['callback'] )();

		$this->assertFalse(
			WordPressStubState::has( 'action', 'admin_notices' ),
			'A supported setup should not queue any admin notice.'
		);
	}

	/**
	 * The `init` callback registers the provider with the AI client.
	 */
	public function test_init_registers_the_provider_with_the_ai_client(): void {
		$registry = AiClient::defaultRegistry();

		( $this->bootstrap_hook( 'actions', 'init' )['callback'] )();

		$this->assertTrue( $registry->hasProvider( MittwaldAIProvider::class ) );
		$this->assertTrue( $registry->hasProvider( 'mittwald' ) );
		$this->assertContains( 'mittwald', $registry->getRegisteredProviderIds() );
	}

	/**
	 * Registering twice is harmless, whatever order the hooks fire in.
	 */
	public function test_registering_the_provider_twice_is_harmless(): void {
		$callback = $this->bootstrap_hook( 'actions', 'init' )['callback'];

		$callback();
		$callback();

		$this->assertCount(
			1,
			array_keys( AiClient::defaultRegistry()->getRegisteredProviderIds(), 'mittwald', true )
		);
	}

	/**
	 * The unsupported-version notice names the version that is in use.
	 */
	public function test_unsupported_version_notice_names_the_current_version(): void {
		WordPressStubState::$wp_version = '6.8.1';

		$notice = $this->render(
			static function (): void {
				display_unsupported_wordpress_version_notice();
			}
		);

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'WordPress 7.0 or newer', $notice );
		$this->assertStringContainsString( '<code>6.8.1</code>', $notice );
	}

	/**
	 * The missing-client notice explains where the client comes from.
	 */
	public function test_missing_ai_client_notice_mentions_the_requirement(): void {
		$notice = $this->render(
			static function (): void {
				display_missing_ai_plugin_notice();
			}
		);

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'WordPress AI client', $notice );
	}

	/**
	 * The Composer notice tells the user what to run and where.
	 */
	public function test_composer_notice_names_the_command_and_directory(): void {
		$notice = $this->render(
			static function (): void {
				display_composer_notice();
			}
		);

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'composer install --no-dev', $notice );
		$this->assertStringContainsString( dirname( __DIR__, 2 ) . '/', $notice );
	}

	/**
	 * Captures the output of a notice callback.
	 *
	 * @param callable $callback The notice callback.
	 */
	private function render( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}
}
