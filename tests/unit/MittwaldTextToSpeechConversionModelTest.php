<?php
/**
 * Tests for the text-to-speech conversion model.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldTextToSpeechConversionModel;
use Mittwald\AiProvider\Tests\Includes\FakeHttpTransporter;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Covers the `audio/speech` implementation the plugin writes by hand.
 *
 * The SDK has no OpenAI-compatible base class for text-to-speech, so unlike the
 * other model classes this one owns its whole request/response cycle.
 */
final class MittwaldTextToSpeechConversionModelTest extends TestCase {

	/**
	 * The model ID mittwald offers for speech synthesis.
	 *
	 * @var string
	 */
	private const TTS_MODEL_ID = 'Qwen3-TTS-12Hz-1.7B-CustomVoice';

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
	 * Builds a wired-up speech conversion model.
	 *
	 * @param ModelConfig|null $config Optional configuration.
	 */
	private function model( ?ModelConfig $config = null ): MittwaldTextToSpeechConversionModel {
		$model = new MittwaldTextToSpeechConversionModel(
			$this->model_metadata( self::TTS_MODEL_ID ),
			MittwaldAIProvider::metadata()
		);

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
	 * Queues a successful audio response.
	 *
	 * @param string $mime_type The content type to answer with.
	 */
	private function queue_audio( string $mime_type = 'audio/mpeg' ): string {
		$audio = "ID3\x04\x00\x00\x00fake audio bytes\x00\xff";
		$this->transporter->queue_raw( $audio, $mime_type );

		return $audio;
	}

	/**
	 * Speech requests are posted to the provider's own speech endpoint.
	 */
	public function test_speech_is_posted_to_the_mittwald_endpoint(): void {
		$this->queue_audio();

		$this->model()->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$request = $this->transporter->last_request();

		$this->assertSame( 'https://llm.aihosting.mittwald.de/v1/audio/speech', $request->getUri() );
		$this->assertTrue( $request->getMethod()->equals( HttpMethodEnum::POST() ) );
		$this->assertSame( 'application/json', $request->getHeaderAsString( 'Content-Type' ) );
		$this->assertSame( 'Bearer ' . self::TEST_API_KEY, $request->getHeaderAsString( 'Authorization' ) );
	}

	/**
	 * Without configuration, the API's required parameters are still filled in.
	 */
	public function test_defaults_fill_in_the_required_parameters(): void {
		$this->queue_audio();

		$this->model()->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$this->assertSame(
			array(
				'model'           => self::TTS_MODEL_ID,
				'input'           => 'Moin.',
				'voice'           => MittwaldTextToSpeechConversionModel::DEFAULT_VOICE,
				'response_format' => 'mp3',
			),
			$this->transporter->last_request_payload()
		);
	}

	/**
	 * A configured voice is used instead of the default.
	 *
	 * @param string $voice A voice the API supports.
	 *
	 * @dataProvider provide_voices
	 */
	#[DataProvider( 'provide_voices' )]
	public function test_configured_voice_is_used( string $voice ): void {
		$this->queue_audio();

		$config = new ModelConfig();
		$config->setOutputSpeechVoice( $voice );

		$this->model( $config )->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$this->assertSame( $voice, $this->transporter->last_request_payload()['voice'] );
	}

	/**
	 * Every voice the model class advertises.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_voices(): array {
		return array_map(
			static function ( string $voice ): array {
				return array( $voice );
			},
			MittwaldTextToSpeechConversionModel::VOICES
		);
	}

	/**
	 * Output MIME types are translated to the API's format names.
	 *
	 * @param string $mime_type       The requested MIME type.
	 * @param string $response_format The format name the API expects.
	 *
	 * @dataProvider provide_mime_types
	 */
	#[DataProvider( 'provide_mime_types' )]
	public function test_mime_types_map_to_api_response_formats( string $mime_type, string $response_format ): void {
		$this->queue_audio( $mime_type );

		$config = new ModelConfig();
		$config->setOutputMimeType( $mime_type );

		$result = $this->model( $config )->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$this->assertSame( $response_format, $this->transporter->last_request_payload()['response_format'] );
		$this->assertSame( $mime_type, $result->toAudioFile()->getMimeType() );
	}

	/**
	 * Supported MIME types and the API format name each maps to.
	 *
	 * @return list<array{string, string}>
	 */
	public static function provide_mime_types(): array {
		$cases = array();
		foreach ( MittwaldTextToSpeechConversionModel::RESPONSE_FORMATS as $mime_type => $response_format ) {
			$cases[] = array( $mime_type, $response_format );
		}

		return $cases;
	}

	/**
	 * MIME types are matched case-insensitively.
	 */
	public function test_mime_types_are_matched_case_insensitively(): void {
		$this->queue_audio( 'audio/wav' );

		$config = new ModelConfig();
		$config->setOutputMimeType( 'AUDIO/WAV' );

		$this->model( $config )->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$this->assertSame( 'wav', $this->transporter->last_request_payload()['response_format'] );
	}

	/**
	 * An unsupported MIME type is rejected before a request is made.
	 */
	public function test_unsupported_mime_type_is_rejected(): void {
		$config = new ModelConfig();
		$config->setOutputMimeType( 'audio/aac' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'audio/aac' );

		try {
			$this->model( $config )->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );
		} finally {
			$this->assertSame( 0, $this->transporter->request_count() );
		}
	}

