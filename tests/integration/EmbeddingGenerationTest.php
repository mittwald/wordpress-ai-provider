<?php
/**
 * Integration tests for embedding generation.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Integration;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\Tests\Includes\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\EmbeddingBuilder;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Embedding;
use WordPress\AiClient\Results\DTO\EmbeddingResult;

/**
 * Exercises the `embeddings` endpoint against the real API.
 *
 * Scope here is what this plugin has to get right against a live endpoint: the
 * request shape it builds, and the response shape it parses back. The vector
 * width is asserted because the plugin reports it; the quality of the vectors
 * is the model's business and is left to mittwald.
 *
 * The batch cases carry the most weight, since neither documentation page says
 * whether `input` accepts an array — both examples embed a single string.
 */
final class EmbeddingGenerationTest extends IntegrationTestCase {

	/**
	 * The model mittwald documents for embeddings.
	 *
	 * @var string
	 */
	private const MODEL_ID = 'Qwen3-Embedding-8B';

	/**
	 * The vector width the model documentation pins down.
	 *
	 * @var int
	 */
	private const DIMENSIONS = 4096;

	/**
	 * Returns the embedding model, skipping if it is not on offer.
	 */
	private function embedding_model(): EmbeddingGenerationModelInterface {
		$model = $this->model( self::MODEL_ID );

		$this->assertInstanceOf( EmbeddingGenerationModelInterface::class, $model );

		return $model;
	}

	/**
	 * Embeds the given texts in one request.
	 *
	 * @param string ...$texts The texts to embed.
	 */
	private function embed( string ...$texts ): EmbeddingResult {
		$inputs = array_map(
			static function ( string $text ): MessagePart {
				return new MessagePart( $text );
			},
			$texts
		);

		return $this->embedding_model()->generateEmbeddingResult( $inputs );
	}

	/**
	 * Returns the cosine similarity of two vectors.
	 *
	 * @param Embedding $a First vector.
	 * @param Embedding $b Second vector.
	 */
	private function cosine_similarity( Embedding $a, Embedding $b ): float {
		$left  = $a->getValues();
		$right = $b->getValues();

		$this->assertSame( count( $left ), count( $right ) );

		$dot        = 0.0;
		$left_norm  = 0.0;
		$right_norm = 0.0;
		$dimensions = count( $left );

		for ( $i = 0; $i < $dimensions; $i++ ) {
			$dot        += (float) $left[ $i ] * (float) $right[ $i ];
			$left_norm  += (float) $left[ $i ] ** 2;
			$right_norm += (float) $right[ $i ] ** 2;
		}

		$this->assertGreaterThan( 0.0, $left_norm, 'An embedding should not be the zero vector.' );
		$this->assertGreaterThan( 0.0, $right_norm, 'An embedding should not be the zero vector.' );

		return $dot / ( sqrt( $left_norm ) * sqrt( $right_norm ) );
	}

	/**
	 * A single input produces one vector of the documented width.
	 */
	public function test_a_single_input_yields_one_vector_of_the_documented_width(): void {
		$result = $this->embed( 'An important document' );

		$this->assertCount( 1, $result->getEmbeddings() );
		$this->assertSame( self::DIMENSIONS, $result->getDimensions() );
		$this->assertCount( self::DIMENSIONS, $result->getEmbedding()->getValues() );
		$this->assertSame( self::MODEL_ID, $result->getModelMetadata()->getId() );
	}

	/**
	 * The endpoint reports the tokens an embedding request consumed.
	 */
	public function test_token_usage_is_reported(): void {
		$usage = $this->embed( 'An important document' )->getTokenUsage();

		$this->assertGreaterThan( 0, $usage->getPromptTokens() );
		$this->assertGreaterThan( 0, $usage->getTotalTokens() );
	}

	/**
	 * A batch is answered with one vector per input, in input order.
	 *
	 * Neither documentation page says whether the endpoint accepts an array of
	 * inputs, so this is the check that records it. Order is verified against
	 * vectors embedded one at a time rather than by trusting the response: the
	 * SDK maps embeddings to inputs positionally, so a batch coming back in a
	 * different order would silently attach every vector to the wrong input.
	 */
	public function test_a_batch_yields_one_vector_per_input_in_input_order(): void {
		$first  = 'The cat sat on the mat.';
		$second = 'Berlin is the capital of Germany.';

		$batch = $this->embed( $first, $second );

		$this->assertCount( 2, $batch->getEmbeddings() );
		$this->assertSame( self::DIMENSIONS, $batch->getDimensions() );

		$separate_first  = $this->embed( $first )->getEmbedding();
		$separate_second = $this->embed( $second )->getEmbedding();

		$embeddings = $batch->getEmbeddings();

		$this->assertGreaterThan(
			0.99,
			$this->cosine_similarity( $embeddings[0], $separate_first ),
			'The first vector of the batch does not match the first input embedded on its own.'
		);
		$this->assertGreaterThan(
			0.99,
			$this->cosine_similarity( $embeddings[1], $separate_second ),
			'The second vector of the batch does not match the second input embedded on its own.'
		);
	}

