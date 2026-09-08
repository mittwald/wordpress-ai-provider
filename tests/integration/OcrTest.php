<?php
/**
 * Integration tests for the OCR model.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Integration;

use Mittwald\AiProvider\MittwaldTextGenerationModel;
use Mittwald\AiProvider\Tests\Includes\IntegrationTestCase;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Exercises GLM-OCR against the real API.
 *
 * The OCR model is configured with a deliberately reduced option set, so this
 * also checks that the smaller set is enough to drive it.
 */
final class OcrTest extends IntegrationTestCase {

	/**
	 * The OCR model mittwald offers.
	 *
	 * @var string
	 */
	private const OCR_MODEL_ID = 'GLM-OCR';

	/**
	 * Returns the OCR model, skipping if it is not on offer.
	 *
	 * @param ModelConfig|null $config Optional configuration.
	 */
	private function ocr_model( ?ModelConfig $config = null ): MittwaldTextGenerationModel {
		$model = $this->model( self::OCR_MODEL_ID, $config );

		$this->assertInstanceOf( MittwaldTextGenerationModel::class, $model );

		return $model;
	}

	/**
	 * The OCR model transcribes text out of an image.
	 */
	public function test_ocr_model_transcribes_text_from_an_image(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 256 );

		$model = $this->ocr_model( $config );

		$image = new File(
			base64_encode( $this->fixture( 'ocr-sample.png' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'image/png'
		);

		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array(
					new MessagePart( 'Transcribe all text in this image.' ),
					new MessagePart( $image ),
				)
			),
		);

		$result = $model->generateTextResult( $prompt );

		$text = $result->toText();

		$this->assertNotSame( '', trim( $text ) );
		$this->assertStringContainsStringIgnoringCase( 'mittwald', $text );
		$this->assertStringContainsStringIgnoringCase( 'hosting', $text );
	}

	/**
	 * The OCR model reports token usage like any other completion.
	 */
	public function test_ocr_results_carry_token_usage_and_model_metadata(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 128 );

		$model = $this->ocr_model( $config );

		$image = new File(
			base64_encode( $this->fixture( 'ocr-sample.png' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'image/png'
		);

		$result = $model->generateTextResult(
			array(
				new Message(
					MessageRoleEnum::user(),
					array(
						new MessagePart( 'Transcribe all text in this image.' ),
						new MessagePart( $image ),
					)
				),
			)
		);

		$this->assertSame( self::OCR_MODEL_ID, $result->getModelMetadata()->getId() );
		$this->assertSame( 'mittwald', $result->getProviderMetadata()->getId() );
		$this->assertGreaterThan( 0, $result->getTokenUsage()->getPromptTokens() );
	}
}
