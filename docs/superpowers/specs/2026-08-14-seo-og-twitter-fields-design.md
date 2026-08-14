# SEO breadth, slice 1: faithful TSF stub + OG/Twitter title/description fields

Issue: #37 (flagged DESIGN DECISION; Dax authorized making the call rather than
deferring, given this is a delegated single-pass agent run with no synchronous
back-and-forth available).

## Context

`trait-seo.php` (`plugins/diviops-agent/includes/trait-seo.php`) exposes
`seo_metadata_get` / `seo_metadata_update` / `seo_provider_list` through a single
adapter, `DiviOps_SEO_TSF_Adapter`, wrapping The SEO Framework (TSF) and exactly two
semantic fields: `seo_title` and `meta_description`. The trait has real per-post
checksum/dry-run/rollback machinery (~1000 lines) but **zero test coverage** — no
`tests/test-seo.php`, no TSF stub in `tests/wp-shim.php`.

The issue bundles three things: (1) OG/Twitter/JSON-LD field breadth, (2) site-vs-page
defaults, (3) a second provider adapter. A prior scoping pass (issue comment, 2026-07-28)
already read TSF 5.1.4's real source (installed but inactive on the reference site,
`/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/plugins/autodescription`)
and found:

- OG/Twitter explicit values are stored in `_open_graph_title`, `_open_graph_description`,
  `_twitter_title`, `_twitter_description` (plus `_tsf_twitter_card_type`,
  `_social_image_url`/`_social_image_id` — see Deferred below), all already present in
  `Data\Plugin\Post::get_default_meta()`'s 17-key default set.
- Twitter effective title/description falls back to the OG value internally
  (`Twitter::fallback_to_open_graph()`), and OG itself falls back to the plain
  title/description generator — but this fallback lives entirely inside TSF's own
  `tsf()->open_graph()->get_title()` / `tsf()->twitter()->get_title()` methods. The
  adapter never needs to reimplement it; it only needs to call the right accessor.
- Schema/JSON-LD (`Schema::get_generated_graph()`) has **no stored field at all** — it is
  computed from title/description/OG/site settings. Exposing it means a new read-only
  response section, not a seventh writable field. Real new design work, not repetition.
- Rank Math is the owner-decided second-provider priority (issue comment, 2026-07-27),
  ahead of Yoast — but it is installed-and-inactive on the reference site, and this
  fork's own hard-learned lesson (#28, #36, #35, #97: shimmed tests pass, real behavior
  differs) means a faithful adapter needs live verification against the real plugin
  after activation. `CLAUDE.md`'s site constraints require Dax's explicit go-ahead
  before any plugin activation on the reference site. That approval isn't obtainable
  synchronously in this delegated run.

## Decision

Ship the smallest coherent, fully-tested slice: **the faithful TSF stub (prerequisite
for everything) plus four new writable fields — `og_title`, `og_description`,
`twitter_title`, `twitter_description`** — added to the existing adapter using its
exact established pattern (FIELD_KEYS entry, plain-text validation, checksum,
dry-run, rollback). Comprehensive tests are added for the trait's *entire* surface,
not just the four new fields, since none existed before this slice.

Deferred, as separate follow-up issues (see PR body):

