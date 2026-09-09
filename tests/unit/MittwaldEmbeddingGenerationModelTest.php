<?php
/**
 * Tests for the embedding generation model.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldEmbeddingGenerationModel;
use Mittwald\AiProvider\Tests\Includes\FakeHttpTransporter;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Covers the `embeddings` implementation the plugin writes by hand.
 *
 * As with text-to-speech, the SDK ships no OpenAI-compatible base class for
 * embedding generation, so this model class owns its whole request/response
 * cycle and every part of it is worth pinning down here.
 */
final class MittwaldEmbeddingGenerationModelTest extends TestCase {

	/**
	 * The model ID mittwald offers for embeddings.
	 *
	 * @var string
	 */
	private const EMBEDDING_MODEL_ID = 'Qwen3-Embedding-8B';

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
	 * Builds a wired-up embedding generation model.
	 *
	 * @param ModelConfig|null $config Optional configuration.
	 */
	private function model( ?ModelConfig $config = null ): MittwaldEmbeddingGenerationModel {
		$model = new MittwaldEmbeddingGenerationModel(
			$this->model_metadata( self::EMBEDDING_MODEL_ID ),
			MittwaldAIProvider::metadata()
		);

		if ( null !== $config ) {
			$model->setConfig( $config );
		}

		return $this->wire( $model, $this->transporter );
	}

	/**
	 * Builds text inputs, one message part per string.
	 *
	 * @param string ...$texts Input texts.
	 *
	 * @return list<MessagePart>
	 */
	private function inputs( string ...$texts ): array {
		return array_map(
			static function ( string $text ): MessagePart {
				return new MessagePart( $text );
			},
			$texts
		);
	}

	/**
	 * Builds a vector of the given width, distinguishable by its first value.
	 *
	 * @param int   $dimensions Vector width.
	 * @param float $marker     Value to put in the first position.
	 *
	 * @return list<float>
	 */
	private function vector( int $dimensions, float $marker ): array {
		$values = array_fill( 0, $dimensions, 0.5 );

		$values[0] = $marker;

		return $values;
	}

	/**
	 * Queues a successful embeddings response.
	 *
	 * @param list<array{index: int, embedding: list<float>}> $data  Response entries.
	 * @param array<string, int>                              $usage Token usage to report.
	 */
	private function queue_embeddings( array $data, array $usage = array() ): void {
		$entries = array();
		foreach ( $data as $entry ) {
			$entries[] = array(
				'object'    => 'embedding',
				'index'     => $entry['index'],
				'embedding' => $entry['embedding'],
			);
		}

		$this->transporter->queue_json(
			array(
				'object' => 'list',
				'data'   => $entries,
				'model'  => self::EMBEDDING_MODEL_ID,
				'usage'  => array() === $usage
					? array(
						'prompt_tokens' => 4,
						'total_tokens'  => 4,
					)
					: $usage,
			)
		);
	}

	/**
	 * Queues a response embedding a single input.
	 *
	 * @param int   $dimensions Vector width.
	 * @param float $marker     Value to put in the vector's first position.
	 */
	private function queue_single_embedding( int $dimensions = 8, float $marker = 0.1 ): void {
		$this->queue_embeddings(
			array(
				array(
					'index'     => 0,
					'embedding' => $this->vector( $dimensions, $marker ),
				),
			)
		);
	}

	/**
	 * Embedding requests are posted to the provider's own embeddings endpoint.
	 */
	public function test_embeddings_are_posted_to_the_mittwald_endpoint(): void {
		$this->queue_single_embedding();

		$this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );

		$request = $this->transporter->last_request();

