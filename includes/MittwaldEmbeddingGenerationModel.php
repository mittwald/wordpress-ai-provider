<?php

declare( strict_types=1 );

namespace Mittwald\AiProvider;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Results\DTO\Embedding;
use WordPress\AiClient\Results\DTO\EmbeddingResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Class for an embedding generation model, using the OpenAI compatible `embeddings` endpoint.
 *
 * The AI client SDK does not provide an abstract OpenAI compatible base class for embedding generation (yet),
 * so this class implements the relevant interface directly, in the same way as
 * {@see MittwaldTextToSpeechConversionModel} does for speech synthesis.
 *
 * @since 1.3.0
 *
 * @phpstan-type EmbeddingParams array{
 *     model: string,
 *     input: list<string>,
 *     encoding_format: string,
 *     dimensions?: int,
 *     ...
 * }
 */
class MittwaldEmbeddingGenerationModel extends AbstractApiBasedModel implements
	EmbeddingGenerationModelInterface {

	/**
	 * The encoding the plugin asks for, matching the documented API example.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const ENCODING_FORMAT = 'float';

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 *
	 * @throws InvalidArgumentException If the inputs or the model configuration are invalid.
	 * @throws ResponseException If the API response does not contain usable embeddings.
	 */
	public function generateEmbeddingResult( array $inputs ): EmbeddingResult {
		$params = $this->prepareGenerateEmbeddingParams( $inputs );

		$request = $this->createRequest(
			HttpMethodEnum::POST(),
			'embeddings',
			array( 'Content-Type' => 'application/json' ),
			$params
		);

		// Add authentication credentials to the request.
		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		// Send and process the request.
		$response = $this->getHttpTransporter()->send( $request );
		$this->throwIfNotSuccessful( $response );

		return $this->parseResponseToEmbeddingResult( $response );
	}

	/**
	 * Prepares the given inputs and the model configuration into parameters for the API request.
	 *
	 * @since 1.3.0
	 *
	 * @param MessagePart[] $inputs The inputs to embed, one embedding generated per input.
	 * @phpstan-param list<MessagePart> $inputs
	 *
	 * @return EmbeddingParams The parameters for the API request.
	 * @throws InvalidArgumentException If the inputs or the model configuration are invalid.
	 */
	protected function prepareGenerateEmbeddingParams( array $inputs ): array {
		$config = $this->getConfig();

		$params = array(
			'model'           => $this->metadata()->getId(),
			'input'           => $this->prepareInputParam( $inputs ),
			'encoding_format' => self::ENCODING_FORMAT,
		);

		/*
		 * Whether a model can project to a narrower vector, and which widths it takes, are properties
		 * of that model, so both answers come from its own metadata. A model whose entry in
		 * `MittwaldModelMetadataDirectory` declares the option and the requested width forwards it;
		 * anything else is refused here.
		 *
		 * Model resolution matches on the same supported options, so this is unreachable through
		 * `EmbeddingBuilder`. It is reached through the named-model API, which performs no
		 * requirements matching, and where a vector of the wrong width would misreport what the
		 * caller asked for.
		 */
		$dimensions = $config->getDimensions();
		if ( null !== $dimensions ) {
			$supportedOption = $this->supportedOption( OptionEnum::dimensions() );

			if ( null === $supportedOption ) {
				throw new InvalidArgumentException(
					sprintf(
						'The model "%s" produces vectors of a fixed width and does not support the '
						. 'dimensions option. Truncate and re-normalise the vectors instead.',
						esc_html( $this->metadata()->getId() )
					)
				);
			}

			if ( ! $supportedOption->isSupportedValue( $dimensions ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'The model "%s" does not produce vectors of %s dimensions. Supported widths '
						. 'are: %s.',
						esc_html( $this->metadata()->getId() ),
						esc_html( (string) $dimensions ),
						esc_html( $this->describeSupportedValues( $supportedOption ) )
					)
				);
			}

			$params['dimensions'] = $dimensions;
		}

		/*
		 * Any custom options are added to the parameters as well.
		 * This allows developers to pass other options that may be more niche or not yet supported by the SDK.
		 */
		$customOptions = $config->getCustomOptions();
		foreach ( $customOptions as $key => $value ) {
			if ( isset( $params[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'The custom option "%s" conflicts with an existing parameter.',
						esc_html( (string) $key )
					)
				);
			}
			$params[ $key ] = $value;
		}

		/** @var EmbeddingParams $params *///phpcs:ignore
		return $params;
	}

	/**
	 * Prepares the input parameter for the API request.
	 *
	 * The endpoint embeds each entry of the `input` array independently, so the inputs map to it
	 * positionally: the n-th input produces the n-th embedding.
	 *
	 * @since 1.3.0
	 *
	 * @param MessagePart[] $inputs The inputs to prepare. The API only supports text.
	 * @phpstan-param list<MessagePart> $inputs
	 *
	 * @return list<string> The prepared input parameter.
	 * @throws InvalidArgumentException If the inputs cannot be used as input for the API.
	 */
	protected function prepareInputParam( array $inputs ): array {
		if ( array() === $inputs ) {
			throw new InvalidArgumentException(
				'The API requires at least one input to embed.'
			);
		}

		$texts = array();
		foreach ( $inputs as $position => $input ) {
			$text = $input->getText();

			/*
			 * Non-text inputs are rejected rather than skipped: embeddings map positionally to
			 * inputs, so dropping one would silently misalign every embedding after it.
			 */
			if ( null === $text || ! $input->getType()->isText() ) {
				throw new InvalidArgumentException(
					sprintf(
						'The model "%s" only embeds text, but input %d is not a text part.',
						esc_html( $this->metadata()->getId() ),
						(int) $position
					)
				);
			}

			$texts[] = $text;
		}

		return $texts;
	}

	/**
	 * Renders an option's supported values as a human-readable list.
	 *
	 * Used for error messages, so a caller is told which values would have worked.
	 *
	 * @since 1.3.0
	 *
	 * @param SupportedOption $option The option to describe.
	 *
	 * @return string The supported values, comma separated.
	 */
	protected function describeSupportedValues( SupportedOption $option ): string {
		$rendered = array();

		foreach ( $option->getSupportedValues() ?? array() as $value ) {
			if ( is_scalar( $value ) ) {
				$rendered[] = (string) $value;
			}
		}

		return implode( ', ', $rendered );
	}

	/**
	 * Returns this model's declaration of the given option, if it advertises one.
	 *
	 * The declaration carries the values the model accepts as well as the option itself, so callers
	 * can check a specific value against it.
	 *
	 * @since 1.3.0
	 *
	 * @param OptionEnum $option The option to look for.
	 *
	 * @return SupportedOption|null The model's declaration, or null if it advertises none.
	 */
	protected function supportedOption( OptionEnum $option ): ?SupportedOption {
		foreach ( $this->metadata()->getSupportedOptions() as $supportedOption ) {
			if ( $supportedOption->getName()->equals( $option ) ) {
				return $supportedOption;
			}
		}

		return null;
	}

	/**
	 * Creates a request object for the provider's API.
	 *
	 * @since 1.3.0
	 *
	 * @param HttpMethodEnum                     $method  The HTTP method.
	 * @param string                             $path    The API endpoint path, relative to the base URI.
	 * @param array<string, string|list<string>> $headers The request headers.
	 * @param string|array<string, mixed>|null   $data    The request data.
	 *
	 * @return Request The request object.
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			MittwaldAIProvider::url( $path ),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}

	/**
	 * Throws an exception if the response is not successful.
	 *
	 * @since 1.3.0
	 *
	 * @param Response $response The HTTP response to check.
	 */
	protected function throwIfNotSuccessful( Response $response ): void {
		ResponseUtil::throwIfNotSuccessful( $response );
	}

	/**
	 * Parses the response from the API endpoint to an embedding result.
	 *
	 * @since 1.3.0
	 *
	 * @param Response $response The response from the API endpoint.
	 *
	 * @return EmbeddingResult The parsed embedding result.
	 * @throws ResponseException If the response does not contain usable embeddings.
	 */
	protected function parseResponseToEmbeddingResult( Response $response ): EmbeddingResult {
		$apiName      = $this->providerMetadata()->getName();
		$responseData = $response->getData();

		if ( null === $responseData || ! isset( $responseData['data'] ) ) {
			throw ResponseException::fromMissingData( esc_html( $apiName ), 'data' );
		}

		$entries = $responseData['data'];
		if ( ! is_array( $entries ) || array() === $entries ) {
			throw ResponseException::fromMissingData( esc_html( $apiName ), 'data' );
		}

		$vectors = array();
		$order   = 0;
		foreach ( $entries as $entry ) {
			$vectors[] = array(
				'index'  => $this->readIndex( $entry, $order ),
				'order'  => $order,
				'values' => $this->readVector( $entry ),
			);
			++$order;
		}

		/*
		 * The SDK maps embeddings to inputs by position, so the response order has to be the input
		 * order. The API reports the input each embedding belongs to in `index`; sort by it rather
		 * than trusting the order the entries happen to arrive in. `usort()` is not stable on
		 * PHP 7.4, so the arrival position breaks ties.
		 */
		usort(
			$vectors,
			static function ( array $a, array $b ): int {
				$byIndex = $a['index'] <=> $b['index'];

				return 0 !== $byIndex ? $byIndex : $a['order'] <=> $b['order'];
			}
		);

		$dimensions = count( $vectors[0]['values'] );

		$embeddings = array();
		foreach ( $vectors as $vector ) {
			if ( count( $vector['values'] ) !== $dimensions ) {
				throw ResponseException::fromInvalidData(
					esc_html( $apiName ),
					'data',
					'All embeddings in a response must have the same number of dimensions.'
				);
			}

			$embeddings[] = new Embedding( $vector['values'], $dimensions );
		}

		return new EmbeddingResult(
			$this->readId( $responseData ),
			$embeddings,
			$dimensions,
			$this->readTokenUsage( $responseData ),
			$this->providerMetadata(),
			$this->metadata()
		);
	}

	/**
	 * Reads the input index a response entry belongs to.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $entry    One entry of the response's `data` array.
	 * @param int   $fallback The index to assume if the entry does not report one.
	 *
	 * @return int The input index.
	 */
	private function readIndex( $entry, int $fallback ): int {
		if ( is_array( $entry ) && isset( $entry['index'] ) && is_int( $entry['index'] ) ) {
			return $entry['index'];
		}

		return $fallback;
	}

	/**
	 * Reads the vector values out of a response entry.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $entry One entry of the response's `data` array.
	 *
	 * @return list<float|int> The vector values.
	 * @throws ResponseException If the entry does not carry a usable vector.
	 */
	private function readVector( $entry ): array {
		$apiName = $this->providerMetadata()->getName();

		if ( ! is_array( $entry ) || ! isset( $entry['embedding'] ) ) {
			throw ResponseException::fromMissingData( esc_html( $apiName ), 'data[].embedding' );
		}

		$embedding = $entry['embedding'];
		if ( ! is_array( $embedding ) || array() === $embedding ) {
			throw ResponseException::fromInvalidData(
				esc_html( $apiName ),
				'data[].embedding',
				'An embedding must be a non-empty array of numbers.'
			);
		}

		$values = array();
		foreach ( $embedding as $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				throw ResponseException::fromInvalidData(
					esc_html( $apiName ),
					'data[].embedding',
					'An embedding must consist of numbers only.'
				);
			}

			$values[] = $value;
		}

		return $values;
	}

	/**
	 * Reads the result ID out of the response.
	 *
	 * The `embeddings` endpoint is not documented to return one, so an absent ID is not an error.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $responseData The decoded response body.
	 *
	 * @return string The result ID, or an empty string if the response carries none.
	 */
	private function readId( array $responseData ): string {
		if ( isset( $responseData['id'] ) && is_string( $responseData['id'] ) ) {
			return $responseData['id'];
		}

		return '';
	}

	/**
	 * Reads the token usage out of the response.
	 *
	 * Embedding requests produce no completion tokens, so only the prompt and total counts are
	 * reported. Absent counts are reported as zero rather than treated as an error.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $responseData The decoded response body.
	 *
	 * @return TokenUsage The token usage the response reports.
	 */
	private function readTokenUsage( array $responseData ): TokenUsage {
		$usage = $responseData['usage'] ?? array();
		if ( ! is_array( $usage ) ) {
			$usage = array();
		}

		$promptTokens = $usage['prompt_tokens'] ?? 0;
		$totalTokens  = $usage['total_tokens'] ?? 0;

		return new TokenUsage(
			is_int( $promptTokens ) ? $promptTokens : 0,
			0,
			is_int( $totalTokens ) ? $totalTokens : 0
		);
	}
}
