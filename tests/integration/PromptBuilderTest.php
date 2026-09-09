<?php
/**
 * Integration tests that drive the provider through the SDK's prompt builder.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Integration;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\Tests\Includes\IntegrationTestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\PromptBuilder;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Exercises the entry point a WordPress site actually uses.
 *
 * The other integration tests call `generateTextResult()` on a model object the
 * test resolved itself. That skips two things a site never skips: the SDK
 * discovering which model to use from the metadata this plugin publishes, and
 * `PromptBuilder` gating on the interfaces the model classes implement. A model
 * that stops satisfying `TextGenerationModelInterface` still answers a direct
 * call — its methods are inherited from the SDK base — and simply disappears
 * from every builder-driven path. Only these tests would notice.
 */
final class PromptBuilderTest extends IntegrationTestCase {

	/**
	 * Completion budget for tests that assert on content.
	 *
	 * The first model discovery picks is a reasoning model, so the budget has to
	 * cover the reasoning block as well as the answer.
	 *
	 * @var int
	 */
	private const CONTENT_BUDGET = 1024;

	/**
	 * Starts a builder bound to this provider.
	 *
	 * Deliberately does not name a model: letting the builder discover one is
	 * what exercises the metadata this plugin publishes.
	 *
	 * It also does not set a token budget. Discovery matches on the options a
	 * model advertises, so requiring `maxTokens` excludes every model that does
	 * not list it — the speech model among them, correctly, since a token limit
	 * means nothing for speech synthesis. Text tests add the budget themselves.
	 *
	 * @param string|null $text Optional prompt text.
	 */
	private function builder( ?string $text = null ): PromptBuilder {
		$request_options = new RequestOptions();
		$request_options->setTimeout( self::REQUEST_TIMEOUT );

		return AiClient::prompt( $text, $this->registry() )
			->usingProvider( MittwaldAIProvider::class )
			->usingRequestOptions( $request_options );
	}

	/**
	 * The builder discovers a text model and generates through it.
	 *
	 * This is the whole chain: the metadata directory advertises capabilities,
	 * the registry matches them, `createModel()` routes to a model class, and
	 * `PromptBuilder` checks that class satisfies
	 * `TextGenerationModelInterface` before calling it.
	 */
	public function test_text_generation_through_the_builder(): void {
		$answer = $this->builder( 'Reply with exactly the word: pong' )
			->usingMaxTokens( self::CONTENT_BUDGET )
			->usingTemperature( 0.0 )
			->generateText();

		$this->assertNotSame( '', trim( $answer ) );
	}

	/**
	 * The provider reports itself as usable for text generation.
	 *
	 * `isSupported()` infers the capability from the model's interfaces, so a
	 * model class that lost one reports false here rather than erroring.
	 */
	public function test_provider_is_reported_as_supporting_text_generation(): void {
		$this->assertTrue(
			$this->builder( 'Anything.' )->usingMaxTokens( self::CONTENT_BUDGET )->isSupportedForTextGeneration(),
			'The provider advertises no model usable for text generation.'
		);
	}

	/**
	 * The provider reports itself as usable for speech conversion.
	 */
	public function test_provider_is_reported_as_supporting_speech_conversion(): void {
		$this->assertTrue(
			$this->builder( 'Anything.' )->isSupportedForTextToSpeechConversion(),
			'The provider advertises no model usable for text-to-speech conversion.'
		);
	}

	/**
	 * Capabilities the provider has no model for are reported as unsupported.
	 *
	 * The negative case matters as much as the positive one: a model wrongly
	 * advertising a capability shows up here rather than as a failed generation
	 * in front of a user.
	 */
	public function test_unavailable_capabilities_are_reported_as_unsupported(): void {
		$builder = $this->builder( 'Anything.' );

		$this->assertFalse(
			$builder->isSupportedForImageGeneration(),
			'mittwald AI hosting offers no image model, so the builder should say so.'
		);
		$this->assertFalse(
			$builder->isSupported( CapabilityEnum::videoGeneration() ),
			'mittwald AI hosting offers no video model, so the builder should say so.'
		);
	}

	/**
	 * A prompt with an image is routed to a model that accepts image input.
	 *
	 * Discovery has to read the input modalities from the metadata, not just the
	 * capability, so this covers the multimodal option bundle end to end.
	 */
	public function test_image_input_is_routed_to_a_vision_model(): void {
		$image = new File(
			base64_encode( $this->fixture( 'red-circle.png' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'image/png'
		);

		$answer = $this->builder()
			->withText( 'What colour is the shape in this image? Answer in a few words.' )
			->withFile( $image )
			->usingMaxTokens( self::CONTENT_BUDGET )
			->usingTemperature( 0.0 )
			->generateText();

		$this->assertMatchesRegularExpression( '/red|rot/i', $answer );
	}

	/**
	 * Speech conversion is routed to the speech model and returns audio.
	 */
	public function test_speech_conversion_through_the_builder(): void {
		$audio = $this->builder( 'Moin, dies ist ein Test der Sprachausgabe.' )
			->convertTextToSpeech();

		$this->assertTrue( $audio->isAudio() );
		$this->assertTrue( $audio->isInline() );

		$bytes = (string) base64_decode( (string) $audio->getBase64Data(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertGreaterThan( 1024, strlen( $bytes ) );
	}

	/**
	 * A requested voice reaches the speech endpoint through the builder.
	 */
	public function test_speech_voice_is_carried_through_the_builder(): void {
		$audio = $this->builder( 'Kurzer Test.' )
			->asOutputSpeechVoice( 'serena' )
			->convertTextToSpeech();

		$this->assertTrue( $audio->isAudio() );
		$this->assertNotSame( '', (string) $audio->getBase64Data() );
	}

	/**
	 * A JSON schema asked for through the builder is honoured.
	 */
	public function test_json_response_through_the_builder(): void {
		$answer = $this->builder( 'Give me the capital of Germany and roughly how many people live there.' )
			->usingMaxTokens( self::CONTENT_BUDGET )
			->usingTemperature( 0.0 )
			->asJsonResponse(
				array(
					'type'                 => 'object',
					'properties'           => array(
						'city'       => array( 'type' => 'string' ),
						'population' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'city', 'population' ),
					'additionalProperties' => false,
				)
			)
			->generateText();

		$decoded = json_decode( $answer, true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'city', $decoded );
		$this->assertArrayHasKey( 'population', $decoded );
	}

	/**
	 * A model named explicitly still passes the builder's interface gate.
	 *
	 * `usingModel()` skips discovery but not `executeModelGeneration()`, which
	 * is where the interface check lives.
	 */
	public function test_an_explicitly_named_model_passes_the_interface_gate(): void {
		$model = $this->first_available_model(
			array( 'gpt-oss-120b', 'Ministral-3-14B-Instruct-2512', 'Qwen3.5-0.8B' )
		);

		$request_options = new RequestOptions();
		$request_options->setTimeout( self::REQUEST_TIMEOUT );

		$answer = AiClient::prompt( 'Reply with exactly the word: pong', $this->registry() )
			->usingModel( $model )
			->usingRequestOptions( $request_options )
			->usingMaxTokens( self::CONTENT_BUDGET )
			->usingTemperature( 0.0 )
			->generateText();

		$this->assertNotSame( '', trim( $answer ) );
	}
}
