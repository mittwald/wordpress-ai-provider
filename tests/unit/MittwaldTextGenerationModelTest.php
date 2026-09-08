<?php
/**
 * Tests for the text generation model.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldTextGenerationModel;
use Mittwald\AiProvider\Tests\Includes\FakeHttpTransporter;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/**
 * Covers request construction and response handling for chat completions.
 */
final class MittwaldTextGenerationModelTest extends TestCase {

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
	 * Builds a wired-up text generation model.
	 *
	 * @param string           $model_id Model ID to use.
	 * @param ModelConfig|null $config   Optional configuration.
	 */
	private function model( string $model_id = 'gpt-oss-120b', ?ModelConfig $config = null ): MittwaldTextGenerationModel {
		$model = new MittwaldTextGenerationModel(
			$this->model_metadata( $model_id ),
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
	 * Queues a minimal successful chat completion response.
	 *
	 * @param string $content The assistant message content.
	 */
	private function queue_completion( string $content = 'Hello there.' ): void {
		$this->transporter->queue_json(
			array(
				'id'      => 'chatcmpl-test',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => $content,
						),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 11,
					'completion_tokens' => 3,
					'total_tokens'      => 14,
				),
			)
		);
	}

	/**
	 * Completions are posted to the provider's own chat completions endpoint.
	 */
	public function test_completions_are_posted_to_the_mittwald_endpoint(): void {
		$this->queue_completion();

		$this->model()->generateTextResult( $this->user_prompt( 'Hi' ) );

		$request = $this->transporter->last_request();

		$this->assertSame( 'https://llm.aihosting.mittwald.de/v1/chat/completions', $request->getUri() );
		$this->assertTrue( $request->getMethod()->equals( HttpMethodEnum::POST() ) );
		$this->assertSame( 'application/json', $request->getHeaderAsString( 'Content-Type' ) );
		$this->assertSame( 'Bearer ' . self::TEST_API_KEY, $request->getHeaderAsString( 'Authorization' ) );
	}

