<?php
/**
 * Tests for the model metadata directory.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldModelMetadataDirectory;
use Mittwald\AiProvider\MittwaldTextToSpeechConversionModel;
use Mittwald\AiProvider\Tests\Includes\FakeHttpTransporter;
use Mittwald\AiProvider\Tests\Includes\ModelCatalogue;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Covers how the `/v1/models` response is turned into model metadata.
 */
final class MittwaldModelMetadataDirectoryTest extends TestCase {

	/**
	 * Builds a directory wired to a fake transporter.
	 *
	 * @param FakeHttpTransporter $transporter The transporter to use.
	 */
	private function directory( FakeHttpTransporter $transporter ): MittwaldModelMetadataDirectory {
		$directory = new MittwaldModelMetadataDirectory();
		$directory->setHttpTransporter( $transporter );
		$directory->setRequestAuthentication( new ApiKeyRequestAuthentication( self::TEST_API_KEY ) );

		return $directory;
	}

	/**
	 * The listing request goes to the provider's own models endpoint.
	 */
	public function test_list_models_requests_the_mittwald_models_endpoint(): void {
		$transporter = new FakeHttpTransporter();
		$transporter->queue_json( array( 'data' => array( array( 'id' => 'gpt-oss-120b' ) ) ) );

		$this->directory( $transporter )->listModelMetadata();

		$request = $transporter->last_request();

		$this->assertSame( 'https://llm.aihosting.mittwald.de/v1/models', $request->getUri() );
		$this->assertTrue( $request->getMethod()->equals( HttpMethodEnum::GET() ) );
		$this->assertSame( 'Bearer ' . self::TEST_API_KEY, $request->getHeaderAsString( 'Authorization' ) );
	}

	/**
	 * The model list is cached for the lifetime of the directory instance.
	 */
	public function test_model_list_is_only_fetched_once_per_instance(): void {
		$transporter = new FakeHttpTransporter();
		$transporter->queue_json( array( 'data' => array( array( 'id' => 'gpt-oss-120b' ) ) ) );

		$directory = $this->directory( $transporter );
		$directory->listModelMetadata();
		$directory->listModelMetadata();
		$this->assertTrue( $directory->hasModelMetadata( 'gpt-oss-120b' ) );

		$this->assertSame( 1, $transporter->request_count() );
	}

	/**
	 * A response without a `data` key is rejected rather than silently ignored.
	 */
	public function test_missing_data_key_raises_a_response_exception(): void {
		$transporter = new FakeHttpTransporter();
		$transporter->queue_json( array( 'object' => 'list' ) );

		$this->expectException( ResponseException::class );

		$this->directory( $transporter )->listModelMetadata();
	}

	/**
	 * An empty model list is treated the same way as a missing one.
	 */
	public function test_empty_data_key_raises_a_response_exception(): void {
		$transporter = new FakeHttpTransporter();
		$transporter->queue_json( array( 'data' => array() ) );

		$this->expectException( ResponseException::class );

		$this->directory( $transporter )->listModelMetadata();
	}

	/**
	 * An HTTP error is surfaced, with the API's own message attached.
	 */
	public function test_error_status_raises_a_client_exception(): void {
		$transporter = new FakeHttpTransporter();
		$transporter->queue_json( array( 'error' => array( 'message' => 'Invalid API key' ) ), 401 );

		$this->expectException( ClientException::class );
		$this->expectExceptionMessage( 'Invalid API key' );

		$this->directory( $transporter )->listModelMetadata();
	}

	/**
	 * A server-side failure is surfaced as such.
	 */
	public function test_server_error_raises_a_server_exception(): void {
		$transporter = new FakeHttpTransporter();
		$transporter->queue_json( array( 'error' => array( 'message' => 'Upstream unavailable' ) ), 503 );

		$this->expectException( ServerException::class );

		$this->directory( $transporter )->listModelMetadata();
	}

	/**
	 * The model ID doubles as the display name, since the API reports no name.
	 */
	public function test_model_id_is_used_as_the_display_name(): void {
		$metadata = $this->model_metadata( 'gpt-oss-120b' );

		$this->assertSame( 'gpt-oss-120b', $metadata->getId() );
		$this->assertSame( 'gpt-oss-120b', $metadata->getName() );
	}

