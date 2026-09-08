<?php

/**
 * @file
 * Asserts that every provider class can actually be declared and loaded.
 *
 * Also reports the model interfaces each class satisfies, so that a missing
 * interface shows up next to the ones inherited from the SDK base classes.
 * This is the only check that catches class declaration fatals, such as a
 * redeclared property narrowing an inherited type, or an interface coming from
 * a wordpress/php-ai-client release newer than the one installed. Neither
 * phpcs nor phpstan reliably detects those.
 *
 * Usage:
 * php .agents/skills/synchronize-mittwald-models/scripts/verify_class.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Could not find vendor/autoload.php below $root. Run composer install.\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

/*
 * The plugin classes call a handful of WordPress functions. None of them run
 * at declaration time, but createProviderMetadata() below does need them, so
 * stub the ones the provider touches.
 */
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return $text;
    }
}
if (!function_exists('get_user_locale')) {
    function get_user_locale(): string
    {
        return 'en_US';
    }
}

$classes = [];
foreach (glob($root . '/includes/*.php') ?: [] as $file) {
    $classes[] = 'Mittwald\\AiProvider\\' . basename($file, '.php');
}

if (!$classes) {
    fwrite(STDERR, "No classes found in $root/includes.\n");
    exit(1);
}

/*
 * A declaration failure is a fatal rather than an exception, so report where we
 * got to if the process dies inside the autoloader.
 */
$loading = null;
register_shutdown_function(static function () use (&$loading): void {
    $error = error_get_last();
    if ($error !== null && ($error['type'] & (E_ERROR | E_COMPILE_ERROR))) {
        fwrite(STDERR, "\nFAIL: $loading could not be declared.\n");
        fwrite(STDERR, $error['message'] . "\n");
    }
});

// Every interface the SDK defines for a provider or a model.
$interfaceFiles = array_merge(
    glob($root . '/vendor/wordpress/php-ai-client/src/Providers/Contracts/*Interface.php') ?: [],
    glob($root . '/vendor/wordpress/php-ai-client/src/Providers/Models/Contracts/*Interface.php') ?: [],
    glob($root . '/vendor/wordpress/php-ai-client/src/Providers/Models/*/Contracts/*Interface.php') ?: []
);

$interfaces = [];
foreach ($interfaceFiles as $file) {
    $relative = substr($file, strlen($root . '/vendor/wordpress/php-ai-client/src/'));
    $name = 'WordPress\\AiClient\\' . str_replace('/', '\\', substr($relative, 0, -4));
    if (interface_exists($name)) {
        $interfaces[] = $name;
    }
}

$failures = 0;

foreach ($classes as $class) {
    $loading = $class;
    $reflection = new ReflectionClass($class);
    echo "OK: $class declared cleanly.\n";

    $implemented = [];
    foreach ($interfaces as $interface) {
        if ($reflection->implementsInterface($interface)) {
            $implemented[] = substr($interface, strrpos($interface, '\\') + 1);
        }
    }

    if ($implemented) {
        echo '     implements: ' . implode(', ', $implemented) . "\n";
    }

    // An abstract class here means the plugin cannot instantiate it at all.
    if ($reflection->isAbstract()) {
        echo "     ! abstract — this class can never be instantiated\n";
        $failures++;
    }
}

/*
 * Exercise the provider's own static factories. createProviderMetadata() reads
 * the logo from disk and createModelMetadataDirectory() has to return the
 * mittwald directory, not the SDK default.
 */
echo "\nProvider factories:\n";

try {
    $metadata = Mittwald\AiProvider\MittwaldAIProvider::metadata();
    printf("  metadata()                 %s (%s)\n", $metadata->getId(), $metadata->getName());
} catch (Throwable $e) {
    printf("! metadata()                 %s: %s\n", get_class($e), $e->getMessage());
    $failures++;
}

try {
    $directory = Mittwald\AiProvider\MittwaldAIProvider::modelMetadataDirectory();
    printf("  modelMetadataDirectory()   %s\n", get_class($directory));
} catch (Throwable $e) {
    printf("! modelMetadataDirectory()   %s: %s\n", get_class($e), $e->getMessage());
    $failures++;
}

try {
    $availability = Mittwald\AiProvider\MittwaldAIProvider::availability();
    printf("  availability()             %s\n", get_class($availability));
} catch (Throwable $e) {
    printf("! availability()             %s: %s\n", get_class($e), $e->getMessage());
    $failures++;
}

echo "\nLines marked ! need attention.\n";

exit($failures > 0 ? 1 : 0);
