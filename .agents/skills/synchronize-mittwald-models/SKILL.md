---
name: synchronize-mittwald-models
description: Reconcile this plugin with the models and endpoints mittwald currently offers, and assert that every offered model is routed to the right model class with the right capabilities and options. Use when models are added or removed upstream, when a model entry looks stale, or as a periodic audit. For adding one specific known model, use add-model instead.
allowed-tools: Read, Edit, Bash, Grep, Glob, WebFetch
---

# Synchronize mittwald models

Bring this plugin back in sync with what mittwald AI Hosting actually offers,
and prove the result. This is an audit-and-reconcile workflow: it ends with a
report of what matches, what drifted, and what was changed.

**Never guess a model ID, an endpoint, or a capability.** Every claim in this
repo has to trace back to the mittwald documentation or to an observed API
response. Guessed models are the main failure mode this skill exists to
prevent — an ID that does not match a `case` falls through to `default:`, is
given zero capabilities, and silently disappears from every model picker.

## Step 1: Collect the sources

### mittwald documentation

The URL layout is not guessable — derive it from the sitemap rather than
assembling paths by hand:

```bash
curl -s https://developer.mittwald.de/sitemap.xml | grep -o '[^<]*aihosting[^<]*'
```

The pages that matter:

| Page | URL |
| --- | --- |
| Model table | `https://developer.mittwald.de/docs/v2/platform/aihosting/models/` |
| Supported endpoints | `https://developer.mittwald.de/docs/v2/platform/aihosting/api-endpoints/supported-endpoints/` |
| Deviations and limitations | `https://developer.mittwald.de/docs/v2/platform/aihosting/api-endpoints/deviations-and-limitations/` |
| Errors | `https://developer.mittwald.de/docs/v2/platform/aihosting/api-endpoints/errors/` |

Note the segment is `api-endpoints/`, not `endpoints/`. The plausible-looking
`.../aihosting/endpoints/supported/` returns 404.

When fetching the model table, ask for it **verbatim, every row**. A summarising
prompt will silently drop models, and a dropped model reads exactly like a
removed model. Ask for the exact IDs, the type, and the modalities.

### The AI client SDK

`wordpress/php-ai-client` and `wordpress/wp-ai-client` are dev dependencies, so
read them from `vendor/` rather than the web — that copy is guaranteed to match
what the plugin is built against. There is no `docs/` directory in either; the
source is the specification:

- `vendor/wordpress/php-ai-client/src/Providers/OpenAiCompatibleImplementation/` —
  the abstract base classes this plugin extends. Read these before overriding a
  method; most behaviour is inherited.
- `vendor/wordpress/php-ai-client/src/Providers/Models/Enums/CapabilityEnum.php`
  and `OptionEnum.php` — the complete vocabulary available to a model entry.
- `vendor/wordpress/php-ai-client/src/Providers/Models/*/Contracts/` — the
  interface an operation's model class must satisfy.
- `vendor/wordpress/php-ai-client/src/Providers/Models/DTO/ModelRequirements.php` —
  `areMetBy()` is how a model actually gets selected. It is the authority on
  what "offering" a model means.
- `vendor/wordpress/php-ai-client/README.md` — the caller-side API, useful for
  writing the README's usage examples.

## Step 2: Probe for undocumented endpoints

The supported-endpoints page has been incomplete before. Distinguish "missing
from the docs" from "does not exist" by status code — an unauthenticated
request is enough, and no API key is needed:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
  https://llm.aihosting.mittwald.de/v1/<endpoint> \
  -H "Content-Type: application/json" -d '{}'
