<?php

declare( strict_types=1 );

namespace Mittwald\AiProvider;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for a text-to-speech conversion model, using the OpenAI compatible `audio/speech` endpoint.
 *
 * The AI client SDK does not provide an abstract OpenAI compatible base class for text-to-speech conversion (yet),
 * so this class implements the relevant interface directly.
 *
 * @since 1.3.0
 *
 * @phpstan-type TextToSpeechParams array{
 *     model: string,
 *     input: string,
 *     voice: string,
 *     response_format: string,
 *     ...
 * }
 */
class MittwaldTextToSpeechConversionModel extends AbstractApiBasedModel implements
	TextToSpeechConversionModelInterface {

	/**
	 * The voices supported by the API.
	 *
	 * @since 1.3.0
	 * @var list<string>
	 */
	public const VOICES = array(
		'aiden',
		'dylan',
		'eric',
		'ono_anna',
		'ryan',
		'serena',
		'sohee',
		'uncle_fu',
		'vivian',
	);

	/**
	 * The voice used if the model configuration does not specify one. The API requires a voice.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const DEFAULT_VOICE = 'aiden';

	/**
	 * Map of supported output MIME types to the API's 'response_format' values.
	 *
	 * The API additionally supports 'pcm', which is omitted here as it has no meaningful MIME type.
	 *
	 * @since 1.3.0
	 * @var array<string, string>
	 */
	public const RESPONSE_FORMATS = array(
		'audio/mpeg' => 'mp3',
		'audio/wav'  => 'wav',
		'audio/flac' => 'flac',
		'audio/ogg'  => 'opus',
	);

	/**
	 * The MIME type used if the model configuration does not specify one.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const DEFAULT_MIME_TYPE = 'audio/mpeg';

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 *
	 * @throws InvalidArgumentException If the prompt or the model configuration is invalid.
	 * @throws ResponseException If the API response does not contain any audio data.
	 */
	public function convertTextToSpeechResult( array $prompt ): GenerativeAiResult {
		$params = $this->prepareConvertTextToSpeechParams( $prompt );

		$request = $this->createRequest(
			HttpMethodEnum::POST(),
			'audio/speech',
			array( 'Content-Type' => 'application/json' ),
			$params
		);

		// Add authentication credentials to the request.
		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		// Send and process the request.
		$response = $this->getHttpTransporter()->send( $request );
		$this->throwIfNotSuccessful( $response );

		$mimeType = array_search( $params['response_format'], self::RESPONSE_FORMATS, true );

		return $this->parseResponseToGenerativeAiResult(
			$response,
			is_string( $mimeType ) ? $mimeType : self::DEFAULT_MIME_TYPE
		);
	}

	/**
	 * Prepares the given prompt and the model configuration into parameters for the API request.
	 *
	 * @since 1.3.0
	 *
	 * @param Message[] $prompt The prompt to convert to speech. The API only supports a single user message.
	 * @phpstan-param list<Message> $prompt
	 *
	 * @return TextToSpeechParams The parameters for the API request.
	 * @throws InvalidArgumentException If the prompt or the model configuration is invalid.
	 */
	protected function prepareConvertTextToSpeechParams( array $prompt ): array {
		$config = $this->getConfig();

		$voice = $config->getOutputSpeechVoice();
		if ( null === $voice ) {
			$voice = self::DEFAULT_VOICE;
		}

		$outputMimeType = $config->getOutputMimeType();
		if ( null === $outputMimeType ) {
			$outputMimeType = self::DEFAULT_MIME_TYPE;
		}
		$outputMimeType = strtolower( $outputMimeType );
		if ( ! isset( self::RESPONSE_FORMATS[ $outputMimeType ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'The output MIME type "%s" is not supported. Supported types are: %s.',
					esc_html( $outputMimeType ),
					esc_html( implode( ', ', array_keys( self::RESPONSE_FORMATS ) ) )
				)
			);
		}

		$params = array(
			'model'           => $this->metadata()->getId(),
			'input'           => $this->prepareInputParam( $prompt ),
			'voice'           => $voice,
			'response_format' => self::RESPONSE_FORMATS[ $outputMimeType ],
		);

		/*
		 * Any custom options are added to the parameters as well.
		 * This allows developers to pass other options that may be more niche or not yet supported by the SDK,
		 * such as 'speed', 'language', or 'instructions'.
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

		/** @var TextToSpeechParams $params *///phpcs:ignore
		return $params;
	}

	/**
	 * Prepares the input parameter for the API request.
	 *
	 * @since 1.3.0
	 *
	 * @param Message[] $messages The messages to prepare. The API only supports a single user message.
	 * @phpstan-param list<Message> $messages
	 *
	 * @return string The prepared input parameter.
	 * @throws InvalidArgumentException If the messages cannot be used as input for the API.
	 */
	protected function prepareInputParam( array $messages ): string {
		if ( 1 !== count( $messages ) ) {
			throw new InvalidArgumentException(
				'The API requires a single user message as prompt.'
			);
		}
		$message = $messages[0];
		if ( ! $message->getRole()->isUser() ) {
			throw new InvalidArgumentException(
				'The API requires a user message as prompt.'
			);
		}

		$textParts = array();
		foreach ( $message->getParts() as $part ) {
			$text = $part->getText();
			if ( null !== $text ) {
				$textParts[] = $text;
			}
		}

		if ( ! $textParts ) {
			throw new InvalidArgumentException(
				'The API requires at least one text message part as prompt.'
			);
		}

		return implode( "\n", $textParts );
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
	 * Parses the response from the API endpoint to a generative AI result.
	 *
	 * Contrary to most other endpoints, the `audio/speech` endpoint responds with the raw audio data instead of JSON.
	 *
	 * @since 1.3.0
	 *
	 * @param Response $response The response from the API endpoint.
	 * @param string   $mimeType The MIME type the response audio is in.
	 *
	 * @return GenerativeAiResult The parsed generative AI result.
	 * @throws ResponseException If the response does not contain any audio data.
	 */
	protected function parseResponseToGenerativeAiResult( Response $response, string $mimeType ): GenerativeAiResult {
		$body = $response->getBody();
		if ( null === $body || '' === $body ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), 'body' );
		}

		// The audio data needs to be base64 encoded to be passed to the file object; this is not obfuscation.
		$audioFile = new File( base64_encode( $body ), $mimeType ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$candidate = new Candidate(
			new Message( MessageRoleEnum::model(), array( new MessagePart( $audioFile ) ) ),
			FinishReasonEnum::stop()
		);

		return new GenerativeAiResult(
			'',
			array( $candidate ),
			new TokenUsage( 0, 0, 0 ),
			$this->providerMetadata(),
			$this->metadata()
		);
	}
}
