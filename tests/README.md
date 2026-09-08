# Tests

Two suites live here, with different costs and different things to say when
they fail.

```bash
composer run test              # unit suite (alias for test:unit)
composer run test:unit         # offline, fast, no credentials
composer run test:integration  # real API calls, needs MITTWALD_AI_API_KEY
```

## Layout

| Path                | What it holds                                                       |
|---------------------|---------------------------------------------------------------------|
| `bootstrap.php`     | Composer autoloader plus the WordPress function stubs.               |
| `stubs/wordpress.php` | The handful of WordPress functions the plugin calls.               |
| `includes/`         | Base test cases and test doubles.                                    |
| `unit/`             | Offline tests. Every HTTP call goes through `FakeHttpTransporter`.   |
| `integration/`      | Tests that talk to mittwald AI hosting for real.                     |
| `fixtures/`         | Images the vision and OCR tests send to the API.                     |

`tests/includes/ModelCatalogue.php` holds the documented mittwald model lineup —
`CURRENT`, `RETIRED` and `UNIMPLEMENTED`. It is maintained by hand from the
upstream model table and is the single place to edit when models change; the
`add-model` and `synchronize-mittwald-models` skills point at it, and
`ModelCatalogueTest` audits the plugin against it.

## Unit suite

The unit suite runs without WordPress and without network access. The plugin is
a thin layer over the `wordpress/php-ai-client` SDK and never touches the
WordPress database, so instead of a full WordPress test installation,
`tests/stubs/wordpress.php` defines the dozen functions the plugin actually
calls. Hook registrations are recorded in `WordPressStubState`, which lets
`PluginBootstrapTest` assert on what `mittwald-ai-provider.php` registers and
then invoke those callbacks directly.

`FakeHttpTransporter` stands in for the SDK's HTTP transport: it records the
requests the model classes build and replays queued responses. That makes the
wire format the plugin produces — endpoint, headers, request body — directly
assertable, which is where most of this plugin's behaviour lives.

Model metadata in the tests is not hand-written. `TestCase::resolve_model_metadata()`
runs model IDs through the real `MittwaldModelMetadataDirectory`, so the
capabilities and options the tests assert on cannot drift away from the ones the
plugin ships.

The suite runs on PHP 7.4 through 8.5. Data providers carry both the
`@dataProvider` annotation and the `#[DataProvider]` attribute: Composer pins
the resolution platform to PHP 7.4 (`config.platform.php`) so that one lock file
installs on every supported version, which means the locked PHPUnit is 9.6 and
reads the annotation. The attribute keeps the suite working on PHPUnit 10 and
newer, where annotations are deprecated. On PHP 7.4 the attribute is parsed as a
comment, so carrying both is harmless.

PHPStan analyses `includes/` and the plugin bootstrap, not `tests/`. Analysing
test code needs `phpstan/phpstan-phpunit`, which requires a newer PHPStan than
this project pins, and it cannot resolve the `#[DataProvider]` attributes against
the locked PHPUnit 9.6 anyway. PHPCS does cover `tests/`.

## Integration suite

Every test in the integration suite needs an API key:

```bash
MITTWALD_AI_API_KEY=... composer run test:integration
```

Without one, the whole suite skips itself rather than failing, so
`composer run test` stays useful for contributors without an AI hosting account.

Individual tests also skip themselves when the model they need is not offered to
the account under test. The model lineup changes over time, and a model that is
no longer on offer is not a defect in this plugin — `ProviderAvailabilityTest`
is the place that notices catalogue drift, and it says so explicitly.

Coverage by model kind:

| Test                        | What it exercises                                                    |
|-----------------------------|----------------------------------------------------------------------|
| `ProviderAvailabilityTest`  | Credentials, the model catalogue, capability coverage of every offered model. |
| `TextGenerationTest`        | Chat, system instructions, history, token limits, stop sequences, JSON mode, output schemas, function calling. |
| `VisionTest`                | Image input on the vision-capable chat models.                       |
| `OcrTest`                   | `GLM-OCR`, including its deliberately reduced option set.            |
| `TextToSpeechTest`          | `audio/speech`: every advertised voice and container format.         |
| `ImageGenerationTest`       | Image generation — skips while no image model is on offer.           |

Two checks watch for the catalogue drifting, and they are deliberately not
equally loud:

- `ProviderAvailabilityTest::test_offered_models_are_known_to_the_plugin()`
  reports models mittwald offers that the plugin has no entry for. Those models
  are invisible to sites until they are added to
  `MittwaldModelMetadataDirectory`, so it is worth surfacing — but it is marked
  **incomplete**, not failed, and the suite still exits zero. mittwald releasing
  a model is not a defect in this plugin and should not turn the build red. The
  test only fails outright if the plugin recognises *none* of the models on
  offer, which means the catalogue has gone stale wholesale.
- `MittwaldAIProviderTest::test_shipped_model_classes_are_reachable_from_the_router()`
  **fails** when a model class stops being reachable from
  `MittwaldAIProvider::createModel()`. That one is about this repository being
  internally consistent rather than about upstream, so it is a real failure:
  an unreachable model class is dead code. `MittwaldImageGenerationModel` is the
  one known exception, and it is named explicitly.

Generative models are not deterministic. Where a test depends on the model
choosing to do something rather than on the provider building a valid request —
function calling, for instance — it skips rather than fails when the model
answers differently.

### Reasoning models and `max_tokens`

Several models on offer, `gpt-oss-120b` and the Qwen3.5 vision models among
them, answer with a `reasoning_content` block before they emit any content. The
SDK files that block under the thought channel, and `max_tokens` covers both.
A budget sized for the visible answer alone is therefore spent entirely on
reasoning: the response comes back with `content: null` and
`finish_reason: length`, and `GenerativeAiResult::toText()` throws a bare
"No text content found in first candidate".

Two things follow for tests here:

- Any test that asserts on an answer's content needs real headroom — these use
  1024 tokens for prompts whose answers are a few words. Use
  `IntegrationTestCase::text_of()` rather than `toText()`; it fails with the
  finish reason and the thought-part count instead of the SDK's opaque message.
- A test measuring something a reasoning block interferes with should run
  against a model that answers directly. `TextGenerationTest::DIRECT_ANSWER_MODELS`
  lists those; the stop-sequence test uses it, because a stop sequence can match
  inside the reasoning block and end the generation before any content appears.

Writing a system instruction is worth a moment's thought too. An instruction
that asks the model to state something untrue makes a poor probe: `gpt-oss-120b`
reasons its way out of one, on the grounds that the system prompt outranks it,
and answers correctly anyway. Steer the shape of the answer instead.
