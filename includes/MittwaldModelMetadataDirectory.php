<?php

declare( strict_types=1 );

namespace Mittwald\AiProvider;

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Class for the OpenAI model metadata directory.
 *
 * @since 0.1.0
 *
 * @phpstan-type ModelsResponseData array{
 *     data?: list<array{id: string}>
 * }
 */
class MittwaldModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {
	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			MittwaldAIProvider::url( $path ),
			$headers,
			$data
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ResponseException
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		/** @var ModelsResponseData $responseData *///phpcs:ignore
		$responseData = $response->getData();
		if ( ! isset( $responseData['data'] ) || ! $responseData['data'] ) {
			throw ResponseException::fromMissingData( 'OpenAI', 'data' );
		}

		// Unfortunately, the OpenAI API does not return model capabilities, so we have to hardcode them here.
		$gptCapabilities           = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);
		$gptBaseOptions            = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::logprobs() ),
			new SupportedOption( OptionEnum::topLogprobs() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);
		$gptOptions                = array_merge(
			$gptBaseOptions,
			array(
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			)
		);
		$gptMultimodalInputOptions = array_merge(
			$gptBaseOptions,
			array(
				new SupportedOption(
					OptionEnum::inputModalities(),
					array(
						array( ModalityEnum::text() ),
						array( ModalityEnum::text(), ModalityEnum::image() ),
					)
				),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			)
		);

		$modelsData = (array) $responseData['data'];

		$models = array_values(
			array_map(
				static function ( array $modelData ) use (
					$gptCapabilities,
					$gptOptions,
					$gptMultimodalInputOptions,
				): ModelMetadata {
					$modelId = $modelData['id'];
					switch ( $modelId ) {
						case 'gpt-oss-120b':
						case 'Qwen3-Coder-30B-Instruct':
						case 'Devstral-Small-2-24B-Instruct-2512':
							$modelCaps    = $gptCapabilities;
							$modelOptions = $gptOptions;
							break;
						case 'Mistral-Small-3.2-24B-Instruct':
						case 'Ministral-3-14B-Instruct-2512':
							$modelCaps    = $gptCapabilities;
							$modelOptions = $gptMultimodalInputOptions;
							break;
						default:
							$modelCaps    = array();
							$modelOptions = array();
					}

					return new ModelMetadata(
						$modelId,
						$modelId, // The OpenAI API does not return a display name.
						$modelCaps,
						$modelOptions
					);
				},
				$modelsData
			)
		);

		usort( $models, array( $this, 'modelSortCallback' ) );

		return $models;
	}

	/**
	 * Callback function for sorting models by ID, to be used with `usort()`.
	 *
	 * This method expresses preferences for certain models or model families within the provider by putting them
	 * earlier in the sorted list. The objective is not to be opinionated about which models are better, but to ensure
	 * that more commonly used, more recent, or flagship models are presented first to users.
	 *
	 * @param ModelMetadata $a First model.
	 * @param ModelMetadata $b Second model.
	 *
	 * @return int Comparison result.
	 * @since 0.2.1
	 */
	protected function modelSortCallback( ModelMetadata $a, ModelMetadata $b ): int {
		$aId = $a->getId();
		$bId = $b->getId();

		// Prefer non-preview models over preview models.
		if ( str_contains( $aId, '-preview' ) && ! str_contains( $bId, '-preview' ) ) {
			return 1;
		}
		if ( str_contains( $bId, '-preview' ) && ! str_contains( $aId, '-preview' ) ) {
			return - 1;
		}

		// Prefer GPT models over non-GPT models.
		if ( str_starts_with( $aId, 'gpt-' ) && ! str_starts_with( $bId, 'gpt-' ) ) {
			return - 1;
		}
		if ( str_starts_with( $bId, 'gpt-' ) && ! str_starts_with( $aId, 'gpt-' ) ) {
			return 1;
		}

		// Prefer GPT models with version numbers (e.g. 'gpt-5.1', 'gpt-5') over those without.
		$aMatch = preg_match( '/^gpt-([0-9.]+)(-[a-z0-9-]+)?$/', $aId, $aMatches );
		$bMatch = preg_match( '/^gpt-([0-9.]+)(-[a-z0-9-]+)?$/', $bId, $bMatches );
		if ( $aMatch && ! $bMatch ) {
			return - 1;
		}
		if ( $bMatch && ! $aMatch ) {
			return 1;
		}
		if ( $aMatch && $bMatch ) {
			// Prefer later model versions.
			$aVersion = $aMatches[1];
			$bVersion = $bMatches[1];
			if ( version_compare( $aVersion, $bVersion, '>' ) ) {
				return - 1;
			}
			if ( version_compare( $bVersion, $aVersion, '>' ) ) {
				return 1;
			}

			// Prefer models without a suffix (i.e. base models) over those with a suffix.
			if ( ! isset( $aMatches[2] ) && isset( $bMatches[2] ) ) {
				return - 1;
			}
			if ( ! isset( $bMatches[2] ) && isset( $aMatches[2] ) ) {
				return 1;
			}

			// Prefer '-mini' models over others with a suffix.
			if ( isset( $aMatches[2] ) && isset( $bMatches[2] ) ) {
				if ( '-mini' === $aMatches[2] && '-mini' !== $bMatches[2] ) {
					return - 1;
				}
				if ( '-mini' === $bMatches[2] && '-mini' !== $aMatches[2] ) {
					return 1;
				}
			}
		}

		// Fallback: Sort alphabetically.
		return strcmp( $a->getId(), $b->getId() );
	}
}
