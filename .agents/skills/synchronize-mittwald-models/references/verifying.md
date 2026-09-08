# Verifying a model change

Shared reference for the `add-model` and `synchronize-mittwald-models` skills.

## Run the test suites

Run these from the repository root after any change to `includes/`. Both need
`composer install` to have been run: the SDK is a dev dependency, so
`vendor/wordpress/php-ai-client/` is where the contracts come from.

```bash
composer run test              # unit suite, offline
composer run test:integration  # real API, needs MITTWALD_AI_API_KEY
```

**Neither is optional after a model change.** The unit suite proves the plugin
maps the model the way you intended; only the integration suite proves the
endpoint agrees. Claiming a capability the model does not actually have —
vision on a text-only model, say — passes every offline check and fails only
against the API.

If no `MITTWALD_AI_API_KEY` is available, the integration suite skips itself
rather than failing. That is a silent pass, not a green light: say so in the
report instead of claiming the change was verified end to end.

`tests/README.md` documents the layout and the traps, including why reasoning
models need generous `max_tokens`.

## Update the catalogue first

`tests/includes/ModelCatalogue.php` holds the lineup by hand, in three lists:

- `CURRENT` — every model the documented table offers.
- `RETIRED` — models the table no longer lists.
- `UNIMPLEMENTED` — currently offered models the plugin deliberately exposes no
  capability for, because the operation type has no model class yet.

**Update these from the verbatim mittwald model table before running anything.**
That is what turns the unit suite from a static check into an audit. Also bump
the "Last synchronised" date in that file's docblock.

## What the suites tell you

`ModelCatalogueTest` is the audit. It builds a synthetic `/v1/models` response,
runs it through the real
`MittwaldModelMetadataDirectory::parseResponseToModelMetadataList()` and pushes
each result through `MittwaldAIProvider::createModel()`, so it cannot drift from
the code it checks.

- A model in `CURRENT` with no capabilities, and not listed in `UNIMPLEMENTED`,
  **fails**: either the ID is misspelled, or the operation type is genuinely not
  implemented and belongs in `UNIMPLEMENTED`. Check the switch before deciding.
- A model in `RETIRED` that still claims capabilities is reported **incomplete**,
  naming the stale `case`. It does not fail: an account may still have access to
  a model that has left the public table, so removing the case is a judgement
  call. Note it in the report.
- A `CURRENT` model that no longer reaches a model class **fails**, because a
  capability the provider does not route is a runtime exception rather than a
  missing picker entry.

`MittwaldModelMetadataDirectoryTest` matches each model against
`ModelRequirements::areMetBy()` — the same check the SDK uses when picking a
model — so `vision` appearing on a model whose documented modalities are
text-only means it landed in the multimodal option bundle by mistake. A model
you did not touch changing its row means your edit moved a shared option bundle
rather than a single case.

`ModelSortOrderTest::test_current_lineup_is_presented_in_the_expected_order()`
pins the order the picker shows. Adding a model changes it, so update the
expected list in that test deliberately rather than reflexively.

`ShippedClassesTest` loads every class in `includes/` and asserts the SDK
contracts each satisfies. This is what catches a class declaration fatal — a
redeclared property narrowing an inherited type, or an interface from a
`php-ai-client` release newer than the one installed. A fatal there takes the
whole run with it, which is the intended signal; neither phpcs nor phpstan
catches those.

`MittwaldAIProviderTest::test_shipped_model_classes_are_reachable_from_the_router()`
flags a model class nothing routes to — usually a capability removed from the
switch without removing its class.

## Standard checks

```bash
composer run analyse      # PHPStan level 10
composer run format       # PHPCS, WordPress Coding Standards
composer run format:fix   # auto-fix formatting
```

`composer run analyse` is a real signal in this repo and must stay clean —
level 10 with the WordPress extension resolves the SDK types. Treat any new
error as a regression rather than noise.

## End-to-end

The integration suite covers what used to need a manual walkthrough: it drives
chat, vision, OCR and speech against the real endpoint. Add a case there for any
capability a change newly claims, rather than checking it by hand.

A manual pass against a WordPress install is still worth doing when the change
touches how models are presented rather than how they behave:

1. Settings → AI Experiments (`/options-general.php?page=ai-experiments`),
   with a valid mittwald API key configured.
2. Confirm the model appears in the picker, in the position
   `modelSortCallback()` predicts.
