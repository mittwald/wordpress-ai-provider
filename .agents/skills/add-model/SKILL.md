---
name: add-model
description: Add support for one specific new AI model to the mittwald AI provider plugin — give it the right capabilities and supported options, route it to the right model class, and update every place a model ID appears. Use when a named model should become available. For a full audit of the lineup, or for removing retired models, use synchronize-mittwald-models instead.
allowed-tools: Read, Edit, Bash, Grep, Glob, WebFetch
---

# Add a mittwald model

Make one named model available through this plugin. This is the targeted
counterpart to `synchronize-mittwald-models`: that skill reconciles the whole
lineup and handles removals; this one adds a single known model.

**Never guess a model ID or a capability.** The metadata switch compares IDs
with `===`, so an ID that does not exist matches no `case`, falls through to
`default:`, and is given zero capabilities. The model then satisfies no
`ModelRequirements` and silently vanishes from every picker — no error, no log.
Every ID and every capability claim has to trace back to the mittwald
documentation or an observed API response.

## Step 1: Verify the model against the documentation

Fetch the model table and read the row for this model **verbatim**:

https://developer.mittwald.de/docs/v2/platform/aihosting/models/

Ask for the exact ID, the type, and the modality column — a summarising prompt
drops detail, and the modality column is what decides the capability and option
claims. Take the ID's exact spelling from here, including the parameter-size
suffix and the casing.

If the model is not in that table, stop. A speculative model entry is
indistinguishable from a correct one until someone tries to use it.

If the model implies an operation this plugin does not implement yet (a new
endpoint rather than a new model), that is `synchronize-mittwald-models`
territory — it covers endpoint probing, the operation interfaces in the SDK, and
the SDK version constraint that implementing one imposes. Say so rather than
half-implementing it here.

## Step 2: Decide what it should claim

From the modality column, not the family name, determine:

- **capabilities** — which `CapabilityEnum` values it gets. This is what
  `MittwaldAIProvider::createModel()` routes on:
  `textGeneration` → `MittwaldTextGenerationModel`,
  `textToSpeechConversion` → `MittwaldTextToSpeechConversionModel`.
  `chatHistory` is a separate capability from `textGeneration`; a model that
  only does single-turn work (OCR, for example) gets `textGeneration` alone.
- **supported options** — which of the existing bundles above the switch it
  belongs in. `$gptOptions` is text input only; `$gptMultimodalInputOptions` is
  the same list with image input added, and it is the *only* thing that makes a
  model usable for vision. `$gptOcrOptions` and `$ttsOptions` are narrower sets
  for models that do not take the general chat parameters.

Reuse an existing bundle if the model fits one. Introduce a new bundle only when
the documented modalities genuinely differ from all of them, and derive it from
the nearest existing one rather than writing it out fresh.

A family is not uniformly capable. `Qwen3.5-0.8B` is text-only while
`Qwen3.5-122B-A10B-FP8` accepts image input, and both are Qwen3.5 — so they sit
in different `case` groups.

## Step 3: Apply the change everywhere the ID belongs

Read `references/model-touchpoints.md` in the `synchronize-mittwald-models`
skill:

`.agents/skills/synchronize-mittwald-models/references/model-touchpoints.md`

It carries the full inventory of locations and the hazards. For an addition, the
rows that usually apply are the metadata switch, the option bundle it selects,
`README.md` and `readme.txt`. `modelSortCallback()` applies if the model should
appear somewhere specific in the picker; `MittwaldTextToSpeechConversionModel`'s
constants apply if it is a TTS model with a different voice or format set.

Two things specific to adding:

- Group the new `case` with the models that share its capability and option set,
  stacking the label above the existing ones rather than writing a parallel arm.
  Fall-through grouping is how the switch stays readable.
- Check whether the model's options are genuinely identical to the group's. A
  model dropped into `$gptMultimodalInputOptions` because its family-mates are
  there, without the modality column saying it takes image input, claims vision
  it does not have — and that failure only surfaces when a user attaches an
  image.

## Step 4: Verify

Follow `.agents/skills/synchronize-mittwald-models/references/verifying.md`.

Add the new model to the `$current` array in `verify_models.php` first,
otherwise the matrix will not show it. Confirm it appears under exactly the
capabilities Step 2 established, routes to the model class you expect, and that
no other model's row changed.

Then run `composer run analyse` and `composer run format`. Both are real signals
in this repo and must stay clean.

## Step 5: Commit

Conventional Commits, as the repo uses elsewhere:

```
feat: Add MODEL_ID support
```

## Step 6: Report

State the model ID as documented, the capabilities and options it now claims,
the model class it routes to, the touchpoints changed, and anything deliberately
left out of scope — in particular any drift noticed in passing but not fixed.
