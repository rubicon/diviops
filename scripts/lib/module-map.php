<?php
// SPDX-License-Identifier: MIT
/**
 * Build the batched per-module map artifact from two sources that answer
 * different questions (#385).
 *
 * **Source 1 — Divi's per-module `PresetAttrsMap.php`**, read through
 * `scripts/lib/preset-attrs-map.php` (#119). Authoritative on what a module really
 * declares, *including what it removes*: `get_map()` both adds and unsets keys, and
 * the unset ones sit in the file as quoted strings a text scanner cannot tell from
 * the valid ones. Yields `paths` and `invalidates` per module.
 *
 * **Source 2 — `@divi/types`** (GPL-2.0-or-later, published by the Divi Visual
 * Builder team). Authoritative on structure: each module's element map and the
 * decoration groups each element picks. Yields `elements` per module.
 *
 * Where the two disagree the disagreement is recorded, never resolved. A source
 * that says an element exists and a source that never mentions it are making
 * different kinds of claim, and which one is wrong is a finding about Divi or about
 * our own extraction — worth more than either source alone.
 *
 * ## The `@divi/types` seam
 *
 * Every dependence on `@divi/types` in this repository enters through
 * `$types_root` — a directory holding an unpacked copy of the npm package
 * (`package.json` plus `src/module/library/<dir>/index.ts`). Nothing here decides
 * how that directory is obtained. #384 owns that decision (fetch at generation time
 * versus a pinned snapshot); when it lands, point `--types` at whatever it
 * establishes and nothing else changes.
 *
 * ## Not `verified-attrs.json`
 *
 * `diviops-server/data/verified-attrs.json` sits next to this artifact and answers a
 * different question. It is the **evidence-gated write registry** for the preset
 * emitters: per `(module, pattern-family)` cell it records how strongly a shape has
 * been observed round-tripping through the Visual Builder, and
 * `src/preset-cli/registry.ts` refuses to emit below `VB_PRESET_STORAGE_VERIFIED`.
 * Its content is hand-earned, four pattern families wide, and deliberately narrow.
 *
 * This artifact answers "what does this module declare", is generated, and carries no
 * evidence grading at all. Folding one into the other would either grade 16,000
 * generated paths as verified — collapsing the write threshold that registry exists
 * to hold — or bury four hand-verified families inside a machine-generated file that
 * is rewritten on every Divi release. They stay separate.
 *
 * ## What this deliberately does not cover
 *
 * `difl/*` and `d5bgo/*`. Those have no per-module `PresetAttrsMap.php` and no
 * `@divi/types` entry, so neither source here says anything about them. Covering
 * them needs a live `dump_all` round-trip — sources 1, 4, 5 and 6 of
 * `docs/superpowers/specs/2026-07-31-module-map-artifact-design.md`, which this
 * does not implement.
 *
 * Divi source and the `@divi/types` package are read-only reference; nothing here
 * writes to either.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/preset-attrs-map.php';

const DIVIOPS_MODULE_MAP_SCHEMA_VERSION = '1.0.0';

/**
 * Blank out TypeScript comments while preserving every byte offset.
 *
 * Offsets are preserved so the caller can keep reasoning about the original text
 * without a second mapping. Quote state is tracked because a `//` inside a string
 * literal is not a comment.
 *
 * @param string $source TypeScript source.
 */
function diviops_module_map_strip_ts_comments( string $source ): string {
	$out    = $source;
	$length = strlen( $source );
	$i      = 0;

	while ( $i < $length ) {
		$char = $source[ $i ];

		if ( "'" === $char || '"' === $char || '`' === $char ) {
			$quote = $char;
			++$i;
			while ( $i < $length ) {
				if ( '\\' === $source[ $i ] ) {
					$i += 2;
					continue;
				}
				if ( $source[ $i ] === $quote ) {
					break;
				}
				++$i;
			}
			++$i;
			continue;
		}

		if ( '/' === $char && $i + 1 < $length && '/' === $source[ $i + 1 ] ) {
			while ( $i < $length && "\n" !== $source[ $i ] ) {
				$out[ $i ] = ' ';
				++$i;
			}
			continue;
		}

		if ( '/' === $char && $i + 1 < $length && '*' === $source[ $i + 1 ] ) {
			$end = strpos( $source, '*/', $i + 2 );
			$end = false === $end ? $length : $end + 2;
			while ( $i < $end ) {
				if ( "\n" !== $source[ $i ] ) {
					$out[ $i ] = ' ';
				}
				++$i;
			}
			continue;
		}

		++$i;
	}

	return $out;
}

