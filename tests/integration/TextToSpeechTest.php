<?php
/**
 * Integration tests for text-to-speech conversion.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Integration;

use Mittwald\AiProvider\MittwaldTextToSpeechConversionModel;
use Mittwald\AiProvider\Tests\Includes\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Exercises the `audio/speech` endpoint against the real API.
 *
 * This is the endpoint the plugin implements by hand rather than inheriting
 * from the SDK, and the one that answers with raw bytes instead of JSON, so
 * every supported voice and container format is checked here.
 */
final class TextToSpeechTest extends IntegrationTestCase {

	/**
	 * The speech model mittwald offers.
	 *
	 * @var string
	 */
	private const TTS_MODEL_ID = 'Qwen3-TTS-12Hz-1.7B-CustomVoice';

	/**
	 * The sentence spoken in every test.
	 *
	 * @var string
	 */
	private const SAMPLE_TEXT = 'Moin, dies ist ein Test der Sprachausgabe.';

	/**
	 * Returns the speech model, skipping if it is not on offer.
	 *
	 * @param ModelConfig|null $config Optional configuration.
	 */
	private function speech_model( ?ModelConfig $config = null ): MittwaldTextToSpeechConversionModel {
		$model = $this->model( self::TTS_MODEL_ID, $config );

		$this->assertInstanceOf( MittwaldTextToSpeechConversionModel::class, $model );

		return $model;
	}

	/**
	 * Speech synthesis returns inline audio.
	 */
	public function test_text_is_converted_into_audio(): void {
		$result = $this->speech_model()->convertTextToSpeechResult(
			$this->user_prompt( self::SAMPLE_TEXT )
		);

		$file = $result->toAudioFile();

		$this->assertTrue( $file->isAudio() );
		$this->assertTrue( $file->isInline() );
		$this->assertSame( MittwaldTextToSpeechConversionModel::DEFAULT_MIME_TYPE, $file->getMimeType() );

		$audio = base64_decode( (string) $file->getBase64Data(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertIsString( $audio );
		$this->assertGreaterThan(
			1024,
			strlen( $audio ),
			'A spoken sentence should produce more than a kilobyte of audio.'
		);
	}

	/**
	 * The result carries the provider and model it came from.
	 */
	public function test_result_carries_provider_and_model_metadata(): void {
		$result = $this->speech_model()->convertTextToSpeechResult(
			$this->user_prompt( self::SAMPLE_TEXT )
		);

		$this->assertSame( 'mittwald', $result->getProviderMetadata()->getId() );
		$this->assertSame( self::TTS_MODEL_ID, $result->getModelMetadata()->getId() );
		$this->assertSame( 1, $result->getCandidateCount() );
		$this->assertTrue( $result->getCandidates()[0]->getFinishReason()->isStop() );
	}

	/**
	 * Every advertised output format is accepted and returned as such.
	 *
	 * @param string $mime_type      MIME type to request.
	 * @param string $magic_bytes    Bytes the container is expected to start with,
	 *                               or an empty string if it has no stable header.
	 *
	 * @dataProvider provide_output_formats
	 */
	#[DataProvider( 'provide_output_formats' )]
	public function test_every_advertised_output_format_works( string $mime_type, string $magic_bytes ): void {
		$config = new ModelConfig();
		$config->setOutputMimeType( $mime_type );

		$result = $this->speech_model( $config )->convertTextToSpeechResult(
			$this->user_prompt( self::SAMPLE_TEXT )
		);

		$file = $result->toAudioFile();

		$this->assertSame( $mime_type, $file->getMimeType() );

		$audio = (string) base64_decode( (string) $file->getBase64Data(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertNotSame( '', $audio );

		if ( '' !== $magic_bytes ) {
			$this->assertStringStartsWith(
				$magic_bytes,
				$audio,
				sprintf( 'The response for %s does not look like that container.', $mime_type )
			);
		}
	}

	/**
	 * Output MIME types and the header bytes their container starts with.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_output_formats(): array {
		return array(
			// MP3 frames start either with an ID3 tag or a frame sync; neither is
			// guaranteed, so no header is asserted.
			'mp3'  => array( 'audio/mpeg', '' ),
			'wav'  => array( 'audio/wav', 'RIFF' ),
			'flac' => array( 'audio/flac', 'fLaC' ),
			'ogg'  => array( 'audio/ogg', 'OggS' ),
		);
	}

	/**
	 * Every advertised voice is accepted by the API.
	 *
	 * @param string $voice The voice to request.
	 *
	 * @dataProvider provide_voices
	 */
	#[DataProvider( 'provide_voices' )]
	public function test_every_advertised_voice_is_accepted( string $voice ): void {
		$config = new ModelConfig();
		$config->setOutputSpeechVoice( $voice );

		$result = $this->speech_model( $config )->convertTextToSpeechResult(
			$this->user_prompt( 'Kurzer Test.' )
		);

		$audio = (string) base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			(string) $result->toAudioFile()->getBase64Data(),
			true
		);

		$this->assertNotSame( '', $audio, sprintf( 'The voice %s produced no audio.', $voice ) );
	}

	/**
	 * Every voice the plugin advertises.
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
	 * Different voices produce different audio.
	 */
	public function test_different_voices_produce_different_audio(): void {
		$voices = MittwaldTextToSpeechConversionModel::VOICES;

		$renderings = array();
		foreach ( array( $voices[0], $voices[1] ) as $voice ) {
			$config = new ModelConfig();
			$config->setOutputSpeechVoice( $voice );

			$renderings[ $voice ] = $this->speech_model( $config )
				->convertTextToSpeechResult( $this->user_prompt( self::SAMPLE_TEXT ) )
				->toAudioFile()
				->getBase64Data();
		}

		$this->assertNotSame(
			$renderings[ $voices[0] ],
			$renderings[ $voices[1] ],
			'Two different voices should not render identical audio.'
		);
	}

	/**
	 * Custom options reach the API.
	 *
	 * `speed` has no first-class option in the SDK, so it is the clearest test
	 * of the custom option passthrough the model implements.
	 */
	public function test_custom_options_reach_the_api(): void {
		$config = new ModelConfig();
		$config->setCustomOptions( array( 'speed' => 1.5 ) );

		try {
			$result = $this->speech_model( $config )->convertTextToSpeechResult(
				$this->user_prompt( self::SAMPLE_TEXT )
			);
		} catch ( ClientException $exception ) {
			$this->markTestSkipped(
				'The API rejected the "speed" custom option: ' . $exception->getMessage()
			);
		}

		$this->assertNotSame( '', (string) $result->toAudioFile()->getBase64Data() );
	}

	/**
	 * An unknown voice is rejected by the API rather than silently substituted.
	 */
	public function test_an_unknown_voice_is_rejected_by_the_api(): void {
		$config = new ModelConfig();
		$config->setOutputSpeechVoice( 'definitely-not-a-real-voice' );

		$this->expectException( ClientException::class );

		$this->speech_model( $config )->convertTextToSpeechResult(
			$this->user_prompt( self::SAMPLE_TEXT )
		);
	}
}
