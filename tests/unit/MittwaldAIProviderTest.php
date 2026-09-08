<?php
/**
 * Tests for the provider class.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldModelMetadataDirectory;
use Mittwald\AiProvider\MittwaldTextGenerationModel;
use Mittwald\AiProvider\MittwaldTextToSpeechConversionModel;
use Mittwald\AiProvider\Tests\Includes\ModelCatalogue;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use Mittwald\AiProvider\Tests\Includes\WordPressStubState;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Covers provider metadata, URL construction and model routing.
 */
final class MittwaldAIProviderTest extends TestCase {

	/**
	 * The provider identifies itself as mittwald's cloud AI hosting.
	 */
	public function test_provider_metadata_identifies_mittwald_ai_hosting(): void {
		$metadata = MittwaldAIProvider::metadata();

		$this->assertSame( 'mittwald', $metadata->getId() );
		$this->assertSame( 'mittwald', $metadata->getName() );
		$this->assertTrue( $metadata->getType()->isCloud() );

		$authentication_method = $metadata->getAuthenticationMethod();

		$this->assertNotNull( $authentication_method );
		$this->assertTrue( RequestAuthenticationMethod::apiKey()->equals( $authentication_method ) );
		$this->assertNotNull( $metadata->getDescription() );
	}

	/**
	 * The plugin ships the logo the metadata points at.
	 */
	public function test_provider_metadata_points_at_a_logo_that_exists(): void {
		$logo_path = MittwaldAIProvider::metadata()->getLogoPath();

		$this->assertNotNull( $logo_path, 'The provider should expose the bundled icon.' );
		$this->assertFileExists( $logo_path );
		$this->assertSame( 'icon.svg', basename( $logo_path ) );
	}

	/**
	 * German users are pointed at the German credentials documentation.
	 *
	 * @param string $locale       The user locale.
	 * @param string $expected_url The documentation URL expected for it.
	 *
	 * @dataProvider provide_locales
	 */
	#[DataProvider( 'provide_locales' )]
	public function test_credentials_url_follows_the_user_locale( string $locale, string $expected_url ): void {
		WordPressStubState::$user_locale = $locale;

		/*
		 * `metadata()` memoises, so the locale branch has to be exercised
		 * through the factory it caches.
		 */
		$metadata = $this->invoke_hidden_method_on_class( MittwaldAIProvider::class, 'createProviderMetadata' );

		$this->assertInstanceOf( ProviderMetadata::class, $metadata );
		$this->assertSame( $expected_url, $metadata->getCredentialsUrl() );
	}

	/**
	 * Locales and the credentials documentation URL each should produce.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_locales(): array {
		$base = 'https://developer.mittwald.de/';
		$path = 'docs/v2/platform/aihosting/access-and-usage/access/';

		return array(
			'German'            => array( 'de_DE', $base . 'de/' . $path ),
			'German (informal)' => array( 'de_DE_formal', $base . 'de/' . $path ),
			'Austrian German'   => array( 'de_AT', $base . 'de/' . $path ),
			'US English'        => array( 'en_US', $base . $path ),
			'British English'   => array( 'en_GB', $base . $path ),
			'Dutch'             => array( 'nl_NL', $base . $path ),
		);
	}

	/**
	 * Endpoint paths are appended to the mittwald AI hosting base URL.
	 *
	 * @param string $path     Path to append.
	 * @param string $expected Expected full URL.
	 *
	 * @dataProvider provide_paths
	 */
	#[DataProvider( 'provide_paths' )]
	public function test_url_construction( string $path, string $expected ): void {
		$this->assertSame( $expected, MittwaldAIProvider::url( $path ) );
	}

	/**
	 * Endpoint paths and the URLs they should produce.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_paths(): array {
		$base = 'https://llm.aihosting.mittwald.de/v1';

		return array(
			'empty path'             => array( '', $base ),
			'models'                 => array( 'models', $base . '/models' ),
			'chat completions'       => array( 'chat/completions', $base . '/chat/completions' ),
			'speech'                 => array( 'audio/speech', $base . '/audio/speech' ),
			'images'                 => array( 'images/generations', $base . '/images/generations' ),
			'leading slash'          => array( '/models', $base . '/models' ),
			'repeated leading slash' => array( '//models', $base . '/models' ),
		);
	}

	/**
	 * Availability is decided by whether the models endpoint answers.
	 */
	public function test_availability_is_determined_by_listing_models(): void {
		$this->assertInstanceOf(
			ListModelsApiBasedProviderAvailability::class,
			MittwaldAIProvider::availability()
		);
	}

