<?php
/**
 * The mittwald AI hosting model lineup, as documented.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Includes;

use Mittwald\AiProvider\MittwaldModelMetadataDirectory;

/**
 * The model lineup the tests are written against.
 *
 * This is the one place to edit when models are added or retired upstream. It
 * is maintained by hand from the mittwald model table, and that is what turns
 * the tests reading it from a static check into an audit:
 *
 * https://developer.mittwald.de/docs/v2/platform/aihosting/models/
 *
 * Last synchronised: 2026-10-26. Re-fetch the table before trusting a run.
 *
 * @see \Mittwald\AiProvider\Tests\Unit\ModelCatalogueTest
 */
final class ModelCatalogue {

	/**
	 * Every model the documented lineup currently offers.
	 *
	 * @var list<string>
	 */
	public const CURRENT = array(
		'gpt-oss-120b',
		'Qwen3.5-0.8B',
		'Ministral-3-14B-Instruct-2512',
		'Qwen3.5-122B-A10B-FP8',
		'Qwen3.6-35B-A3B-FP8',
		'Qwen3.8-27B-NVFP4',
		'GLM-OCR',
		'Qwen3-Embedding-8B',
		'Qwen3-VL-Reranker-2B',
		'whisper-large-v3-turbo',
		'cohere-transcribe-03-2026',
		'Qwen3-TTS-12Hz-1.7B-CustomVoice',
	);

	/**
	 * Models the lineup no longer offers.
	 *
	 * A retired model that still claims capabilities means a stale `case`
	 * survived in `MittwaldModelMetadataDirectory`.
	 *
	 * @var list<string>
	 */
	public const RETIRED = array(
		'Mistral-Small-3.2-24B-Instruct',
		'Mistral-Medium-3.5-128B',
		'Qwen3-Coder-30B-Instruct',
		'Qwen3-VL-Reranker',
	);

	/**
	 * Currently offered models the plugin deliberately exposes no capability for.
	 *
	 * These are operation types the plugin does not implement yet — reranking
	 * and speech-to-text. They are listed so the audit can tell a known gap
	 * apart from a model that was simply forgotten.
	 *
	 * @var list<string>
	 */
	public const UNIMPLEMENTED = array(
		'Qwen3-VL-Reranker-2B',
		'whisper-large-v3-turbo',
		'cohere-transcribe-03-2026',
	);

	/**
	 * Currently offered embedding models.
	 *
	 * Embedding generation needs php-ai-client 1.4.0, which WordPress 7.0 does
	 * not bundle. On an older SDK the plugin lists these without capabilities,
	 * so {@see self::unimplemented()} counts them as known gaps there.
	 *
	 * @var list<string>
	 */
	public const EMBEDDING = array(
		'Qwen3-Embedding-8B',
	);

	/**
	 * Currently offered models the plugin exposes no capability for on the SDK at hand.
	 *
	 * @return list<string>
	 */
	public static function unimplemented(): array {
		if ( MittwaldModelMetadataDirectory::supportsEmbeddingGeneration() ) {
			return self::UNIMPLEMENTED;
		}

		return array_values( array_merge( self::UNIMPLEMENTED, self::EMBEDDING ) );
	}

	/**
	 * Currently offered models that should resolve to a model class.
	 *
	 * @return list<string>
	 */
	public static function routable(): array {
		return array_values( array_diff( self::CURRENT, self::unimplemented() ) );
	}
}
