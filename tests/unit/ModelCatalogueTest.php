<?php
/**
 * Audits the model catalogue against the documented lineup.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\Tests\Includes\ModelCatalogue;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use ReflectionMethod;

/**
 * Checks the plugin's model switch against the lineup mittwald documents.
 *
 * The other unit tests pin down what each model resolves to. This one asks a
 * different question: does the set of models the plugin knows about still match
 * the set mittwald actually offers? That is drift against an external document
 * rather than a defect in the code, so the audit reports rather than fails —
 * the lineup moving is not a bug in this plugin, and it should not turn a build
 * red on somebody else's release.
 *
 * Edit {@see ModelCatalogue} when the lineup changes; nothing here is hardcoded.
 */
final class ModelCatalogueTest extends TestCase {

	/**
	 * Every documented model still resolves through the metadata directory.
	 *
	 * This one does fail: a model the directory drops entirely is a parsing
	 * bug, not catalogue drift.
	 */
	public function test_every_documented_model_survives_parsing(): void {
		$resolved = $this->resolve_model_metadata( ModelCatalogue::CURRENT );

		foreach ( ModelCatalogue::CURRENT as $model_id ) {
			$this->assertArrayHasKey(
				$model_id,
				$resolved,
				"The metadata directory dropped {$model_id} instead of listing it."
			);
		}
	}

	/**
	 * Documented models the plugin exposes no capability for are reported.
	 *
	 * A model with no capabilities is invisible in the picker. Some of those
	 * are known gaps — reranking and speech-to-text have no model class yet —
	 * and those are listed in the catalogue so they can be told apart from a
	 * model that was simply missed.
	 */
	public function test_documented_models_without_capabilities_are_known_gaps(): void {
		$unexpected = array();

		foreach ( $this->resolve_model_metadata( ModelCatalogue::CURRENT ) as $model_id => $metadata ) {
			if ( array() !== $metadata->getSupportedCapabilities() ) {
				continue;
			}

			if ( in_array( $model_id, ModelCatalogue::UNIMPLEMENTED, true ) ) {
				continue;
			}

			$unexpected[] = $model_id;
		}

		$this->assertSame(
			array(),
			$unexpected,
			'These models are in the documented lineup but carry no capabilities, and are not '
			. 'listed as a known gap in ModelCatalogue::UNIMPLEMENTED. Either give them '
			. 'capabilities in MittwaldModelMetadataDirectory, or add them to that list: '
			. implode( ', ', $unexpected )
		);
	}

	/**
	 * The known gaps are still gaps.
	 *
	 * If one of these grows a capability, the catalogue entry is stale and the
	 * model should move out of the gap list.
	 */
	public function test_known_gaps_still_claim_nothing(): void {
		$now_implemented = array();

		foreach ( $this->resolve_model_metadata( ModelCatalogue::UNIMPLEMENTED ) as $model_id => $metadata ) {
			if ( array() !== $metadata->getSupportedCapabilities() ) {
				$now_implemented[] = $model_id;
			}
		}

		$this->assertSame(
			array(),
			$now_implemented,
			'These models now claim capabilities but are still listed as unimplemented gaps '
			. 'in ModelCatalogue::UNIMPLEMENTED; remove them from that list: '
			. implode( ', ', $now_implemented )
		);
	}

	/**
	 * Retired models that still claim capabilities are reported.
	 *
	 * A `case` in `MittwaldModelMetadataDirectory` for a model mittwald no
	 * longer lists is dead weight, but removing one is a judgement call rather
	 * than an obvious fix: an account may still have access to a model that has
	 * left the public table. So this reports rather than fails.
	 */
	public function test_retired_models_no_longer_claim_capabilities(): void {
		$stale = array();

		foreach ( $this->resolve_model_metadata( ModelCatalogue::RETIRED ) as $model_id => $metadata ) {
			if ( array() !== $metadata->getSupportedCapabilities() ) {
				$stale[] = $model_id;
			}
		}

		$this->assertNotEmpty(
			ModelCatalogue::RETIRED,
			'The retired list is empty, so this audit is checking nothing.'
		);

		if ( array() !== $stale ) {
			$this->markTestIncomplete(
				'These models have left the documented lineup but still have a case in '
				. 'MittwaldModelMetadataDirectory, so they keep their capabilities. Decide '
				. 'whether accounts still have access to them before removing the cases: '
				. implode( ', ', $stale )
			);
		}
	}

	/**
	 * Every routable documented model reaches a model class.
	 *
	 * A capability the provider does not route is a runtime exception rather
	 * than a missing picker entry, so this is a failure rather than a report.
	 */
	public function test_routable_models_reach_a_model_class(): void {
		$create_model = new ReflectionMethod( MittwaldAIProvider::class, 'createModel' );

		// Redundant, and deprecated, from PHP 8.1 onwards.
		if ( PHP_VERSION_ID < 80100 ) {
			$create_model->setAccessible( true );
		}

		$provider_metadata = MittwaldAIProvider::metadata();

		foreach ( $this->resolve_model_metadata( ModelCatalogue::routable() ) as $model_id => $metadata ) {
			$model = $create_model->invokeArgs( null, array( $metadata, $provider_metadata ) );

			$this->assertNotNull( $model, "{$model_id} did not resolve to a model class." );
		}
	}

	/**
	 * The documented lineup and the retired list do not overlap.
	 *
	 * Guards the catalogue itself: a model in both lists makes every audit
	 * above contradict itself.
	 */
	public function test_catalogue_lists_are_disjoint(): void {
		$this->assertSame(
			array(),
			array_values( array_intersect( ModelCatalogue::CURRENT, ModelCatalogue::RETIRED ) ),
			'A model cannot be both current and retired.'
		);

		$this->assertSame(
			array(),
			array_values( array_diff( ModelCatalogue::UNIMPLEMENTED, ModelCatalogue::CURRENT ) ),
			'Every known gap must be a currently offered model.'
		);
	}

	/**
	 * The catalogue lists are free of duplicates.
	 */
	public function test_catalogue_lists_have_no_duplicates(): void {
		foreach ( array( 'CURRENT', 'RETIRED', 'UNIMPLEMENTED' ) as $list ) {
			/** @var list<string> $values */
			$values = constant( ModelCatalogue::class . '::' . $list );

			$this->assertSame(
				array_values( array_unique( $values ) ),
				$values,
				"ModelCatalogue::{$list} contains a duplicate."
			);
		}
	}
}
