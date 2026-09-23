<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Bounded structural integrity for full-content authoring writes.
 *
 * Adopted from upstream (#474, #328 item 1). A resource budget that runs before
 * any dry-run plan or mutation, counting bytes, blocks, depth, string fields and
 * total string bytes across one walk of the parsed tree.
 *
 * Additive, not a replacement. `assert_divi_full_content_safe_for_write()` checks
 * STRUCTURE — balanced openers and closers, marker sequence — and is left exactly
 * where it is, inside the write integrity guard in trait-core.php. This checks
 * SIZE only, earlier. Calling the structural check from here too (as upstream
 * does) flattened the `counts`/`marker` diagnostics its WP_Error carries.
 *
 * The failure it prevents is ours rather than an attacker's. Every write route is
 * capability-gated, so a hostile caller is not the threat model; this plugin
 * exists to be driven by an agent, and a runaway generation loop is the realistic
 * source of a multi-million-block payload. The expensive outcome is not a
 * rejected request — it is `parse_blocks()` exhausting memory partway through a
 * write, leaving a half-written page.
 *
 * @package DiviOps
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_AuthoringShape {

	/**
	 * Read the budget.
	 *
	 * An accessor rather than reaching for the constant directly, so a test can
	 * assert the published numbers without reflecting into a private const — and
	 * so the limits have exactly one name to grep for.
	 *
	 * @return array<string,int>
	 */
	private static function authoring_shape_limits(): array {
		return self::AUTHORING_SHAPE_LIMITS;
	}

	/**
	 * Check all content before any dry-run plan or mutation.
	 *
	 * Upstream's signature is
	 * `( array $contents, string $operation, string $target, $request, ?int $target_id = null )`,
	 * but four of those five are never referenced in its body — the only
	 * occurrence of `$operation`, `$target`, `$request` or `$target_id` is the
	 * signature line itself. Carrying four dead parameters through seven call
	 * sites is weight with no behaviour, so this fork takes the content list
	 * alone. Recorded in FORK.md.
	 *
	 * The budget is shared across the whole list, not applied per item:
	 * `tb_template_create` passes header, footer and body together, and three
	 * contents each just under the limit must not total three times it.
	 *
	 * @param string[] $contents Full-content strings about to be written.
	 * @return true|WP_Error
	 */
	private static function authoring_shape_preflight( array $contents ) {
		$state = [ 'bytes' => 0, 'blocks' => 0, 'fields' => 0, 'string_bytes' => 0 ];

		// Bytes first, in their own pass: rejecting an oversized payload before
		// parse_blocks() ever sees it is the entire point. Parsing to discover the
		// input was too big to parse defeats the guard.
		foreach ( $contents as $content ) {
			if ( ! is_string( $content ) ) {
				return self::authoring_shape_refusal( 'parser_invalid' );
			}
			$state['bytes'] += strlen( $content );
			if ( $state['bytes'] > self::AUTHORING_SHAPE_LIMITS['input_bytes'] ) {
				return self::authoring_shape_refusal( 'budget_exceeded' );
			}
		}

		// Upstream's preflight also calls assert_divi_full_content_safe_for_write()
		// here. This fork does NOT, deliberately: that check already runs inside
		// the write integrity guard in trait-core.php, and its
		// WP_Error carries `counts` and `marker` diagnostics that
		// test-page-characterization.php pins. Running it a second time out here
		// and re-wrapping the result flattened those diagnostics away — a real
		// regression, caught by that suite. The budget is a budget; structural
		// integrity stays where it already lives and keeps its own error shape.
		foreach ( $contents as $content ) {
			if ( '' === $content ) {
				continue;
			}
			// Two deliberate departures from upstream, which calls bare parse_blocks()
			// and REFUSES outright when it is unavailable.
			//
			// 1. parse_blocks_for_write(), not parse_blocks(). This is a write-path
			//    parse, and #11 routes every one of those through that call so the
			//    tree matches what Divi's own layout-context parser produces. A
			//    budget computed over a DIFFERENT tree than the write walks is
			//    measuring the wrong thing — it would pass a payload the write then
			//    expands, which is precisely the case this guard exists for.
			// 2. A missing parser skips the tree walk rather than refusing the write.
			//    parse_blocks() has shipped in WordPress since 5.0 and this plugin
			//    requires Divi 5, so the branch is unreachable in production; making
			//    it fatal would convert a degraded environment into a total write
			//    outage for a defence-in-depth check. The byte budget above still
			//    applies, and assert_divi_full_content_safe_for_write() has already
			//    run — neither needs a parser.
			if ( ! function_exists( 'parse_blocks' ) ) {
				continue;
			}
			try {
				$blocks = self::parse_blocks_for_write( $content );
				$result = is_array( $blocks ) ? self::authoring_shape_walk( $blocks, 1, $state ) : 'parser_invalid';
			} catch ( \Throwable $error ) {
				return self::authoring_shape_refusal( 'parser_invalid' );
			}
			if ( true !== $result ) {
				return self::authoring_shape_refusal( $result );
			}
		}
		return true;
	}

	/**
	 * Count parsed strings without retaining or rendering a second content corpus.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $depth  Current nesting depth, 1-based.
	 * @param array $state  Running counters, by reference.
	 * @return true|string True, or a refusal reason.
	 */
	private static function authoring_shape_walk( array $blocks, int $depth, array &$state ) {
		if ( $depth > self::AUTHORING_SHAPE_LIMITS['depth'] ) {
			return 'budget_exceeded';
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				return 'parser_invalid';
			}
			if ( ++$state['blocks'] > self::AUTHORING_SHAPE_LIMITS['blocks'] ) {
				return 'budget_exceeded';
			}
			$name = $block['blockName'] ?? null;
			if ( null !== $name && ! is_string( $name ) ) {
				return 'parser_invalid';
			}
			$collect = function ( $value ) use ( &$collect, &$state, $name ) {
				if ( is_string( $value ) ) {
					if ( ++$state['fields'] > self::AUTHORING_SHAPE_LIMITS['fields'] || ( $state['string_bytes'] += strlen( $value ) ) > self::AUTHORING_SHAPE_LIMITS['string_bytes'] ) {
						return 'budget_exceeded';
					}
					// A freeform block carrying a block delimiter in its TEXT is an
					// unparsed delimiter leaking into content — the same family as the
					// truncation defects fixed in #7/#10/#18, caught before it persists.
					if ( null === $name && ( false !== strpos( $value, '<!-- wp:' ) || false !== strpos( $value, '<!-- /wp:' ) ) ) {
						return 'parser_invalid';
					}
				} elseif ( is_array( $value ) ) {
					foreach ( $value as $child ) {
						$result = $collect( $child );
						if ( true !== $result ) {
							return $result;
						}
					}
				}
				return true;
			};
			$attrs         = $block['attrs'] ?? [];
			$inner_html    = $block['innerHTML'] ?? '';
			$inner_content = $block['innerContent'] ?? [];
			$children      = $block['innerBlocks'] ?? [];
			if ( ! is_array( $attrs ) || ! is_string( $inner_html ) || ! is_array( $inner_content ) || ! is_array( $children ) ) {
				return 'parser_invalid';
			}
			$values = [ $attrs, $inner_content ];
			// WordPress mirrors innerContent strings in innerHTML; count that text once.
			if ( $inner_html !== implode( '', array_filter( $inner_content, 'is_string' ) ) ) {
				$values[] = $inner_html;
			}
			$result = $collect( $values );
			if ( true !== $result ) {
				return $result;
			}
			if ( ! empty( $children ) ) {
				$result = self::authoring_shape_walk( $children, $depth + 1, $state );
				if ( true !== $result ) {
					return $result;
				}
			}
		}
		return true;
	}

	/**
	 * Build the refusal. Two reasons, kept distinct: "too big" and "not what it
	 * claims to be" are different problems and an operator acts differently on each.
	 *
	 * @param string $reason budget_exceeded|parser_invalid.
	 * @return WP_Error
	 */
	private static function authoring_shape_refusal( string $reason ) {
		$message = 'budget_exceeded' === $reason
			? 'Full-content authoring input exceeds the required validation limits.'
			: 'Full-content authoring input could not be parsed for required validation.';
		return new WP_Error( 'invalid_input', $message, [ 'status' => 400 ] );
	}
}
