<?php
/**
 * Base test case for the unit test suite.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Includes;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldModelMetadataDirectory;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use ReflectionMethod;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Shared setup and helpers for unit tests.
 */
abstract class TestCase extends PhpUnitTestCase {

	/**
	 * The API key the fake authentication uses.
	 *
	 * @var string
	 */
	protected const TEST_API_KEY = 'test-api-key';

	/**
	 * Resets the WordPress stub state before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		WordPressStubState::reset();
	}

	/**
	 * Returns the provider metadata, built by the plugin's own factory.
	 */
	protected function provider_metadata(): ProviderMetadata {
		return MittwaldAIProvider::metadata();
	}

	/**
	 * Builds model metadata by running IDs through the real metadata directory.
	 *
	 * Going through the directory rather than hand-building metadata keeps the
	 * capabilities and supported options in these tests in lockstep with the
	 * catalogue the plugin actually ships.
	 *
	 * @param list<string> $model_ids Model IDs as the API would report them.
	 *
	 * @return array<string, ModelMetadata> Metadata keyed by model ID.
	 */
	protected function resolve_model_metadata( array $model_ids ): array {
		$payload = array(
			'data' => array_map(
				static function ( string $id ): array {
					return array( 'id' => $id );
				},
				$model_ids
			),
		);

		$response = new Response(
			200,
			array( 'Content-Type' => 'application/json' ),
			(string) json_encode( $payload, JSON_THROW_ON_ERROR )
		);

		$directory = new MittwaldModelMetadataDirectory();

		/** @var list<ModelMetadata> $list */
		$list = $this->invoke_hidden_method( $directory, 'parseResponseToModelMetadataList', array( $response ) );

		$resolved = array();
		foreach ( $list as $metadata ) {
			$resolved[ $metadata->getId() ] = $metadata;
		}

		return $resolved;
	}

	/**
	 * Returns metadata for a single model ID.
	 *
	 * @param string $model_id Model ID as the API would report it.
	 */
	protected function model_metadata( string $model_id ): ModelMetadata {
		$resolved = $this->resolve_model_metadata( array( $model_id ) );

		$this->assertArrayHasKey( $model_id, $resolved, "The metadata directory dropped model {$model_id}." );

		return $resolved[ $model_id ];
	}

	/**
	 * Wires a model up with a fake transporter and API key authentication.
	 *
	 * @param AbstractApiBasedModel $model       The model to wire up.
	 * @param FakeHttpTransporter   $transporter The transporter to use.
	 *
	 * @template T of AbstractApiBasedModel
	 * @phpstan-param T $model
	 * @phpstan-return T
	 */
	protected function wire( AbstractApiBasedModel $model, FakeHttpTransporter $transporter ): AbstractApiBasedModel {
		$model->setHttpTransporter( $transporter );
		$model->setRequestAuthentication( new ApiKeyRequestAuthentication( self::TEST_API_KEY ) );

		return $model;
	}

	/**
	 * Narrows a mixed value to an array, failing the test if it is not one.
	 *
	 * Payloads decoded from JSON are untyped by nature; this keeps the
	 * assertions that read them honest without scattering casts around.
	 *
	 * @param mixed $value Value to narrow.
	 *
	 * @return array<array-key, mixed>
	 */
	protected function as_array( $value ): array {
		$this->assertIsArray( $value );

		return $value;
	}

	/**
	 * Narrows a mixed value to a string, failing the test if it is not one.
	 *
	 * @param mixed $value Value to narrow.
	 */
	protected function as_string( $value ): string {
		$this->assertIsString( $value );

		return $value;
	}

	/**
	 * Invokes a protected or private method.
	 *
	 * @param object       $target    Object to call the method on.
	 * @param string       $method    Method name.
	 * @param list<mixed>  $arguments Arguments to pass.
	 *
	 * @return mixed The method's return value.
	 */
	protected function invoke_hidden_method( object $target, string $method, array $arguments = array() ) {
		$reflection = new ReflectionMethod( $target, $method );

		// Redundant, and deprecated, from PHP 8.1 onwards.
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invokeArgs( $target, $arguments );
	}
}
