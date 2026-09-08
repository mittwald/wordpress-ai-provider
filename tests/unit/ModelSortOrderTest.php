<?php
/**
 * Tests for the order models are presented in.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\Tests\Includes\ModelCatalogue;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers `MittwaldModelMetadataDirectory::modelSortCallback()`.
 *
 * The sort order is what a site sees in its model picker, so each preference
 * the callback expresses gets its own case here.
 */
final class ModelSortOrderTest extends TestCase {

	/**
	 * Sorts model IDs the way the directory would.
	 *
	 * @param list<string> $model_ids Model IDs in arbitrary order.
	 *
	 * @return list<string> Model IDs in the order the directory presents them.
	 */
	private function sorted( array $model_ids ): array {
		return array_keys( $this->resolve_model_metadata( $model_ids ) );
	}

	/**
	 * Each individual sort preference holds.
	 *
	 * @param string       $description Human-readable description of the rule.
	 * @param list<string> $input       Model IDs to sort.
	 * @param list<string> $expected    Expected order.
	 *
	 * @dataProvider provide_sort_preferences
	 */
	#[DataProvider( 'provide_sort_preferences' )]
	public function test_sort_preferences( string $description, array $input, array $expected ): void {
		$this->assertSame( $expected, $this->sorted( $input ), $description );
	}

	/**
	 * Sort rules, each expressed as an input order and the expected output order.
	 *
	 * @return array<string, array{string, list<string>, list<string>}>
	 */
	public static function provide_sort_preferences(): array {
		return array(
			'gpt- models come before other families'      => array(
				'A gpt- model outranks a non-gpt one.',
				array( 'Qwen3.5-0.8B', 'gpt-oss-120b' ),
				array( 'gpt-oss-120b', 'Qwen3.5-0.8B' ),
			),
			'preview models come last'                    => array(
				'A preview model is ranked below a stable one, even a non-gpt one.',
				array( 'gpt-5-preview', 'Qwen3.5-0.8B' ),
				array( 'Qwen3.5-0.8B', 'gpt-5-preview' ),
			),
			'versioned gpt- models beat unversioned ones' => array(
				'gpt-5 outranks gpt-oss-120b, which carries no version number.',
				array( 'gpt-oss-120b', 'gpt-5' ),
				array( 'gpt-5', 'gpt-oss-120b' ),
			),
			'later versions come first'                   => array(
				'gpt-5.1 outranks gpt-5, which outranks gpt-4.1.',
				array( 'gpt-4.1', 'gpt-5', 'gpt-5.1' ),
				array( 'gpt-5.1', 'gpt-5', 'gpt-4.1' ),
			),
			'base models beat suffixed ones'              => array(
				'gpt-5 outranks gpt-5-mini and gpt-5-nano.',
				array( 'gpt-5-nano', 'gpt-5-mini', 'gpt-5' ),
				array( 'gpt-5', 'gpt-5-mini', 'gpt-5-nano' ),
			),
			'mini beats other suffixes'                   => array(
				'Among suffixed models of the same version, -mini comes first.',
				array( 'gpt-5-nano', 'gpt-5-mini' ),
				array( 'gpt-5-mini', 'gpt-5-nano' ),
			),
			'alphabetical fallback'                       => array(
				'Models the rules say nothing about are ordered alphabetically.',
				array( 'Qwen3.6-35B-A3B-FP8', 'GLM-OCR', 'Ministral-3-14B-Instruct-2512' ),
				array( 'GLM-OCR', 'Ministral-3-14B-Instruct-2512', 'Qwen3.6-35B-A3B-FP8' ),
			),
		);
	}

	/**
	 * Sorting does not depend on the order the API happens to report models in.
	 */
	public function test_sort_order_is_independent_of_input_order(): void {
		$model_ids = array(
			'gpt-oss-120b',
			'Qwen3.5-0.8B',
			'Ministral-3-14B-Instruct-2512',
			'Qwen3.5-122B-A10B-FP8',
			'Qwen3.6-35B-A3B-FP8',
			'Qwen3.8-27B-NVFP4',
			'GLM-OCR',
			'Qwen3-Embedding-8B',
			'Qwen3-TTS-12Hz-1.7B-CustomVoice',
		);

		$forwards  = $this->sorted( $model_ids );
		$backwards = $this->sorted( array_reverse( $model_ids ) );

		$this->assertSame( $forwards, $backwards );
	}

	/**
	 * The current lineup lands in the order the plugin intends.
	 *
	 * This pins the order a site actually sees today, so a change to the sort
	 * callback or the catalogue shows up as a deliberate update here.
	 */
	public function test_current_lineup_is_presented_in_the_expected_order(): void {
		$sorted = $this->sorted( ModelCatalogue::CURRENT );

		$this->assertSame(
			array(
				'gpt-oss-120b',
				'GLM-OCR',
				'Ministral-3-14B-Instruct-2512',
				'Qwen3-Embedding-8B',
				'Qwen3-TTS-12Hz-1.7B-CustomVoice',
				'Qwen3-VL-Reranker-2B',
				'Qwen3.5-0.8B',
				'Qwen3.5-122B-A10B-FP8',
				'Qwen3.6-35B-A3B-FP8',
				'Qwen3.8-27B-NVFP4',
				'whisper-large-v3-turbo',
			),
			$sorted
		);
	}
}