	/**
	 * Custom options are merged into the request.
	 *
	 * They are how a site reaches parameters the SDK has no first-class option
	 * for, such as `speed` or `language`.
	 */
	public function test_custom_options_are_merged_into_the_request(): void {
		$this->queue_audio();

		$config = new ModelConfig();
		$config->setCustomOptions(
			array(
				'speed'    => 1.25,
				'language' => 'de',
			)
		);

		$this->model( $config )->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$payload = $this->transporter->last_request_payload();

		$this->assertSame( 1.25, $payload['speed'] );
		$this->assertSame( 'de', $payload['language'] );
	}

	/**
	 * A custom option cannot silently overwrite a parameter the model owns.
	 *
	 * @param string $option A parameter name the model sets itself.
	 *
	 * @dataProvider provide_reserved_parameters
	 */
	#[DataProvider( 'provide_reserved_parameters' )]
	public function test_custom_options_cannot_override_owned_parameters( string $option ): void {
		$config = new ModelConfig();
		$config->setCustomOptions( array( $option => 'something else' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $option );

		$this->model( $config )->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );
	}

	/**
	 * Parameters the model sets itself.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_reserved_parameters(): array {
		return array(
			array( 'model' ),
			array( 'input' ),
			array( 'voice' ),
			array( 'response_format' ),
		);
	}

	/**
	 * All text parts of the message are joined into the input.
	 */
	public function test_multiple_text_parts_are_joined(): void {
		$this->queue_audio();

		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array(
					new MessagePart( 'Moin.' ),
					new MessagePart( 'Wie geht es dir?' ),
				)
			),
		);

		$this->model()->convertTextToSpeechResult( $prompt );

		$this->assertSame(
			"Moin.\nWie geht es dir?",
			$this->transporter->last_request_payload()['input']
		);
	}

	/**
	 * Non-text parts are ignored as long as some text remains.
	 */
	public function test_non_text_parts_are_ignored(): void {
		$this->queue_audio();

		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array(
					new MessagePart( 'Moin.' ),
					new MessagePart( new File( 'aGVsbG8=', 'image/png' ) ),
				)
			),
		);

		$this->model()->convertTextToSpeechResult( $prompt );

		$this->assertSame( 'Moin.', $this->transporter->last_request_payload()['input'] );
	}

	/**
	 * The endpoint takes exactly one message.
	 */
	public function test_a_multi_message_prompt_is_rejected(): void {
		$prompt = array(
			new Message( MessageRoleEnum::user(), array( new MessagePart( 'Moin.' ) ) ),
			new Message( MessageRoleEnum::user(), array( new MessagePart( 'Und tschüss.' ) ) ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'single user message' );

		$this->model()->convertTextToSpeechResult( $prompt );
	}

	/**
	 * An empty prompt is rejected.
	 */
	public function test_an_empty_prompt_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'single user message' );

		$this->model()->convertTextToSpeechResult( array() );
	}

	/**
	 * The endpoint takes a user message, not a model one.
	 */
	public function test_a_non_user_message_is_rejected(): void {
		$prompt = array(
			new Message( MessageRoleEnum::model(), array( new MessagePart( 'Moin.' ) ) ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'user message' );

		$this->model()->convertTextToSpeechResult( $prompt );
	}

	/**
	 * A message without any text is rejected.
	 */
	public function test_a_message_without_text_is_rejected(): void {
		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array( new MessagePart( new File( 'aGVsbG8=', 'image/png' ) ) )
			),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'at least one text message part' );

		$this->model()->convertTextToSpeechResult( $prompt );
	}

	/**
	 * The raw audio body is returned as an inline audio file.
	 *
	 * The endpoint answers with audio bytes rather than JSON, so the model has
	 * to base64 encode them itself before handing them to the SDK's file object.
	 */
	public function test_audio_body_is_returned_as_an_inline_file(): void {
		$audio = $this->queue_audio();

		$result = $this->model()->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$file = $result->toAudioFile();

		$this->assertTrue( $file->isAudio() );
		$this->assertTrue( $file->isInline() );
		$this->assertSame( 'audio/mpeg', $file->getMimeType() );
		// The model base64 encodes the audio bytes for transport, not to obfuscate them.
		$this->assertSame( base64_encode( $audio ), $file->getBase64Data() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * The result carries the provider and model it came from.
	 */
	public function test_result_carries_provider_and_model_metadata(): void {
		$this->queue_audio();

		$result = $this->model()->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$this->assertSame( 'mittwald', $result->getProviderMetadata()->getId() );
		$this->assertSame( self::TTS_MODEL_ID, $result->getModelMetadata()->getId() );
		$this->assertSame( 1, $result->getCandidateCount() );
		$this->assertTrue( $result->getCandidates()[0]->getFinishReason()->isStop() );
		$this->assertTrue( $result->getCandidates()[0]->getMessage()->getRole()->isModel() );
	}

	/**
	 * The endpoint reports no token usage, so the result reports none either.
	 */
	public function test_token_usage_is_reported_as_zero(): void {
		$this->queue_audio();

		$usage = $this->model()->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) )->getTokenUsage();

		$this->assertSame( 0, $usage->getPromptTokens() );
		$this->assertSame( 0, $usage->getCompletionTokens() );
		$this->assertSame( 0, $usage->getTotalTokens() );
	}

	/**
	 * An empty body is an error, not an empty audio file.
	 */
	public function test_an_empty_response_body_is_rejected(): void {
		$this->transporter->queue_raw( '', 'audio/mpeg' );

		$this->expectException( ResponseException::class );

		$this->model()->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );
	}

	/**
	 * An API error is surfaced rather than treated as audio.
	 */
	public function test_api_errors_are_surfaced(): void {
		$this->transporter->queue_json(
			array( 'error' => array( 'message' => 'voice not available' ) ),
			400
		);

		$this->expectException( ClientException::class );
		$this->expectExceptionMessage( 'voice not available' );

		$this->model()->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );
	}

	/**
	 * Request options set on the model are carried into the request.
	 */
	public function test_request_options_are_passed_through(): void {
		$this->queue_audio();

		$request_options = new RequestOptions();
		$request_options->setTimeout( 120.0 );

		$model = $this->model();
		$model->setRequestOptions( $request_options );
		$model->convertTextToSpeechResult( $this->user_prompt( 'Moin.' ) );

		$options = $this->transporter->last_request()->getOptions();

		$this->assertNotNull( $options );
		$this->assertSame( 120.0, $options->getTimeout() );
	}

	/**
	 * The default MIME type is one the model can actually map.
	 */
	public function test_default_mime_type_is_a_supported_one(): void {
		$this->assertArrayHasKey(
			MittwaldTextToSpeechConversionModel::DEFAULT_MIME_TYPE,
			MittwaldTextToSpeechConversionModel::RESPONSE_FORMATS
		);
	}

	/**
	 * The default voice is one the model advertises.
	 */
	public function test_default_voice_is_an_advertised_one(): void {
		$this->assertContains(
			MittwaldTextToSpeechConversionModel::DEFAULT_VOICE,
			MittwaldTextToSpeechConversionModel::VOICES
		);
	}
}