	/**
	 * The model ID and prompt reach the request body.
	 */
	public function test_model_and_prompt_are_sent(): void {
		$this->queue_completion();

		$this->model( 'Qwen3.6-35B-A3B-FP8' )->generateTextResult( $this->user_prompt( 'Hi' ) );

		$payload = $this->transporter->last_request_payload();

		$this->assertSame( 'Qwen3.6-35B-A3B-FP8', $payload['model'] );
		$this->assertSame(
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Hi',
						),
					),
				),
			),
			$payload['messages']
		);
	}

	/**
	 * Request options set on the model are carried into the request.
	 *
	 * The plugin overrides `createRequest()` purely to pass these through, so a
	 * regression here would silently drop a site's configured timeout.
	 */
	public function test_request_options_are_passed_through(): void {
		$this->queue_completion();

		$request_options = new RequestOptions();
		$request_options->setTimeout( 42.0 );

		$model = $this->model();
		$model->setRequestOptions( $request_options );
		$model->generateTextResult( $this->user_prompt( 'Hi' ) );

		$options = $this->transporter->last_request()->getOptions();

		$this->assertNotNull( $options, 'The request should carry the configured request options.' );
		$this->assertSame( 42.0, $options->getTimeout() );
	}

	/**
	 * A JSON schema is sent as a named `json_schema` response format.
	 *
	 * mittwald AI hosting expects OpenAI's structured-output shape, which nests
	 * the schema under a name; the SDK's default does not.
	 */
	public function test_output_schema_is_sent_as_a_named_json_schema(): void {
		$this->queue_completion( '{"answer":"42"}' );

		$schema = array(
			'type'       => 'object',
			'properties' => array( 'answer' => array( 'type' => 'string' ) ),
			'required'   => array( 'answer' ),
		);

		$config = new ModelConfig();
		$config->setOutputMimeType( 'application/json' );
		$config->setOutputSchema( $schema );

		$this->model( 'gpt-oss-120b', $config )->generateTextResult( $this->user_prompt( 'Answer' ) );

		$payload = $this->transporter->last_request_payload();

		$this->assertSame(
			array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'outputSchema',
					'schema' => $schema,
				),
			),
			$payload['response_format']
		);
	}

	/**
	 * JSON output without a schema falls back to plain JSON mode.
	 */
	public function test_json_output_without_a_schema_uses_json_object_mode(): void {
		$this->queue_completion( '{"answer":"42"}' );

		$config = new ModelConfig();
		$config->setOutputMimeType( 'application/json' );

		$this->model( 'gpt-oss-120b', $config )->generateTextResult( $this->user_prompt( 'Answer' ) );

		$this->assertSame(
			array( 'type' => 'json_object' ),
			$this->transporter->last_request_payload()['response_format']
		);
	}

	/**
	 * Plain text output sends no response format at all.
	 */
	public function test_plain_text_output_sends_no_response_format(): void {
		$this->queue_completion();

		$config = new ModelConfig();
		$config->setOutputMimeType( 'text/plain' );

		$this->model( 'gpt-oss-120b', $config )->generateTextResult( $this->user_prompt( 'Hi' ) );

		$this->assertArrayNotHasKey( 'response_format', $this->transporter->last_request_payload() );
	}

	/**
	 * Generation options configured on the model reach the request.
	 */
	public function test_generation_options_are_sent(): void {
		$this->queue_completion();

		$config = new ModelConfig();
		$config->setSystemInstruction( 'You are terse.' );
		$config->setMaxTokens( 128 );
		$config->setTemperature( 0.25 );
		$config->setTopP( 0.9 );
		$config->setStopSequences( array( '\n\n' ) );
		$config->setPresencePenalty( 0.1 );
		$config->setFrequencyPenalty( 0.2 );
		$config->setCandidateCount( 2 );

		$this->model( 'gpt-oss-120b', $config )->generateTextResult( $this->user_prompt( 'Hi' ) );

		$payload = $this->transporter->last_request_payload();

		$this->assertSame( 128, $payload['max_tokens'] );
		$this->assertSame( 0.25, $payload['temperature'] );
		$this->assertSame( 0.9, $payload['top_p'] );
		$this->assertSame( array( '\n\n' ), $payload['stop'] );
		$this->assertSame( 0.1, $payload['presence_penalty'] );
		$this->assertSame( 0.2, $payload['frequency_penalty'] );
		$this->assertSame( 2, $payload['n'] );
		$this->assertSame(
			array(
				'role'    => 'system',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'You are terse.',
					),
				),
			),
			$this->as_array( $payload['messages'] )[0]
		);
	}

	/**
	 * Chat history is sent in order, with the roles the API expects.
	 */
	public function test_chat_history_is_sent_in_order(): void {
		$this->queue_completion();

		$prompt = array(
			new Message( MessageRoleEnum::user(), array( new MessagePart( 'My name is Ada.' ) ) ),
			new Message( MessageRoleEnum::model(), array( new MessagePart( 'Nice to meet you, Ada.' ) ) ),
			new Message( MessageRoleEnum::user(), array( new MessagePart( 'What is my name?' ) ) ),
		);

		$this->model()->generateTextResult( $prompt );

		$payload = $this->transporter->last_request_payload();

		$this->assertSame(
			array( 'user', 'assistant', 'user' ),
			array_column( $this->as_array( $payload['messages'] ), 'role' )
		);
	}

	/**
	 * Image parts are sent as OpenAI-style image URLs.
	 */
	public function test_image_input_is_sent_as_an_image_url_part(): void {
		$this->queue_completion( 'A red dot.' );

		$image  = new File( self::red_dot_png_base64(), 'image/png' );
		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array(
					new MessagePart( 'What is in this image?' ),
					new MessagePart( $image ),
				)
			),
		);

		$this->model( 'Qwen3.5-122B-A10B-FP8' )->generateTextResult( $prompt );

		$messages = $this->as_array( $this->transporter->last_request_payload()['messages'] );
		$message  = $this->as_array( $messages[0] );
		$content  = $this->as_array( $message['content'] );

		$text_part  = $this->as_array( $content[0] );
		$image_part = $this->as_array( $content[1] );

		$this->assertSame( 'text', $text_part['type'] );
		$this->assertSame( 'image_url', $image_part['type'] );
		$this->assertStringStartsWith(
			'data:image/png;base64,',
			$this->as_string( $this->as_array( $image_part['image_url'] )['url'] )
		);
	}

	/**
	 * Function declarations are sent as tools.
	 */
	public function test_function_declarations_are_sent_as_tools(): void {
		$this->queue_completion();

		$config = new ModelConfig();
		$config->setFunctionDeclarations(
			array(
				new FunctionDeclaration(
					'get_weather',
					'Returns the current weather for a city.',
					array(
						'type'       => 'object',
						'properties' => array( 'city' => array( 'type' => 'string' ) ),
						'required'   => array( 'city' ),
					)
				),
			)
		);

		$this->model( 'gpt-oss-120b', $config )->generateTextResult( $this->user_prompt( 'Weather in Espelkamp?' ) );

		$tools = $this->as_array( $this->transporter->last_request_payload()['tools'] );

		$this->assertCount( 1, $tools );

		$tool = $this->as_array( $tools[0] );

		$this->assertSame( 'function', $tool['type'] );
		$this->assertSame( 'get_weather', $this->as_array( $tool['function'] )['name'] );
	}

	/**
	 * A completion is parsed into text, a finish reason and token usage.
	 */
	public function test_completion_is_parsed_into_a_result(): void {
		$this->queue_completion( 'Hello there.' );

		$result = $this->model()->generateTextResult( $this->user_prompt( 'Hi' ) );

		$this->assertSame( 'Hello there.', $result->toText() );
		$this->assertSame( 1, $result->getCandidateCount() );
		$this->assertTrue( $result->getCandidates()[0]->getFinishReason()->isStop() );
		$this->assertSame( 11, $result->getTokenUsage()->getPromptTokens() );
		$this->assertSame( 3, $result->getTokenUsage()->getCompletionTokens() );
		$this->assertSame( 14, $result->getTokenUsage()->getTotalTokens() );
		$this->assertSame( 'mittwald', $result->getProviderMetadata()->getId() );
		$this->assertSame( 'gpt-oss-120b', $result->getModelMetadata()->getId() );
	}

	/**
	 * A tool call in the response is parsed back into a function call part.
	 */
	public function test_tool_calls_are_parsed_back_into_function_calls(): void {
		$this->transporter->queue_json(
			array(
				'id'      => 'chatcmpl-tools',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'       => 'assistant',
							'content'    => null,
							'tool_calls' => array(
								array(
									'id'       => 'call_1',
									'type'     => 'function',
									'function' => array(
										'name'      => 'get_weather',
										'arguments' => '{"city":"Espelkamp"}',
									),
								),
							),
						),
						'finish_reason' => 'tool_calls',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 20,
					'completion_tokens' => 8,
					'total_tokens'      => 28,
				),
			)
		);

		$result = $this->model()->generateTextResult( $this->user_prompt( 'Weather in Espelkamp?' ) );

		$parts = $result->getCandidates()[0]->getMessage()->getParts();

		$this->assertCount( 1, $parts );

		$function_call = $parts[0]->getFunctionCall();

		$this->assertNotNull( $function_call );
		$this->assertSame( 'get_weather', $function_call->getName() );
		$this->assertSame( array( 'city' => 'Espelkamp' ), $function_call->getArgs() );
	}

	/**
	 * Multiple candidates are all returned.
	 */
	public function test_multiple_candidates_are_returned(): void {
		$this->transporter->queue_json(
			array(
				'id'      => 'chatcmpl-multi',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => 'First',
						),
						'finish_reason' => 'stop',
					),
					array(
						'index'         => 1,
						'message'       => array(
							'role'    => 'assistant',
							'content' => 'Second',
						),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 5,
					'completion_tokens' => 2,
					'total_tokens'      => 7,
				),
			)
		);

		$result = $this->model()->generateTextResult( $this->user_prompt( 'Hi' ) );

		$this->assertTrue( $result->hasMultipleCandidates() );
		$this->assertSame( array( 'First', 'Second' ), $result->toTexts() );
	}

	/**
	 * An API error is surfaced with the message the API returned.
	 */
	public function test_api_errors_are_surfaced(): void {
		$this->transporter->queue_json(
			array( 'error' => array( 'message' => 'model not found' ) ),
			404
		);

		$this->expectException( ClientException::class );
		$this->expectExceptionMessage( 'model not found' );

		$this->model()->generateTextResult( $this->user_prompt( 'Hi' ) );
	}

	/**
	 * A 1x1 red PNG, base64 encoded.
	 */
	private static function red_dot_png_base64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}
}