/**
 * Find the offset of the brace matching the one at `$open`.
 *
 * @param string $source Source text, comments already blanked.
 * @param int    $open   Offset of the opening brace.
 *
 * @throws RuntimeException When the brace never closes.
 *
 * @return int Offset of the matching closing brace.
 */
function diviops_module_map_match_brace( string $source, int $open ): int {
	$length = strlen( $source );
	$depth  = 0;

	for ( $i = $open; $i < $length; $i++ ) {
		$char = $source[ $i ];

		if ( "'" === $char || '"' === $char || '`' === $char ) {
			$quote = $char;
			++$i;
			while ( $i < $length ) {
				if ( '\\' === $source[ $i ] ) {
					$i += 2;
					continue;
				}
				if ( $source[ $i ] === $quote ) {
					break;
				}
				++$i;
			}
			continue;
		}

		if ( '{' === $char ) {
			++$depth;
			continue;
		}

		if ( '}' === $char ) {
			--$depth;
			if ( 0 === $depth ) {
				return $i;
			}
		}
	}

	throw new RuntimeException( 'unbalanced braces: an opening brace is never closed' );
}

/**
 * Split an object-type body into its members.
 *
 * Walks the text rather than matching a pattern, because a member's value carries
 * nested object literals, generics and unions that a bounded pattern would either
 * stop inside or run past. Every key is validated as an identifier, so a construct
 * this walk does not understand surfaces as a thrown error rather than a plausible
 * but wrong member list.
 *
 * @param string $body   Text between an interface or object literal's braces.
 * @param string $origin Label used in error messages.
 *
 * @throws RuntimeException When a member key is not a plain identifier.
 *
 * @return array<string, string> Member name to the raw text of its type.
 */
function diviops_module_map_ts_members( string $body, string $origin ): array {
	$members = array();
	$length  = strlen( $body );
	$i       = 0;

	while ( $i < $length ) {
		// Collect the key: everything up to the next top-level colon.
		$key_start = $i;
		$depth     = 0;
		$colon     = -1;

		for ( ; $i < $length; $i++ ) {
			$char = $body[ $i ];

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				++$i;
				while ( $i < $length ) {
					if ( '\\' === $body[ $i ] ) {
						$i += 2;
						continue;
					}
					if ( $body[ $i ] === $quote ) {
						break;
					}
					++$i;
				}
				continue;
			}

			if ( false !== strpos( '{[(<', $char ) ) {
				++$depth;
				continue;
			}

			if ( false !== strpos( '}])>', $char ) ) {
				--$depth;
				continue;
			}

			if ( ':' === $char && 0 === $depth ) {
				$colon = $i;
				break;
			}
		}

		if ( -1 === $colon ) {
			// Trailing whitespace after the last member is the only legal tail.
			$tail = trim( substr( $body, $key_start ) );
			if ( '' !== $tail ) {
				throw new RuntimeException(
					sprintf( '%s: trailing text with no member key: %s', $origin, $tail )
				);
			}
			break;
		}

		$key = rtrim( trim( substr( $body, $key_start, $colon - $key_start ) ), '?' );

		if ( 1 !== preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key ) ) {
			throw new RuntimeException(
				sprintf( '%s: member key is not a plain identifier: %s', $origin, $key )
			);
		}

		// Collect the value: everything up to the next top-level `;` or `,`.
		$value_start = $colon + 1;
		$depth       = 0;
		$value_end   = $length;

		for ( $i = $value_start; $i < $length; $i++ ) {
			$char = $body[ $i ];

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
				++$i;
				while ( $i < $length ) {
					if ( '\\' === $body[ $i ] ) {
						$i += 2;
						continue;
					}
					if ( $body[ $i ] === $quote ) {
						break;
					}
					++$i;
				}
				continue;
			}

			if ( false !== strpos( '{[(<', $char ) ) {
				++$depth;
				continue;
			}

			if ( false !== strpos( '}])>', $char ) ) {
				--$depth;
				continue;
			}

			if ( 0 === $depth && ( ';' === $char || ',' === $char ) ) {
				$value_end = $i;
				break;
			}
		}

		$members[ $key ] = trim( substr( $body, $value_start, $value_end - $value_start ) );
		$i               = $value_end + 1;
	}

	return $members;
}

