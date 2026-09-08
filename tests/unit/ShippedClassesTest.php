<?php
/**
 * Tests that every class the plugin ships is loadable and usable.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Unit;

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldImageGenerationModel;
use Mittwald\AiProvider\MittwaldModelMetadataDirectory;
use Mittwald\AiProvider\MittwaldTextGenerationModel;
use Mittwald\AiProvider\MittwaldTextToSpeechConversionModel;
use Mittwald\AiProvider\Tests\Includes\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use WordPress\AiClient\Providers\ApiBasedImplementation\Contracts\ApiBasedModelInterface;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;

/**
 * Covers the declaration of every class under `includes/`.
 *
 * Most of what this file checks cannot fail quietly: a class that will not
 * declare — a redeclared property narrowing an inherited type, or an interface
 * that a newer `php-ai-client` release moved — is a fatal, and the fatal takes
 * the test run with it. That is the point. Neither PHPCS nor PHPStan reliably
 * catches those, and a provider whose classes will not load is a plugin that
 * does nothing at all.
 *
 * The interface assertions are the other half: the SDK decides what a model can
 * be asked to do by which contract it satisfies, so a class that quietly stops
 * implementing one disappears from the picker rather than erroring.
 */
final class ShippedClassesTest extends TestCase {

	/**
	 * Every class the plugin ships declares cleanly and is concrete.
	 *
	 * An abstract class here can never be instantiated by the provider, so it
	 * would be dead weight rather than a working model.
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @dataProvider provide_shipped_classes
	 */
	#[DataProvider( 'provide_shipped_classes' )]
	public function test_shipped_classes_declare_cleanly_and_are_concrete( string $class_name ): void {
		$this->assertTrue(
			class_exists( $class_name ),
			"{$class_name} could not be autoloaded from includes/."
		);

		$reflection = new ReflectionClass( $class_name );

		$this->assertFalse(
			$reflection->isAbstract(),
			"{$class_name} is abstract, so the provider can never instantiate it."
		);
	}

	/**
	 * Every PHP file in `includes/` maps to a class of the same name.
	 *
	 * @return list<array{string}>
	 */
	public static function provide_shipped_classes(): array {
		$files = glob( dirname( __DIR__, 2 ) . '/includes/*.php' );

		if ( ! is_array( $files ) || array() === $files ) {
			throw new \RuntimeException( 'No classes found in includes/.' );
		}

		$cases = array();
		foreach ( $files as $file ) {
			$cases[] = array( 'Mittwald\\AiProvider\\' . basename( $file, '.php' ) );
		}

		return $cases;
	}

	/**
	 * Each class satisfies the SDK contracts its role requires.
	 *
	 * @param string       $class_name Fully qualified class name.
	 * @param list<string> $interfaces Interfaces the class must implement.
	 *
	 * @dataProvider provide_required_interfaces
	 */
	#[DataProvider( 'provide_required_interfaces' )]
	public function test_classes_satisfy_their_sdk_contracts( string $class_name, array $interfaces ): void {
		$reflection = new ReflectionClass( $class_name );

		foreach ( $interfaces as $interface ) {
			$this->assertTrue(
				$reflection->implementsInterface( $interface ),
				"{$class_name} must implement {$interface}."
			);
		}
	}

	/**
	 * The contracts each shipped class is required to satisfy.
	 *
	 * @return array<string, array{string, list<string>}>
	 */
	public static function provide_required_interfaces(): array {
		return array(
			'provider'           => array(
				MittwaldAIProvider::class,
				array( ProviderInterface::class ),
			),
			'metadata directory' => array(
				MittwaldModelMetadataDirectory::class,
				array( ModelMetadataDirectoryInterface::class ),
			),
			'text generation'    => array(
				MittwaldTextGenerationModel::class,
				array(
					ModelInterface::class,
					ApiBasedModelInterface::class,
					TextGenerationModelInterface::class,
				),
			),
			'image generation'   => array(
				MittwaldImageGenerationModel::class,
				array(
					ModelInterface::class,
					ApiBasedModelInterface::class,
					ImageGenerationModelInterface::class,
				),
			),
			'speech'             => array(
				MittwaldTextToSpeechConversionModel::class,
				array(
					ModelInterface::class,
					ApiBasedModelInterface::class,
					TextToSpeechConversionModelInterface::class,
				),
			),
		);
	}

	/**
	 * The provider's static factories all produce something usable.
	 *
	 * These run at plugin registration, so a throw here is a plugin that fails
	 * to register rather than one that merely misbehaves.
	 */
	public function test_provider_static_factories_work(): void {
		$this->assertSame( 'mittwald', MittwaldAIProvider::metadata()->getId() );
		$this->assertInstanceOf(
			MittwaldModelMetadataDirectory::class,
			MittwaldAIProvider::modelMetadataDirectory()
		);
		$this->assertInstanceOf(
			ProviderAvailabilityInterface::class,
			MittwaldAIProvider::availability()
		);
	}
}
