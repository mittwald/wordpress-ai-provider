<?php
/**
 * Mutable state behind the WordPress function stubs.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Includes;

/**
 * Holds the state the WordPress function stubs read and write.
 *
 * The plugin bootstrap registers hooks and branches on the WordPress version
 * and the user locale. Rather than pulling in a full mocking framework, the
 * stubs in `tests/stubs/wordpress.php` record hook registrations here and read
 * their return values from here, so a test can drive them directly.
 *
 * @phpstan-type HookRegistration array{callback: callable, priority: int, accepted_args: int}
 */
final class WordPressStubState {

	/**
	 * Registered actions, keyed by hook name.
	 *
	 * @var array<string, list<array{callback: callable, priority: int, accepted_args: int}>>
	 */
	public static array $actions = array();

	/**
	 * Registered filters, keyed by hook name.
	 *
	 * @var array<string, list<array{callback: callable, priority: int, accepted_args: int}>>
	 */
	public static array $filters = array();

	/**
	 * Value returned by the `wp_get_wp_version()` stub.
	 *
	 * @var string
	 */
	public static string $wp_version = '7.0';

	/**
	 * Value returned by the `get_user_locale()` stub.
	 *
	 * @var string
	 */
	public static string $user_locale = 'en_US';

	/**
	 * Resets all state to its defaults.
	 */
	public static function reset(): void {
		self::$actions     = array();
		self::$filters     = array();
		self::$wp_version  = '7.0';
		self::$user_locale = 'en_US';
	}

	/**
	 * Records a hook registration.
	 *
	 * @param 'action'|'filter' $type          Hook type.
	 * @param string            $hook          Hook name.
	 * @param callable          $callback      The callback.
	 * @param int               $priority      Hook priority.
	 * @param int               $accepted_args Number of accepted arguments.
	 */
	public static function record( string $type, string $hook, callable $callback, int $priority, int $accepted_args ): void {
		$registration = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		if ( 'action' === $type ) {
			self::$actions[ $hook ][] = $registration;
			return;
		}

		self::$filters[ $hook ][] = $registration;
	}

	/**
	 * Returns all registrations for a hook.
	 *
	 * @param 'action'|'filter' $type Hook type.
	 * @param string            $hook Hook name.
	 *
	 * @return list<array{callback: callable, priority: int, accepted_args: int}>
	 */
	public static function registrations( string $type, string $hook ): array {
		if ( 'action' === $type ) {
			return self::$actions[ $hook ] ?? array();
		}

		return self::$filters[ $hook ] ?? array();
	}

	/**
	 * Returns true if at least one callback is registered for a hook.
	 *
	 * @param 'action'|'filter' $type Hook type.
	 * @param string            $hook Hook name.
	 */
	public static function has( string $type, string $hook ): bool {
		return array() !== self::registrations( $type, $hook );
	}
}