/**
 * Collect the decoration group names from every `PickedAttributes<...>` in a type.
 *
 * Scoped to `PickedAttributes` by name on purpose. Reading the string literals out
 * of every generic would report `Wrapper.Attributes<'sizing'>` — an element type
 * parameter, not a decoration pick — as a decoration group.
 *
 * @param string $type Raw type text.
 *
 * @return array<int, string> Sorted, unique group names.
 */
function diviops_module_map_picked_groups( string $type ): array {
	$groups = array();
	$offset = 0;

	while ( true ) {
		$at = strpos( $type, 'PickedAttributes', $offset );
		if ( false === $at ) {
			break;
		}

		$cursor = $at + strlen( 'PickedAttributes' );
		while ( $cursor < strlen( $type ) && ctype_space( $type[ $cursor ] ) ) {
			++$cursor;
		}

		if ( $cursor >= strlen( $type ) || '<' !== $type[ $cursor ] ) {
			$offset = $at + 1;
			continue;
		}

		$depth  = 0;
		$length = strlen( $type );

		for ( $i = $cursor; $i < $length; $i++ ) {
			$char = $type[ $i ];

			if ( "'" === $char || '"' === $char ) {
				$quote   = $char;
				$literal = '';
				++$i;
				while ( $i < $length && $type[ $i ] !== $quote ) {
					$literal .= $type[ $i ];
					++$i;
				}
				if ( '' !== $literal ) {
					$groups[ $literal ] = true;
				}
				continue;
			}

			if ( '<' === $char ) {
				++$depth;
				continue;
			}

			if ( '>' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			}
		}

		$offset = $i;
	}

	$names = array_keys( $groups );
	sort( $names );

	return $names;
}

/**
 * Parse one `@divi/types` module file into an element map.
 *
 * A module's attributes are the members of the single interface extending
 * `InternalAttrs`. Sibling interfaces in the same file are not the module's, so the
 * scan is bounded to that interface's body — otherwise a helper interface's own
 * `PickedAttributes<>` leaks into whichever element happens to be nearby.
 *
 * A file this cannot read throws. Returning an empty element map would report every
 * one of that module's paths as absent from types, producing a disagreement list
 * built entirely out of this parser's own silence.
 *
 * @param string $source TypeScript source of `src/module/library/<dir>/index.ts`.
 * @param string $origin Label used in error messages, normally the module directory.
 *
 * @throws RuntimeException When no single `InternalAttrs` interface is found.
 *
 * @return array<string, array{decoration_groups: array<int, string>, type_ref: string|null}>
 */
function diviops_module_map_parse_types( string $source, string $origin ): array {
	$clean   = diviops_module_map_strip_ts_comments( $source );
	$matched = preg_match_all(
		'/\binterface\s+[A-Za-z0-9_$]+\s+extends\s+InternalAttrs\s*\{/',
		$clean,
		$hits,
		PREG_OFFSET_CAPTURE
	);

	if ( 1 !== $matched ) {
		throw new RuntimeException(
			sprintf(
				'%s: expected exactly one interface extending InternalAttrs, found %d',
				$origin,
				(int) $matched
			)
		);
	}

	$open  = $hits[0][0][1] + strlen( $hits[0][0][0] ) - 1;
	$close = diviops_module_map_match_brace( $clean, $open );
	$body  = substr( $clean, $open + 1, $close - $open - 1 );

	$elements = array();

	foreach ( diviops_module_map_ts_members( $body, $origin ) as $key => $type ) {
		$groups   = array();
		$type_ref = $type;
		$brace    = strpos( $type, '{' );

		if ( false !== $brace ) {
			$inner_close = diviops_module_map_match_brace( $type, $brace );
			$inner       = substr( $type, $brace + 1, $inner_close - $brace - 1 );
			$type_ref    = substr( $type, 0, $brace ) . substr( $type, $inner_close + 1 );

			$inner_members = diviops_module_map_ts_members( $inner, $origin . '.' . $key );
			if ( isset( $inner_members['decoration'] ) ) {
				$groups = diviops_module_map_picked_groups( $inner_members['decoration'] );
			}
		}

		$type_ref = trim( $type_ref, " \t\n\r&" );

		$elements[ $key ] = array(
			'decoration_groups' => $groups,
			'type_ref'          => '' === $type_ref ? null : $type_ref,
		);
	}

	ksort( $elements );

	return $elements;
}

