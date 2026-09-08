<?php
/**
 * Integration tests for chat completions.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Integration;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldTextGenerationModel;
use Mittwald\AiProvider\Tests\Includes\IntegrationTestCase;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Exercises text generation against the real API.
 *
 * Assertions stay behavioural rather than checking exact wording: the point is
 * that the request the plugin builds is one mittwald AI hosting accepts, and
 * that its answer round-trips back into the SDK's result objects.
 */
final class TextGenerationTest extends IntegrationTestCase {

	/**
	 * Chat models to try, in order of preference.
	 *
	 * @var list<string>
	 */
	private const CHAT_MODELS = array(
		'gpt-oss-120b',
		'Qwen3.6-35B-A3B-FP8',
		'Qwen3.5-122B-A10B-FP8',
		'Qwen3.8-27B-NVFP4',
		'Ministral-3-14B-Instruct-2512',
		'Qwen3.5-0.8B',
	);

	/**
	 * Returns a text generation model, skipping if none is on offer.
	 *
	 * @param ModelConfig|null $config Optional configuration.
	 */
	private function chat_model( ?ModelConfig $config = null ): MittwaldTextGenerationModel {
		$model = $this->first_available_model( self::CHAT_MODELS, $config );

		$this->assertInstanceOf( MittwaldTextGenerationModel::class, $model );

		return $model;
	}

	/**
	 * A plain prompt comes back as text, with usage and a finish reason.
	 */
	public function test_a_prompt_produces_text(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 64 );

		$result = $this->chat_model( $config )->generateTextResult(
			$this->user_prompt( 'Reply with exactly the word: pong' )
		);

