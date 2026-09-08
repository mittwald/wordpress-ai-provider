# Where a model ID lives in this repo

Shared reference for the `add-model` and `synchronize-mittwald-models` skills.
Both additions and removals have to visit every row below; a half-applied change
is the usual failure mode, because a missed touchpoint fails silently rather
than erroring.

Sweep before concluding anything is complete:

```bash
grep -rn "<model-id>" includes/ README.md readme.txt mittwald-ai-provider.php
```

Do not narrow that to `includes/`. Model IDs live at the repo root too, and
`readme.txt` — the WordPress.org readme — is the easiest one to miss because it
duplicates `README.md` rather than including it.

## The inventory

| Location | What lives there |
| --- | --- |
| `MittwaldModelMetadataDirectory::parseResponseToModelMetadataList()` switch | one `case` per model ID, selecting a capability set and an option set |
| the `$gpt…` / `$tts…` bundles above that switch | the capability and option sets the cases choose between |
| that switch's `default:` arm | **silently gives an unrecognised ID zero capabilities** |
| `MittwaldModelMetadataDirectory::modelSortCallback()` | the order models appear in the picker |
| `MittwaldAIProvider::createModel()` | capability → model class routing |
| `MittwaldTextToSpeechConversionModel::VOICES`, `RESPONSE_FORMATS`, `DEFAULT_*` | per-model configuration for the TTS endpoint |
| `MittwaldImageGenerationModel::prepareGenerateImageParams()` | model-family-specific request parameters (the `gpt-image-` prefix check) |
| `README.md` | model bullet list, capability sentences, usage examples |
| `readme.txt` | the same lists again, for the WordPress.org plugin page |

The two readmes carry independent copies of the model bullet list, the
capability sentences, and the code examples. Fixing one and missing the other is
easy; grep, do not read.

## Two claims, not one

A model entry makes two separate claims, and they are enforced at different
points:

- **`CapabilityEnum` values** decide which model class `createModel()` hands the
  model to. Get this wrong and the provider throws at model construction.
- **The `SupportedOption` list** decides which features a caller may request of
  it. `ModelRequirements::areMetBy()` checks both, so a missing option removes
  the model from a picker just as effectively as a missing capability — with no
  error anywhere.

Vision is an option, not a capability: it is the difference between
`$gptOptions` (text input only) and `$gptMultimodalInputOptions` (text, or text
plus image). Picking the wrong bundle is how a text-only model comes to claim
image input.

## Hazards

### The `default:` arm swallows typos

The switch compares IDs with `===`, so spelling and casing must match the API
exactly. An ID that does not match any `case` falls to `default:`, which assigns
empty capabilities and empty options. That model then satisfies no
`ModelRequirements` at all and disappears from every picker; nothing logs, and
nothing fails. If something does reach `createModel()` with it, the only symptom
is a `RuntimeException` reading `Unsupported model capabilities:` with an empty
list after the colon.

Take the ID's exact spelling from the mittwald model table, including the
parameter-size suffix. `Qwen3-VL-Reranker` and `Qwen3-VL-Reranker-2B` are
different strings, and only one of them exists.

### A family is not uniformly capable

`Qwen3.5-0.8B` is text-only while `Qwen3.5-122B-A10B-FP8` accepts image input,
and both are Qwen3.5. Because the switch matches exact IDs, every variant needs
its own `case` in the right group. Never collapse a family into a prefix check
here — `modelSortCallback()` is the only place prefixes are appropriate, and it
only affects ordering.

Capability and option claims must come from the model table's **modality
column**, not from the family name.

### Removing a model strands the sites already using it

The metadata switch only decides what is *offered*. WordPress stores the
selected provider and model in its options, and nothing in this plugin rewrites
that. A site already configured for a removed model keeps calling an ID that no
longer resolves, and — because the `default:` arm is silent — the feature simply
stops working with no message.

So for a removal:

- Take the model out of `README.md` and `readme.txt` in the same change, so the
  documented lineup and the code never disagree.
- If nothing else offers what the removed model did, say so plainly in the
  report; a site owner has to re-pick a model by hand.
- Take the model out of `ModelCatalogue::CURRENT` and add it to
  `ModelCatalogue::RETIRED` in `tests/includes/ModelCatalogue.php`.
- Check `createModel()` afterwards: removing the last model of a capability
  leaves its model class unreachable.
  `MittwaldAIProviderTest::test_shipped_model_classes_are_reachable_from_the_router()`
  reports that, and `ModelCatalogueTest` reports a retired model whose `case`
  survived.
