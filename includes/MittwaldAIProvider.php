<?php

namespace Mittwald\AiProvider;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the OpenAI provider.
 *
 * @since 0.1.0
 */
class MittwaldAIProvider extends AbstractApiProvider {
	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected static function baseUrl(): string {
		return 'https://llm.aihosting.mittwald.de/v1';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createModel(
		ModelMetadata $modelMetadata,
		ProviderMetadata $providerMetadata
	): ModelInterface {
		$capabilities = $modelMetadata->getSupportedCapabilities();
		foreach ( $capabilities as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new MittwaldTextGenerationModel( $modelMetadata, $providerMetadata );
			}
			if ( $capability->isEmbeddingGeneration() ) {
				// TODO: Implement MittwaldEmbeddingConversionModel.
				throw new RuntimeException(
					'Mittwald embedding model class is not yet implemented.'
				);
			}
		}

		throw new RuntimeException(
			'Unsupported model capabilities: ' . implode( ', ', $capabilities )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$langUrlPart = str_starts_with( get_user_locale(), 'de' ) ? 'de/' : '';

		return new ProviderMetadata(
			'mittwald',
			'mittwald',
			ProviderTypeEnum::cloud(),
			'https://developer.mittwald.de/' . $langUrlPart . 'docs/v2/platform/aihosting/access-and-usage/access/',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		// Check valid API access by attempting to list models.
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new MittwaldModelMetadataDirectory();
	}
}
