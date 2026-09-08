<?php
/**
 * Integration tests for provider availability and the model catalogue.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Integration;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\Tests\Includes\IntegrationTestCase;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Checks that the provider can reach mittwald AI hosting and read its catalogue.
 *
 * These are the cheapest integration tests: they list models rather than
 * generating anything, so they are the first place to look when the whole
 * integration suite starts failing.
 */
final class ProviderAvailabilityTest extends IntegrationTestCase {

	/**
	 * A valid API key makes the provider report itself as configured.
	 */
	public function test_provider_reports_itself_as_configured(): void {
		$this->registry();

		$this->assertTrue(
			MittwaldAIProvider::availability()->isConfigured(),
			'The provider should be configured once a valid API key is set.'
		);
	}

	/**
	 * The API returns a non-empty model catalogue.
	 */
	public function test_api_returns_a_model_catalogue(): void {
		$model_ids = $this->available_model_ids();

		$this->assertNotEmpty( $model_ids, 'mittwald AI hosting should offer at least one model.' );
	}

	/**
	 * Every model the API reports is retrievable by ID.
	 */
	public function test_every_listed_model_is_retrievable_by_id(): void {
		$directory = MittwaldAIProvider::modelMetadataDirectory();
		$this->registry();

		foreach ( $this->available_model_ids() as $model_id ) {
			$this->assertTrue( $directory->hasModelMetadata( $model_id ) );
			$this->assertSame( $model_id, $directory->getModelMetadata( $model_id )->getId() );
		}
	}

	/**
	 * At least one model can be used for chat.
	 */
	public function test_a_chat_capable_model_is_on_offer(): void {
		$requirements = new ModelRequirements(
			array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ),
			array()
		);

		$this->assertNotEmpty(
			$this->models_matching( $requirements ),
			'No model on offer supports chat; check the capability table in MittwaldModelMetadataDirectory.'
		);
	}

	/**
	 * At least one model accepts image input.
	 */
	public function test_a_vision_capable_model_is_on_offer(): void {
		$requirements = new ModelRequirements(
			array( CapabilityEnum::textGeneration() ),
			array(
				new RequiredOption(
					OptionEnum::inputModalities(),
					array( ModalityEnum::text(), ModalityEnum::image() )
				),
			)
		);

		$this->assertNotEmpty(
			$this->models_matching( $requirements ),
			'No model on offer accepts image input.'
		);
	}

	/**
	 * At least one model can synthesise speech.
	 */
	public function test_a_speech_capable_model_is_on_offer(): void {
		$requirements = new ModelRequirements(
			array( CapabilityEnum::textToSpeechConversion() ),
			array()
		);

		$this->assertNotEmpty(
			$this->models_matching( $requirements ),
			'No model on offer supports text-to-speech conversion.'
		);
	}

	/**
	 * Reports models the API offers that the plugin has no entry for.
	 *
	 * A model with no capabilities is invisible to a site, so this is worth
	 * surfacing. It is reported as incomplete rather than failed: mittwald
	 * adding a model upstream is not a defect in this plugin, and it should not
	 * turn the build red on somebody else's release.
	 *
	 * The one genuine failure here is the plugin knowing about nothing at all,
	 * which means the catalogue in `MittwaldModelMetadataDirectory` has gone
	 * stale wholesale.
	 */
	public function test_offered_models_are_known_to_the_plugin(): void {
		$known   = array();
		$unknown = array();

		foreach ( $this->available_models() as $metadata ) {
			if ( array() === $metadata->getSupportedCapabilities() ) {
				$unknown[] = $metadata->getId();
				continue;
			}

			$known[] = $metadata->getId();
		}

		$this->assertNotEmpty(
			$known,
			'The plugin recognises none of the models on offer; the catalogue in '
			. 'MittwaldModelMetadataDirectory has gone stale entirely.'
		);

		if ( array() !== $unknown ) {
			$this->markTestIncomplete(
				'mittwald AI hosting offers models this plugin has no entry for, so no site can '
				. 'use them yet. Give them capabilities in MittwaldModelMetadataDirectory and '
				. 'route them in MittwaldAIProvider::createModel(): ' . implode( ', ', $unknown )
			);
		}
	}

	/**
	 * An invalid API key is reported as such rather than silently accepted.
	 */
	public function test_an_invalid_api_key_is_not_reported_as_configured(): void {
		$registry = $this->registry();

		$registry->setProviderRequestAuthentication(
			MittwaldAIProvider::class,
			new ApiKeyRequestAuthentication( 'definitely-not-a-valid-key' )
		);

		try {
			$this->invalidate_model_cache();

			$this->assertFalse(
				MittwaldAIProvider::availability()->isConfigured(),
				'A bogus API key should not pass the availability check.'
			);
		} finally {
			// Put the real key back and drop anything cached under the bogus one.
			$registry->setProviderRequestAuthentication(
				MittwaldAIProvider::class,
				new ApiKeyRequestAuthentication( (string) self::api_key() )
			);
			$this->invalidate_model_cache();
		}
	}

	/**
	 * Returns the IDs of offered models that satisfy the given requirements.
	 *
	 * @param ModelRequirements $requirements Requirements to match.
	 *
	 * @return list<string>
	 */
	private function models_matching( ModelRequirements $requirements ): array {
		$matching = array();

		foreach ( $this->available_models() as $metadata ) {
			if ( $requirements->areMetBy( $metadata ) ) {
				$matching[] = $metadata->getId();
			}
		}

		return $matching;
	}
}
