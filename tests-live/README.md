# Live-WP integration suite (#20)

Opt-in only. Never wired into `tests/run.php`, never run in CI — see
`.github/workflows/test.yaml`, which invokes `tests/run.php` exclusively.
This suite makes real HTTP REST calls and real WP-CLI writes against a real
WordPress site. Run it manually, locally, when you want the confidence that
`tests/run.php`'s 900+ shimmed assertions cannot give you.

## Why this exists

Three separate bugs — [#28](https://github.com/rubicon/diviops/issues/28)'s
namespaced Safe SVG class, [#36](https://github.com/rubicon/diviops/issues/36)'s
dynamic-content write guard, [#35](https://github.com/rubicon/diviops/issues/35)/[#97](https://github.com/rubicon/diviops/issues/97)'s
validator false-positive and lossy round trip — passed the entire plain-PHP
suite and failed the instant they touched real WordPress. `tests/wp-shim.php`
deliberately does not implement a real `parse_blocks()`/`serialize_blocks()`
round trip, and its stubs model this fork's *assumptions* about WordPress and
Divi, not their actual behavior. See [#20](https://github.com/rubicon/diviops/issues/20)'s
"PROMOTED" comment for the full postmortem.

## Setup

You need a WordPress site with the DiviOps Agent plugin active, a WP-CLI
install that can reach its database, and an Application Password for an
administrator on that site.

```bash
# 1. Point at the site (adjust for your local environment).
export DIVIOPS_LIVE_URL="http://colleyvillelions.local"
export DIVIOPS_LIVE_USER="daxdavis"
export DIVIOPS_LIVE_WP_PATH="/Users/daxdavis/Local Sites/colleyvillelions/app/public"

# 2. Local by Flywheel's MySQL socket path changes if the site is ever
#    recreated — find the current one and match it to this site in the Local
#    app if this stops working:
#      ls "$HOME/Library/Application Support/Local/run/"
export DIVIOPS_LIVE_WP_CLI_SOCK="/Users/daxdavis/Library/Application Support/Local/run/6NaIbVmzy/mysql/mysqld.sock"

# 3. Generate a dedicated Application Password (do not reuse one you cannot
#    see the plaintext of again — WP-CLI/WP Admin only show it once).
SOCK="$DIVIOPS_LIVE_WP_CLI_SOCK"
php -d display_errors=0 -d mysqli.default_socket="$SOCK" /opt/homebrew/bin/wp \
  --path="$DIVIOPS_LIVE_WP_PATH" \
  user application-password create "$DIVIOPS_LIVE_USER" "DiviOps Live Test Runner" --porcelain

# 4. Store that value in 1Password rather than leaving it in shell history —
#    this is a real credential for a real (if local) WordPress admin account.
op item create --category=login --title="DiviOps Live Test Runner (colleyvillelions)" \
  --vault=Private username="$DIVIOPS_LIVE_USER" password="<paste the value from step 3>"

# 5. Export it for the runner (read back from 1Password rather than pasted
#    inline, once step 4 is done):
export DIVIOPS_LIVE_APP_PASSWORD="$(op read 'op://Private/DiviOps Live Test Runner (colleyvillelions)/password')"
```

`DIVIOPS_LIVE_WP_CLI_BIN` is also overridable (defaults to
`/opt/homebrew/bin/wp`) if `wp` lives somewhere else.

## Run

```bash
php tests-live/run.php                # every live test file
php tests-live/run.php page-duplicate  # only files matching a substring
```

Missing any required environment variable fails fast with a clear message
instead of partway through a fixture.

## Safety model

- **Page 900390 is permanently read-only.** `harness.php` hard-codes it in
  `DIVIOPS_LIVE_FORBIDDEN_POST_IDS`, and every mutating helper checks against
  that list before writing. Tests that need real, complex, already-rendering
  Divi markup read it via `live_get_post_content()` (read-only) and write the
  content into a *new* scratch page — never back into 900390.
- **Every scratch page is cleaned up automatically**, even on failure or a
  thrown exception, via a `register_shutdown_function()` in `harness.php`
  that force-deletes everything `live_create_scratch_page()` created during
  the run.
- **Fixture setup and the operation under test use different transports on
  purpose.** Fixtures are written via WP-CLI (direct DB access, `--user=`
  matched to the REST auth user so `unfiltered_html` behaves identically) so
  they land byte-for-byte, uncomplicated by REST-layer content filtering. The
  diviops operation actually being tested always goes through a real HTTP
  REST call with Application Password auth — the same transport and auth a
  real MCP client uses. A test that fixtures *and* asserts through REST
  cannot tell "the write guard is broken" apart from "core's own KSES already
  mangled my fixture before the guard ran."

## Adding a test

Drop a `test-*.php` file in this directory. It's auto-discovered by
`run.php`'s glob, same as `tests/test-*.php`. Use `live_rest_call()`,
`live_create_scratch_page()`, `live_get_post_content()`, and `live_wp_cli()`
from `harness.php`; use `assert_true()`/`assert_same()` exactly as in
`tests/run.php`. A run that discovers zero files is a hard failure, not a
silent no-op — same reasoning as `tests/run.php`'s own guard.