	/**
	 * Text-only chat models get the text generation and chat history capabilities.
	 *
	 * @param string $model_id Model ID.
	 *
	 * @dataProvider provide_text_only_chat_models
	 */
	#[DataProvider( 'provide_text_only_chat_models' )]
	public function test_text_only_chat_models_accept_text_input_only( string $model_id ): void {
		$metadata = $this->model_metadata( $model_id );

		$this->assertHasCapabilities(
			$metadata,
			array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() )
		);
		$this->assertSame(
			array( array( 'text' ) ),
			$this->supported_option_values( $metadata, OptionEnum::inputModalities() )
		);
	}

	/**
	 * Model IDs that are configured as text-only chat models.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_text_only_chat_models(): array {
		return array(
			array( 'gpt-oss-120b' ),
			array( 'Qwen3.5-0.8B' ),
		);
	}

	/**
	 * Vision-capable chat models additionally accept image input.
	 *
	 * @param string $model_id Model ID.
	 *
	 * @dataProvider provide_multimodal_chat_models
	 */
	#[DataProvider( 'provide_multimodal_chat_models' )]
	public function test_multimodal_chat_models_accept_image_input( string $model_id ): void {
		$metadata = $this->model_metadata( $model_id );

		$this->assertHasCapabilities(
			$metadata,
			array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() )
		);
		$this->assertSame(
			array( array( 'text' ), array( 'text', 'image' ) ),
			$this->supported_option_values( $metadata, OptionEnum::inputModalities() )
		);
	}

	/**
	 * Model IDs that are configured as vision-capable chat models.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_multimodal_chat_models(): array {
		return array(
			array( 'Ministral-3-14B-Instruct-2512' ),
			array( 'Qwen3.5-122B-A10B-FP8' ),
			array( 'Qwen3.6-35B-A3B-FP8' ),
			array( 'Qwen3.8-27B-NVFP4' ),
		);
	}

	/**
	 * Every chat model advertises the generation options the plugin promises.
	 *
	 * @param string $model_id Model ID.
	 *
	 * @dataProvider provide_text_only_chat_models
	 * @dataProvider provide_multimodal_chat_models
	 */
	#[DataProvider( 'provide_text_only_chat_models' )]
	#[DataProvider( 'provide_multimodal_chat_models' )]
	public function test_chat_models_advertise_the_full_option_set( string $model_id ): void {
		$metadata = $this->model_metadata( $model_id );
		$options  = $this->supported_option_names( $metadata );

		$expected = array(
			OptionEnum::systemInstruction()->value,
			OptionEnum::candidateCount()->value,
			OptionEnum::maxTokens()->value,
			OptionEnum::temperature()->value,
			OptionEnum::topP()->value,
			OptionEnum::stopSequences()->value,
			OptionEnum::presencePenalty()->value,
			OptionEnum::frequencyPenalty()->value,
			OptionEnum::logprobs()->value,
			OptionEnum::topLogprobs()->value,
			OptionEnum::outputMimeType()->value,
			OptionEnum::outputSchema()->value,
			OptionEnum::functionDeclarations()->value,
			OptionEnum::customOptions()->value,
			OptionEnum::inputModalities()->value,
			OptionEnum::outputModalities()->value,
		);

		foreach ( $expected as $option ) {
			$this->assertContains( $option, $options, "{$model_id} should support the {$option} option." );
		}
	}

	/**
	 * Chat models offer both plain text and JSON output.
	 */
	public function test_chat_models_offer_json_and_plain_text_output(): void {
		$metadata = $this->model_metadata( 'gpt-oss-120b' );

		$this->assertSame(
			array( 'text/plain', 'application/json' ),
			$this->supported_option_values( $metadata, OptionEnum::outputMimeType() )
		);
	}

	/**
	 * The OCR model is image-in/text-out and offers no chat history.
	 */
	public function test_ocr_model_is_image_to_text_without_chat_history(): void {
		$metadata = $this->model_metadata( 'GLM-OCR' );

		$this->assertHasCapabilities( $metadata, array( CapabilityEnum::textGeneration() ) );
		$this->assertNotContains(
			CapabilityEnum::chatHistory()->value,
			$this->capability_values( $metadata ),
			'GLM-OCR is a one-shot OCR model and must not advertise chat history.'
		);
		$this->assertSame(
			array( array( 'text', 'image' ) ),
			$this->supported_option_values( $metadata, OptionEnum::inputModalities() )
		);
		$this->assertSame(
			array( array( 'text' ) ),
			$this->supported_option_values( $metadata, OptionEnum::outputModalities() )
		);
	}

	/**
	 * The OCR model exposes only the options it actually accepts.
	 */
	public function test_ocr_model_exposes_a_reduced_option_set(): void {
		$metadata = $this->model_metadata( 'GLM-OCR' );

		$this->assertSame(
			array(
				OptionEnum::maxTokens()->value,
				OptionEnum::inputModalities()->value,
				OptionEnum::outputModalities()->value,
				OptionEnum::customOptions()->value,
			),
			$this->supported_option_names( $metadata )
		);
	}

	/**
	 * The TTS model converts text into audio.
	 */
	public function test_tts_model_is_text_to_audio(): void {
		$metadata = $this->model_metadata( 'Qwen3-TTS-12Hz-1.7B-CustomVoice' );

		$this->assertHasCapabilities( $metadata, array( CapabilityEnum::textToSpeechConversion() ) );
		$this->assertSame(
			array( array( 'text' ) ),
			$this->supported_option_values( $metadata, OptionEnum::inputModalities() )
		);
		$this->assertSame(
			array( array( 'audio' ) ),
			$this->supported_option_values( $metadata, OptionEnum::outputModalities() )
		);
	}

	/**
	 * The advertised voices and formats match what the TTS model implements.
	 */
	public function test_tts_model_advertises_the_voices_and_formats_it_implements(): void {
		$metadata = $this->model_metadata( 'Qwen3-TTS-12Hz-1.7B-CustomVoice' );

		$this->assertSame(
			MittwaldTextToSpeechConversionModel::VOICES,
			$this->supported_option_values( $metadata, OptionEnum::outputSpeechVoice() )
		);
		$this->assertSame(
			array_keys( MittwaldTextToSpeechConversionModel::RESPONSE_FORMATS ),
			$this->supported_option_values( $metadata, OptionEnum::outputMimeType() )
		);
	}

	/**
	 * Models the plugin does not know about are listed without capabilities.
	 *
	 * They still need to appear in the directory: dropping them would make
	 * `hasModelMetadata()` lie about what the account can see.
	 *
	 * @param string $model_id Model ID.
	 *
	 * @dataProvider provide_unsupported_models
	 */
	#[DataProvider( 'provide_unsupported_models' )]
	public function test_unsupported_models_are_listed_without_capabilities( string $model_id ): void {
		$metadata = $this->model_metadata( $model_id );

		$this->assertSame( array(), $metadata->getSupportedCapabilities() );
		$this->assertSame( array(), $metadata->getSupportedOptions() );
	}

	/**
	 * Model IDs the plugin currently exposes no capabilities for.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_unsupported_models(): array {
		return array(
			array( 'Qwen3-Embedding-8B' ),
			array( 'Qwen3-VL-Reranker-2B' ),
			array( 'whisper-large-v3-turbo' ),
			array( 'some-model-released-tomorrow' ),
		);
	}

	/**
	 * Capability profiles a caller can ask the SDK for resolve to the right models.
	 *
	 * This is the check that matters to a site: the SDK picks models by matching
	 * `ModelRequirements`, so a capability or option that is missing from the
	 * metadata makes the model invisible for that use case.
	 *
	 * @param ModelRequirements $requirements Requirements to match.
	 * @param list<string>      $expected     Model IDs expected to match.
	 *
	 * @dataProvider provide_capability_profiles
	 */
	#[DataProvider( 'provide_capability_profiles' )]
	public function test_capability_profiles_resolve_to_the_expected_models(
		ModelRequirements $requirements,
		array $expected
	): void {
		$resolved = $this->resolve_model_metadata( ModelCatalogue::CURRENT );

		$matching = array();
		foreach ( $resolved as $model_id => $metadata ) {
			if ( $requirements->areMetBy( $metadata ) ) {
				$matching[] = $model_id;
			}
		}

		sort( $matching );
		sort( $expected );

		$this->assertSame( $expected, $matching );
	}

	/**
	 * Capability profiles and the models expected to satisfy them.
	 *
	 * @return array<string, array{ModelRequirements, list<string>}>
	 */
	public static function provide_capability_profiles(): array {
		$chat_models = array(
			'gpt-oss-120b',
			'Qwen3.5-0.8B',
			'Ministral-3-14B-Instruct-2512',
			'Qwen3.5-122B-A10B-FP8',
			'Qwen3.6-35B-A3B-FP8',
			'Qwen3.8-27B-NVFP4',
		);

		$vision_models = array(
			'Ministral-3-14B-Instruct-2512',
			'Qwen3.5-122B-A10B-FP8',
			'Qwen3.6-35B-A3B-FP8',
			'Qwen3.8-27B-NVFP4',
		);

		return array(
			'chat'      => array(
				new ModelRequirements(
					array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ),
					array()
				),
				$chat_models,
			),
			'text'      => array(
				new ModelRequirements( array( CapabilityEnum::textGeneration() ), array() ),
				array_merge( $chat_models, array( 'GLM-OCR' ) ),
			),
			'vision'    => array(
				new ModelRequirements(
					array( CapabilityEnum::textGeneration() ),
					array(
						new RequiredOption(
							OptionEnum::inputModalities(),
							array( ModalityEnum::text(), ModalityEnum::image() )
						),
					)
				),
				array_merge( $vision_models, array( 'GLM-OCR' ) ),
			),
			'json'      => array(
				new ModelRequirements(
					array( CapabilityEnum::textGeneration() ),
					array( new RequiredOption( OptionEnum::outputMimeType(), 'application/json' ) )
				),
				$chat_models,
			),
			'schema'    => array(
				new ModelRequirements(
					array( CapabilityEnum::textGeneration() ),
					array( new RequiredOption( OptionEnum::outputSchema(), array() ) )
				),
				$chat_models,
			),
			'tools'     => array(
				new ModelRequirements(
					array( CapabilityEnum::textGeneration() ),
					array( new RequiredOption( OptionEnum::functionDeclarations(), array() ) )
				),
				$chat_models,
			),
			'tts'       => array(
				new ModelRequirements( array( CapabilityEnum::textToSpeechConversion() ), array() ),
				array( 'Qwen3-TTS-12Hz-1.7B-CustomVoice' ),
			),
			'image'     => array(
				new ModelRequirements( array( CapabilityEnum::imageGeneration() ), array() ),
				array(),
			),
			'embedding' => array(
				new ModelRequirements( array( CapabilityEnum::embeddingGeneration() ), array() ),
				array(),
			),
		);
	}

	/**
	 * Asserts a model advertises exactly the given capabilities.
	 *
	 * @param ModelMetadata        $metadata     Metadata under test.
	 * @param list<CapabilityEnum> $capabilities Expected capabilities.
	 */
	private function assertHasCapabilities( ModelMetadata $metadata, array $capabilities ): void {
		$expected = array_map(
			static function ( CapabilityEnum $capability ): string {
				return $capability->value;
			},
			$capabilities
		);

		$this->assertSame( $expected, $this->capability_values( $metadata ) );
	}

	/**
	 * Returns a model's capabilities as plain strings.
	 *
	 * @param ModelMetadata $metadata Metadata to read.
	 *
	 * @return list<string>
	 */
	private function capability_values( ModelMetadata $metadata ): array {
		return array_map(
			static function ( CapabilityEnum $capability ): string {
				return $capability->value;
			},
			$metadata->getSupportedCapabilities()
		);
	}

	/**
	 * Returns the names of a model's supported options, in declaration order.
	 *
	 * @param ModelMetadata $metadata Metadata to read.
	 *
	 * @return list<string>
	 */
	private function supported_option_names( ModelMetadata $metadata ): array {
		return array_map(
			static function ( SupportedOption $option ): string {
				return $option->getName()->value;
			},
			$metadata->getSupportedOptions()
		);
	}

	/**
	 * Returns the values a model advertises for one supported option.
	 *
	 * @param ModelMetadata $metadata Metadata to read.
	 * @param OptionEnum    $option   Option to look up.
	 *
	 * @return list<mixed>
	 */
	private function supported_option_values( ModelMetadata $metadata, OptionEnum $option ): array {
		foreach ( $metadata->getSupportedOptions() as $supported ) {
			if ( ! $supported->getName()->equals( $option ) ) {
				continue;
			}

			$normalized = $this->normalize( $supported->getSupportedValues() ?? array() );

			$this->assertIsArray( $normalized );

			return array_values( $normalized );
		}

		$this->fail( sprintf( 'Model %s does not support the %s option.', $metadata->getId(), $option->value ) );
	}

	/**
	 * Reduces enum objects in supported values to their string values.
	 *
	 * @param mixed $value Value to normalise.
	 *
	 * @return mixed
	 */
	private function normalize( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'normalize' ), $value );
		}

		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}

		return $value;
	}
}