/**
 * Index the module directories an unpacked `@divi/types` package declares.
 *
 * Keys are directory paths relative to `src/module/library`, not Divi block names.
 * For every module that has a per-module preset map the two coincide (the block
 * name is `divi/` plus the directory), but the nested `woocommerce/*` subtree does
 * not join that way — Divi registers `divi/woocommerce-add-to-cart` where the
 * directory is `woocommerce/product-add-to-cart`. Keying by directory states what
 * was actually read instead of guessing a block name.
 *
 * @param string $types_root Unpacked `@divi/types` package root.
 *
 * @throws RuntimeException When the root holds no module directories.
 *
 * @return array<string, string> Directory path to absolute `index.ts` path.
 */
function diviops_module_map_types_index( string $types_root ): array {
	$library = rtrim( $types_root, '/' ) . '/src/module/library';

	$index = array();

	if ( is_dir( $library ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $library, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'index.ts' !== $file->getFilename() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			if ( false === strpos( $source, 'extends InternalAttrs' ) ) {
				continue;
			}

			$dir = substr( dirname( $file->getPathname() ), strlen( $library ) + 1 );

			$index[ $dir ] = $file->getPathname();
		}
	}

	if ( array() === $index ) {
		throw new RuntimeException(
			sprintf( 'no module type files found under %s', $library )
		);
	}

	ksort( $index );

	return $index;
}

/**
 * Read the version of an unpacked `@divi/types` package from its own manifest.
 *
 * Never accepted as a parameter: a hand-entered provenance stamp is how the
 * generated index came to claim a Divi version the site was not running.
 *
 * @param string $types_root Unpacked `@divi/types` package root.
 *
 * @throws RuntimeException When the manifest is missing or declares no version.
 */
function diviops_module_map_types_version( string $types_root ): string {
	$manifest = rtrim( $types_root, '/' ) . '/package.json';
	$raw      = @file_get_contents( $manifest );

	if ( false === $raw ) {
		throw new RuntimeException( sprintf( 'cannot read %s', $manifest ) );
	}

	$decoded = json_decode( $raw, true );

	if ( ! is_array( $decoded ) || ! isset( $decoded['version'] ) || '' === $decoded['version'] ) {
		throw new RuntimeException( sprintf( '%s declares no version', $manifest ) );
	}

	return (string) $decoded['version'];
}

/**
 * Take the element a preset-attr path is rooted at.
 *
 * Divi's preset attr paths use two separators, and both can open the first segment:
 * `.` descends into a sub-object (`button.decoration.button__icon.enable` → `button`)
 * while `__` names a sub-key of the element itself (`css__freeForm` → `css`). Splitting
 * on `.` alone reads `css__freeForm` as an element named `css__freeForm`, which no type
 * declaration has ever declared — reporting 188 disagreements against `divi/*` that were
 * entirely an artifact of the split. Whichever separator comes first ends the root.
 *
 * @param string $path Preset attr dot-path.
 */
function diviops_module_map_path_root( string $path ): string {
	$dot   = strpos( $path, '.' );
	$under = strpos( $path, '__' );

	if ( false === $dot && false === $under ) {
		return $path;
	}

	if ( false === $dot ) {
		return substr( $path, 0, (int) $under );
	}

	if ( false === $under ) {
		return substr( $path, 0, $dot );
	}

	return substr( $path, 0, min( $dot, $under ) );
}

/**
 * Record where the two sources contradict each other for one module.
 *
 * Only one direction is a contradiction. An element `@divi/types` declares that the
 * preset map never roots a path at is the ordinary case — the module contributes
 * nothing there on top of the shared base — and recording it would bury the signal
 * under one entry per element per module. The reverse is a real conflict: the
 * preset map roots settable paths at an element the type declaration does not have.
 *
 * Which source is wrong is deliberately not decided here.
 *
 * @param array<int, string>                     $paths    Resolved leaf paths.
 * @param array<string, array>|null              $elements Element map, or null when
 *                                                         types has no file for this module.
 *
 * @return array<int, array{kind: string, element: string}>
 */
function diviops_module_map_disagreements( array $paths, ?array $elements ): array {
	if ( null === $elements ) {
		return array();
	}

	$roots = array();

	foreach ( $paths as $path ) {
		$roots[ diviops_module_map_path_root( $path ) ] = true;
	}

	$found = array();

	foreach ( array_keys( $roots ) as $root ) {
		if ( ! isset( $elements[ $root ] ) ) {
			$found[] = array(
				'kind'    => 'element_absent_from_types',
				'element' => $root,
			);
		}
	}

	usort(
		$found,
		static function ( array $a, array $b ): int {
			return strcmp( $a['element'], $b['element'] );
		}
	);

	return $found;
}

