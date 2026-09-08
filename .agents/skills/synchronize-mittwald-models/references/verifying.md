# Verifying a model change

Shared reference for the `add-model` and `synchronize-mittwald-models` skills.

## The two harnesses

Both live in `scripts/` next to the `synchronize-mittwald-models` skill. Run
both, from the repository root, after any change to `includes/`.

```bash
php .agents/skills/synchronize-mittwald-models/scripts/verify_class.php
php .agents/skills/synchronize-mittwald-models/scripts/verify_models.php
```

Both need `composer install` to have been run: the SDK is a dev dependency, so
`vendor/wordpress/php-ai-client/` is what they load the contracts from.

### `verify_class.php`

Loads every class in `includes/` through the composer autoloader and reports the
SDK interfaces each satisfies, then exercises the provider's static factories.
This is the only check that catches class declaration fatals — a redeclared
property narrowing an inherited type, or an interface from a php-ai-client
release newer than the one installed. Neither phpcs nor phpstan reliably
catches those.

### `verify_models.php`

Prints a model-to-capability matrix. It builds a synthetic `/v1/models`
response and runs it through the real
`MittwaldModelMetadataDirectory::parseResponseToModelMetadataList()`, then
matches each result against `ModelRequirements::areMetBy()` — the same check the
SDK uses when picking a model — so it cannot drift from the code. Every model is
also pushed through `MittwaldAIProvider::createModel()`, because a capability
the provider does not route is a runtime exception rather than a missing picker
entry.

Only the `$current` and `$retired` arrays at the top are maintained by hand.
**Update them from the verbatim mittwald model table before trusting a run** —
that is what turns the script from a static check into an audit.

Reading the matrix:

- A `$current` model showing `(none)` is unrecognised by the switch. Either the
  ID is misspelled, or that model's operation type is genuinely not implemented
  yet. Both look identical here, so check the switch before concluding which.
- A `$retired` model showing any capability means a stale `case` survived.
- `vision` appearing on a model whose documented modalities are text-only means
  it landed in the multimodal option bundle by mistake.
- A model you did not touch changing its row means your edit moved a shared
  option bundle rather than a single case.
- The reachability list at the bottom flags a model class nothing routes to —
  usually a capability removed from the switch without removing its class.

The script exits non-zero when any line is flagged. Some flags are known and
expected while an operation type is unimplemented; note them in the report
rather than silencing them.

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

There is no test framework in this repo, so the final check is manual, against a
WordPress install with the plugin and the [AI
Experiments](https://github.com/WordPress/ai) plugin active:

1. Settings → AI Experiments (`/options-general.php?page=ai-experiments`),
   with a valid mittwald API key configured.
2. Confirm the model appears in the picker, in the position
   `modelSortCallback()` predicts.
3. Exercise the capability the change claims — a chat turn, an image attached to
   a prompt for a vision claim, a tool call, a TTS request. Claiming a
   capability the endpoint rejects surfaces only here.
