# Upstream Sync PR 2: Permission-Hardening Package — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adopt upstream's per-post-type capability gating (`published_post_types_permission_result()` family) on `page_create`, `page_update_status`, `canvas_create`, `canvas_duplicate`, `library_save`, `tb_template_create` — replacing today's blanket `edit_pages`/`manage_options` checks — with the `post_type`-blind gap in `page_create_permission_result()` fixed as part of adoption, and a good-citizen issue filed on `oaris-dev/diviops` reporting that gap.

**Architecture:** New private static helper methods added to the main `DiviOps_Agent` class (`diviops-agent.php`), wired in as `permission_callback` on 6 existing REST routes. No new files, no new REST routes — only tightening the gate on routes that already exist. See `docs/superpowers/specs/2026-08-02-upstream-sync-reconciliation-design.md` §"Adopt with modification" for the source-of-truth rationale.

**Tech Stack:** PHP 7.4+ (WordPress plugin, `plugins/diviops-agent/`).

## Global Constraints

- Never rename or alter behavior of the four frozen identifiers: plugin slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`, filter `diviops_agent_handshake_extensions`. Nothing in this PR touches any of them.
- **Verification boundary** (see the spec's Revision history and PR 1's plan): `current_user_can()` and `get_post_type_object()` are not shimmed in `tests/wp-shim.php`. No task in this plan writes a unit test that controls their return values — that would be a mocked-behavior test, prohibited outright by this project's engineering policy. Every task here verifies via `php -l` + inspection, with a full `php tests/run.php` regression run at the end confirming zero regressions (specifically re-confirming #31's existing `page_create` tests still pass).
- `page_create()`'s current status validation uses `get_post_stati( [ 'internal' => false ] )` (dynamic — reflects any custom public statuses registered on the site). Upstream's `supported_page_statuses()` is a hardcoded 5-value list (`draft`, `pending`, `publish`, `future`, `private`) — the same 5 values `get_post_stati` returns on a stock WP install with no custom statuses registered, but NOT dynamically equivalent on a site with additional registered public statuses. This PR adopts the hardcoded list at the **permission** layer (matching upstream) while leaving the handler's own dynamic check in `page_create()` untouched — the permission check runs first and is stricter-or-equal in the stock case; a site with genuinely custom statuses would see the permission layer reject before the handler's own (looser) check ever runs. This is a real, if narrow, behavior interaction — documented here rather than silently accepted.
- `page_update_status_permission_result()` narrows `page_update_status()` to `page`-type posts only (today it accepts any post type). Adopted deliberately — the route and its MCP tool are both explicitly page-scoped — but this is a genuine behavior change, not a no-op refactor. Flagged again at Task 3 below.
- Signed commits, no `--no-verify`.

---

### Task 1: Add the shared permission infrastructure (safe, no post_type collision)

**Files:**
- Modify: `plugins/diviops-agent/diviops-agent.php` (insert after `check_write_permission()`, before `check_authenticated_permission()`)

**Interfaces:**
- Produces: `DiviOps_Agent::supported_page_statuses(): array`,
  `DiviOps_Agent::page_status_requires_publish_capability( string $status ): bool`,
  `DiviOps_Agent::post_type_permission_refusal( $error )` (converts a `WP_Error` into the
  canonical DiviOps envelope-error shape),
  `DiviOps_Agent::published_post_types_permission_result( array $post_types )` (returns `true`
  or a `WP_Error`),
  `DiviOps_Agent::fixed_publish_route_permission( string $base_capability, array $post_types )`,
  `DiviOps_Agent::check_canvas_create_permission()`,
  `DiviOps_Agent::check_library_save_permission()`,
  `DiviOps_Agent::check_tb_template_create_permission( $request )` — all `public static` except
  the first four helpers, which are `private static`.
- Consumed by: Task 2 (page_create/page_update_status permission functions reuse
  `supported_page_statuses()`/`page_status_requires_publish_capability()`), Task 4 (route
  wiring for canvas/library/theme-builder).

**Why these are safe to add verbatim** (verified, not assumed): `check_canvas_create_permission()`
uses base capability `'edit_pages'`, matching the existing `check_write_permission()` it
replaces on `/canvas/create` and `/canvas/duplicate`. `check_library_save_permission()` and
`check_tb_template_create_permission()` use `'manage_options'`, matching the existing
`check_admin_permission()` they replace on `/library/save` and `/theme-builder/template/create`.
Every new function is a strict **superset** check (same base capability, PLUS the new
post-type-specific `create_posts`/`publish_posts` checks) — not a narrowing, for these three
routes specifically. This fork has made no other changes to `canvas_create`/`canvas_duplicate`/
`library_save`/`tb_template_create` that a permission-layer change could interact with
(confirmed via `git merge-tree` in the original research pass).

- [ ] **Step 1: Insert the shared infrastructure + 3 safe permission functions**

Find (in `diviops-agent.php`):
```php
	public static function check_write_permission() {
		return current_user_can( 'edit_pages' );
	}

	public static function check_authenticated_permission() {
		return get_current_user_id() > 0;
	}