		$this->assertNotSame( '', trim( $result->toText() ) );
		$this->assertSame( 1, $result->getCandidateCount() );
		$this->assertGreaterThan( 0, $result->getTokenUsage()->getPromptTokens() );
		$this->assertGreaterThan( 0, $result->getTokenUsage()->getCompletionTokens() );
		$this->assertSame( 'mittwald', $result->getProviderMetadata()->getId() );
		$this->assertContains( $result->getModelMetadata()->getId(), self::CHAT_MODELS );
	}

	/**
	 * A system instruction steers the answer.
	 */
	public function test_a_system_instruction_is_honoured(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 32 );
		$config->setTemperature( 0.0 );
		$config->setSystemInstruction(
			'You are a machine that answers every question with the single word NORDWEST, in capitals, and nothing else.'
		);

		$result = $this->chat_model( $config )->generateTextResult(
			$this->user_prompt( 'What is the capital of France?' )
		);

		$this->assertStringContainsStringIgnoringCase( 'NORDWEST', $result->toText() );
	}

	/**
	 * Earlier turns of a conversation are available to the model.
	 */
	public function test_chat_history_is_carried_into_the_answer(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 32 );
		$config->setTemperature( 0.0 );

		$prompt = array(
			new Message(
				MessageRoleEnum::user(),
				array( new MessagePart( 'My favourite colour is chartreuse. Remember it.' ) )
			),
			new Message(
				MessageRoleEnum::model(),
				array( new MessagePart( 'Noted, your favourite colour is chartreuse.' ) )
			),
			new Message(
				MessageRoleEnum::user(),
				array( new MessagePart( 'What is my favourite colour? Answer with one word.' ) )
			),
		);

		$result = $this->chat_model( $config )->generateTextResult( $prompt );

		$this->assertStringContainsStringIgnoringCase( 'chartreuse', $result->toText() );
	}

	/**
	 * A low token limit is respected and reported as such.
	 */
	public function test_max_tokens_stops_the_generation(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 8 );

		$result = $this->chat_model( $config )->generateTextResult(
			$this->user_prompt( 'Write a detailed history of the printing press.' )
		);

		$this->assertTrue(
			$result->getCandidates()[0]->getFinishReason()->isLength(),
			'A generation cut off by max_tokens should report the length finish reason.'
		);
		$this->assertLessThanOrEqual( 8, $result->getTokenUsage()->getCompletionTokens() );
	}

	/**
	 * JSON mode produces parseable JSON.
	 */
	public function test_json_output_mode_produces_valid_json(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 256 );
		$config->setTemperature( 0.0 );
		$config->setOutputMimeType( 'application/json' );

		$result = $this->chat_model( $config )->generateTextResult(
			$this->user_prompt(
				'Return a JSON object with a single key "city" whose value is the capital of Germany.'
			)
		);

		$decoded = json_decode( $result->toText(), true );

		$this->assertIsArray( $decoded, 'JSON mode should return a parseable JSON document.' );
	}

	/**
	 * A JSON schema is honoured, using the `json_schema` shape the plugin sends.
	 *
	 * This is the mittwald-specific override in `MittwaldTextGenerationModel`,
	 * so it is the assertion most worth having against the real API.
	 */
	public function test_output_schema_is_honoured(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 256 );
		$config->setTemperature( 0.0 );
		$config->setOutputMimeType( 'application/json' );
		$config->setOutputSchema(
			array(
				'type'                 => 'object',
				'properties'           => array(
					'city'       => array( 'type' => 'string' ),
					'population' => array( 'type' => 'integer' ),
				),
				'required'             => array( 'city', 'population' ),
				'additionalProperties' => false,
			)
		);

		$result = $this->chat_model( $config )->generateTextResult(
			$this->user_prompt( 'Give me the capital of Germany and roughly how many people live there.' )
		);

		$decoded = json_decode( $result->toText(), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'city', $decoded );
		$this->assertArrayHasKey( 'population', $decoded );
		$this->assertIsString( $decoded['city'] );
		$this->assertIsInt( $decoded['population'] );
	}

	/**
	 * The model can be steered into calling a declared function.
	 */
	public function test_function_calling_produces_a_tool_call(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 256 );
		$config->setTemperature( 0.0 );
		$config->setFunctionDeclarations(
			array(
				new FunctionDeclaration(
					'get_current_weather',
					'Returns the current weather for a given city. Call this whenever the user asks about weather.',
					array(
						'type'       => 'object',
						'properties' => array(
							'city' => array(
								'type'        => 'string',
								'description' => 'The city to look up, e.g. "Espelkamp".',
							),
						),
						'required'   => array( 'city' ),
					)
				),
			)
		);

		$result = $this->chat_model( $config )->generateTextResult(
			$this->user_prompt( 'What is the weather like in Espelkamp right now?' )
		);

		$function_call = null;
		foreach ( $result->getCandidates()[0]->getMessage()->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( null !== $call ) {
				$function_call = $call;
				break;
			}
		}

		if ( null === $function_call ) {
			$this->markTestSkipped(
				'The model answered in prose instead of calling the declared function; '
				. 'tool use is a model behaviour, not a provider guarantee.'
			);
		}

		$args = $function_call->getArgs();

		$this->assertSame( 'get_current_weather', $function_call->getName() );
		$this->assertIsArray( $args );
		$this->assertArrayHasKey( 'city', $args );
	}

	/**
	 * A tool result can be fed back for a final answer.
	 */
	public function test_a_function_response_can_be_fed_back(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 128 );
		$config->setTemperature( 0.0 );
		$config->setFunctionDeclarations(
			array(
				new FunctionDeclaration(
					'get_current_weather',
					'Returns the current weather for a given city.',
					array(
						'type'       => 'object',
						'properties' => array( 'city' => array( 'type' => 'string' ) ),
						'required'   => array( 'city' ),
					)
				),
			)
		);

		$model = $this->chat_model( $config );

		$first = $model->generateTextResult(
			$this->user_prompt( 'What is the weather like in Espelkamp right now?' )
		);

		$call = null;
		foreach ( $first->getCandidates()[0]->getMessage()->getParts() as $part ) {
			$candidate_call = $part->getFunctionCall();
			if ( null !== $candidate_call ) {
				$call = $candidate_call;
				break;
			}
		}

		if ( null === $call ) {
			$this->markTestSkipped( 'The model did not call the declared function on this run.' );
		}

		$prompt = array(
			$this->user_prompt( 'What is the weather like in Espelkamp right now?' )[0],
			$first->getCandidates()[0]->getMessage(),
			new Message(
				MessageRoleEnum::user(),
				array(
					new MessagePart(
						new FunctionResponse(
							(string) $call->getId(),
							(string) $call->getName(),
							array(
								'temperature_celsius' => 7,
								'conditions'          => 'light rain',
							)
						)
					),
				)
			),
		);

		$second = $model->generateTextResult( $prompt );

		$this->assertNotSame( '', trim( $second->toText() ) );
		$this->assertMatchesRegularExpression( '/7|rain|regn/i', $second->toText() );
	}

	/**
	 * Asking for several candidates returns several candidates.
	 */
	public function test_multiple_candidates_can_be_requested(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 32 );
		$config->setCandidateCount( 2 );
		$config->setTemperature( 1.0 );

		try {
			$result = $this->chat_model( $config )->generateTextResult(
				$this->user_prompt( 'Name a colour. One word only.' )
			);
		} catch ( ClientException $exception ) {
			$this->markTestSkipped(
				'The model rejected a multi-candidate request: ' . $exception->getMessage()
			);
		}

		$this->assertSame( 2, $result->getCandidateCount() );
		$this->assertCount( 2, $result->toTexts() );
	}

	/**
	 * Stop sequences are passed through to the API.
	 */
	public function test_stop_sequences_are_applied(): void {
		$config = new ModelConfig();
		$config->setMaxTokens( 64 );
		$config->setTemperature( 0.0 );
		$config->setStopSequences( array( 'THREE' ) );

		$result = $this->chat_model( $config )->generateTextResult(
			$this->user_prompt( 'Count upwards in capitals, one word per line: ONE TWO THREE FOUR FIVE.' )
		);

		$this->assertStringNotContainsString( 'THREE', $result->toText() );
	}

	/**
	 * An unknown model is rejected by the API rather than silently answered.
	 */
	public function test_an_unknown_model_is_rejected_by_the_api(): void {
		$model = new MittwaldTextGenerationModel(
			new ModelMetadata(
				'definitely-not-a-real-model',
				'definitely-not-a-real-model',
				array( CapabilityEnum::textGeneration() ),
				array()
			),
			MittwaldAIProvider::metadata()
		);
		$this->registry()->bindModelDependencies( $model );

		$this->expectException( ClientException::class );

		$model->generateTextResult( $this->user_prompt( 'Hello' ) );
	}
}
