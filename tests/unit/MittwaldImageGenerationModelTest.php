<?php
/**
 * Tests for the image generation model.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldImageGenerationModel;
use Mittwald\AiProvider\Tests\Includes\FakeHttpTransporter;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Covers the `gpt-image-*` parameter handling the plugin adds.
 *
 * mittwald AI hosting does not currently offer an image generation model, so
 * this class is not reachable through `MittwaldAIProvider::createModel()`. It is
 * still tested directly, because the parameter juggling it does is the reason
 * the class exists and would be easy to break unnoticed.
 */
final class MittwaldImageGenerationModelTest extends TestCase {

	/**
	 * The transporter the model under test writes to.
	 *
	 * @var FakeHttpTransporter
	 */
	private FakeHttpTransporter $transporter;

	/**
	 * Prepares a fresh transporter for each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->transporter = new FakeHttpTransporter();
	}

	/**
	 * Builds a wired-up image generation model.
	 *
	 * @param string           $model_id Model ID to use.
	 * @param ModelConfig|null $config   Optional configuration.
	 */
	private function model( string $model_id, ?ModelConfig $config = null ): MittwaldImageGenerationModel {
		$metadata = new ModelMetadata(
			$model_id,
			$model_id,
			array( CapabilityEnum::imageGeneration() ),
			array(
				new SupportedOption( OptionEnum::outputMimeType(), array( 'image/png', 'image/webp' ) ),
				new SupportedOption( OptionEnum::outputFileType() ),
				new SupportedOption( OptionEnum::candidateCount() ),
				new SupportedOption( OptionEnum::customOptions() ),
			)
		);

		$model = new MittwaldImageGenerationModel( $metadata, MittwaldAIProvider::metadata() );

		if ( null !== $config ) {
			$model->setConfig( $config );
		}

		return $this->wire( $model, $this->transporter );
	}

	/**
	 * Builds a single user message.
	 *
	 * @param string $text Message text.
	 *
	 * @return list<Message>
	 */
	private function user_prompt( string $text ): array {
		return array( new Message( MessageRoleEnum::user(), array( new MessagePart( $text ) ) ) );
	}

	/**
	 * Queues a minimal successful image generation response.
	 */
	private function queue_image(): void {
		$this->transporter->queue_json(
			array(
				'created' => 1735689600,
				'data'    => array(
					array( 'b64_json' => self::red_dot_png_base64() ),
				),
			)
		);
	}

	/**
	 * Image requests are posted to the provider's own image endpoint.
	 */
	public function test_images_are_posted_to_the_mittwald_endpoint(): void {
		$this->queue_image();

		$this->model( 'some-image-model' )->generateImageResult( $this->user_prompt( 'A red dot' ) );

		$request = $this->transporter->last_request();

		$this->assertSame( 'https://llm.aihosting.mittwald.de/v1/images/generations', $request->getUri() );
		$this->assertTrue( $request->getMethod()->equals( HttpMethodEnum::POST() ) );
		$this->assertSame( 'Bearer ' . self::TEST_API_KEY, $request->getHeaderAsString( 'Authorization' ) );
	}

	/**
	 * Request options set on the model are carried into the request.
	 */
	public function test_request_options_are_passed_through(): void {
		$this->queue_image();

		$request_options = new RequestOptions();
		$request_options->setTimeout( 90.0 );

		$model = $this->model( 'some-image-model' );
		$model->setRequestOptions( $request_options );
		$model->generateImageResult( $this->user_prompt( 'A red dot' ) );

		$options = $this->transporter->last_request()->getOptions();

		$this->assertNotNull( $options );
		$this->assertSame( 90.0, $options->getTimeout() );
	}

	/**
	 * `gpt-image-*` models always return base64 and take an output format.
	 *
	 * @param string $model_id A model ID from the gpt-image family.
	 *
	 * @dataProvider provide_gpt_image_models
	 */
	#[DataProvider( 'provide_gpt_image_models' )]
	public function test_gpt_image_models_take_output_format_and_no_response_format( string $model_id ): void {
		$this->queue_image();

		$config = new ModelConfig();
		$config->setOutputMimeType( 'image/webp' );
		$config->setOutputFileType( FileTypeEnum::inline() );

		$this->model( $model_id, $config )->generateImageResult( $this->user_prompt( 'A red dot' ) );

		$payload = $this->transporter->last_request_payload();

		$this->assertArrayNotHasKey(
			'response_format',
			$payload,
			'gpt-image models reject response_format and always answer with base64.'
		);
		$this->assertSame( 'webp', $payload['output_format'] );
	}

	/**
	 * Model IDs from the gpt-image family.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_gpt_image_models(): array {
		return array(
			array( 'gpt-image-1' ),
			array( 'gpt-image-1-mini' ),
			array( 'gpt-image-2' ),
		);
	}

	/**
	 * A gpt-image model drops `response_format` even without an output MIME type.
	 */
	public function test_gpt_image_models_drop_response_format_without_a_mime_type(): void {
		$this->queue_image();

		$this->model( 'gpt-image-1' )->generateImageResult( $this->user_prompt( 'A red dot' ) );

		$payload = $this->transporter->last_request_payload();

		$this->assertArrayNotHasKey( 'response_format', $payload );
		$this->assertArrayNotHasKey( 'output_format', $payload );
	}

	/**
	 * Older image models take a response format and no output format.
	 *
	 * @param string $model_id A model ID outside the gpt-image family.
	 *
	 * @dataProvider provide_non_gpt_image_models
	 */
	#[DataProvider( 'provide_non_gpt_image_models' )]
	public function test_other_models_take_response_format_and_no_output_format( string $model_id ): void {
		$this->queue_image();

		$config = new ModelConfig();
		$config->setOutputMimeType( 'image/png' );

		$this->model( $model_id, $config )->generateImageResult( $this->user_prompt( 'A red dot' ) );

		$payload = $this->transporter->last_request_payload();

		$this->assertSame( 'b64_json', $payload['response_format'] );
		$this->assertArrayNotHasKey(
			'output_format',
			$payload,
			'Only gpt-image models understand output_format.'
		);
	}

	/**
	 * Model IDs outside the gpt-image family.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_non_gpt_image_models(): array {
		return array(
			array( 'dall-e-3' ),
			array( 'FLUX.1-schnell' ),
			// Close enough to trip a naive prefix check, but not part of the family.
			array( 'gpt-image' ),
			array( 'my-gpt-image-1' ),
		);
	}

	/**
	 * A remote output file type still asks for a URL on non-gpt-image models.
	 */
	public function test_remote_output_file_type_requests_a_url(): void {
		$this->queue_image();

		$config = new ModelConfig();
		$config->setOutputFileType( FileTypeEnum::remote() );

		$this->model( 'dall-e-3', $config )->generateImageResult( $this->user_prompt( 'A red dot' ) );

		$this->assertSame( 'url', $this->transporter->last_request_payload()['response_format'] );
	}

	/**
	 * The generated image is returned as an inline file.
	 */
	public function test_generated_image_is_returned_as_a_file(): void {
		$this->queue_image();

		$result = $this->model( 'gpt-image-1' )->generateImageResult( $this->user_prompt( 'A red dot' ) );

		$file = $result->toImageFile();

		$this->assertTrue( $file->isImage() );
		$this->assertSame( self::red_dot_png_base64(), $file->getBase64Data() );
	}

	/**
	 * A 1x1 red PNG, base64 encoded.
	 */
	private static function red_dot_png_base64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}
}
