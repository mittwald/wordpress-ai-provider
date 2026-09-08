<?php
/**
 * Base test case for the integration test suite.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Includes;

use Mittwald\AiProvider\MittwaldAIProvider;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Contracts\CachesDataInterface;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\ApiBasedImplementation\Contracts\ApiBasedModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Shared setup for tests that talk to the real mittwald AI hosting API.
 *
 * Every test in the integration suite needs a MITTWALD_AI_API_KEY environment
 * variable. Without one the whole suite skips itself, so `composer run test`
 * stays usable for contributors without an AI hosting account.
 *
 * Tests also skip themselves when the model they need is not offered to the
 * account under test, because the model lineup changes over time and an
 * unavailable model is not a defect in this plugin.
 */
abstract class IntegrationTestCase extends PhpUnitTestCase {

	/**
	 * Name of the environment variable holding the API key.
	 *
	 * @var string
	 */
	public const API_KEY_ENV = 'MITTWALD_AI_API_KEY';

	/**
	 * Request timeout, in seconds.
	 *
	 * Generous, because these are real generations on shared infrastructure.
	 *
	 * @var float
	 */
	protected const REQUEST_TIMEOUT = 180.0;

	/**
	 * The registry the provider is registered with.
	 *
	 * @var ProviderRegistry|null
	 */
	private static ?ProviderRegistry $registry = null;

	/**
	 * Model IDs the account under test can see, or null if not yet fetched.
	 *
	 * @var list<string>|null
	 */
	private static ?array $available_model_ids = null;

	/**
	 * Skips the whole suite when no API key is configured.
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( null === self::api_key() ) {
			$this->markTestSkipped(
				sprintf(
					'Set %s to run the integration tests against mittwald AI hosting.',
					self::API_KEY_ENV
				)
			);
		}
	}

	/**
	 * Returns the API key, or null if none is configured.
	 */
	protected static function api_key(): ?string {
		$key = getenv( self::API_KEY_ENV );

		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			return null;
		}

		return trim( $key );
	}

	/**
	 * Returns a registry with the provider registered and authenticated.
	 */
	protected function registry(): ProviderRegistry {
		if ( null === self::$registry ) {
			$registry = AiClient::defaultRegistry();

			if ( ! $registry->hasProvider( MittwaldAIProvider::class ) ) {
				$registry->registerProvider( MittwaldAIProvider::class );
			}

			$registry->setProviderRequestAuthentication(
				MittwaldAIProvider::class,
				new ApiKeyRequestAuthentication( (string) self::api_key() )
			);

			self::$registry = $registry;
		}

		return self::$registry;
	}

	/**
	 * Returns the model IDs the account under test can see.
	 *
	 * @return list<string>
	 */
	protected function available_model_ids(): array {
		if ( null === self::$available_model_ids ) {
			$ids = array();
			foreach ( $this->available_models() as $metadata ) {
				$ids[] = $metadata->getId();
			}

			self::$available_model_ids = $ids;
		}

		return self::$available_model_ids;
	}

	/**
	 * Returns the metadata for every model the account under test can see.
	 *
	 * @return list<ModelMetadata>
	 */
	protected function available_models(): array {
		$this->registry();

		return MittwaldAIProvider::modelMetadataDirectory()->listModelMetadata();
	}

	/**
	 * Returns a model instance, skipping the test if the model is unavailable.
	 *
	 * @param string           $model_id The model to use.
	 * @param ModelConfig|null $config   Optional configuration.
	 */
	protected function model( string $model_id, ?ModelConfig $config = null ): ApiBasedModelInterface {
		if ( ! in_array( $model_id, $this->available_model_ids(), true ) ) {
			$this->markTestSkipped(
				sprintf( 'mittwald AI hosting does not currently offer the model %s.', $model_id )
			);
		}

		$model = $this->registry()->getProviderModel( MittwaldAIProvider::class, $model_id, $config );

		// Every model this plugin ships is API based; anything else is a routing bug.
		$this->assertInstanceOf( ApiBasedModelInterface::class, $model );

		$request_options = new RequestOptions();
		$request_options->setTimeout( static::REQUEST_TIMEOUT );
		$model->setRequestOptions( $request_options );

		return $model;
	}

	/**
	 * Returns the first available model out of a list of candidates.
	 *
	 * The lineup changes over time, so tests name every model that would do and
	 * skip only when none of them is on offer.
	 *
	 * @param list<string>     $model_ids Candidate model IDs, in order of preference.
	 * @param ModelConfig|null $config    Optional configuration.
	 */
	protected function first_available_model( array $model_ids, ?ModelConfig $config = null ): ApiBasedModelInterface {
		foreach ( $model_ids as $model_id ) {
			if ( in_array( $model_id, $this->available_model_ids(), true ) ) {
				return $this->model( $model_id, $config );
			}
		}

		$this->markTestSkipped(
			sprintf(
				'None of the candidate models is currently offered: %s.',
				implode( ', ', $model_ids )
			)
		);
	}

	/**
	 * Drops anything the metadata directory has cached about the model list.
	 */
	protected function invalidate_model_cache(): void {
		$directory = MittwaldAIProvider::modelMetadataDirectory();

		$this->assertInstanceOf( CachesDataInterface::class, $directory );

		$directory->invalidateCaches();
	}

	/**
	 * Builds a prompt consisting of a single user message.
	 *
	 * @param string $text Message text.
	 *
	 * @return list<Message>
	 */
	protected function user_prompt( string $text ): array {
		return array( new Message( MessageRoleEnum::user(), array( new MessagePart( $text ) ) ) );
	}

	/**
	 * Reads a fixture file.
	 *
	 * @param string $name File name, relative to `tests/fixtures`.
	 */
	protected function fixture( string $name ): string {
		$path = dirname( __DIR__ ) . '/fixtures/' . $name;

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}
}
