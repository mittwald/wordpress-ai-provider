<?php
/**
 * PHPUnit bootstrap for the mittwald AI provider plugin.
 *
 * Loads the Composer autoloader and the small set of WordPress function stubs
 * the plugin code touches. The plugin is a thin layer on top of the
 * `wordpress/php-ai-client` SDK and never talks to the WordPress database, so a
 * full WordPress test installation would add a lot of moving parts without
 * covering anything the stubs below do not.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

$mittwald_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_file( $mittwald_autoload ) ) {
	fwrite( STDERR, "Could not find vendor/autoload.php. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $mittwald_autoload;
require_once __DIR__ . '/stubs/wordpress.php';
