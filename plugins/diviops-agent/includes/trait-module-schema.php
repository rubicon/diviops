<?php
/**
 * Trait DiviOps_Agent_ModuleSchema
 *
 * Module registry + per-module schema introspection.
 *
 * Part of the diviops-agent monolith split (#220). Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and
 * class constants resolve as if these methods lived directly on the
 * class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_ModuleSchema {

	/**
	 * True when a registered block is a Divi module for schema/targeting purposes.
	 *
	 * The block-type registry alone cannot answer this: on a live site Divi's own
	 * core modules (e.g. `divi/text`, `divi/section`) are largely NOT registered
	 * with WP_Block_Type_Registry, so gating on namespace membership in the
	 * registry would reject them. A block counts as a Divi module when its
	 * namespace is `divi`, OR its category is `module`/`child-module` — the
	 * category third-party Divi 5 modules (`difl/*`, `d5bgo/*`) register under,
	 * which unrelated blocks (`core/*`, `gravityforms/*`, `pdfemb/*`, `tec/*`)
	 * do not use.
	 *
	 * @param string $name       Full block name.
	 * @param mixed  $block_type Registered WP_Block_Type instance.
	 * @return bool
	 */
	private static function is_divi_module_block( string $name, $block_type ): bool {
		if ( 0 === strpos( $name, 'divi/' ) ) {
			return true;
		}
		$category = $block_type->category ?? '';
		return in_array( $category, [ 'module', 'child-module' ], true );
	}

	/**
	 * List all registered Divi modules with basic info.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 */
	public static function schema_list_modules( $request ) {
		$registry = WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$modules  = [];

		foreach ( $all as $name => $block_type ) {
			if ( ! self::is_divi_module_block( $name, $block_type ) ) {
				continue;
			}

			$modules[] = [
				'name'        => $name,
				'title'       => $block_type->title ?? $name,
				'category'    => $block_type->category ?? '',
				'description' => $block_type->description ?? '',
				'supports'    => $block_type->supports ?? [],
			];
		}

		// Native Divi 5 modules are largely absent from the block registry; add
		// them from their on-disk module.json definitions, de-duplicating by name.
		$modules = self::merge_module_lists( $modules, self::native_module_list() );

		return self::envelope_success( $modules );
	}

	/**
	 * Dump every registered Divi module's schema in one call.
	 *
	 * Build-time call for the skill regen pipeline. Walks the
	 * block-type registry once and returns each `divi/*` block's
	 * full attributes alongside a `schema_version` hash so consumers
	 * can short-circuit when nothing changed.
	 *
	 * `schema_version` is SHA-1 over the concatenated contents of
	 * every `*PresetAttrsMap.php` file under `Packages/` — both the
	 * shared option maps in `Packages/Module/Options/` and the
	 * per-module maps in `Packages/ModuleLibrary/`, since the former
	 * are imported by the latter (TextPresetAttrsMap pulls in
	 * LoopPresetAttrsMap). That set is the canonical attr-path index —
	 * its byte-stable hash is what changes only when canonical paths
	 * change.
	 *
	 * Divi core only. The walk roots at `get_theme_file_path()`, the
	 * Divi theme, so it covers no third-party module source: a module
	 * plugin such as DiviFlash ships its own modules from
	 * `wp-content/plugins/`, and updating it cannot move
	 * `schema_version`. Do not read this as a general "some module's
	 * schema changed" signal — on a site with third-party modules
	 * installed it answers that question for the core ones only.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 */
	/**
	 * Every native module's full schema (with attributes), keyed by name, read
	 * from the `module.json` files in a given components directory. The bulk
	 * counterpart of native_module_list() (which returns basic info only) — this
	 * carries the `attributes` tree each dump consumer needs. Pure of the live
	 * path resolution so it is unit-testable against a fixture directory.
	 *
	 * @param string $components_dir The module-library components directory.
	 * @return array<string,array>
	 */
	private static function native_module_schemas_all_from_dir( $components_dir ): array {
		if ( ! is_string( $components_dir ) || ! is_dir( $components_dir ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) glob( $components_dir . '/*/module.json' ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$json = (string) file_get_contents( $path );
			$data = json_decode( $json, true );
			$name = is_array( $data ) ? (string) ( $data['name'] ?? '' ) : '';
			if ( '' === $name ) {
				continue;
			}
			$schema = self::parse_native_module_json( $json, $name );
			if ( is_array( $schema ) ) {
				$out[ $name ] = $schema;
			}
		}
		return $out;
	}

	/**
	 * Every native Divi 5 module's full schema, keyed by name (live path).
	 *
	 * @return array<string,array>
	 */
	private static function native_module_schemas_all(): array {
		return self::native_module_schemas_all_from_dir( self::native_module_components_dir() );
	}

	public static function schema_get_module_dump_all( $request ) {
		$registry = WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$modules  = [];

		foreach ( $all as $name => $block_type ) {
			if ( ! self::is_divi_module_block( $name, $block_type ) ) {
				continue;
			}

			$modules[ $name ] = [
				'name'        => $block_type->name,
				'title'       => $block_type->title ?? '',
				'category'    => $block_type->category ?? '',
				'description' => $block_type->description ?? '',
				'attributes'  => $block_type->attributes ?? [],
				'supports'    => $block_type->supports ?? [],
			];
		}

		// Native Divi 5 core modules are largely absent from the block registry;
		// add their full schemas from the on-disk module.json definitions so the
		// bulk skill-regen source is complete. Registered entries win on a name
		// collision (they carry the live block-type's own metadata).
		foreach ( self::native_module_schemas_all() as $name => $schema ) {
			if ( ! isset( $modules[ $name ] ) ) {
				$modules[ $name ] = $schema;
			}
		}

		ksort( $modules );

		return self::envelope_success( [
			'schema_version' => self::schema_preset_attrs_map_hash(),
			'divi_version'   => self::resolve_divi_version(),
			'modules'        => $modules,
		] );
	}

	/**
	 * Resolve the Divi build version, preferring the builder constant
	 * over the core fallback. Single source of truth for both the
	 * dump-all response payload and the schema-hash cache key, so the
	 * two never diverge on a site that defines only ET_CORE_VERSION.
	 */
	private static function resolve_divi_version() {
		if ( defined( 'ET_BUILDER_VERSION' ) && ET_BUILDER_VERSION ) {
			return ET_BUILDER_VERSION;
		}
		if ( defined( 'ET_CORE_VERSION' ) && ET_CORE_VERSION ) {
			return ET_CORE_VERSION;
		}
		return '';
	}

	/**
	 * Hash every `*PresetAttrsMap.php` under `Packages/`.
	 *
	 * Walks `Packages/` (not just `Packages/ModuleLibrary/`) because
	 * shared option maps live under `Packages/Module/Options/**` and are
	 * imported by per-module maps — e.g. TextPresetAttrsMap pulls in
	 * LoopPresetAttrsMap. Hashing only ModuleLibrary would miss real
	 * canonical-path changes when a shared option map shifts and let the
	 * regen pipeline short-circuit on a stale hash.
	 *
	 * The hash mixes in each file's path *relative to* the Packages
	 * root, not its absolute path. That keeps the hash environment-
	 * stable across local / staging / prod installs with byte-
	 * identical theme files but different filesystem roots.
	 *
	 * Cached per-request via a static (PresetAttrsMap files don't
	 * change between requests within a PHP process) and across
	 * requests via a transient keyed by Divi build version + the
	 * directory mtime, so a theme update or file edit invalidates it
	 * automatically.
	 */
	private static function schema_preset_attrs_map_hash() {
		static $cached_hash = null;
		if ( null !== $cached_hash ) {
			return $cached_hash;
		}

		$packages_root = get_theme_file_path( 'includes/builder-5/server/Packages' );
		if ( ! is_dir( $packages_root ) ) {
			$cached_hash = '';
			return $cached_hash;
		}

		$cache_key = 'diviops_schema_attrs_map_hash_' . md5(
			'Packages|' . self::resolve_divi_version() . '|' . self::dir_latest_mtime( $packages_root )
		);
		$transient = get_transient( $cache_key );
		if ( is_string( $transient ) && '' !== $transient ) {
			$cached_hash = $transient;
			return $cached_hash;
		}

		$root_len = strlen( $packages_root ) + 1; // +1 to drop the trailing slash too
		$files    = [];
		$it       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $packages_root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$abs = $file->getPathname();
			if ( substr( $abs, -strlen( 'PresetAttrsMap.php' ) ) !== 'PresetAttrsMap.php' ) {
				continue;
			}
			// Use forward-slash relative paths so Windows installs hash
			// the same way as Unix ones (otherwise DIRECTORY_SEPARATOR
			// would couple the hash to the host OS).
			$rel     = substr( $abs, $root_len );
			$rel     = str_replace( DIRECTORY_SEPARATOR, '/', $rel );
			$files[] = [ 'rel' => $rel, 'abs' => $abs ];
		}
		usort( $files, static function ( $a, $b ) { return strcmp( $a['rel'], $b['rel'] ); } );

		$ctx = hash_init( 'sha1' );
		foreach ( $files as $file ) {
			hash_update( $ctx, $file['rel'] . "\n" );
			hash_update_file( $ctx, $file['abs'] );
		}
		$cached_hash = hash_final( $ctx );

		// HOUR_IN_SECONDS, not MINUTE — the cache key already incorporates
		// the directory's latest mtime, so any *PresetAttrsMap.php edit
		// (theme update, manual file change) invalidates the entry on the
		// next request regardless of TTL. The TTL is only the upper bound
		// for "no on-disk change happened, just expire and re-hash."
		set_transient( $cache_key, $cached_hash, HOUR_IN_SECONDS );
		return $cached_hash;
	}

	/**
	 * Latest mtime of any file under $dir, recursive. Cheap signal for
	 * "did the on-disk schema change since last request" — don't need
	 * full file content here, just an invalidation hint.
	 */
	private static function dir_latest_mtime( $dir ) {
		$latest = 0;
		$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$mtime = $file->getMTime();
			if ( $mtime > $latest ) {
				$latest = $mtime;
			}
		}
		return $latest;
	}

	/**
	 * The Divi 5 native-module `module.json` components directory.
	 *
	 * Divi ships one `module.json` per native module under this tree, each
	 * carrying the module's `name` (`divi/<slug>`), `title`, `category`, and full
	 * `attributes`. Resolved through `get_theme_file_path()` like the plugin's
	 * other builder-5 lookups, so it follows a child/parent theme correctly.
	 */
	private static function native_module_components_dir() {
		return get_theme_file_path( 'includes/builder-5/visual-builder/packages/module-library/src/components' );
	}

	/**
	 * Map a `divi/<slug>` block name to its component directory slug.
	 *
	 * This is also the path-traversal defense: the slug must be lowercase
	 * alphanumeric with single hyphens (the shape every real Divi module dir
	 * uses), so nothing containing `.`, `/`, `..`, or an empty segment can reach
	 * the filesystem. Returns null for any non-`divi/` name or a malformed slug.
	 *
	 * @param string $name Full block name.
	 * @return string|null
	 */
	private static function native_module_slug_from_name( string $name ) {
		if ( 0 !== strpos( $name, 'divi/' ) ) {
			return null;
		}
		$slug = substr( $name, strlen( 'divi/' ) );
		// The `D` modifier is load-bearing: without it PCRE's `$` also matches
		// just before a single trailing newline, so a slug ending in "\n" would
		// pass the guard. `D` anchors `$` to the true end of the string.
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) ) {
			return null;
		}
		return $slug;
	}

	/**
	 * Parse a native module's `module.json` string into the schema shape.
	 *
	 * Rejects (returns null) malformed JSON and a file whose declared `name`
	 * does not match the requested block name, so a slug can never surface a
	 * different module's schema than the one asked for.
	 *
	 * @param string $json Raw module.json contents.
	 * @param string $name The block name that was requested.
	 * @return array|null
	 */
	private static function parse_native_module_json( string $json, string $name ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ( $data['name'] ?? '' ) !== $name ) {
			return null;
		}
		return [
			'name'          => (string) $data['name'],
			'title'         => (string) ( $data['title'] ?? '' ),
			'category'      => (string) ( $data['category'] ?? '' ),
			'description'   => '',
			'attributes'    => is_array( $data['attributes'] ?? null ) ? $data['attributes'] : [],
			'settings'      => is_array( $data['settings'] ?? null ) ? $data['settings'] : [],
			'custom_css'    => is_array( $data['customCssFields'] ?? null ) ? $data['customCssFields'] : [],
			'children_name' => is_array( $data['childrenName'] ?? null ) ? $data['childrenName'] : [],
			'module_class'  => (string) ( $data['moduleClassName'] ?? '' ),
			'd4_shortcode'  => (string) ( $data['d4Shortcode'] ?? '' ),
			'supports'      => [],
			'source'        => 'divi_module_json',
		];
	}

	/**
	 * Read a native module's schema from a given components directory.
	 *
	 * Split from native_module_schema() so the read/parse logic is testable
	 * against a fixture directory without a live Divi install. Enforces the slug
	 * guard AND a realpath-containment check (defense in depth) so a resolved
	 * file can never sit outside the components directory.
	 *
	 * @param string $name           Full block name.
	 * @param string $components_dir  The module-library components directory.
	 * @return array|null
	 */
	private static function native_module_schema_from_dir( string $name, $components_dir ) {
		$slug = self::native_module_slug_from_name( $name );
		if ( null === $slug || ! is_string( $components_dir ) || '' === $components_dir ) {
			return null;
		}
		$path     = $components_dir . '/' . $slug . '/module.json';
		$real     = realpath( $path );
		$real_dir = realpath( $components_dir );
		if ( false === $real || false === $real_dir || 0 !== strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		$json = file_get_contents( $real );
		if ( false === $json ) {
			return null;
		}
		return self::parse_native_module_json( $json, $name );
	}

	/**
	 * Resolve a native Divi 5 module's schema from its on-disk `module.json`,
	 * or null if $name is not a native module / the file is missing.
	 *
	 * @param string $name Full block name.
	 * @return array|null
	 */
	private static function native_module_schema( string $name ) {
		return self::native_module_schema_from_dir( $name, self::native_module_components_dir() );
	}

	/**
	 * List every native Divi 5 module from its on-disk `module.json` files.
	 *
	 * Basic-info shape matching schema_list_modules' registered entries, so the
	 * two sets merge cleanly. Empty array when the components dir is absent.
	 *
	 * @return array
	 */
	private static function native_module_list() {
		$dir = self::native_module_components_dir();
		if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) glob( $dir . '/*/module.json' ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $data ) || empty( $data['name'] ) ) {
				continue;
			}
			$out[] = [
				'name'        => (string) $data['name'],
				'title'       => (string) ( $data['title'] ?? $data['name'] ),
				'category'    => (string) ( $data['category'] ?? '' ),
				'description' => '',
				'supports'    => [],
				'source'      => 'divi_module_json',
			];
		}
		return $out;
	}

	/**
	 * Merge a native-module list into a registered-module list, de-duplicating
	 * by `name`: a native module already present as a registered block is not
	 * added twice, and no registered entry is dropped. Pure (no I/O) so the
	 * merge/dedup contract is unit-testable without a live Divi install.
	 *
	 * @param array $registered Registered-module entries (each with a `name`).
	 * @param array $native      Native module.json entries (each with a `name`).
	 * @return array
	 */
	private static function merge_module_lists( array $registered, array $native ): array {
		$seen = [];
		foreach ( $registered as $entry ) {
			if ( isset( $entry['name'] ) ) {
				$seen[ $entry['name'] ] = true;
			}
		}
		$merged = $registered;
		foreach ( $native as $entry ) {
			$name = $entry['name'] ?? '';
			if ( '' !== $name && empty( $seen[ $name ] ) ) {
				$merged[]       = $entry;
				$seen[ $name ]  = true;
			}
		}
		return $merged;
	}

	/**
	 * Get full schema/attributes for a specific module.
	 *
	 * Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.
	 * Errors:
	 *  - not_found (HTTP 404): the module name does not resolve in the
	 *    Divi block-type registry. Hint suggests `diviops_schema_list_modules`.
	 */
	public static function schema_get_module( $request ) {
		$name = sanitize_text_field( (string) $request['name'] );

		// Normalize: accept "text", "divi/text", or "difl/faq".
		$name = self::block_name_from_identifier( $name );

		$registry   = WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $name );

		if ( ! $block_type ) {
			// Native Divi 5 core modules (divi/text, divi/section, …) are largely
			// absent from WP_Block_Type_Registry; fall back to Divi's own on-disk
			// module.json definition so they are introspectable too.
			$native = self::native_module_schema( $name );
			if ( is_array( $native ) ) {
				return self::envelope_success( $native );
			}
			return self::envelope_error(
				'not_found',
				"Module '{$name}' not found",
				'Run diviops_schema_list_modules to see available modules. Native Divi modules resolve from their on-disk module.json; third-party modules must be registered.',
				404
			);
		}

		return self::envelope_success( [
			'name'        => $block_type->name,
			'title'       => $block_type->title ?? '',
			'category'    => $block_type->category ?? '',
			'description' => $block_type->description ?? '',
			'attributes'  => $block_type->attributes ?? [],
			'supports'    => $block_type->supports ?? [],
			'source'      => 'block_registry',
		] );
	}
}