1. **Twitter card type** (`_tsf_twitter_card_type`) — an enum, not plain text; needs its
   own validation shape (allowlist against TSF's `get_supported_cards()`), not a
   drop-in FIELD_KEYS repetition.
2. **Social image** (`_social_image_url` + `_social_image_id` pair) — URL-shaped, not
   plain text, and paired (a URL write likely wants an attachment-id resolution step);
   different validation shape again.
3. **Schema/JSON-LD read-only effective evidence** — no stored field; a new response
   section, not a field. Confirmed architecturally distinct by reading
   `schema.class.php` directly.
4. **Homepage-level override layer** — TSF reads `Data\Plugin::get_option('homepage_og_title')`
   etc. as a third layer above per-post explicit/effective when the target is the front
   page. Out of scope for post-level field breadth; feeds into the site-defaults design
   work below.
5. **Site-vs-page defaults** — a new stored-data concept absent from the current
   per-post-only design; needs its own design pass per the issue's own research note.
6. **Second provider adapter (Rank Math)** — blocked on Dax's explicit approval to
   activate the already-installed Rank Math on the reference site, per `CLAUDE.md`'s
   site constraints. Its real source was skimmed enough to confirm it stores
   OG/Twitter/schema fields as flat, differently-named postmeta keys (not TSF's single
   merged-array save), so it is a genuinely separate adapter, not a copy-paste of the
   TSF one — reinforcing that it needs its own live-verified build, not a guess.

## Why not more in one PR

`trait-seo.php`'s write path carries checksum-drift detection, dry-run planning,
provider-state snapshot/rollback, and multi-layer plain-text validation — all of it
per-field generic already (rollback snapshots the full TSF default-meta key set, not
just the exposed fields, because TSF's real `save_meta()` rewrites every default key on
every single-field write; confirmed by reading `Data\Plugin\Post::save_meta()`
directly). Adding four fields that reuse that machinery is a mechanical, reviewable
diff. Bundling in the image field, the enum field, schema, site defaults, and a second
provider in the same pass would multiply both the validation-shape surface and the
review size for no compounding benefit — each of those pieces is better reviewed (and,
for Rank Math, live-verified) on its own.

## Test approach

`tests/tsf-shim.php` (new, required only by `tests/test-seo.php`, mirroring how
`tests/wp-shim.php` is the generic WordPress-core shim and other trait-specific
primitives — e.g. the nav-menu registry — live inline where only one suite needs them):

- `tsf()` returns a stub root object exposing the same method surface
  `DiviOps_SEO_TSF_Adapter` calls: `data()->plugin()->post()`, `sanitize()`,
  `post_type()`, `title()`, `description()`, `open_graph()`, `twitter()`.
- `data()->plugin()->post()`'s `get_meta_item()` / `get_default_meta()` /
  `update_single_meta_item()` are backed by the SAME postmeta store
  `tests/wp-shim.php`'s `get_post_meta()`/`update_post_meta()`/`delete_post_meta()`
  already provide, and `update_single_meta_item()` faithfully reproduces TSF's real
  full-default-key rewrite (verified against `Post::save_meta()`), not a single-key
  write — this is the behavior the rollback machinery is implicitly built around, so a
  stub that only touched the written key would be a mocked-behavior test that passes
  while proving nothing about the real rollback path.
  `Post::get_default_meta()`'s 17 keys and defaults are copied verbatim from the real
  source.
- `title()`/`description()`/`open_graph()`/`twitter()`'s `get_title()`/
  `get_description()` are **scripted**, not reimplemented: a test sets
  `$GLOBALS['diviops_test_tsf_effective'][...]` and the stub returns it. TSF's actual
  generation/fallback decision tree (query-state-dependent, front-page detection, sites
  options) is real third-party logic the adapter never re-derives — it only calls into
  it — so reimplementing it here would risk being *wrong* in a way nothing would catch,
  the opposite of faithful.
- `sanitize()->metadata_content()` models only the string-normalization steps that are
  plain PHP (nbsp/newline/tab-to-space, trim, collapse-repeated-spacing) and explicitly
  does **not** model the later WP-core-dependent steps (`wptexturize`,
  `capital_P_dangit`, HTML-entity decoding) — documented inline so it's never mistaken
  for the byte-exact sanitizer.
- `discovery()`'s not-installed / installed-inactive / active-incompatible /
  active-compatible states are driven by the harness's plugin-file and constant-based
  seams already established by the adapter itself (`is_file()` on a scripted plugin
  path is not overridden — instead, tests exercise `discovery()`'s active-and-compatible
  branch directly, since that's the state the reference site is verified to be in, and
  assert the *shape* of the inactive/absent branches via direct construction of a
  `DiviOps_Test_Request` against a version-mismatched constant instead of fighting the
  filesystem check).

`tests/test-seo.php` covers, end to end against the real trait code (not reimplemented
assertions): `seo_provider_list`, `seo_metadata_get` (post validation, permission gate,
provider gate, post-type-support gate, read payload shape, checksum), and
`seo_metadata_update` (every `seo_validate_update_request` branch, checksum-drift 409,
dry-run noop/non-noop, real write + readback verification for all six fields including
the four new ones, and the rollback-on-mismatch path).