```

Replace with:
```php
	public static function check_write_permission() {
		return current_user_can( 'edit_pages' );
	}

	/**
	 * Statuses intentionally supported by DiviOps page create/status routes.
	 *
	 * WordPress core can register additional workflow statuses, but accepting
	 * every non-internal status here would bypass the status-specific contract
	 * enforced by WP_REST_Posts_Controller::handle_status_param().
	 *
	 * @return string[]
	 */
	private static function supported_page_statuses(): array {
		return [ 'draft', 'pending', 'publish', 'future', 'private' ];
	}

	private static function page_status_requires_publish_capability( string $status ): bool {
		return in_array( $status, [ 'publish', 'future', 'private' ], true );
	}

	/**
	 * Convert a route-style capability error into the canonical DiviOps refusal.
	 *
	 * @param WP_Error $error Permission error from a request-aware guard.
	 * @return WP_REST_Response
	 */
	private static function post_type_permission_refusal( $error ) {
		$data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		unset( $data['status'] );
		return self::envelope_error(
			'forbidden',
			(string) $error->get_error_message(),
			'Authenticate as a user with the required content capability, then retry.',
			403,
			empty( $data ) ? null : $data
		);
	}

	/**
	 * Require mapped creation and publishing capabilities for fixed-publish CPT writes.
	 *
	 * @param string[] $post_types Post types the operation will create.
	 * @return true|WP_Error
	 */
	private static function published_post_types_permission_result( array $post_types ) {
		foreach ( array_values( array_unique( $post_types ) ) as $post_type_name ) {
			$post_type = get_post_type_object( $post_type_name );
			if ( ! $post_type || ! isset( $post_type->cap->create_posts, $post_type->cap->publish_posts ) ) {
				return new WP_Error(
					'rest_cannot_create',
					'Sorry, this content type does not expose the capabilities required for creation.',
					[ 'status' => 403, 'post_type' => $post_type_name ]
				);
			}
			foreach ( [ 'create_posts', 'publish_posts' ] as $cap_key ) {
				$capability = (string) $post_type->cap->{$cap_key};
				if ( '' === $capability || ! current_user_can( $capability ) ) {
					return new WP_Error(
						'create_posts' === $cap_key ? 'rest_cannot_create' : 'rest_cannot_publish',
						'Sorry, you are not allowed to create published content of this type.',
						[
							'status'              => 403,
							'post_type'           => $post_type_name,
							'required_capability' => $capability,
						]
					);
				}
			}
		}

		return true;
	}

	private static function fixed_publish_route_permission( string $base_capability, array $post_types ) {
		if ( ! current_user_can( $base_capability ) ) {
			return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to perform this operation.', [ 'status' => 403 ] );
		}
		return self::published_post_types_permission_result( $post_types );
	}

	public static function check_canvas_create_permission() {
		return self::fixed_publish_route_permission( 'edit_pages', [ 'et_pb_canvas' ] );
	}

	public static function check_library_save_permission() {
		return self::fixed_publish_route_permission( 'manage_options', [ 'et_pb_layout' ] );
	}

	public static function check_tb_template_create_permission( $request ) {
		$post_types = [ 'et_theme_builder', 'et_template' ];
		$header_content = $request->get_param( 'header_content' );
		$footer_content = $request->get_param( 'footer_content' );
		if ( is_string( $header_content ) && '' !== $header_content ) {
			$post_types[] = 'et_header_layout';
		}
		if ( is_string( $footer_content ) && '' !== $footer_content ) {
			$post_types[] = 'et_footer_layout';
		}
		return self::fixed_publish_route_permission( 'manage_options', $post_types );
	}

	public static function check_authenticated_permission() {
		return get_current_user_id() > 0;
	}
