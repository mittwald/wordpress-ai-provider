<?php
/**
 * Integration tests for image generation.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Integration;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldImageGenerationModel;
use Mittwald\AiProvider\Tests\Includes\IntegrationTestCase;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Exercises image generation against the real API.
 *
 * mittwald AI hosting does not currently offer an image generation model, so
 * these tests normally skip. They are kept because the plugin ships an image
 * model class, and they are what will confirm it works the day a model appears.
 */
final class ImageGenerationTest extends IntegrationTestCase {

	/**
	 * Returns the first image-capable model on offer, or null if there is none.
	 */
	private function image_model_metadata(): ?ModelMetadata {
		$requirements = new ModelRequirements( array( CapabilityEnum::imageGeneration() ), array() );

		foreach ( $this->available_models() as $metadata ) {
			if ( $requirements->areMetBy( $metadata ) ) {
				return $metadata;
			}
		}

		return null;
	}

	/**
	 * If an image model is on offer, it produces an image.
	 */
	public function test_an_image_model_produces_an_image(): void {
		$metadata = $this->image_model_metadata();

		if ( null === $metadata ) {
			$this->markTestSkipped(
				'mittwald AI hosting does not currently offer an image generation model. '
				. 'When one appears, add it to MittwaldModelMetadataDirectory and route it in '
				. 'MittwaldAIProvider::createModel(); this test then covers it.'
			);
		}

		$config = new ModelConfig();
		$config->setCandidateCount( 1 );

		$model = $this->model( $metadata->getId(), $config );

		$this->assertInstanceOf( MittwaldImageGenerationModel::class, $model );

		$result = $model->generateImageResult( $this->user_prompt( 'A single red circle on a white background.' ) );

		$file = $result->toImageFile();

		$this->assertTrue( $file->isImage() );
		$this->assertSame( 'mittwald', $result->getProviderMetadata()->getId() );
	}

	/**
	 * The provider does not route image-capable models to the wrong class.
	 *
	 * `MittwaldAIProvider::createModel()` currently has no image generation
	 * branch, so an image model appearing upstream would fail to instantiate.
	 * This test states that expectation either way.
	 */
	public function test_image_capable_models_are_routed_to_the_image_model(): void {
		$metadata = $this->image_model_metadata();

		if ( null === $metadata ) {
			$this->markTestSkipped( 'No image-capable model is currently on offer.' );
		}

		$model = $this->registry()->getProviderModel( MittwaldAIProvider::class, $metadata->getId() );

		$this->assertInstanceOf( MittwaldImageGenerationModel::class, $model );
	}
}
