<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Bulk / site-wide content operations (#38).
 *
 * Fork-owned, absent upstream. Phase 1 of the design spec at
 * `docs/superpowers/specs/2026-08-14-bulk-site-wide-operations-design.md`,
 * which governs everything in this file.
 *
 * Phase 1 is `content_search` and it is **read-only**. Discovery and mutation
 * are deliberately separate tools: a query re-evaluated at apply time is not
 * the set the caller reviewed, so the write half (phases 2 and 3) takes an
 * explicit id list and nothing else. `content_search` is what makes that
 * explicit-id model usable rather than tedious.
 *
 * @package DiviOps
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk / site-wide content operations.
 */
trait DiviOps_Agent_Bulk {

	/**
	 * Post statuses a site-wide content read reaches.
	 *
	 * Fixed rather than a parameter, and matching `variable_id_appears_anywhere()`
	 * (`trait-variable.php`) and `preset_reassign`'s own scan, so the search a
	 * caller runs before a bulk write covers the same rows those writers do.
	 * `trash` and `auto-draft` are excluded: a match inside a trashed post is
	 * not something a caller can act on through any tool this plugin ships.
	 */
	const BULK_SEARCH_POST_STATUSES = [ 'publish', 'draft', 'private', 'pending', 'future' ];

	/** Largest number of posts a single content_search will return. */
	const BULK_SEARCH_MAX_POSTS = 200;

	/** Default number of posts returned when the caller names no limit. */
	const BULK_SEARCH_DEFAULT_POSTS = 50;

	/** Largest number of per-post match records returned. */
	const BULK_SEARCH_MAX_MATCHES_PER_POST = 50;

	/** Default number of per-post match records returned. */
	const BULK_SEARCH_DEFAULT_MATCHES_PER_POST = 10;

	/** Largest context window, in characters, on either side of a match. */
	const BULK_SEARCH_MAX_CONTEXT = 200;

	/** Default context window, in characters, on either side of a match. */
	const BULK_SEARCH_DEFAULT_CONTEXT = 60;

	/**
	 * Site-wide literal substring search over `post_content`.
	 *
	 * Read-only, `check_read_permission` at the route plus the same row-level
	 * `edit_post` boundary every raw object read uses.
	 *
	 * ── Why two needles ──────────────────────────────────────────────────
	 *
	 * In Divi 5 module text lives inside the block comment's attribute JSON,
	 * and core's `serialize_block_attributes()` escapes `<`, `>`, `&`, `"`,
	 * `--` and `\` on the way in. So the bytes stored for a phrase a human
	 * reads on the page are frequently NOT the bytes of the phrase. A single
	 * `LIKE` on the literal needle silently misses every such post — the post
	 * never becomes a candidate, and the tool reports "no matches" rather than
	 * "I cannot see this". Searching both the literal form and the stored form
	 * is therefore not a refinement; it is the difference between a working
	 * tool and one that is confidently blind.
	 *
	 * The stored form is derived through `serialize_block_attrs_canonical()`
	 * itself rather than by re-listing core's escape table here, so the two
	 * cannot drift apart.
	 *
	 * ── What `truncated` means ───────────────────────────────────────────
	 *
	 * "More posts matched the SQL `LIKE` than the ceiling returned." It does
	 * NOT mean "more posts were scanned", and it is not affected by the
	 * row-level permission filter, which runs after the ceiling.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function content_search( $request ) {
		global $wpdb;

		$needle = (string) $request->get_param( 'search' );
		if ( '' === $needle ) {
			return self::envelope_error(
				'invalid_input',
				'search must be a non-empty string.',
				'Pass the literal text to look for. Pattern matching is not supported.',
				400
			);
		}

		$post_types = self::bulk_resolve_search_post_types( $request->get_param( 'post_types' ) );
		if ( is_wp_error( $post_types ) ) {
			return self::envelope_from_helper_error( $post_types, 'content_search', 0 );
		}

		$limit           = self::bulk_clamp(
			$request->get_param( 'limit' ),
			self::BULK_SEARCH_DEFAULT_POSTS,
			1,
			self::BULK_SEARCH_MAX_POSTS
		);
		$max_matches     = self::bulk_clamp(
			$request->get_param( 'max_matches_per_post' ),
			self::BULK_SEARCH_DEFAULT_MATCHES_PER_POST,
			1,
			self::BULK_SEARCH_MAX_MATCHES_PER_POST
		);
		$context_chars   = self::bulk_clamp(
			$request->get_param( 'context_chars' ),
			self::BULK_SEARCH_DEFAULT_CONTEXT,
			0,
			self::BULK_SEARCH_MAX_CONTEXT
		);

		// The bytes this needle takes when stored inside block attribute JSON.
		// Null when the canonical serializer is unavailable or its output shape
		// changed — in which case the escaped-form search is skipped and said
		// so in the response, rather than guessed at.
		$stored_form = self::bulk_needle_stored_form( $needle );

		$candidates = self::bulk_search_candidate_ids( $wpdb, $needle, $stored_form, $post_types, $limit );
		if ( is_wp_error( $candidates ) ) {
			return self::envelope_from_helper_error( $candidates, 'content_search', 0 );
		}

		$ids = self::filter_inspectable_post_objects( $candidates['ids'] );

		$results       = [];
		$total_matches = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$hit = self::bulk_search_post_matches(
				(string) $post->post_content,
				$needle,
				$stored_form,
				$max_matches,
				$context_chars
			);
			if ( 0 === $hit['match_count'] ) {
				// The LIKE matched a row the byte scan cannot confirm. This is
				// reachable: MySQL's default collation is case-insensitive and
				// accent-insensitive, while strpos is neither. Dropping the row
				// keeps the reported matches and the reported count describing
				// the same thing.
				continue;
			}
			$total_matches += $hit['match_count'];
			$results[]      = [
				'id'             => (int) $post->ID,
				'post_type'      => (string) $post->post_type,
				'post_status'    => (string) $post->post_status,
				'title'          => (string) $post->post_title,
				'uses_divi'      => false !== strpos( (string) $post->post_content, self::BLOCK_PREFIX ),
				'match_count'    => $hit['match_count'],
				'matches'        => $hit['matches'],
				'matches_capped' => $hit['capped'],
			];
		}

		$data = [
			'search'              => $needle,
			'stored_form'         => $stored_form,
			'searched_forms'      => ( null !== $stored_form && $stored_form !== $needle )
				? [ 'literal', 'escaped' ]
				: [ 'literal' ],
			'post_types'          => $post_types,
			'post_statuses'       => self::BULK_SEARCH_POST_STATUSES,
			'limit'               => $limit,
			'candidate_posts'     => count( $candidates['ids'] ),
			'total_posts'         => count( $results ),
			'total_matches'       => $total_matches,
			'truncated'           => $candidates['truncated'],
			'results'             => $results,
		];

		return self::envelope_success( $data );
	}

	/**
	 * Clamp an optional integer parameter into range.
	 *
	 * @param mixed $raw     Caller value.
	 * @param int   $default Value when unset.
	 * @param int   $min     Lower bound.
	 * @param int   $max     Upper bound.
	 * @return int
	 */
	private static function bulk_clamp( $raw, int $default, int $min, int $max ): int {
		if ( null === $raw || '' === $raw ) {
			return $default;
		}
		return max( $min, min( $max, (int) $raw ) );
	}

	/**
	 * Resolve and validate the requested post-type scope.
	 *
	 * The READ scope is `SCANNABLE_POST_TYPES`, deliberately wider than the
	 * write scope phases 2 and 3 use (`page`, `post`). A reader that cannot
	 * see as far as a writer writes is a writer nobody can audit; the reverse
	 * — a reader seeing further than any writer reaches — is safe.
	 *
	 * @param mixed $raw Caller value: null, or an array of post types.
	 * @return array|WP_Error
	 */
	private static function bulk_resolve_search_post_types( $raw ) {
		if ( null === $raw || '' === $raw || [] === $raw ) {
			return self::SCANNABLE_POST_TYPES;
		}
		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'invalid_input',
				'post_types must be an array of post types.',
				[ 'status' => 400 ]
			);
		}

		$resolved = [];
		$unknown  = [];
		foreach ( $raw as $type ) {
			$type = (string) $type;
			if ( in_array( $type, self::SCANNABLE_POST_TYPES, true ) ) {
				if ( ! in_array( $type, $resolved, true ) ) {
					$resolved[] = $type;
				}
				continue;
			}
			$unknown[] = $type;
		}

		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'invalid_input',
				'Unsupported post type(s): ' . implode( ', ', $unknown ) . '.',
				[
					'status'    => 400,
					'supported' => self::SCANNABLE_POST_TYPES,
					'unknown'   => $unknown,
				]
			);
		}

		return $resolved;
	}

	/**
	 * The bytes a needle takes when stored as a JSON string value inside a
	 * block opener's attributes.
	 *
	 * Derived by round-tripping a one-key object through
	 * `serialize_block_attrs_canonical()` — which is core's
	 * `serialize_block_attributes()` when WordPress supplies it — rather than
	 * re-listing core's escape table. Re-listing it would be a second copy of
	 * a table that can change, and the failure mode of a stale copy is a
	 * search that silently misses rows.
	 *
	 * Returns null when the serializer is unavailable or its output does not
	 * take the expected shape, so the caller can say the escaped form was not
	 * searched instead of searching for something wrong.
	 *
	 * @param string $needle Literal search string.
	 * @return string|null
	 */
	private static function bulk_needle_stored_form( string $needle ): ?string {
		$encoded = self::serialize_block_attrs_canonical( (object) [ 'v' => $needle ] );
		if ( ! is_string( $encoded ) ) {
			return null;
		}

		$prefix = '{"v":"';
		$suffix = '"}';
		if ( 0 !== strpos( $encoded, $prefix ) ) {
			return null;
		}
		if ( substr( $encoded, -strlen( $suffix ) ) !== $suffix ) {
			return null;
		}

		return substr( $encoded, strlen( $prefix ), -strlen( $suffix ) );
	}

	/**
	 * Candidate post ids, by prepared `LIKE` over `post_content`.
	 *
	 * `WP_Query` has no `post_content LIKE` argument, so this is a direct
	 * prepared query rather than `query_inspectable_post_ids()`. The row-level
	 * `edit_post` boundary is applied by the caller through
	 * `filter_inspectable_post_objects()` on the ids this returns — the same
	 * discipline `query_inspectable_post_ids()` applies after its own coarse
	 * prefilter.
	 *
	 * Selects one row beyond the ceiling so `truncated` is a fact about the
	 * query rather than an inference.
	 *
	 * @param object      $wpdb        Database handle.
	 * @param string      $needle      Literal search string.
	 * @param string|null $stored_form Escaped form, or null if underivable.
	 * @param array       $post_types  Post types to scan.
	 * @param int         $limit       Ceiling on returned posts.
	 * @return array{ids:array,truncated:bool}|WP_Error
	 */
	private static function bulk_search_candidate_ids( $wpdb, string $needle, ?string $stored_form, array $post_types, int $limit ) {
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->posts ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return new WP_Error(
				'unsupported',
				'This WordPress installation does not expose the posts table to a direct query.',
				[ 'status' => 500 ]
			);
		}

		$needles = [ $needle ];
		if ( null !== $stored_form && $stored_form !== $needle ) {
			$needles[] = $stored_form;
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( self::BULK_SEARCH_POST_STATUSES ), '%s' ) );
		$like_placeholders   = implode( ' OR ', array_fill( 0, count( $needles ), 'post_content LIKE %s' ) );

		$values = array_merge(
			$post_types,
			self::BULK_SEARCH_POST_STATUSES,
			array_map(
				static function ( $one ) use ( $wpdb ) {
					return '%' . $wpdb->esc_like( $one ) . '%';
				},
				$needles
			)
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every interpolated fragment is a placeholder list whose arity is derived from fixed constants and the validated post-type list; all values are bound through prepare() below.
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ($type_placeholders)
					AND post_status IN ($status_placeholders)
					AND ($like_placeholders)
				ORDER BY ID ASC
				LIMIT " . ( (int) $limit + 1 ),
			$values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; post_content is not indexable, so there is no WP_Query equivalent.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

		$truncated = count( $ids ) > $limit;
		if ( $truncated ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		return [
			'ids'       => $ids,
			'truncated' => $truncated,
		];
	}

	/**
	 * Locate and describe every match inside one post's raw content.
	 *
	 * Matches are found over the raw bytes in both needle forms and reported
	 * in a single list ordered by byte offset, so a caller never has to
	 * reconcile two overlapping counts. Each record says which form matched
	 * and whether the offset falls inside a block opener's comment
	 * (`block_attrs`) or in the document body between openers (`body`) —
	 * the distinction phase 3 branches on, because the two are different
	 * operations with different guards.
	 *
	 * @param string      $content       Raw post_content.
	 * @param string      $needle        Literal search string.
	 * @param string|null $stored_form   Escaped form, or null.
	 * @param int         $max_matches   Ceiling on returned match records.
	 * @param int         $context_chars Characters of context each side.
	 * @return array{match_count:int,matches:array,capped:bool}
	 */
	private static function bulk_search_post_matches(
		string $content,
		string $needle,
		?string $stored_form,
		int $max_matches,
		int $context_chars
	): array {
		$forms = [ 'literal' => $needle ];
		if ( null !== $stored_form && $stored_form !== $needle ) {
			$forms['escaped'] = $stored_form;
		}

		$openers = self::bulk_opener_spans( $content );

		$found = [];
		foreach ( $forms as $form => $text ) {
			if ( '' === $text ) {
				continue;
			}
			$offset = 0;
			while ( true ) {
				$at = strpos( $content, $text, $offset );
				if ( false === $at ) {
					break;
				}
				$found[] = [
					'offset' => $at,
					'form'   => $form,
					'length' => strlen( $text ),
				];
				$offset = $at + 1;
			}
		}

		usort(
			$found,
			static function ( $left, $right ) {
				return $left['offset'] <=> $right['offset'];
			}
		);

		$count   = count( $found );
		$capped  = $count > $max_matches;
		$visible = $capped ? array_slice( $found, 0, $max_matches ) : $found;

		$matches = [];
		foreach ( $visible as $hit ) {
			$span       = self::bulk_opener_span_at( $openers, $hit['offset'] );
			$is_attrs   = null !== $span;
			$matches[]  = [
				'offset'         => $hit['offset'],
				'form'           => $hit['form'],
				'location'       => $is_attrs ? 'block_attrs' : 'body',
				'block_name'     => $is_attrs ? $span['name'] : null,
				'decoded_value'  => $is_attrs
					? self::bulk_decoded_value_for_match( $content, $span, $needle )
					: null,
				'context_before' => self::bulk_context( $content, max( 0, $hit['offset'] - $context_chars ), $hit['offset'] ),
				'matched'        => substr( $content, $hit['offset'], $hit['length'] ),
				'context_after'  => self::bulk_context( $content, $hit['offset'] + $hit['length'], $hit['offset'] + $hit['length'] + $context_chars ),
			];
		}

		return [
			'match_count' => $count,
			'matches'     => $matches,
			'capped'      => $capped,
		];
	}

	/**
	 * Byte spans of every block opening comment in a document.
	 *
	 * Uses the JSON-string-aware scanners (#5/#6) rather than a `strpos` for
	 * `-->`, which a `-->` inside an attribute string value fools into
	 * reporting a comment end in the middle of the block's own JSON.
	 *
	 * @param string $content Raw block markup.
	 * @return array<int, array{start:int,end:int,name:string}>
	 */
	private static function bulk_opener_spans( string $content ): array {
		$spans  = [];
		$offset = 0;

		while ( true ) {
			$opener = self::next_block_opener( $content, $offset );
			if ( ! is_array( $opener ) ) {
				break;
			}
			$bounds = self::block_opening_comment_end( $content, $opener['pos'] );
			if ( ! is_array( $bounds ) || ! isset( $bounds['comment_end'] ) ) {
				// Malformed markup: no terminator. Stop rather than guessing —
				// every later offset would be classified against a span this
				// scan cannot establish.
				break;
			}
			$spans[] = [
				'start' => (int) $opener['pos'],
				'end'   => (int) $bounds['comment_end'],
				'name'  => (string) $opener['name'],
			];
			$offset = (int) $bounds['comment_end'];
		}

		return $spans;
	}

	/**
	 * The opener span containing a byte offset, or null when it is body text.
	 *
	 * @param array $spans  Spans from bulk_opener_spans().
	 * @param int   $offset Byte offset.
	 * @return array|null
	 */
	private static function bulk_opener_span_at( array $spans, int $offset ) {
		foreach ( $spans as $span ) {
			if ( $offset >= $span['start'] && $offset < $span['end'] ) {
				return $span;
			}
			if ( $span['start'] > $offset ) {
				break;
			}
		}
		return null;
	}

	/**
	 * The decoded attribute string value a match sits inside, if one can be
	 * identified.
	 *
	 * This is the field that lets a human judge a match: the stored bytes of
	 * an attribute value are escaped, so the raw context window shows
	 * `Acme & Co` where the page shows `Acme & Co`. Returns null when the
	 * opener's attrs do not decode, which is information rather than a
	 * failure — an undecodable opener is exactly the target phase 3 refuses.
	 *
	 * @param string $content Raw post content.
	 * @param array  $span    Opener span.
	 * @param string $needle  Literal search string.
	 * @return string|null
	 */
	private static function bulk_decoded_value_for_match( string $content, array $span, string $needle ): ?string {
		$markup = substr( $content, $span['start'], $span['end'] - $span['start'] );
		$attrs  = self::extract_attrs_from_block_markup( $markup );
		if ( ! is_array( $attrs ) ) {
			return null;
		}
		return self::bulk_first_string_leaf_containing( $attrs, $needle );
	}

	/**
	 * First string leaf of a decoded attrs tree containing the needle.
	 *
	 * @param mixed  $value  Decoded value.
	 * @param string $needle Literal search string.
	 * @return string|null
	 */
	private static function bulk_first_string_leaf_containing( $value, string $needle ): ?string {
		if ( '' === $needle ) {
			return null;
		}
		if ( is_string( $value ) ) {
			return false !== strpos( $value, $needle ) ? $value : null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $child ) {
				$found = self::bulk_first_string_leaf_containing( $child, $needle );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * A context window that never splits a UTF-8 sequence.
	 *
	 * The offsets this receives are byte offsets into block markup, which can
	 * land mid-sequence on any multibyte character. `substr` would then emit a
	 * lone continuation byte, which is invalid UTF-8 and makes the whole JSON
	 * response unencodable — one multibyte page would take down a response
	 * describing fifty others.
	 *
	 * @param string $content Raw content.
	 * @param int    $start   Start byte offset.
	 * @param int    $end     End byte offset.
	 * @return string
	 */
	private static function bulk_context( string $content, int $start, int $end ): string {
		$length = strlen( $content );
		$start  = max( 0, min( $start, $length ) );
		$end    = max( $start, min( $end, $length ) );

		// Walk the start forward off any UTF-8 continuation byte (10xxxxxx),
		// which is what a window opening mid-character lands on.
		while ( $start < $end && ( ord( $content[ $start ] ) & 0xC0 ) === 0x80 ) {
			$start++;
		}

		// At the end, drop the last sequence only when the window actually cut
		// it short. Walking back off every trailing continuation byte and then
		// dropping the lead would also eat a COMPLETE character that happened to
		// end exactly on the boundary — correct output, but a character the
		// caller asked for and did not get. So find the last sequence's lead
		// byte, read its declared length, and trim only if that length runs past
		// the window.
		$scan = $end;
		while ( $scan > $start && ( ord( $content[ $scan - 1 ] ) & 0xC0 ) === 0x80 ) {
			$scan--;
		}
		if ( $scan > $start ) {
			$lead = ord( $content[ $scan - 1 ] );
			if ( 0 !== ( $lead & 0x80 ) ) {
				if ( 0xC0 === ( $lead & 0xE0 ) ) {
					$sequence = 2;
				} elseif ( 0xE0 === ( $lead & 0xF0 ) ) {
					$sequence = 3;
				} elseif ( 0xF0 === ( $lead & 0xF8 ) ) {
					$sequence = 4;
				} else {
					// A stray continuation byte with no lead: not a sequence this
					// can complete, so drop it rather than emit it.
					$sequence = PHP_INT_MAX;
				}
				if ( ( $scan - 1 ) + $sequence > $end ) {
					$end = $scan - 1;
				}
			}
		}

		return substr( $content, $start, $end - $start );
	}
}