/**
 * Fingerprint the preset-map files the artifact was built from.
 *
 * Covers path and contents of exactly the files read, so a Divi update that touches
 * a per-module map moves the hash and one that does not, does not. This is the only
 * staleness signal that does not depend on a version number being bumped.
 *
 * @param array<string, string> $index      Module name to absolute map-file path.
 * @param string                $packages   Packages root, stripped from the paths.
 */
function diviops_module_map_preset_maps_hash( array $index, string $packages ): string {
	$files = array_unique( array_values( $index ) );
	sort( $files );

	$context = hash_init( 'sha256' );

	foreach ( $files as $file ) {
		hash_update( $context, substr( $file, strlen( rtrim( $packages, '/' ) ) + 1 ) );
		hash_update( $context, "\0" );
		hash_update_file( $context, $file );
		hash_update( $context, "\0" );
	}

	return hash_final( $context );
}

/**
 * Build the whole artifact from both sources.
 *
 * `$divi_version` is passed in rather than derived here so the build stays a pure
 * function of two directories plus a version string; the CLI derives it from Divi's
 * own theme header and refuses to run when it cannot.
 *
 * @param string $packages_dir Divi builder-5 root, or a `Packages` root.
 * @param string $types_root   Unpacked `@divi/types` package root.
 * @param string $divi_version Divi version the packages root came from.
 *
 * @throws RuntimeException When either source yields nothing.
 *
 * @return array<string, mixed>
 */
function diviops_module_map_build( string $packages_dir, string $types_root, string $divi_version ): array {
	// Accept either shape the #119 extractor's CLI accepts: Divi's `includes/builder-5`
	// or a bare `Packages` root, which is what the fixtures are.
	$trimmed      = rtrim( $packages_dir, '/' );
	$packages_dir = is_dir( $trimmed . '/server/Packages' ) ? $trimmed . '/server/Packages' : $trimmed;

	$packages    = diviops_preset_attrs_map_packages_root( $packages_dir );
	$preset_maps = diviops_preset_attrs_map_index( $packages_dir );
	$types_index = diviops_module_map_types_index( $types_root );

	$modules              = array();
	$unserved             = array();
	$without_types        = array();
	$paths_total          = 0;
	$invalidates_total    = 0;
	$disagreements_total  = 0;

	foreach ( $preset_maps as $name => $file ) {
		try {
			$resolved = diviops_preset_attrs_map_resolve( $packages_dir, $name, array() );
		} catch ( RuntimeException $error ) {
			// An indexed name no map file actually serves. Letting this escape would
			// produce no artifact at all; swallowing it would drop the module with no
			// trace. It is neither a module nor nothing, so it gets its own list.
			$unserved[ $name ] = $error->getMessage();
			continue;
		}

		$dir      = substr( $name, 0, 5 ) === 'divi/' ? substr( $name, 5 ) : $name;
		$elements = null;

		if ( isset( $types_index[ $dir ] ) ) {
			$elements = diviops_module_map_parse_types(
				(string) file_get_contents( $types_index[ $dir ] ),
				$dir
			);
		} else {
			$without_types[] = $name;
		}

		$disagreements = diviops_module_map_disagreements( $resolved['final'], $elements );

		$modules[ $name ] = array(
			'class'         => $resolved['class'],
			'file'          => substr( $resolved['file'], strlen( rtrim( $packages, '/' ) ) + 1 ),
			'inert'         => $resolved['inert'],
			'wipes_base'    => $resolved['wipes_base'],
			'paths'         => $resolved['final'],
			'invalidates'   => $resolved['invalidates'],
			'elements'      => $elements,
			'disagreements' => $disagreements,
		);

		$paths_total         += count( $resolved['final'] );
		$invalidates_total   += count( $resolved['invalidates'] );
		$disagreements_total += count( $disagreements );
	}

	if ( array() === $modules ) {
		throw new RuntimeException(
			sprintf( 'resolved no modules at all from %s', $packages )
		);
	}

	$without_preset_map = array();

	foreach ( array_keys( $types_index ) as $dir ) {
		if ( ! isset( $preset_maps[ 'divi/' . $dir ] ) ) {
			$without_preset_map[] = $dir;
		}
	}

	ksort( $modules );
	ksort( $unserved );
	sort( $without_types );
	sort( $without_preset_map );

	return array(
		'schema_version' => DIVIOPS_MODULE_MAP_SCHEMA_VERSION,
		'generated_at'   => gmdate( 'Y-m-d' ),
		'notes'          => array(
			'paths are what the module PresetAttrsMap contributes on top of whatever base it is handed'
			. ' (base empty). Shared decoration vocabulary is not repeated here; see'
			. ' skills/divi-5-builder/references/advanced-attributes.md and scripts/extract-shared-preset-paths.php.',
			'invalidates are keys the module map is proven to strip whether or not the base held them.'
			. ' A text scanner reading quoted strings out of the same file would document them as valid.',
			'elements and their decoration_groups come from @divi/types, and only from picks declared'
			. ' inline. An element whose groups live behind a type name carries type_ref instead, unresolved.',
			'modules_without_types includes modules whose @divi/types file only re-exports another type'
			. ' (export type XAttrs = Y.Attrs). Types does cover them; this generator does not follow the'
			. ' reference. See issue #384.',
			'coverage is divi/* modules that declare a per-module PresetAttrsMap. difl/* and d5bgo/* are'
			. ' absent from both sources and are not covered here at all.',
			'a static map says what is settable, never what is currently set. It does not remove the need'
			. ' to read back after a write.',
		),
		'sources'        => array(
			'divi'       => array(
				'version'           => $divi_version,
				'preset_map_files'  => count( array_unique( array_values( $preset_maps ) ) ),
				'preset_maps_hash'  => diviops_module_map_preset_maps_hash( $preset_maps, $packages ),
			),
			'divi_types' => array(
				'package'      => '@divi/types',
				'version'      => diviops_module_map_types_version( $types_root ),
				'module_files' => count( $types_index ),
			),
		),
		'counts'         => array(
			'modules'                    => count( $modules ),
			'paths'                      => $paths_total,
			'invalidates'                => $invalidates_total,
			'disagreements'              => $disagreements_total,
			'modules_without_types'      => count( $without_types ),
			'modules_without_preset_map' => count( $without_preset_map ),
			'unserved'                   => count( $unserved ),
		),
		'modules'                    => $modules,
		'modules_without_types'      => $without_types,
		'modules_without_preset_map' => $without_preset_map,
		'unserved'                   => $unserved,
	);
}

