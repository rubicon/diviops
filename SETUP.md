# DiviOps — Setup Guide

Get from zero to generating Divi 5 pages with Claude Code or Codex in ~15 minutes. This file ships as `SETUP.md` at the root of the free + pro dist repos; for project framing, suite components, use cases, and the response-contract overview, see the dist-root README.

> **Beta software.** DiviOps is under active development. Use on production sites at your own discretion. Always back up your WordPress site before running write operations.

## Prerequisites

- **WordPress** 6.5+ with **Divi 5** theme (5.1.0+)
- **PHP** 7.4+
- **Node.js** 22+ (for the MCP server)
- **Claude Code** CLI or **Codex** installed
- A local or remote WordPress site (Local by Flywheel recommended for local dev)

## Three independent components

Native Divi authoring requires all three components below. They are installed and
updated independently:

1. **DiviOps Agent** runs inside WordPress and owns the REST capability surface.
2. **`@rubicontv/diviops-mcp`** connects the AI client to that WordPress site.
3. **`divi-5-builder` skill** gives the client the verified Divi block formats and
   native-module authoring rules.

A successful MCP connection proves transport, not native Divi authoring knowledge.
Updating WordPress or npm does not update a manually copied skill. Verify all three
components before the first write and after changing clients, workspaces, or skill
installation methods.

## Step 1: Install the WordPress Plugin