	/**
	 * The builder discovers the embedding model and generates through it.
	 *
	 * This is the whole chain a site takes: the metadata directory advertises
	 * the capability, the registry matches it, `createModel()` routes to the
	 * model class, and `EmbeddingBuilder` checks that class satisfies
	 * `EmbeddingGenerationModelInterface` before calling it.
	 */
	public function test_embeddings_through_the_builder(): void {
		$embeddings = $this->builder( 'An important document', 'Another document' )->generateEmbeddings();

		$this->assertCount( 2, $embeddings );

		foreach ( $embeddings as $embedding ) {
			$this->assertCount( self::DIMENSIONS, $embedding->getValues() );
		}
	}

	/**
	 * The provider reports itself as usable for embedding generation.
	 */
	public function test_provider_is_reported_as_supporting_embeddings(): void {
		$this->assertTrue(
			$this->builder( 'An important document' )->isSupported(),
			'The provider advertises no model usable for embedding generation.'
		);
	}

	/**
	 * Every documented vector width is honoured by the endpoint.
	 *
	 * The model documentation lists a discrete set of widths, and the plugin
	 * advertises exactly that set. This holds the endpoint to it: a width that
	 * stops being served turns into a capability the plugin claims and cannot
	 * deliver, and this is the only check that would notice.
	 *
	 * @param int $width A width the model documentation lists.
	 *
	 * @dataProvider provide_documented_dimensions
	 */
	#[DataProvider( 'provide_documented_dimensions' )]
	public function test_a_requested_vector_width_is_honoured( int $width ): void {
		$embeddings = $this->builder( 'An important document' )
			->usingDimensions( $width )
			->generateEmbeddings();

		$this->assertCount( 1, $embeddings );
		$this->assertCount( $width, $embeddings[0]->getValues() );
		$this->assertSame( $width, $embeddings[0]->getDimensions() );
	}

	/**
	 * The widths the model documentation lists.
	 *
	 * @return list<array{int}>
	 */
	public static function provide_documented_dimensions(): array {
		return array(
			array( 256 ),
			array( 512 ),
			array( 768 ),
			array( 1024 ),
			array( 1536 ),
			array( 2048 ),
			array( 3072 ),
			array( 4096 ),
		);
	}

	/**
	 * A projected vector comes back L2-normalised.
	 *
	 * The documentation states the endpoint normalises after reducing, which is
	 * what lets a caller use the vector directly for dot-product similarity. A
	 * deployment that skipped the step would leave that silently wrong.
	 */
	public function test_a_projected_vector_is_l2_normalised(): void {
		$embedding = $this->builder( 'An important document' )
			->usingDimensions( 256 )
			->generateEmbedding();

		$norm = 0.0;
		foreach ( $embedding->getValues() as $value ) {
			$norm += (float) $value ** 2;
		}

		$this->assertEqualsWithDelta( 1.0, sqrt( $norm ), 0.01, 'A projected vector should be L2-normalised.' );
	}

	/**
	 * A request carrying a width still discovers the model.
	 *
	 * Discovery matches on advertised options, so omitting `dimensions` from
	 * the metadata would remove the model from the running for this request
	 * without any error being raised.
	 */
	public function test_a_request_carrying_a_width_is_still_supported(): void {
		$this->assertTrue(
			$this->builder( 'An important document' )->usingDimensions( 256 )->isSupported(),
			'The embedding model advertises the dimensions option, so it should match.'
		);
	}

	/**
	 * Starts an embedding builder bound to this provider.
	 *
	 * Deliberately does not name a model: letting the builder discover one is
	 * what exercises the metadata this plugin publishes.
	 *
	 * @param string ...$texts The texts to embed.
	 */
	private function builder( string ...$texts ): EmbeddingBuilder {
		$request_options = new RequestOptions();
		$request_options->setTimeout( self::REQUEST_TIMEOUT );

		return AiClient::input( $texts, $this->registry() )
			->usingProvider( MittwaldAIProvider::class )
			->usingRequestOptions( $request_options );
	}
}