```

`401` means the endpoint exists and requires auth. `404` means it does not
exist. Always probe a known-good path (`/v1/embeddings`) and a nonsense path in
the same run, so the two codes are calibrated against that day's gateway
behaviour.

If an endpoint is real but undocumented, its optional parameters are unknown.
Send only what is needed and record the uncertainty — do not invent parameters.

## Step 3: Inventory every place a model ID appears

Model IDs hide in more places than the metadata switch. The full inventory —
every location, the grep that sweeps them, and why the `default:` arm is the one
that bites — lives in `references/model-touchpoints.md`. Read it and visit every
row before concluding anything is in sync.

That reference is shared with the `add-model` skill, so a location discovered
during an audit is immediately in force for additions too. Add newly found
locations there rather than here.

## Step 4: Reconcile the model entries

For every model in the documented lineup, confirm it claims exactly the
capabilities and options it should, and that retired models are recognised
nowhere.

Rules:

- **Retired model** — remove its `case` from the metadata switch, plus its
  `README.md` and `readme.txt` entries. Then check what the removal leaves
  behind: if it was the last model of its capability, `createModel()` now has an
  unreachable branch and a model class with no users. Removal also strands sites
  already configured for that model — see `references/model-touchpoints.md`;
  call that out in the report.
- **New model** — out of scope here unless explicitly asked. Use the `add-model`
  skill, which walks the same touchpoints for a single addition.
- **Capability and option claims must come from the model table's modality
  column**, not from the family name. A family is not uniformly capable.

### Hazards

The `default:` swallow, the two-claims split between capabilities and options,
and the rules for removals are all documented in
`references/model-touchpoints.md`. The two that decide whether an audit is
correct:

- Spelling and casing must match the API exactly; the switch compares with
  `===`, and a near-miss is indistinguishable from a model that was never
  added.
- A capability without the matching option bundle still hides the model.
  `vision` in particular lives in the option list, not in `CapabilityEnum`.

## Step 5: Capabilities and model classes

A model is only usable when **both** are true:

1. Its `case` in `parseResponseToModelMetadataList()` assigns the right
   `CapabilityEnum` values and the right `SupportedOption` list.
2. `MittwaldAIProvider::createModel()` routes that capability to a model class
   that satisfies the operation's interface from
   `vendor/wordpress/php-ai-client/src/Providers/Models/<Operation>/Contracts/`.

`createModel()` checks capabilities in order and returns the first match, so a
model claiming several capabilities gets the first class in that chain — not the
best fit. Check the ordering when adding a capability to an existing model.

Capabilities the provider does not route yet throw a `RuntimeException` at model
construction rather than degrading. Embedding generation is currently in that
state deliberately; if an audit adds a capability with no class behind it, the
result is a hard failure for anyone who selects that model.

### The SDK version constrains what can be declared

Interfaces resolve when the class is declared, so implementing one that a
permitted `wordpress/php-ai-client` version lacks makes the whole plugin fatal
on load — not a degraded operation, a dead plugin. Before implementing a new
model interface, confirm it exists in the lowest version `composer.json` allows,
and raise the constraint if it does not:

```bash
grep -n "php-ai-client\|wp-ai-client" composer.json
ls vendor/wordpress/php-ai-client/src/Providers/Models/
```

`verify_class.php` is what proves the declaration actually loads.

Type hints and `use` statements behave differently: they resolve lazily, at call
time. Referencing a class from a newer version inside a method body is safe on
older versions as long as the method is never reached.

## Step 6: Verify

Follow `references/verifying.md`: both harnesses in `scripts/`, then
`composer run analyse` and `composer run format`, then the manual walkthrough in
a WordPress install. Update the `$current` and `$retired` arrays in
`verify_models.php` to the lineup you fetched in Step 1 before reading its
matrix — that is what turns it from a static check into an audit.

## Step 7: Report

State plainly:

- models offered upstream vs. models this plugin recognises, and any gap either
  way
- capability or option claims that do not match the documented modalities
- endpoints offered upstream but not implemented, and vice versa
- what changed, and what was deliberately left out of scope
- for any removal, that sites already configured for that model need to re-pick
  one by hand

Flag drift you did not fix rather than silently widening scope.