1. If installing from WordPress.org after listing, go to **WP Admin → Plugins → Add New**, search for **DiviOps Agent**, install it, and activate it.
2. For a pre-listing package or manual fallback install, download `diviops-agent.zip` from the [latest release](https://github.com/rubicon/diviops/releases/latest), verify it against the release's `SHA256SUMS.txt`, then upload it via **WP Admin → Plugins → Add New → Upload Plugin** and activate it.
3. Verify: visit `http://your-site.local/wp-json/diviops/v1/schema/settings` — you should get a 401 (auth required)

> **If Divi is not active**, authenticated requests return `503 divi_unavailable`. Unauthenticated requests return 401 first.

### Free plugin updates

The npm MCP server updates with `npx`/npm. Once the Free WordPress plugin is published on WordPress.org, WordPress delivers plugin updates through the normal **Dashboard → Updates** and **Plugins** screens.

For pre-listing test packages or a manual fallback install, replace the plugin ZIP through WordPress admin:

1. Download `diviops-agent.zip` from the [latest release](https://github.com/rubicon/diviops/releases/latest)
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Upload the new `diviops-agent.zip`
4. When WordPress asks, choose **Replace current with uploaded**

Your Application Password and MCP client config stay the same across Free plugin updates.

### Purchased Pro: install the Pro plugin too

If you downloaded the Pro package, install the Pro add-on after the Free plugin:

1. Upload and activate `diviops-agent-pro.zip`
2. Confirm both plugins are active: **DiviOps Agent** and **DiviOps Agent Pro**
3. Open **DiviOps → Pro License**
4. Paste your license key and activate it
5. Confirm the status shows an active matched plan and update eligibility

The Pro plugin requires the Free plugin. Pro runtime features continue to work after install, while the license gates updates and support.

> **Activation policy.** A license activation represents one active WordPress environment where DiviOps Pro is installed and used, including local development sites such as `localhost`, `.local`, `.test`, and `.lab`. Deactivate old environments from your customer account when they are no longer in active use.

## Step 2: Create an Application Password

1. Go to **WP Admin → Users → Your Profile**
2. Scroll to **Application Passwords**
3. Enter a name (e.g., "Claude MCP") and click **Add New Application Password**
4. Copy the generated password

> **Strip the spaces.** WordPress generates passwords like `758r WQ1X URcg GW3s wCwQ QI0V` for readability but accepts them without spaces. Use `758rWQ1XURcgGW3swCwQQI0V` in `claude mcp add` — spaces can be misparsed as separate arguments.

> Save this — you won't see it again.

## Step 3: Register with Your AI Client

The MCP server runs from the published npm package — no clone, no build step.

> **Important**: Choose a unique MCP name that won't conflict with other MCP servers you have registered. Use your site name (e.g., `diviops-mysite`).

### Claude Code

#### Minimal (REST API only — works with any WordPress host)

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx -y --package @rubicontv/diviops-mcp diviops-mcp
```

#### With WP-CLI (Local by Flywheel — enables the `diviops_meta_wp_cli` tool)

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  --env "WP_PATH=/Users/you/Local Sites/your-site/app/public" \
  -- npx -y --package @rubicontv/diviops-mcp diviops-mcp
```

> **Use `--env` flags, not the `env` command.** Claude Code's native `--env KEY=VALUE` flags survive copy-paste; the older `-- env KEY=VALUE` form (piping through unix `env`) breaks silently when any value contains a space. Quote any value with spaces using regular double quotes — no backslash escaping needed inside quotes.

> `LOCAL_SITE_ID` is auto-detected from `WP_PATH` — no need to find it manually.

### Codex

Add an MCP server entry to `~/.codex/config.toml`:

```toml
[mcp_servers.diviops-mysite]
command = "npx"
args = ["-y", "--package", "@rubicontv/diviops-mcp", "diviops-mcp"]

[mcp_servers.diviops-mysite.env]
WP_URL = "http://your-site.local"
WP_USER = "your-username"
WP_APP_PASSWORD = "xxxxXXXXxxxxXXXXxxxxXXXX"
```

For a local WordPress site where you want WP-CLI passthrough tools, add this line under `[mcp_servers.diviops-mysite.env]`:

```toml
WP_PATH = "/absolute/path/to/wordpress"
```

Restart Codex after changing MCP config.

### Local Development Environments

DiviOps connects via standard WordPress REST API and works with any host that exposes WordPress over HTTP with Application Password support.

| Environment | `WP_URL` | WP-CLI setup | Notes |
|-------------|----------|--------------|-------|
| **Local by Flywheel** | `http://site-name.local` | `WP_PATH=/path/to/site/app/public` | Site ID auto-detected |
| **WordPress Studio** | `http://localhost:{port}` | `WP_CLI_CMD="studio wp --path=/path/to/site"` | Port auto-assigned (8881, 8882, …); SQLite |
| **DDEV** | `https://site-name.ddev.site` | `WP_CLI_CMD="ddev wp"` plus `WP_PATH=/path/to/project` | Wrapper runs from `WP_PATH` |
| **wp-env** | `http://localhost:8888` | `WP_CLI_CMD="npx wp-env run cli wp"` plus `WP_PATH=/path/to/project` | Requires `WP_ENVIRONMENT_TYPE=local` (see below) |
| **DevKinsta** | `https://site-name.local` | `WP_CLI_CMD="docker exec -u www-data devkinsta_fpm wp --path=/www/kinsta/public/sitename"` | HTTPS with self-signed certs |
| **Custom / Remote** | Your site URL | `WP_PATH=/path/to/site` or `WP_CLI_CMD="..."` | Works with any WP host; over ssh, [use a shim](#remote-hosts-over-ssh) |

> **Application Passwords on HTTP:** WordPress requires HTTPS for Application Passwords unless `WP_ENVIRONMENT_TYPE` is set to `'local'`. HTTPS environments (DDEV, DevKinsta) work out of the box. HTTP environments (wp-env, WordPress Studio) need this in `wp-config.php`:
> ```php
> define('WP_ENVIRONMENT_TYPE', 'local');
> ```
> Local by Flywheel sets this automatically.

### Remote hosts over SSH

A remote host is the same shape as a containerized one — `WP_CLI_CMD` runs a wrapper, the wrapper runs `wp` somewhere else — but ssh needs one extra step that the container wrappers do not.

**Never point `WP_CLI_CMD` straight at `ssh`.** The server runs the wrapper with `execFile`, which passes arguments to the child one by one with no shell in between. `ddev wp` and `docker exec … wp` forward them the same way, so nothing is lost. ssh does not: it joins everything after the host into a single string that the *remote* shell re-parses, and every argument boundary disappears on the way:

```
WP_CLI_CMD="ssh HOST wp --path=/srv/site"
wp eval 'echo wp_get_environment_type();'
  → bash: -c: line 0: syntax error near unexpected token `('
```

The remote shell is the one complaining, so the message reads like a malformed wp-cli command. It is not — the argument was well-formed when it left. This breaks `eval`, `search-replace` patterns, and any `--format=json` filter, and it corrupts multi-word values silently when they happen to contain no shell metacharacters. The server warns at startup when it sees `WP_CLI_CMD` beginning with `ssh`, for exactly this reason.

**The fix is a local shim that re-quotes each argument.** Save it, `chmod +x` it, and point `WP_CLI_CMD` at the shim:

```bash
#!/bin/bash
exec ssh -o BatchMode=yes HOST "cd /srv/site && wp $(printf '%q ' "$@")"
```

`printf '%q '` escapes each argument so the remote shell's re-parse reconstructs the original argv. Verified end to end, including `wp eval` with parentheses and quotes.

**Turn on connection multiplexing.** Without it every wp-cli call opens a fresh ssh session, and a managed host starts refusing them — the first connection succeeds and the next two time out. The server reports that as a spawn failure, which reads like a missing binary. In `~/.ssh/config`:

```
Host HOST
  ControlMaster auto
  ControlPath ~/.ssh/cm-%r@%h:%p
  ControlPersist 10m
```

**Raise the timeout.** A cold connection plus a large `search-replace` or `export` runs past the 30-second default. Set `DIVIOPS_WP_CLI_TIMEOUT_MS` (see below) to something like `120000`.

**Keep `WP_PATH` local, or leave it unset.** In `WP_CLI_CMD` mode `WP_PATH` is the working directory the *wrapper* is launched from, on the machine running the MCP server — the remote site path belongs inside the shim (`cd /srv/site && wp …`), not in `WP_PATH`. Setting `WP_PATH` to the remote path makes every command fail before it starts, with `failure_kind: "spawn_failed"` and errno `ENOENT`, because that directory does not exist locally.

The same three points apply to any wrapper that crosses a shell boundary, not only ssh — a `bash -c` wrapper or a remote-exec tool that takes its command as a string has the same problem and the same `printf '%q '` fix.

### Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WP_URL` | Yes | WordPress site URL (e.g. `http://mysite.local`) |
| `WP_USER` | Yes | WordPress username with Editor or Admin role |
| `WP_APP_PASSWORD` | Yes | Application Password (spaces stripped) |
| `WP_PATH` | No | WordPress filesystem path for Local by Flywheel, or wrapper working directory when `WP_CLI_CMD` needs project context |
| `WP_CLI_CMD` | No | Custom WP-CLI command prefix for containerized environments. Arguments are passed to the wrapper individually, with no shell in between — a wrapper that crosses a **shell boundary** (ssh, `bash -c`, any remote-exec tool taking one command string) must re-quote them per argument or every quoted value is destroyed. See [Remote hosts over SSH](#remote-hosts-over-ssh) |
| `LOCAL_SITE_ID` | No | Override auto-detection of Local by Flywheel site ID |
| `DIVIOPS_WP_CLI_ALLOW` | No | Comma-separated list of extended WP-CLI commands to enable ([see below](#wp-cli-security)) |
| `DIVIOPS_WP_CLI_TIMEOUT_MS` | No | Deadline for each WP-CLI child process, in milliseconds. Default `30000`. Raise it for remote wrappers, where a cold connection plus a large `search-replace` or `export` runs past the default. A value that is not a positive integer is ignored with a warning |

### Common Pitfalls

- **Strip spaces from the app password** — covered above; this is the #1 setup snag
- **Use absolute paths** for `WP_PATH` — relative paths break when Claude Code runs from a different directory
- **Unique MCP name** — don't reuse a name from another project
- **Paths with spaces** — wrap the entire `KEY=VALUE` argument in double quotes (e.g. `--env "WP_PATH=/path with spaces/"`). Same goes for any custom server script path passed after `--`
- **MCP not appearing after registration** — in Claude Code, run `claude mcp list` to verify. If it's not there, `claude mcp remove` and re-add. In Codex, verify the `~/.codex/config.toml` entry and restart Codex.

## Step 4: Verify Registration

```bash
claude mcp list
```

You should see your MCP server listed with the correct env vars. If anything looks wrong, remove and re-add:

```bash
claude mcp remove diviops-mysite
claude mcp add diviops-mysite --env KEY=VALUE ... -- npx -y --package @rubicontv/diviops-mcp diviops-mcp
```

## Step 5: Test Connection

Restart Claude Code or Codex, then run:

```
Use diviops_meta_ping to verify the MCP is working.
```

You should see your site URL, WordPress version, and Divi version.

Then try:

```
Use diviops_page_list to show all pages.
```

> **If tools don't appear**: In Claude Code, check `claude mcp list`. In Codex, check `~/.codex/config.toml` and restart Codex. The `npx` command must be reachable on your `PATH` (it ships with Node.js, which provides `npm`/`npx`). The `-y --package @rubicontv/diviops-mcp diviops-mcp` form avoids `npx` prompts and explicitly selects the MCP server bin from the package.

### Purchased Pro: verify Pro capabilities

After activating `diviops-agent-pro.zip` and the Pro license, restart the MCP client and run:

```
Use diviops_meta_info to show the plugin handshake and capability summary.
```

If FluentCart and FluentCart Pro are active on the target site, `diviops_meta_info` should report `slices.fluentcart.active: true` and list `fluentcart_*` entries under `slices.fluentcart.tool_capabilities`. Pro coverage tools such as `diviops_fc_status`, `diviops_fc_product_list`, `diviops_fc_gateway_list`, and license/order readback tools should also appear in the MCP tool list. If the target plugin is not installed or the Pro plugin is inactive, those tools are intentionally omitted.

### Claude Desktop JSON

Use the same command shape in Claude Desktop:

```json
{
  "mcpServers": {
    "diviops-mysite": {
      "command": "npx",
      "args": ["-y", "--package", "@rubicontv/diviops-mcp", "diviops-mcp"],
      "env": {
        "WP_URL": "http://your-site.local",
        "WP_USER": "your-username",
        "WP_APP_PASSWORD": "xxxxXXXXxxxxXXXXxxxxXXXX"
      }
    }
  }
}
```

### Fallback: Global Install

If Claude cannot find `npx`, install the package globally and register the installed bin:

```bash
npm install -g @rubicontv/diviops-mcp@latest

claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- diviops-mcp
```

If the global bin directory is also missing from Claude's `PATH`, register the absolute entrypoint:

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- node "$(npm root -g)/@rubicontv/diviops-mcp/dist/index.js"
```

## Step 6: Optional — Install the Design Library Plugin

For CSS entrance animations (`ddl-fade-up`, `ddl-scale-in`), gradient text effects, and Three.js WebGL shaders:

1. Download `diviops-design-library.zip` from the [repository root](https://github.com/rubicon/diviops)
2. Upload and activate in **WP Admin → Plugins → Add New → Upload Plugin**

This is optional — the MCP agent works without it.

## Step 7: Load the Divi 5 Builder Skill

The skill teaches the assistant the correct Divi 5 block format — module attribute paths, design patterns, and format rules. **Without it, the agent will guess attribute formats and produce broken pages** (e.g., empty buttons, wrong innerContent format).

**Option A — Install as a Claude Code plugin** (recommended):
```bash
claude plugin install rubicon/diviops
```

This installs the `divi-5-builder` skill from this repo. Works from any directory — no need to clone or copy files. To update later:
```bash
claude plugin update divi-5-builder
```

**Option B — Load from cloned repo**:
```bash
git clone https://github.com/rubicon/diviops.git
cd diviops
claude --plugin-dir .
```

**Option C — Copy skill to your project** (auto-loads without flags):
```bash
mkdir -p /path/to/your-project/.claude/skills
cp -r /path/to/diviops/skills/divi-5-builder /path/to/your-project/.claude/skills/
cd /path/to/your-project
claude
```

This project-local path is
`<workspace>/.claude/skills/divi-5-builder/SKILL.md`. Start Claude Code from that
workspace so the project skill is discoverable.

**Option D — Copy skill for Claude Code user-wide use**:
```bash
mkdir -p "$HOME/.claude/skills"
cp -R /path/to/diviops/skills/divi-5-builder "$HOME/.claude/skills/"
```

The resulting path is `~/.claude/skills/divi-5-builder/SKILL.md`.

Verify the skill loaded:
```
What skills do you have?
```
You should see `divi-5-builder` in the list.

Use one ownership path for a skill. Do not keep a Claude plugin-managed copy and a
stale manual copy active at the same time. Plugin-managed skills update with
`claude plugin update divi-5-builder`; manual project or user copies update only
when you replace those copied files from a newer distribution.

Pro packages include additional slice skills such as `diviops-fluentcart` and `diviops-scf`. Install or copy all bundled `skills/*` entries when you want those Pro workflows available to the agent.

**Codex — copy bundled skills into Codex's skill directory** (requires a local repo clone from Option B, or an extracted DiviOps distribution that contains `skills/`):
```bash
mkdir -p "$HOME/.codex/skills"
cp -R /path/to/diviops/skills/* "$HOME/.codex/skills/"
```

Restart Codex after copying skills. Verify with:
```
What skills do you have?
```
You should see `divi-5-builder` and any bundled DiviOps slice skills you installed.

Codex manual skills are not updated by npm, WordPress, or Claude plugin updates;
replace the copied directory from the new distribution and restart Codex. Claude
Desktop and other MCP clients may connect to the server without loading Claude
Code's `.claude/skills` directories. Use that client's supported knowledge or
skill mechanism; otherwise treat the client as read-only for Divi authoring.

## First-run native Divi verification

Run this on a disposable local site or disposable draft before real authoring:

```text
Confirm that divi-5-builder is available, then call diviops_meta_info. Construct
inline markup containing native Divi section, row, column, heading, text, and
button modules and validate that inline content with diviops_validate_blocks
before any write. Do not use a Code module, page-sized HTML, an iframe layout, or
structural HTML inside text or container fields. Only when inline validation has
zero errors, create one disposable draft from the validated bytes, validate the
saved page again by page ID, and report the exact native module names written:
divi/section, divi/row, divi/column, divi/heading, divi/text, and divi/button. If
the skill or required DiviOps tools are unavailable, stop without creating or
changing content.
```

Pass only when the client reports the native module tree and validation succeeds.
A visually correct page made from large HTML blobs is a failed setup, not an
acceptable fallback. Trash the disposable draft after verification.

## Step 8: Optional — Bootstrap the Design System

The skill uses a per-project design system manifest (`.claude/design-system.json`) to resolve preset role keys to site-specific UUIDs. Without it, the agent falls back to inline styling or runtime discovery via `diviops_preset_audit`.

> **This is optional.** Pages can be generated without a design system — the agent uses inline values. The design system adds consistency and reduces token count.

Start with the audit prompt — it detects your project's state and tells you which phases to run.

### Start Here: Audit Your Site

Always start here regardless of project state:

```
Audit my site's design system state. Check for existing oa-* tokens by
running diviops_variable_list twice: once with prefix gcid-oa- (type: colors)
and once with prefix gvid-oa- (type: numbers), and check oa presets with
diviops_preset_audit. Also check diviops_global_color_list for any existing brand
colors. Tell me what exists, what's missing, and which bootstrap phase I
should start from.
```

Then follow the path that matches your result:

---

### Path A: Fresh Site (no tokens, no presets)

Full bootstrap — provide your brand colors:

```
Bootstrap the oa design system tokens for my project.
My brand colors are:
- Primary: Navy #1a2744
- Secondary: Orange #f97316
- Neutral: Slate #64748b
Create the full gcid-oa-* color palette (3 families x 11 shades + white/black)
and all gvid-oa-* number tokens (font sizes, spacings, radii, line heights).
```

Then continue to **Create Presets** below.

### Path B: Branded Site (has global colors, no oa-* tokens)

Your site already has brand colors but they're not in the oa namespace. Adopt them:

```
My site already has brand colors set up (check diviops_global_color_list).
Adopt these into the oa design system:
- Map the primary brand color → gcid-oa-primary family (generate 50-950 shades)
- Map the secondary brand color → gcid-oa-secondary family
- Map the neutral/gray → gcid-oa-neutral family
- Create gcid-oa-white and gcid-oa-black
- Create all gvid-oa-* number tokens (font sizes, spacings, radii, line heights)
Keep the original global colors — the oa tokens are additions, not replacements.
```

Then continue to **Create Presets** below.

### Path C: Partially Bootstrapped (has oa-* tokens, no presets)

Tokens exist but presets are missing. Skip to **Create Presets** below.

### Path D: Existing Project with Non-oa Presets

Your site has presets with project-local names (not `oa *`). You can either:

1. **Keep existing presets** and just generate a manifest mapping them:
```
My site has existing presets that are not oa-named. Run diviops_preset_audit
and list all presets with their names, IDs, and groupNames. Help me map them
to the standard role keys (heading-h1, text-standard, button-primary, etc.)
and generate .claude/design-system.json using my existing preset UUIDs.
```

2. **Or create oa presets alongside** existing ones for consistency with the canonical system. Use the **Create Presets** checklist below.

---

### Create Presets (all paths)

Presets must be created manually in the Visual Builder. Use the following prompt to get a checklist from Claude Code to guide your manual creation:

```
Give me the preset creation checklist. I need to create oa attribute-level
presets in the Visual Builder. List each preset name, which module to create
it on, the groupId, groupName, and which tokens to reference.
```

After creating each batch in the VB, have Claude inspect them:

```
Run diviops_preset_audit and verify the presets I just created.
Capture the UUIDs for the manifest.
```

### Generate Manifest (all paths)

Once tokens and presets are in place:

```
Generate .claude/design-system.json for my project.
Map all oa preset names to role keys and capture UUIDs from diviops_preset_audit.
Also create .claude/instructions/design-system.md with my project's brand
personality and design preferences.
```

See [SKILL.md — Design System Lifecycle](https://github.com/rubicon/diviops/blob/main/skills/divi-5-builder/SKILL.md#design-system-lifecycle) for the full technical reference.

## Quick Test: Generate Your First Page

Ask Claude Code:

```
Create a landing page called "Test Page" with a hero section (dark background,
white heading "Hello World", subtitle, and a CTA button).
```

Claude will use the `divi-5-builder` skill to generate the page. Check the result at your site URL.

> Architecture overview, the per-tool category table, and Free vs Pro differences live in the dist-root README — this guide focuses on the operational walkthrough.

## Targeting Modules

Four ways to target modules for editing:

| Mode | Example | Use when |
|------|---------|----------|
| **Admin label** | `label: "Hero Heading"` | MCP-generated content |
| **Text match** | `match_text: "Hello"` | Find by visible text |
| **Auto-index** | `auto_index: "text:5"` | Any module (from layout response) |
| **Occurrence** | `occurrence: 2` | Duplicate labels |

## WP-CLI Security

The `diviops_meta_wp_cli` tool validates every command against a safety allowlist. Default allowlist covers read-only commands (options, posts, post-term assignment reads, taxonomies, users, ACF field groups, cron/plugin/theme/menu info) plus non-destructive writes (post create/update, term create/update, post-meta set/update, ACF schema export/import, cache flush, transient delete, rewrite flush, WXR export).

**Extended commands** (opt-in via `DIVIOPS_WP_CLI_ALLOW`):

| Command | Risk |
|---------|------|
| `option update` | High — can change site URL, admin email, security settings |
| `post delete` / `post meta delete` / `term delete` | Medium — permanent removal |
| `search-replace` | High — bulk DB modification |
| `import` | Medium — bulk content ingestion |
| `plugin activate` / `plugin deactivate` | Medium |
| `eval-file` | Critical — executes arbitrary PHP |

To enable extended commands:

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=admin \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  --env "WP_PATH=/Users/you/Local Sites/your-site/app/public" \
  --env "DIVIOPS_WP_CLI_ALLOW=option update,post delete,search-replace" \
  -- npx -y --package @rubicontv/diviops-mcp diviops-mcp
```

Only list the specific commands you need. Unknown entries are ignored with a warning.

## Security

Three permission tiers:
- **Read**: `edit_posts` — list/get pages, modules, settings
- **Write**: `edit_pages` — create/modify pages and content
- **Admin**: `manage_options` — presets, library, theme builder, WP-CLI

All endpoints require Application Password authentication.

## Multi-Site / Parallel Testing

The MCP server is a Node.js process that connects to any WordPress site via HTTP. It doesn't need to live inside the WordPress directory — only the `diviops-agent` plugin does.

**Register multiple sites** with different names:

```bash
# Production site
claude mcp add diviops-main \
  --env WP_URL=http://main-site.local \
  --env WP_USER=admin \
  --env WP_APP_PASSWORD=xxxx \
  -- npx -y --package @rubicontv/diviops-mcp diviops-mcp

# Test site (same MCP package, different credentials)
claude mcp add diviops-test \
  --env WP_URL=http://test-site.local \
  --env WP_USER=admin \
  --env WP_APP_PASSWORD=yyyy \
  -- npx -y --package @rubicontv/diviops-mcp diviops-mcp
```

Each registration is independent — different site, different credentials, different MCP name.

**Teammate setup**: They only need:
1. `diviops-agent.zip` installed in their WordPress site
2. For Pro seats, `diviops-agent-pro.zip` installed and activated with their license key
3. `claude mcp add ... npx -y --package @rubicontv/diviops-mcp diviops-mcp` with their own `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`
4. The bundled skills via `claude plugin install rubicon/diviops` or by copying `skills/*` into their Codex skill directory

No clone, no build.

## Troubleshooting

| Issue | Solution |
|-------|----------|
| 401 Unauthorized | Check `WP_USER` and `WP_APP_PASSWORD` (strip spaces) |
| 503 Divi unavailable | Activate Divi 5 theme |
| WP-CLI "not configured" | Set `WP_PATH` (Local by Flywheel) or `WP_CLI_CMD` (containerized) |
| Remote wp-cli reports `bash: -c: line 0: syntax error` | `WP_CLI_CMD` points straight at `ssh`, which destroys per-argument quoting. Use the [re-quoting shim](#remote-hosts-over-ssh) |
| `failure_kind: "spawn_failed"` on a remote wrapper, wp-cli installed | The ssh connection failed, not the binary. Enable `ControlMaster auto` / `ControlPersist` — see [Remote hosts over SSH](#remote-hosts-over-ssh) |
| `failure_kind: "killed"`, "Command timed out" | Raise `DIVIOPS_WP_CLI_TIMEOUT_MS` above the 30000 default, or split the command into smaller batches |
| Styles not rendering | Hard-refresh browser (Cmd+Shift+R) — CSS cache |
| VB shows raw `$variable()$` | Dynamic content binding — click the chip to edit |
| `npx` can't find package | Update Node.js to 22+; verify `npx --version` works; use `npx -y --package @rubicontv/diviops-mcp diviops-mcp`; the explicit package/bin form is required |