/**
 * Report every way a regeneration produced less than the artifact it replaces.
 *
 * Derived from the contents, not from the stated counts, so an artifact whose
 * counts disagree with its own body cannot talk its way past. Growth and equality
 * are silent; every kind of loss is a reason and all of them are returned at once,
 * because a gate that stops at the first problem hides the rest of the damage.
 *
 * This is the gate that exists because a generator whose pass/fail is derived only
 * from problems-found will happily certify an artifact that shrank to nothing.
 *
 * @param array<string, mixed> $previous Artifact currently on disk.
 * @param array<string, mixed> $next     Artifact just generated.
 *
 * @return array<int, string> Human-readable reasons; empty when nothing shrank.
 */
function diviops_module_map_shrink_report( array $previous, array $next ): array {
	$reasons = array();

	$before = isset( $previous['modules'] ) && is_array( $previous['modules'] ) ? $previous['modules'] : array();
	$after  = isset( $next['modules'] ) && is_array( $next['modules'] ) ? $next['modules'] : array();

	foreach ( $before as $name => $entry ) {
		if ( ! isset( $after[ $name ] ) ) {
			$reasons[] = sprintf( '%s: module disappeared', $name );
			continue;
		}

		foreach ( array( 'paths', 'invalidates' ) as $list ) {
			$was = count( $entry[ $list ] );
			$now = count( $after[ $name ][ $list ] );

			if ( $now < $was ) {
				$reasons[] = sprintf( '%s: %s fell from %d to %d', $name, $list, $was, $now );
			}
		}

		if ( null !== $entry['elements'] && null === $after[ $name ]['elements'] ) {
			$reasons[] = sprintf( '%s: element map from @divi/types disappeared', $name );
		}
	}

	foreach ( array( 'modules', 'paths', 'invalidates' ) as $count ) {
		$was = (int) ( $previous['counts'][ $count ] ?? 0 );
		$now = (int) ( $next['counts'][ $count ] ?? 0 );

		if ( $now < $was ) {
			$reasons[] = sprintf( 'total %s fell from %d to %d', $count, $was, $now );
		}
	}

	return $reasons;
}
