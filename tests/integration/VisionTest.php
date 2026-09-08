<?php
/**
 * Integration tests for image input.
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
 * Exercises the vision-capable chat models against the real API.
 *
 * Image input travels as a base64 data URI inside the chat completion request,
 * which is a different request shape from a plain text prompt, so it is worth
 * confirming against the API rather than only against a fake transport.
 */
final class VisionTest extends IntegrationTestCase {

	/**
	 * Vision-capable models to try, in order of preference.
	 *
	 * @var list<string>
	 */
	private const VISION_MODELS = array(
		'Qwen3.5-122B-A10B-FP8',
		'Qwen3.6-35B-A3B-FP8',
		'Qwen3.8-27B-NVFP4',
		'Ministral-3-14B-Instruct-2512',
	);

	/**
	 * Builds a prompt containing a question and an image.
	 *
	 * @param string $question   The question to ask about the image.
	 * @param string $image_name Fixture file name.
	 *
	 * @return list<Message>
	 */
	private function image_prompt( string $question, string $image_name ): array {
		$image = new File(
			base64_encode( $this->fixture( $image_name ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'image/png'
		);

		return array(
			new Message(
				MessageRoleEnum::user(),
				array(
					new MessagePart( $question ),
					new MessagePart( $image ),
				)
			),
		);
	}

	/**
	 * Returns a vision-capable model, skipping if none is on offer.
	 *
	 * @param ModelConfig|null $config Optional configuration.
	 */
	private function vision_model( ?ModelConfig $config = null ): MittwaldTextGenerationModel {
		$model = $this->first_available_model( self::VISION_MODELS, $config );

		$this->assertInstanceOf( MittwaldTextGenerationModel::class, $model );

		return $model;
	}

	/**
	 * A vision model can describe an image it is given.
	 */
	public function test_a_vision_model_can_read_an_image(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 128 );
		$config->setTemperature( 0.0 );

		$model = $this->vision_model( $config );

		$result = $model->generateTextResult(
			$this->image_prompt(
				'What shape and colour is the object in this image? Answer in a few words.',
				'red-circle.png'
			)
		);

		$text = strtolower( $result->toText() );

		$this->assertNotSame( '', trim( $text ) );
		$this->assertMatchesRegularExpression(
			'/red|rot/',
			$text,
			'A vision model should notice that the circle is red. Got: ' . $result->toText()
		);
	}

	/**
	 * A vision model can read text out of an image.
	 */
	public function test_a_vision_model_can_read_text_in_an_image(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 128 );
		$config->setTemperature( 0.0 );

		$model = $this->vision_model( $config );

		$result = $model->generateTextResult(
			$this->image_prompt( 'Transcribe the text in this image, verbatim.', 'ocr-sample.png' )
		);

		$this->assertStringContainsStringIgnoringCase( 'mittwald', $result->toText() );
	}

	/**
	 * Text and image parts can be mixed with earlier conversation turns.
	 */
	public function test_an_image_can_be_used_within_a_conversation(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 128 );
		$config->setTemperature( 0.0 );

		$model = $this->vision_model( $config );

		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array( new MessagePart( 'I am going to show you an image next.' ) )
			),
			new Message(
				MessageRoleEnum::model(),
				array( new MessagePart( 'Understood, please go ahead.' ) )
			),
			$this->image_prompt( 'What colour dominates this image? One word.', 'red-circle.png' )[0],
		);

		$result = $model->generateTextResult( $prompt );

		$this->assertMatchesRegularExpression( '/red|rot/i', $result->toText() );
	}
}
