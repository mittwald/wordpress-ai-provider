<?php

/**
 * @file
 * Prints a model-to-capability matrix for the mittwald model metadata.
 *
 * The matrix is produced by actually running
 * MittwaldModelMetadataDirectory::parseResponseToModelMetadataList() against a
 * synthetic /v1/models response, so it cannot drift away from the code it is
 * checking. Only the model lists below are maintained by hand — update them
 * from the verbatim mittwald model table before each run.
 *
 * Each model is then run through MittwaldAIProvider::createModel() as well,
 * because a capability the provider does not route is a runtime exception
 * rather than a missing menu entry.
 *
 * Usage:
 * php .agents/skills/synchronize-mittwald-models/scripts/verify_models.php
 */

declare(strict_types=1);

// The lineup documented at the mittwald AI hosting models page:
// https://developer.mittwald.de/docs/v2/platform/aihosting/models/
// Last synchronised: 2026-09-08. Re-fetch before trusting a run.
$current = [
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
    'Qwen3-TTS-12Hz-1.7B-CustomVoice',
];

// Models absent from that table. These must resolve to no capabilities at all.
$retired = [
    'Mistral-Small-3.2-24B-Instruct',
    'Mistral-Medium-3.5-128B',
    'Qwen3-Coder-30B-Instruct',
];

$root = dirname(__DIR__, 4);

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Could not find vendor/autoload.php below $root. Run composer install.\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

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

use Mittwald\AiProvider\MittwaldAIProvider;
use Mittwald\AiProvider\MittwaldModelMetadataDirectory;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/*
 * The capability profiles a caller can ask for. Each is a ModelRequirements,
 * matched exactly the way the SDK matches them when picking a model, so a
 * column here corresponds to a feature a site can actually use.
 */
$profiles = [
    'chat' => new ModelRequirements(
        [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
        []
    ),
    'text' => new ModelRequirements(
        [CapabilityEnum::textGeneration()],
        []
    ),
    'vision' => new ModelRequirements(
        [CapabilityEnum::textGeneration()],
        [new RequiredOption(
            OptionEnum::inputModalities(),
            [ModalityEnum::text(), ModalityEnum::image()]
        )]
    ),
    'json' => new ModelRequirements(
        [CapabilityEnum::textGeneration()],
        [new RequiredOption(OptionEnum::outputMimeType(), 'application/json')]
    ),
    'schema' => new ModelRequirements(
        [CapabilityEnum::textGeneration()],
        [new RequiredOption(OptionEnum::outputSchema(), [])]
    ),
    'tools' => new ModelRequirements(
        [CapabilityEnum::textGeneration()],
        [new RequiredOption(OptionEnum::functionDeclarations(), [])]
    ),
    'tts' => new ModelRequirements(
        [CapabilityEnum::textToSpeechConversion()],
        []
    ),
    'image' => new ModelRequirements(
        [CapabilityEnum::imageGeneration()],
        []
    ),
    'embed' => new ModelRequirements(
        [CapabilityEnum::embeddingGeneration()],
        []
    ),
];

/**
 * Runs a list of model IDs through the real metadata directory.
 *
 * @param list<string> $modelIds
 * @return array<string, ModelMetadata> Keyed by model ID, in the order the
 *   directory sorted them.
 */
function resolve_metadata(array $modelIds): array
{
    $body = json_encode(
        ['data' => array_map(static fn(string $id): array => ['id' => $id], $modelIds)]
    );

    $response = new Response(200, ['Content-Type' => 'application/json'], (string) $body);

    $directory = new MittwaldModelMetadataDirectory();
    $method = new ReflectionMethod($directory, 'parseResponseToModelMetadataList');

    $resolved = [];
    foreach ($method->invoke($directory, $response) as $metadata) {
        $resolved[$metadata->getId()] = $metadata;
    }

    return $resolved;
}

/**
 * Reports which model class the provider routes a model to.
 */
function route(ModelMetadata $metadata): string
{
    static $createModel = null;
    static $providerMetadata = null;

    if ($createModel === null) {
        $createModel = new ReflectionMethod(MittwaldAIProvider::class, 'createModel');
        $providerMetadata = MittwaldAIProvider::metadata();
    }

    try {
        $model = $createModel->invoke(null, $metadata, $providerMetadata);
        $class = get_class($model);
        return substr($class, strrpos($class, '\\') + 1);
    } catch (Throwable $e) {
        return '!! ' . trim(str_replace('Unsupported model capabilities:', 'unroutable —', $e->getMessage()));
    }
}

$all = resolve_metadata(array_merge($current, $retired));
$width = max(array_map('strlen', array_merge($current, $retired))) + 2;
$failures = 0;

$report = static function (
    string $heading,
    array $modelIds,
    bool $expectCapabilities
) use ($all, $profiles, $width, &$failures): void {
    echo "\n== $heading ==\n";

    foreach ($modelIds as $modelId) {
        $metadata = $all[$modelId] ?? null;

        if ($metadata === null) {
            printf("! %-{$width}s (dropped by the directory entirely)\n", $modelId);
            $failures++;
            continue;
        }

        $hits = [];
        foreach ($profiles as $label => $requirements) {
            if ($requirements->areMetBy($metadata)) {
                $hits[] = $label;
            }
        }

        $flag = '  ';
        if ($expectCapabilities !== ($hits !== [])) {
            $flag = '! ';
            $failures++;
        }

        $routing = $hits !== [] ? '  ->  ' . route($metadata) : '';
        if (strpos($routing, '!!') !== false) {
            $flag = '! ';
            $failures++;
        }

        printf(
            "%s%-{$width}s %s%s\n",
            $flag,
            $modelId,
            $hits !== [] ? implode(', ', $hits) : '(none)',
            $routing
        );
    }
};

echo "Profiles checked: " . implode(', ', array_keys($profiles)) . "\n";

$report('Currently offered — each should claim at least one capability', $current, true);
$report('Retired — each should claim nothing', $retired, false);

/*
 * The order the directory sorts models into is the order a site sees them in
 * the model picker, so a change to modelSortCallback() shows up here.
 */
echo "\n== Sort order presented to the user ==\n";
$position = 1;
foreach ($all as $modelId => $metadata) {
    if (!in_array($modelId, $current, true)) {
        continue;
    }
    printf("  %2d. %s\n", $position++, $modelId);
}

/*
 * A model class nothing routes to is dead code — usually a capability that was
 * removed from the metadata switch without removing its model class.
 */
echo "\n== Model classes reachable from createModel() ==\n";
$reached = [];
foreach ($all as $metadata) {
    $target = route($metadata);
    if (strpos($target, '!!') === false) {
        $reached[$target] = true;
    }
}
foreach (glob($root . '/includes/*Model.php') ?: [] as $file) {
    $class = basename($file, '.php');
    printf("  [%s] %s\n", isset($reached[$class]) ? 'x' : ' ', $class);
}

echo "\nLines marked ! need attention.\n";
echo "Also check the matches themselves: a model may claim a capability its\n";
echo "documented modalities do not support, or be missing one that they do.\n";

exit($failures > 0 ? 1 : 0);