		$this->assertSame( 'https://llm.aihosting.mittwald.de/v1/embeddings', $request->getUri() );
		$this->assertTrue( $request->getMethod()->equals( HttpMethodEnum::POST() ) );
		$this->assertSame( 'application/json', $request->getHeaderAsString( 'Content-Type' ) );
		$this->assertSame( 'Bearer ' . self::TEST_API_KEY, $request->getHeaderAsString( 'Authorization' ) );
	}

	/**
	 * The request carries the model, the inputs and the documented encoding.
	 */
	public function test_request_body_matches_the_documented_shape(): void {
		$this->queue_single_embedding();

		$this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );

		$this->assertSame(
			array(
				'model'           => self::EMBEDDING_MODEL_ID,
				'input'           => array( 'An important document' ),
				'encoding_format' => MittwaldEmbeddingGenerationModel::ENCODING_FORMAT,
			),
			$this->transporter->last_request_payload()
		);
	}

	/**
	 * A batch of inputs goes out as one request, in input order.
	 */
	public function test_a_batch_is_sent_as_a_single_request(): void {
		$this->queue_embeddings(
			array(
				array(
					'index'     => 0,
					'embedding' => $this->vector( 8, 0.1 ),
				),
				array(
					'index'     => 1,
					'embedding' => $this->vector( 8, 0.2 ),
				),
			)
		);

		$this->model()->generateEmbeddingResult( $this->inputs( 'first', 'second' ) );

		$this->assertSame( 1, $this->transporter->request_count() );
		$this->assertSame(
			array( 'first', 'second' ),
			$this->transporter->last_request_payload()['input']
		);
	}

	/**
	 * Embeddings come back in input order, whatever order the API sends them in.
	 *
	 * The SDK maps embeddings to inputs positionally, so an out-of-order
	 * response that was passed straight through would attach every vector to
	 * the wrong input without anything noticing.
	 */
	public function test_embeddings_are_returned_in_input_order(): void {
		$this->queue_embeddings(
			array(
				array(
					'index'     => 2,
					'embedding' => $this->vector( 8, 0.3 ),
				),
				array(
					'index'     => 0,
					'embedding' => $this->vector( 8, 0.1 ),
				),
				array(
					'index'     => 1,
					'embedding' => $this->vector( 8, 0.2 ),
				),
			)
		);

		$result = $this->model()->generateEmbeddingResult( $this->inputs( 'first', 'second', 'third' ) );

		$markers = array_map(
			static function ( $embedding ): float {
				return (float) $embedding->getValues()[0];
			},
			$result->getEmbeddings()
		);

		$this->assertSame( array( 0.1, 0.2, 0.3 ), $markers );
	}

	/**
	 * The result reports the width of the vectors the API returned.
	 */
	public function test_result_reports_the_vector_dimensions(): void {
		$this->queue_single_embedding( 4096 );

		$result = $this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );

		$this->assertSame( 4096, $result->getDimensions() );
		$this->assertCount( 4096, $result->getEmbedding()->getValues() );
		$this->assertSame( 4096, $result->getEmbedding()->getDimensions() );
	}

	/**
	 * Token usage is carried over from the API's own accounting.
	 */
	public function test_token_usage_is_mapped_from_the_response(): void {
		$this->queue_embeddings(
			array(
				array(
					'index'     => 0,
					'embedding' => $this->vector( 8, 0.1 ),
				),
			),
			array(
				'prompt_tokens' => 12,
				'total_tokens'  => 12,
			)
		);

		$usage = $this->model()
			->generateEmbeddingResult( $this->inputs( 'An important document' ) )
			->getTokenUsage();

		$this->assertSame( 12, $usage->getPromptTokens() );
		$this->assertSame( 12, $usage->getTotalTokens() );
		// Embedding requests generate no completion.
		$this->assertSame( 0, $usage->getCompletionTokens() );
	}

	/**
	 * A response without usage accounting is reported as zero usage.
	 */
	public function test_missing_token_usage_is_reported_as_zero(): void {
		$this->transporter->queue_json(
			array(
				'data' => array(
					array(
						'index'     => 0,
						'embedding' => $this->vector( 8, 0.1 ),
					),
				),
			)
		);

		$usage = $this->model()
			->generateEmbeddingResult( $this->inputs( 'An important document' ) )
			->getTokenUsage();

		$this->assertSame( 0, $usage->getPromptTokens() );
		$this->assertSame( 0, $usage->getTotalTokens() );
	}

	/**
	 * The result carries the provider and model it came from.
	 */
	public function test_result_carries_provider_and_model_metadata(): void {
		$this->queue_single_embedding();

		$result = $this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );

		$this->assertSame( 'mittwald', $result->getProviderMetadata()->getId() );
		$this->assertSame( self::EMBEDDING_MODEL_ID, $result->getModelMetadata()->getId() );
	}

	/**
	 * A non-text input is rejected rather than dropped.
	 *
	 * Dropping it would shift every embedding after it onto the wrong input.
	 */
	public function test_a_non_text_input_is_rejected(): void {
		$inputs = array(
			new MessagePart( 'An important document' ),
			new MessagePart( new File( 'aGVsbG8=', 'image/png' ) ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'only embeds text' );

		try {
			$this->model()->generateEmbeddingResult( $inputs );
		} finally {
			$this->assertSame( 0, $this->transporter->request_count() );
		}
	}

	/**
	 * An empty input list is rejected before a request is made.
	 */
	public function test_an_empty_input_list_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'at least one input' );

		try {
			$this->model()->generateEmbeddingResult( array() );
		} finally {
			$this->assertSame( 0, $this->transporter->request_count() );
		}
	}

	/**
	 * A configured width is rejected by a model that does not advertise it.
	 *
	 * `Qwen3-Embedding-8B` emits fixed-width vectors, so its metadata omits the
	 * option and the SDK's own resolution never hands one over. A directly
	 * constructed model skips that check, and returning full-width vectors
	 * anyway would misreport what was asked for.
	 */
	public function test_a_configured_dimension_is_rejected_when_unsupported(): void {
		$config = new ModelConfig();
		$config->setDimensions( 256 );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not support the dimensions option' );

		try {
			$this->model( $config )->generateEmbeddingResult( $this->inputs( 'An important document' ) );
		} finally {
			$this->assertSame( 0, $this->transporter->request_count() );
		}
	}

	/**
	 * A model that advertises the option forwards the configured width.
	 *
	 * Whether a width can be requested is a property of the individual model,
	 * so the model class reads it from that model's own metadata. Nothing about
	 * `Qwen3-Embedding-8B` is baked into the class: an embedding model whose
	 * entry in `MittwaldModelMetadataDirectory` declares
	 * `OptionEnum::dimensions()` sends the parameter through.
	 */
	public function test_a_configured_dimension_is_forwarded_when_supported(): void {
		$metadata = new ModelMetadata(
			'some-projectable-embedding-model',
			'some-projectable-embedding-model',
			array( CapabilityEnum::embeddingGeneration() ),
			array(
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::dimensions() ),
				new SupportedOption( OptionEnum::customOptions() ),
			)
		);

		$config = new ModelConfig();
		$config->setDimensions( 4 );

		$model = new MittwaldEmbeddingGenerationModel( $metadata, MittwaldAIProvider::metadata() );
		$model->setConfig( $config );
		$this->wire( $model, $this->transporter );

		$this->queue_embeddings(
			array(
				array(
					'index'     => 0,
					'embedding' => $this->vector( 4, 0.1 ),
				),
			)
		);

		$result = $model->generateEmbeddingResult( $this->inputs( 'An important document' ) );

		$this->assertSame( 4, $this->transporter->last_request_payload()['dimensions'] );
		$this->assertSame( 4, $result->getDimensions() );
	}

	/**
	 * Custom options are merged into the request.
	 */
	public function test_custom_options_are_merged_into_the_request(): void {
		$this->queue_single_embedding();

		$config = new ModelConfig();
		$config->setCustomOptions( array( 'user' => 'wp-site' ) );

		$this->model( $config )->generateEmbeddingResult( $this->inputs( 'An important document' ) );

		$this->assertSame( 'wp-site', $this->transporter->last_request_payload()['user'] );
	}

	/**
	 * A custom option cannot silently overwrite a parameter the model owns.
	 *
	 * @param string $option A parameter name the model sets itself.
	 *
	 * @dataProvider provide_reserved_parameters
	 */
	#[DataProvider( 'provide_reserved_parameters' )]
	public function test_custom_options_cannot_override_owned_parameters( string $option ): void {
		$config = new ModelConfig();
		$config->setCustomOptions( array( $option => 'something else' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $option );

		$this->model( $config )->generateEmbeddingResult( $this->inputs( 'An important document' ) );
	}

	/**
	 * Parameters the model sets itself.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_reserved_parameters(): array {
		return array(
			array( 'model' ),
			array( 'input' ),
			array( 'encoding_format' ),
		);
	}

	/**
	 * A response without a `data` key is an error, not an empty result.
	 */
	public function test_a_response_without_data_is_rejected(): void {
		$this->transporter->queue_json( array( 'object' => 'list' ) );

		$this->expectException( ResponseException::class );

		$this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );
	}

	/**
	 * An empty `data` array is treated the same way as a missing one.
	 */
	public function test_an_empty_data_array_is_rejected(): void {
		$this->transporter->queue_json( array( 'data' => array() ) );

		$this->expectException( ResponseException::class );

		$this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );
	}

	/**
	 * An entry without a vector is rejected.
	 */
	public function test_an_entry_without_a_vector_is_rejected(): void {
		$this->transporter->queue_json(
			array( 'data' => array( array( 'index' => 0 ) ) )
		);

		$this->expectException( ResponseException::class );

		$this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );
	}

	/**
	 * A vector that is not made of numbers is rejected.
	 */
	public function test_a_non_numeric_vector_is_rejected(): void {
		$this->transporter->queue_json(
			array(
				'data' => array(
					array(
						'index'     => 0,
						'embedding' => array( 0.1, 'not a number', 0.3 ),
					),
				),
			)
		);

		$this->expectException( ResponseException::class );

		$this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );
	}

	/**
	 * Vectors of differing widths within one response are rejected.
	 */
	public function test_vectors_of_differing_widths_are_rejected(): void {
		$this->queue_embeddings(
			array(
				array(
					'index'     => 0,
					'embedding' => $this->vector( 8, 0.1 ),
				),
				array(
					'index'     => 1,
					'embedding' => $this->vector( 4, 0.2 ),
				),
			)
		);

		$this->expectException( ResponseException::class );

		$this->model()->generateEmbeddingResult( $this->inputs( 'first', 'second' ) );
	}

	/**
	 * An API error is surfaced rather than treated as a result.
	 */
	public function test_api_errors_are_surfaced(): void {
		$this->transporter->queue_json(
			array( 'error' => array( 'message' => 'input too long' ) ),
			400
		);

		$this->expectException( ClientException::class );
		$this->expectExceptionMessage( 'input too long' );

		$this->model()->generateEmbeddingResult( $this->inputs( 'An important document' ) );
	}

	/**
	 * Request options set on the model are carried into the request.
	 */
	public function test_request_options_are_passed_through(): void {
		$this->queue_single_embedding();

		$request_options = new RequestOptions();
		$request_options->setTimeout( 120.0 );

		$model = $this->model();
		$model->setRequestOptions( $request_options );
		$model->generateEmbeddingResult( $this->inputs( 'An important document' ) );

		$options = $this->transporter->last_request()->getOptions();

		$this->assertNotNull( $options );
		$this->assertSame( 120.0, $options->getTimeout() );
	}
}
