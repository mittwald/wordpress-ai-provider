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
`@dataProvider` annotation (read by PHPUnit 9.6 on PHP 7.4) and the
`#[DataProvider]` attribute (read by PHPUnit 10 and newer); on PHP 7.4 the
attribute is parsed as a comment, so both forms are needed.

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

Two assertions are worth knowing about because they are what will fail when the
upstream catalogue moves:

- `ProviderAvailabilityTest::test_no_offered_model_is_left_without_capabilities()`
  fails when mittwald starts offering a model the plugin has no entry for. That
  model is invisible to sites until it is added to
  `MittwaldModelMetadataDirectory`.
- `MittwaldAIProviderTest::test_shipped_model_classes_are_reachable_from_the_router()`
  fails when a model class stops being reachable from
  `MittwaldAIProvider::createModel()`. `MittwaldImageGenerationModel` is the one
  known exception, and it is named explicitly.

Generative models are not deterministic. Where a test depends on the model
choosing to do something rather than on the provider building a valid request —
function calling, for instance — it skips rather than fails when the model
answers differently.
