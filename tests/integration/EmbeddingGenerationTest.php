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
	 * Asking for a narrower vector finds no model rather than a wider one.
	 *
	 * The model emits vectors of a fixed width and rejects the `dimensions`
	 * parameter, so the plugin does not advertise the option. Reporting "no
	 * suitable model" is the intended outcome; answering with a 4096-wide
	 * vector would silently ignore what the caller asked for.
	 */
	public function test_a_narrower_vector_is_not_on_offer(): void {
		$this->assertFalse(
			$this->builder( 'An important document' )->usingDimensions( 256 )->isSupported(),
			'No model on offer can project to a narrower vector, so none should match.'
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