	/**
	 * The provider uses the plugin's own metadata directory.
	 */
	public function test_provider_uses_the_mittwald_metadata_directory(): void {
		$this->assertInstanceOf(
			MittwaldModelMetadataDirectory::class,
			MittwaldAIProvider::modelMetadataDirectory()
		);
	}

	/**
	 * Chat models are routed to the text generation model class.
	 *
	 * @param string $model_id Model ID.
	 *
	 * @dataProvider provide_text_generation_models
	 */
	#[DataProvider( 'provide_text_generation_models' )]
	public function test_text_generation_models_are_routed_to_the_text_model( string $model_id ): void {
		$model = $this->create_model( $this->model_metadata( $model_id ) );

		$this->assertInstanceOf( MittwaldTextGenerationModel::class, $model );
		$this->assertSame( $model_id, $model->metadata()->getId() );
		$this->assertSame( 'mittwald', $model->providerMetadata()->getId() );
	}

	/**
	 * Model IDs that should be served by the text generation model.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_text_generation_models(): array {
		return array(
			array( 'gpt-oss-120b' ),
			array( 'Qwen3.5-0.8B' ),
			array( 'Ministral-3-14B-Instruct-2512' ),
			array( 'Qwen3.5-122B-A10B-FP8' ),
			array( 'Qwen3.6-35B-A3B-FP8' ),
			array( 'Qwen3.8-27B-NVFP4' ),
			array( 'GLM-OCR' ),
		);
	}

	/**
	 * The TTS model is routed to the speech conversion model class.
	 */
	public function test_tts_model_is_routed_to_the_speech_model(): void {
		$model = $this->create_model( $this->model_metadata( 'Qwen3-TTS-12Hz-1.7B-CustomVoice' ) );

		$this->assertInstanceOf( MittwaldTextToSpeechConversionModel::class, $model );
	}

	/**
	 * A model without capabilities cannot be instantiated.
	 */
	public function test_a_model_without_capabilities_is_rejected(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unsupported model capabilities' );

		$this->create_model( $this->model_metadata( 'Qwen3-Embedding-8B' ) );
	}

	/**
	 * Embedding models are recognised, but not yet implemented.
	 */
	public function test_embedding_models_report_that_they_are_not_implemented(): void {
		$metadata = new ModelMetadata(
			'some-embedding-model',
			'some-embedding-model',
			array( CapabilityEnum::embeddingGeneration() ),
			array()
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not yet implemented' );

		$this->create_model( $metadata );
	}

	/**
	 * Every model class the plugin ships is reachable through the router.
	 *
	 * A model class nothing routes to is dead code, usually left behind when a
	 * capability was dropped from the metadata directory.
	 */
	public function test_shipped_model_classes_are_reachable_from_the_router(): void {
		$reachable = array();
		foreach ( $this->resolve_model_metadata( ModelCatalogue::routable() ) as $metadata ) {
			$reachable[ get_class( $this->create_model( $metadata ) ) ] = true;
		}

		$model_files = glob( dirname( __DIR__, 2 ) . '/includes/*Model.php' );
		$this->assertIsArray( $model_files );

		$shipped = array();
		foreach ( $model_files as $file ) {
			$shipped[] = 'Mittwald\\AiProvider\\' . basename( $file, '.php' );
		}

		$unreachable = array_values( array_diff( $shipped, array_keys( $reachable ) ) );

		$this->assertSame(
			array( 'Mittwald\\AiProvider\\MittwaldImageGenerationModel' ),
			$unreachable,
			'The image generation model is the only class no catalogued model routes to, '
			. 'because mittwald AI hosting does not currently offer an image model. '
			. 'Any other class in this list is dead code.'
		);
	}

	/**
	 * Runs model metadata through the provider's model factory.
	 *
	 * @param ModelMetadata $metadata Metadata to route.
	 *
	 */
	private function create_model( ModelMetadata $metadata ): ModelInterface {
		$model = $this->invoke_hidden_method_on_class(
			MittwaldAIProvider::class,
			'createModel',
			array( $metadata, MittwaldAIProvider::metadata() )
		);

		$this->assertInstanceOf( ModelInterface::class, $model );

		return $model;
	}

	/**
	 * Invokes a protected static method.
	 *
	 * @param class-string $class_name Class to call the method on.
	 * @param string       $method     Method name.
	 * @param list<mixed>  $arguments  Arguments to pass.
	 *
	 * @return mixed The method's return value.
	 */
	private function invoke_hidden_method_on_class( string $class_name, string $method, array $arguments = array() ) {
		$reflection = new \ReflectionMethod( $class_name, $method );

		// Redundant, and deprecated, from PHP 8.1 onwards.
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invokeArgs( null, $arguments );
	}
}