```

- [ ] **Step 2: Syntax check**

Run: `php -l plugins/diviops-agent/diviops-agent.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/diviops-agent/diviops-agent.php
git commit -m "feat(perms): add per-post-type capability gating infrastructure

published_post_types_permission_result() checks the ACTUAL target post
type's registered create_posts/publish_posts capabilities, replacing
blanket edit_pages/manage_options checks. check_canvas_create_permission/
check_library_save_permission/check_tb_template_create_permission are
verified strict supersets of the checks they replace (same base
capability, no fork-authored divergence in the routes they gate) — not
yet wired to any route. From upstream ba008d2."
```

---

### Task 2: `page_create_permission_result()` — adopted WITH the post_type fix, not verbatim

**Files:**
- Modify: `plugins/diviops-agent/diviops-agent.php` (insert after Task 1's block, before `check_authenticated_permission()`... — actually insert the new functions between the Task-1 block and `check_authenticated_permission()`, same insertion region)

**Interfaces:**
- Consumes: `supported_page_statuses()`, `page_status_requires_publish_capability()` (Task 1).
- Produces: `DiviOps_Agent::page_create_permission_result( $request )` (`private static`),
  `DiviOps_Agent::check_page_create_permission( $request )` (`public static`) — consumed by
  Task 4's route wiring.

**The fix, precisely:** upstream's own `page_create_permission_result()` hardcodes
`get_post_type_object( 'page' )`. This fork's `page_create()` (#31) accepts a caller-supplied
`post_type` and creates arbitrary registered types. The version below resolves `$post_type`
from the request — defaulting to `'page'` when absent, mirroring `page_create()`'s own
`( null === $post_type_param ) ? 'page' : sanitize_key(...)` default — instead of hardcoding
the string.

- [ ] **Step 1: Insert the fixed permission function**

Find (the tail end of Task 1's inserted block, right before `check_authenticated_permission()`):
```php
	public static function check_tb_template_create_permission( $request ) {
		$post_types = [ 'et_theme_builder', 'et_template' ];
		$header_content = $request->get_param( 'header_content' );
		$footer_content = $request->get_param( 'footer_content' );
		if ( is_string( $header_content ) && '' !== $header_content ) {
			$post_types[] = 'et_header_layout';
		}
		if ( is_string( $footer_content ) && '' !== $footer_content ) {
			$post_types[] = 'et_footer_layout';
		}
		return self::fixed_publish_route_permission( 'manage_options', $post_types );
	}

	public static function check_authenticated_permission() {
```

Replace with:
```php
	public static function check_tb_template_create_permission( $request ) {
		$post_types = [ 'et_theme_builder', 'et_template' ];
		$header_content = $request->get_param( 'header_content' );
		$footer_content = $request->get_param( 'footer_content' );
		if ( is_string( $header_content ) && '' !== $header_content ) {
			$post_types[] = 'et_header_layout';
		}
		if ( is_string( $footer_content ) && '' !== $footer_content ) {
			$post_types[] = 'et_footer_layout';
		}
		return self::fixed_publish_route_permission( 'manage_options', $post_types );
	}

	/**
	 * Resolve the request-aware page-create capability refusal, if any.
	 *
	 * Resolves the TARGET post type from the request (default 'page', mirroring
	 * page_create()'s own default) rather than hardcoding 'page' — this fork's
	 * page_create() (#31) accepts a caller-supplied post_type, and a caller
	 * creating a non-page type must be gated against that type's own
	 * capabilities, not page's. This is the one place this fork's own #31
	 * addition and upstream's new permission function intersect; upstream's
	 * verbatim version does not know about post_type at all.
	 *
	 * @param WP_REST_Request|ArrayAccess $request REST-like request.
	 * @return true|WP_Error
	 */
	private static function page_create_permission_result( $request ) {
		$status = sanitize_key( (string) ( $request->get_param( 'status' ) ?? 'draft' ) );
		if ( ! in_array( $status, self::supported_page_statuses(), true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				'Status is not supported for DiviOps page creation.',
				[ 'status' => 400 ]
			);
		}

		$post_type_param = $request->get_param( 'post_type' );
		$post_type_name  = ( null === $post_type_param ) ? 'page' : sanitize_key( (string) $post_type_param );

		$post_type = get_post_type_object( $post_type_name );
		if ( ! $post_type || ! isset( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'rest_cannot_create',
				"The {$post_type_name} post type does not expose the capabilities required for creation.",
				[ 'status' => 403, 'post_type' => $post_type_name ]
			);
		}

		if ( ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'rest_cannot_create',
				"Sorry, you are not allowed to create {$post_type_name} content.",
				[
					'status'              => 403,
					'post_type'           => $post_type_name,
					'required_capability' => (string) $post_type->cap->create_posts,
				]
			);
		}

		$publish_capability = isset( $post_type->cap->publish_posts ) ? (string) $post_type->cap->publish_posts : '';
		if (
			self::page_status_requires_publish_capability( $status )
			&& ( '' === $publish_capability || ! current_user_can( $publish_capability ) )
		) {
			return new WP_Error(
				'rest_cannot_publish',
				"Sorry, you are not allowed to publish {$post_type_name} content or create private {$post_type_name} content.",
				[
					'status'              => 403,
					'post_type'           => $post_type_name,
					'required_capability' => $publish_capability,
					'requested_status'    => $status,
				]
			);
		}

		return true;
	}

	/**
	 * Request-aware REST permission callback for POST /page/create.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public static function check_page_create_permission( $request ) {
		return self::page_create_permission_result( $request );
	}

	public static function check_authenticated_permission() {
```

(Note: the error messages above say "{$post_type_name} content" instead of upstream's
hardcoded "pages" — this is intentional, so a caller creating a `post` sees an accurate
message naming the type actually being gated, not a misleading reference to pages.)

- [ ] **Step 2: Syntax check**

Run: `php -l plugins/diviops-agent/diviops-agent.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/diviops-agent/diviops-agent.php
git commit -m "feat(perms): add check_page_create_permission with the post_type gap fixed

Upstream's page_create_permission_result() hardcodes
get_post_type_object('page'), blind to this fork's own post_type
override (#31) — a caller creating a non-page type would have been
gated against page's capabilities regardless of what was actually being
created. Fixed here by resolving $post_type from the request before
adoption, not left for a later patch. Not yet wired to the route
(Task 4)."
```

---

### Task 3: `page_update_status_permission_result()` — adopted with the type-narrowing called out

**Files:**
- Modify: `plugins/diviops-agent/diviops-agent.php` (same insertion region, after Task 2's block)

**Interfaces:**
- Consumes: `supported_page_statuses()`, `page_status_requires_publish_capability()` (Task 1).
- Produces: `DiviOps_Agent::page_update_status_permission_result( $request )` (`private static`),
  `DiviOps_Agent::check_page_update_status_permission( $request )` (`public static`) —
  consumed by Task 4.

**Behavior change, called out per the Global Constraints note above:** today
`page_update_status()` accepts any post type the `id` resolves to (its only type-related check
is `! $post`, no `post_type` comparison at all). This adds `'page' !== (string) $post->post_type`
to the not-found check, narrowing the route to pages only. Adopted deliberately — the route
(`/page/update-status`) and its MCP tool (`diviops_page_update_status`) are both explicitly
page-scoped — but this is a real behavior change, not a no-op.

- [ ] **Step 1: Insert the permission function**

Find (the tail end of Task 2's inserted block):
```php
	public static function check_page_create_permission( $request ) {
		return self::page_create_permission_result( $request );
	}

	public static function check_authenticated_permission() {
```

Replace with:
```php
	public static function check_page_create_permission( $request ) {
		return self::page_create_permission_result( $request );
	}

	/**
	 * Resolve page status-transition authority before a plan or mutation.
	 *
	 * NOTE: this narrows page_update_status() to page-type posts only — today
	 * it accepts any post type the id resolves to. Adopted deliberately since
	 * the route and its MCP tool are both explicitly page-scoped; this is a
	 * real behavior change, documented in the PR 2 implementation plan rather
	 * than folded in silently.
	 *
	 * @param WP_REST_Request|ArrayAccess $request REST-like request.
	 * @return true|WP_Error
	 */
	private static function page_update_status_permission_result( $request ) {
		$post_id = absint( $request['id'] ?? 0 );
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );

		if ( ! in_array( $status, self::supported_page_statuses(), true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				'Status is not supported for DiviOps page status updates.',
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || 'page' !== (string) $post->post_type ) {
			return new WP_Error(
				'rest_cannot_edit',
				'Sorry, you are not allowed to edit this page.',
				[ 'status' => 403 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				'Sorry, you are not allowed to edit this page.',
				[ 'status' => 403 ]
			);
		}

		$post_type = get_post_type_object( 'page' );
		if ( ! $post_type ) {
			return new WP_Error(
				'rest_cannot_edit',
				'The page post type does not expose the capability required for status updates.',
				[ 'status' => 403 ]
			);
		}

		$publish_capability = isset( $post_type->cap->publish_posts ) ? (string) $post_type->cap->publish_posts : '';
		if (
			self::page_status_requires_publish_capability( $status )
			&& ( '' === $publish_capability || ! current_user_can( $publish_capability ) )
		) {
			return new WP_Error(
				'rest_cannot_publish',
				'Sorry, you are not allowed to publish pages or make pages private.',
				[
					'status'              => 403,
					'required_capability' => $publish_capability,
					'requested_status'    => $status,
				]
			);
		}

		return true;
	}

	/**
	 * Request-aware REST permission callback for POST /page/update-status/{id}.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public static function check_page_update_status_permission( $request ) {
		return self::page_update_status_permission_result( $request );
	}

	public static function check_authenticated_permission() {
```

- [ ] **Step 2: Syntax check**

Run: `php -l plugins/diviops-agent/diviops-agent.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/diviops-agent/diviops-agent.php
git commit -m "feat(perms): add check_page_update_status_permission (narrows to page type)

Real behavior change, not a no-op: page_update_status previously
accepted any post type the id resolved to; this narrows it to pages
only, matching the route's and MCP tool's page-scoped naming. Not yet
wired to the route (Task 4)."
```

---

### Task 4: Wire all 6 routes to their new permission callbacks + hand-merge `/page/create`'s args

**Files:**
- Modify: `plugins/diviops-agent/diviops-agent.php` (6 `register_rest_route` calls)

**Interfaces:** none new — this task only changes `permission_callback` values and one `args` array.

- [ ] **Step 1: `/library/save`**

Find:
```php
		register_rest_route( self::REST_NAMESPACE, '/library/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'library_save' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
```
Replace with:
```php
		register_rest_route( self::REST_NAMESPACE, '/library/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'library_save' ],
			'permission_callback' => [ __CLASS__, 'check_library_save_permission' ],
```

- [ ] **Step 2: `/theme-builder/template/create`**

Find:
```php
		register_rest_route( self::REST_NAMESPACE, '/theme-builder/template/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'tb_template_create' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
```
Replace with:
```php
		register_rest_route( self::REST_NAMESPACE, '/theme-builder/template/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'tb_template_create' ],
			'permission_callback' => [ __CLASS__, 'check_tb_template_create_permission' ],
```

- [ ] **Step 3: `/page/update-status/{id}`**

Find:
```php
		register_rest_route( self::REST_NAMESPACE, '/page/update-status/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_update_status' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
```
Replace with:
```php
		register_rest_route( self::REST_NAMESPACE, '/page/update-status/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_update_status' ],
			'permission_callback' => [ __CLASS__, 'check_page_update_status_permission' ],
```

- [ ] **Step 4: `/page/create` — permission callback AND hand-merged args array**

Find:
```php
		register_rest_route( self::REST_NAMESPACE, '/page/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_create' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			'args'                => [
				'title'     => [ 'required' => true, 'type' => 'string' ],
				'content'   => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'status'    => [ 'required' => false, 'type' => 'string', 'default' => 'draft' ],
				'post_type' => [ 'required' => false, 'type' => 'string', 'default' => 'page' ],
			],
		] );
```
Replace with:
```php
		register_rest_route( self::REST_NAMESPACE, '/page/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'page_create' ],
			'permission_callback' => [ __CLASS__, 'check_page_create_permission' ],
			'args'                => [
				'title'     => [ 'required' => true, 'type' => 'string' ],
				'content'   => [ 'required' => false, 'type' => 'string', 'default' => '' ],
				'status'    => [
					'required' => false,
					'type'     => 'string',
					'default'  => 'draft',
					'enum'     => [ 'draft', 'pending', 'publish', 'future', 'private' ],
				],
				'post_type' => [ 'required' => false, 'type' => 'string', 'default' => 'page' ],
				'dry_run'   => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );
```

(`dry_run` already works today via `$request->get_param('dry_run')` in the handler despite not
being declared in `args` — WP's REST args declaration is documentation/validation, not a strict
allowlist. Adding it here is a self-documentation improvement, not a functional fix.)

- [ ] **Step 5: `/canvas/create`**

Find:
```php
		register_rest_route( self::REST_NAMESPACE, '/canvas/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_create' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
```
Replace with:
```php
		register_rest_route( self::REST_NAMESPACE, '/canvas/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_create' ],
			'permission_callback' => [ __CLASS__, 'check_canvas_create_permission' ],
```

- [ ] **Step 6: `/canvas/duplicate/{id}`**

Find:
```php
		register_rest_route( self::REST_NAMESPACE, '/canvas/duplicate/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_duplicate' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
```
Replace with:
```php
		register_rest_route( self::REST_NAMESPACE, '/canvas/duplicate/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'canvas_duplicate' ],
			'permission_callback' => [ __CLASS__, 'check_canvas_create_permission' ],
```

(Upstream reuses `check_canvas_create_permission` for `/canvas/duplicate` too — same base
capability and target post type as create, verified in the original research pass.)

- [ ] **Step 7: Syntax check**

Run: `php -l plugins/diviops-agent/diviops-agent.php`
Expected: `No syntax errors detected`

- [ ] **Step 8: Commit**

```bash
git add plugins/diviops-agent/diviops-agent.php
git commit -m "feat(perms): wire the 6 routes to their new per-post-type permission callbacks

library/save, theme-builder/template/create, page/update-status/{id},
page/create, canvas/create, canvas/duplicate/{id} all gate on the actual
target post type's registered capabilities now, replacing blanket
edit_pages/manage_options checks. page/create's args array hand-merged:
kept this fork's post_type field (#31), added upstream's status enum
and a self-documenting dry_run declaration."
```

---

### Task 5: Full regression suite

**Files:** none (verification only).

- [ ] **Step 1: Run the full PHP test suite**

Run: `php tests/run.php`
Expected: same pass count as before this PR (zero regressions). Specifically confirm any
existing `page_create`/#31 tests still pass — they exercise the handler, not the new
permission layer, so should be unaffected, but re-confirm rather than assume.

- [ ] **Step 2: Manual review checklist (in lieu of a unit test — see Global Constraints)**

Confirm by reading the diff:
- [ ] `page_create_permission_result()` reads `$request->get_param('post_type')`, not a
  hardcoded `'page'` string.
- [ ] Every new `check_*_permission` function is wired to exactly one route in Task 4, and no
  route was left pointing at its old `check_write_permission`/`check_admin_permission`
  callback by mistake.
- [ ] `/page/create`'s `args` array still declares `post_type` (confirm `grep -n "'post_type'" plugins/diviops-agent/diviops-agent.php` still matches inside this route's block).

---

### Task 6: File the good-citizen issue on `oaris-dev/diviops`

**Files:** none (GitHub issue only).

- [ ] **Step 1: Open the issue**

Title: `page_create_permission_result() hardcodes get_post_type_object('page'), ignoring a caller-supplied post_type`

Body should cover, concretely (mirror the tone/structure of our existing
`oaris-dev/diviops#11`):
- What the function does today (quote the hardcoded `get_post_type_object( 'page' )` line).
- Why it's a gap: any consumer that lets a caller specify a target post type for page-create-
  shaped routes would silently gate against `page`'s capabilities regardless of the actual
  type being created.
- The fix: resolve the post type from the request (default `'page'` when absent), the same
  pattern `published_post_types_permission_result()` already uses correctly for
  canvas/library/theme-builder in the same commit.
- Reference this fork's own fixed version (link the merged PR from Task 4) as a concrete
  implementation, once merged — do not link an unmerged PR.

Run:
```bash
gh issue create --repo oaris-dev/diviops \
  --title "page_create_permission_result() hardcodes get_post_type_object('page'), ignoring a caller-supplied post_type" \
  --body-file <path-to-drafted-body>
```

- [ ] **Step 2: Link it back**

Add a comment to the design spec's "Related" section (or a follow-up commit to the spec file)
noting the filed issue's URL, matching how `oaris-dev/diviops#11` is already referenced
throughout this fork's docs.
