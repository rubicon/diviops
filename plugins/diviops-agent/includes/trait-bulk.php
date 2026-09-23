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

	// The bulk constants live on DiviOps_Agent itself, beside SCANNABLE_POST_TYPES
	// and REASSIGN_MAX_PAGES. Not a style choice: PHP only allows constants in a
	// trait from 8.2, and this plugin supports 7.4 (the CI matrix lints it), where
	// `const` inside a trait is a fatal parse error rather than a warning.

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
			return self::bulk_envelope_from_error( $post_types, 'Pass a subset of the supported post types, or omit post_types for all of them.' );
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
			return self::bulk_envelope_from_error( $candidates, 'This search needs direct access to the posts table.' );
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

	/* ====================================================================
	 * The write harness (#495, phase 2 of #38)
	 *
	 * Everything genuinely novel in #38 lives here rather than in the payload.
	 * `bulk_status_change` is the payload precisely because it never reads or
	 * writes `post_content` -- no parse, no serialize, no round trip, no marker
	 * census, no global-layout exposure -- so it proves the harness without
	 * carrying any content risk.
	 *
	 * Read the honest limits before trusting any of it:
	 *
	 *  - `update_post_content_with_integrity_guard()` is NOT used here, and that
	 *    is correct rather than an omission. It writes and verifies
	 *    `post_content`; this operation does not touch `post_content`, so the
	 *    guard has nothing to guard. Phase 3 is where it applies.
	 *  - A content snapshot does NOT undo a status change. See
	 *    `bulk_run_manifest_entry()`.
	 *  - The per-target lock is advisory. The Visual Builder takes no such lock,
	 *    so a VB save can still land inside one target's verify-then-write
	 *    window. That residual is real and is not papered over.
	 * ================================================================= */

	/**
	 * Canonical string a plan token is a MAC over.
	 *
	 * Covers the operation, when it was issued, who issued it, the normalized
	 * parameters, and -- per target, in order -- the id, the `post_content`
	 * checksum, the `post_status` and `post_modified_gmt`.
	 *
	 * The last three are what make the token useful rather than decorative. A
	 * content-only binding is INERT for `bulk_status_change`, whose entire
	 * mutation is the status: a target whose status drifted between plan and
	 * apply would still produce a matching token, and that token would stay
	 * replayable for its whole lifetime. Phase 2 exists to prove the harness, and
	 * a harness whose drift detector cannot fire on phase 2's own payload proves
	 * nothing.
	 *
	 * @param string $operation Operation name.
	 * @param int    $issued_at Unix timestamp.
	 * @param int    $user_id   Acting user.
	 * @param array  $params    Normalized operation parameters.
	 * @param array  $state     Ordered per-target state from bulk_target_state().
	 * @return string
	 */
	private static function bulk_plan_canonical( string $operation, int $issued_at, int $user_id, array $params, array $state ): string {
		$rows = [];
		foreach ( $state as $row ) {
			$rows[] = implode(
				':',
				[
					(int) $row['id'],
					(string) $row['content_checksum'],
					(string) $row['post_status'],
					(string) $row['post_modified_gmt'],
				]
			);
		}

		return implode(
			"\n",
			[
				'diviops-bulk-plan/v1',
				$operation,
				(string) $issued_at,
				(string) $user_id,
				(string) wp_json_encode( $params ),
				implode( ',', $rows ),
			]
		);
	}

	/**
	 * Mint a plan token: `<issued_at>.<mac>`.
	 *
	 * Keyed, not a bare hash. A plain SHA-256 over inputs the caller already
	 * holds is computable by anyone who can read this repository -- which
	 * includes the LLM callers the gate exists for -- so an unkeyed token would
	 * reduce "you cannot apply without a plan" to "you must have read each
	 * target's content". `wp_salt()` is site-local and never leaves the server.
	 *
	 * `issued_at` sits in the CLEAR and is covered by the MAC. An earlier design
	 * put it inside the hash and then asked the server to enforce a staleness
	 * window, which is impossible: the server cannot know or invert a timestamp
	 * it never sees. Staleness is checked against the plaintext half; forgery is
	 * prevented by the MAC over both.
	 *
	 * `wp_salt( 'diviops_bulk' )` was verified on the reference install
	 * (`wp-includes/pluggable.php:2585`) to be non-empty, identical across
	 * separate PHP processes -- so a token minted in one request verifies in the
	 * next -- and distinct from both `auth` and other custom schemes.
	 *
	 * @param string $operation Operation name.
	 * @param int    $issued_at Unix timestamp.
	 * @param int    $user_id   Acting user.
	 * @param array  $params    Normalized operation parameters.
	 * @param array  $state     Ordered per-target state.
	 * @return string
	 */
	private static function bulk_plan_token_mint( string $operation, int $issued_at, int $user_id, array $params, array $state ): string {
		$mac = hash_hmac(
			'sha256',
			self::bulk_plan_canonical( $operation, $issued_at, $user_id, $params, $state ),
			wp_salt( self::BULK_PLAN_SALT_SCHEME )
		);

		return $issued_at . '.' . $mac;
	}

	/**
	 * Verify a plan token against LIVE state.
	 *
	 * Returns null when it verifies, or a WP_Error naming why it did not.
	 *
	 * A target that no longer exists surfaces through this same mechanism -- no
	 * checksum is obtainable, so the token cannot match -- and is reported as
	 * `bulk.plan_stale` rather than as a second refusal path. One condition, one
	 * code.
	 *
	 * @param string $token     Caller-supplied token.
	 * @param string $operation Operation name.
	 * @param int    $user_id   Acting user.
	 * @param array  $params    Normalized operation parameters.
	 * @param array  $state     Ordered per-target state, read live.
	 * @param int    $now       Current unix time.
	 * @return WP_Error|null
	 */
	private static function bulk_plan_token_verify( string $token, string $operation, int $user_id, array $params, array $state, int $now ) {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] || ! ctype_digit( $parts[0] ) ) {
			return new WP_Error(
				'bulk.plan_invalid',
				'plan_token is not in the expected <issued_at>.<mac> form.',
				[ 'status' => 400 ]
			);
		}

		$issued_at = (int) $parts[0];
		$age       = $now - $issued_at;
		if ( $age > self::BULK_PLAN_TTL_SECONDS || $age < -60 ) {
			return new WP_Error(
				'bulk.plan_stale',
				sprintf(
					'plan_token was issued %d second(s) ago and plans expire after %d.',
					$age,
					self::BULK_PLAN_TTL_SECONDS
				),
				[
					'status'    => 409,
					'issued_at' => $issued_at,
					'age'       => $age,
					'ttl'       => self::BULK_PLAN_TTL_SECONDS,
				]
			);
		}

		$expected = self::bulk_plan_token_mint( $operation, $issued_at, $user_id, $params, $state );
		if ( ! hash_equals( $expected, $token ) ) {
			return new WP_Error(
				'bulk.plan_stale',
				'plan_token does not match the current state of the targets. Re-run with dry_run to get a fresh plan.',
				[
					'status'  => 409,
					'targets' => array_map(
						static function ( $row ) {
							return [
								'id'                => (int) $row['id'],
								'post_status'       => $row['post_status'],
								'post_modified_gmt' => $row['post_modified_gmt'],
								'content_checksum'  => $row['content_checksum'],
								'exists'            => (bool) $row['exists'],
							];
						},
						$state
					),
				]
			);
		}

		return null;
	}

	/**
	 * The three fields a plan token binds, for one target, read live.
	 *
	 * A target that does not exist still produces a row -- with `exists` false
	 * and empty fields -- so a vanished target changes the canonical string and
	 * fails the MAC, rather than shortening the list and being silently dropped.
	 *
	 * @param int $post_id Target id.
	 * @return array
	 */
	private static function bulk_target_state( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [
				'id'                => $post_id,
				'exists'            => false,
				'content_checksum'  => '',
				'post_status'       => '',
				'post_modified_gmt' => '',
				'post_type'         => '',
			];
		}

		return [
			'id'                => $post_id,
			'exists'            => true,
			'content_checksum'  => 'sha256:' . hash( 'sha256', (string) $post->post_content ),
			'post_status'       => (string) $post->post_status,
			'post_modified_gmt' => (string) $post->post_modified_gmt,
			'post_type'         => (string) $post->post_type,
		];
	}

	/**
	 * Validate and normalize a caller-supplied target id list.
	 *
	 * Explicit ids and only explicit ids, never a query. A query is re-evaluated
	 * at apply time, so the set that applies is not the set that was reviewed --
	 * `preset_reassign` has exactly that shape today and is the least safe thing
	 * in this repository.
	 *
	 * @param mixed $raw Caller value.
	 * @return array|WP_Error Ordered, de-duplicated positive ints.
	 */
	private static function bulk_normalize_targets( $raw ) {
		if ( ! is_array( $raw ) || [] === $raw ) {
			return new WP_Error(
				'invalid_input',
				'targets must be a non-empty array of post ids.',
				[ 'status' => 400 ]
			);
		}

		$ids = [];
		foreach ( $raw as $candidate ) {
			if ( ! is_int( $candidate ) && ! ( is_string( $candidate ) && ctype_digit( $candidate ) ) ) {
				return new WP_Error(
					'invalid_input',
					'targets must contain positive integer post ids.',
					[ 'status' => 400, 'received' => $candidate ]
				);
			}
			$id = (int) $candidate;
			if ( $id <= 0 ) {
				return new WP_Error(
					'invalid_input',
					'targets must contain positive integer post ids.',
					[ 'status' => 400, 'received' => $candidate ]
				);
			}
			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( count( $ids ) > self::BULK_MAX_TARGETS ) {
			return new WP_Error(
				'bulk.too_many_targets',
				sprintf(
					'A bulk operation accepts at most %d targets; %d were supplied.',
					self::BULK_MAX_TARGETS,
					count( $ids )
				),
				[
					'status'      => 400,
					'received'    => count( $ids ),
					'max_targets' => self::BULK_MAX_TARGETS,
				]
			);
		}

		return $ids;
	}

	/**
	 * The upfront whole-set preflight gate.
	 *
	 * Runs across EVERY target before anything is written, and refuses the run
	 * outright if any one of them fails. This is the owner's approved default 4:
	 * a partial application that skipped the bad ones is worse than a refusal
	 * naming them, because nobody reads a results table looking for absences.
	 *
	 * It governs only what is knowable before the first write. Failures
	 * DISCOVERED mid-run -- a target that drifted inside its own verify-then-
	 * write window -- are governed by `on_error`, which defaults to `continue`.
	 * The two are different moments and do not contradict each other.
	 *
	 * @param array  $state  Ordered per-target state.
	 * @param string $status Requested post status.
	 * @return array List of refusals; empty when the whole set may proceed.
	 */
	private static function bulk_status_preflight( array $state, string $status ): array {
		$refusals   = [];
		$needs_pub  = self::page_status_requires_publish_capability( $status );

		foreach ( $state as $row ) {
			$id = (int) $row['id'];

			if ( ! $row['exists'] ) {
				$refusals[] = [ 'id' => $id, 'code' => 'not_found', 'detail' => 'No post with this id.' ];
				continue;
			}

			if ( ! in_array( $row['post_type'], self::BULK_WRITE_POST_TYPES, true ) ) {
				$refusals[] = [
					'id'     => $id,
					'code'   => 'bulk.post_type_not_writable',
					'detail' => sprintf(
						'post_type %s is outside the bulk write scope (%s).',
						$row['post_type'],
						implode( ', ', self::BULK_WRITE_POST_TYPES )
					),
				];
				continue;
			}

			if ( ! current_user_can( 'edit_post', $id ) ) {
				$refusals[] = [ 'id' => $id, 'code' => 'forbidden', 'detail' => 'No edit_post capability for this target.' ];
				continue;
			}

			if ( $needs_pub ) {
				$cap = self::bulk_publish_capability_for( $row['post_type'] );
				if ( null === $cap || ! current_user_can( $cap ) ) {
					$refusals[] = [
						'id'     => $id,
						'code'   => 'rest_cannot_publish',
						'detail' => sprintf( 'Publishing a %s requires the %s capability.', $row['post_type'], (string) $cap ),
					];
				}
			}
		}

		return $refusals;
	}

	/**
	 * The `publish_posts` capability for a post type, or null when unresolvable.
	 *
	 * This exists because `page_update_status`'s own publish gate lives in a
	 * ROUTE permission callback (`page_update_status_permission_result()`), which
	 * resolves the capability for a single `$request['id']`. A single route-level
	 * callback structurally cannot express this for a batch spanning mixed post
	 * types, so omitting a per-target re-check is a privilege-escalation path,
	 * not a nicety.
	 *
	 * @param string $post_type Post type name.
	 * @return string|null
	 */
	private static function bulk_publish_capability_for( string $post_type ): ?string {
		$object = get_post_type_object( $post_type );
		if ( ! $object || ! isset( $object->cap->publish_posts ) ) {
			return null;
		}
		return (string) $object->cap->publish_posts;
	}

	/**
	 * Take a short advisory write lock on one target.
	 *
	 * Narrows the window between a target's pre-write re-verification and its
	 * write to the smallest it can be made without a real lock. It does NOT
	 * serialize against the Visual Builder, which takes no such lock.
	 *
	 * @param int $post_id Target id.
	 * @return bool True when the lock was taken.
	 */
	private static function bulk_target_lock_acquire( int $post_id ): bool {
		$key = 'diviops_bulk_lock_' . $post_id;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::BULK_TARGET_LOCK_SECONDS );
		return true;
	}

	/**
	 * Release a target's write lock.
	 *
	 * @param int $post_id Target id.
	 */
	private static function bulk_target_lock_release( int $post_id ): void {
		delete_transient( 'diviops_bulk_lock_' . $post_id );
	}

	/**
	 * One manifest entry -- and, for a status change, the actual recovery record.
	 *
	 * An adversarial review found the hole this closes. The harness forces a
	 * rollback snapshot on for every bulk write, and that snapshot captures
	 * `post_content` (`rollback_snapshot_before_from_post()`, trait-rollback.php).
	 * `bulk_status_change` never touches `post_content`. So restoring the
	 * snapshot restores bytes that never changed and **does not put the status
	 * back**, nor `post_date` / `post_date_gmt`, which `page_update_status`
	 * mutates on some transitions (trait-page.php:5094-5109).
	 *
	 * A test asserting "a snapshot exists and was marked" would pass straight
	 * over that. So the snapshot stays -- exercising it is the point of the
	 * phase, and an unmarked snapshot is permanently unrestorable -- but the
	 * MANIFEST is what recovery actually reads: prior status and both prior date
	 * fields per target. Reverting is a fresh, planned `bulk_status_change` back
	 * to the recorded statuses, through the same guarded tool with its own
	 * dry-run and its own token. No new tool, and no bulk restore.
	 *
	 * @param array       $row         Live target state before the write.
	 * @param object|null $post        Loaded post.
	 * @param string      $status      Outcome status for this target.
	 * @param string|null $snapshot_id Snapshot id, when one was created.
	 * @param string|null $code        Failure code, when the target failed.
	 * @return array
	 */
	private static function bulk_run_manifest_entry( array $row, $post, string $status, ?string $snapshot_id, ?string $code = null ): array {
		return [
			'id'                => (int) $row['id'],
			'post_type'         => (string) $row['post_type'],
			'status'            => $status,
			'code'              => $code,
			'snapshot_id'       => $snapshot_id,
			// The recovery record. Not decoration: nothing else stored by this
			// run can put a status or a date back.
			'before'            => [
				'post_status'       => (string) $row['post_status'],
				'post_date'         => $post ? (string) ( $post->post_date ?? '' ) : '',
				'post_date_gmt'     => $post ? (string) ( $post->post_date_gmt ?? '' ) : '',
				'post_modified_gmt' => (string) $row['post_modified_gmt'],
				'content_checksum'  => (string) $row['content_checksum'],
			],
		];
	}

	/**
	 * Persist a run manifest.
	 *
	 * Written BEFORE the first write and updated after each target, so a request
	 * that dies mid-run leaves a legible record of how far it got. Stores no
	 * content bytes, and only a truncated token fingerprint for correlation --
	 * never the token, which is a MAC and does not belong in a readable option.
	 *
	 * Non-autoloaded, matching the snapshot store.
	 *
	 * @param array $manifest Manifest to persist.
	 */
	private static function bulk_run_manifest_save( array $manifest ): void {
		$name = self::BULK_RUN_OPTION_PREFIX . $manifest['run_id'];
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $manifest, '', 'no' );
			return;
		}
		update_option( $name, $manifest, false );
	}

	/**
	 * The rate-limit cost of a request, in targets rather than requests.
	 *
	 * Today's 30-writes-per-minute default is a de-facto blast-radius ceiling: an
	 * agent that goes wrong damages at most 30 pages a minute. One batch request
	 * covering 11 posts would silently raise that ceiling 11x, so a bulk APPLY
	 * consumes `count( targets )` from the write bucket instead of 1.
	 *
	 * A dry run costs 1: it writes nothing. An apply is distinguished by
	 * carrying a `plan_token`, which is the only way to apply.
	 *
	 * Every non-bulk route keeps cost 1, so nothing else changes.
	 *
	 * @param string $route   REST route.
	 * @param object $request Request.
	 * @return int
	 */
	public static function bulk_rate_limit_cost( string $route, $request ): int {
		if ( false === strpos( $route, '/bulk/' ) ) {
			return 1;
		}
		$token = $request->get_param( 'plan_token' );
		if ( ! is_string( $token ) || '' === $token ) {
			return 1;
		}
		$targets = $request->get_param( 'targets' );
		if ( ! is_array( $targets ) || [] === $targets ) {
			return 1;
		}
		return min( count( $targets ), self::BULK_MAX_TARGETS );
	}


	/**
	 * Change post status across an explicit list of targets.
	 *
	 * The payload is deliberately the safest one for CONTENT risk: it never
	 * reads or writes `post_content`. That is what makes it the honest way to
	 * prove the harness before anything dangerous rides on it.
	 *
	 * It is NOT, however, trivially reversible, and saying so would be the one
	 * overstatement worth avoiding here. `publish` fires
	 * `transition_post_status` / `publish_post`: pingbacks, feeds, and whatever
	 * notification, newsletter or social-publishing plugin the site runs.
	 * Publishing 11 drafts sends 11 outbound events that setting the status back
	 * does not recall. So the plan warns explicitly on any transition INTO
	 * publish, and the manifest records prior `post_date` / `post_date_gmt`
	 * alongside prior status so a manual repair is at least possible.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function bulk_status_change( $request ) {
		$operation = 'bulk_status_change';
		$user_id   = get_current_user_id();
		$now       = time();

		$targets = self::bulk_normalize_targets( $request->get_param( 'targets' ) );
		if ( is_wp_error( $targets ) ) {
			return self::bulk_envelope_from_error( $targets, 'Split the work into runs of at most ' . self::BULK_MAX_TARGETS . ' ids, found with diviops_content_search.' );
		}

		$status  = sanitize_key( (string) $request->get_param( 'status' ) );
		$allowed = self::supported_page_statuses();
		if ( ! in_array( $status, $allowed, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'status must be one of: ' . implode( ', ', $allowed ) . '.',
				'Pass status as one of the allowed values.',
				400,
				[ 'field' => 'status', 'allowed' => $allowed, 'received' => $status ]
			);
		}
		if ( 'future' === $status ) {
			// Scheduling needs a per-target date, and one date across a batch is
			// a different operation with a different plan shape. Refuse rather
			// than pick a meaning.
			return self::envelope_error(
				'invalid_input',
				"status='future' is not supported by bulk_status_change.",
				'Schedule posts individually with diviops_page_update_status, which takes the required date_gmt.',
				400,
				[ 'field' => 'status', 'received' => $status ]
			);
		}

		$on_error = (string) ( $request->get_param( 'on_error' ) ?: 'continue' );
		if ( ! in_array( $on_error, [ 'continue', 'stop' ], true ) ) {
			return self::envelope_error(
				'invalid_input',
				"on_error must be 'continue' or 'stop'.",
				'Omit it for the default, which is continue.',
				400,
				[ 'field' => 'on_error', 'received' => $on_error ]
			);
		}

		$params = [ 'status' => $status ];
		$state  = [];
		foreach ( $targets as $id ) {
			$state[] = self::bulk_target_state( $id );
		}

		$refusals = self::bulk_status_preflight( $state, $status );
		$token    = $request->get_param( 'plan_token' );
		$token    = is_string( $token ) ? trim( $token ) : '';
		$dry_run  = null === $request->get_param( 'dry_run' ) ? true : (bool) $request->get_param( 'dry_run' );

		// ---- Plan ------------------------------------------------------
		if ( $dry_run ) {
			return self::envelope_success(
				self::bulk_status_plan( $operation, $user_id, $now, $params, $state, $refusals, $status, $on_error )
			);
		}

		// ---- Apply -----------------------------------------------------
		//
		// There is exactly one way to preview and exactly one way to apply.
		// `dry_run: false` with no token is invalid_input, not an implicit plan:
		// "dry_run defaults to true" is not mandatory dry-run, because a caller
		// can still pass false on the very first call having seen nothing.
		if ( '' === $token ) {
			return self::envelope_error(
				'invalid_input',
				'plan_token is required to apply. Run with dry_run to get one.',
				'Call this tool with dry_run first, read the plan, then pass its plan_token back.',
				400,
				[ 'field' => 'plan_token' ]
			);
		}

		$verify = self::bulk_plan_token_verify( $token, $operation, $user_id, $params, $state, $now );
		if ( is_wp_error( $verify ) ) {
			return self::bulk_envelope_from_error( $verify, 'Re-run with dry_run to get a fresh plan and token, then apply that one.' );
		}

		// The upfront whole-set gate. Nothing has been written yet, and nothing
		// will be if any target fails it.
		if ( ! empty( $refusals ) ) {
			return self::envelope_error(
				'bulk.preflight_refused',
				sprintf(
					'%d of %d target(s) cannot be written; the whole run is refused.',
					count( $refusals ),
					count( $state )
				),
				'Fix or remove the named targets, then re-plan.',
				409,
				[ 'refused' => $refusals, 'targets' => count( $state ) ]
			);
		}

		return self::bulk_status_apply( $operation, $params, $state, $status, $on_error, $token );
	}

	/**
	 * Build the plan a caller has to be able to judge.
	 *
	 * Anything the operation will decline to do appears as an explicit entry
	 * with a reason. A target that is silently absent from the plan is the exact
	 * failure this shape exists to prevent.
	 *
	 * `warnings` is always present, even empty. `dry_run_response()` omits the
	 * key when it is empty, which leaves callers branching on its absence.
	 *
	 * @param string $operation Operation name.
	 * @param int    $user_id   Acting user.
	 * @param int    $now       Current unix time.
	 * @param array  $params    Normalized parameters.
	 * @param array  $state     Ordered per-target state.
	 * @param array  $refusals  Preflight refusals.
	 * @param string $status    Requested status.
	 * @param string $on_error  Error policy.
	 * @return array
	 */
	private static function bulk_status_plan( string $operation, int $user_id, int $now, array $params, array $state, array $refusals, string $status, string $on_error ): array {
		$refused_by_id = [];
		foreach ( $refusals as $refusal ) {
			$refused_by_id[ (int) $refusal['id'] ] = $refusal;
		}

		$changes  = [];
		$warnings = [];
		$counts   = [ 'will_apply' => 0, 'will_noop' => 0, 'will_refuse' => 0 ];

		foreach ( $state as $row ) {
			$id   = (int) $row['id'];
			$post = $row['exists'] ? get_post( $id ) : null;

			if ( isset( $refused_by_id[ $id ] ) ) {
				$counts['will_refuse']++;
				$changes[] = [
					'id'      => $id,
					'verdict' => 'will_refuse:' . $refused_by_id[ $id ]['code'],
					'reason'  => $refused_by_id[ $id ]['detail'],
				];
				continue;
			}

			$noop = ( $row['post_status'] === $status );
			$counts[ $noop ? 'will_noop' : 'will_apply' ]++;

			$changes[] = [
				'id'                => $id,
				'post_type'         => $row['post_type'],
				'title'             => $post ? (string) $post->post_title : '',
				'permalink'         => $post ? (string) get_permalink( $id ) : '',
				'uses_divi'         => $post ? ( false !== strpos( (string) $post->post_content, self::BLOCK_PREFIX ) ) : false,
				'content_checksum'  => $row['content_checksum'],
				'post_modified_gmt' => $row['post_modified_gmt'],
				'verdict'           => $noop ? 'will_skip:already_' . $status : 'will_apply',
				'before'            => [ 'post_status' => $row['post_status'] ],
				'after'             => [ 'post_status' => $status ],
			];

			if ( ! $noop && 'publish' === $status ) {
				$warnings[] = sprintf(
					'Publishing #%d fires transition_post_status and publish_post: pingbacks, feeds, and any notification or '
						. 'social-publishing plugin on this site. Setting the status back afterwards does not recall those events.',
					$id
				);
			}
		}

		return [
			'operation'   => $operation,
			'dry_run'     => true,
			'on_error'    => $on_error,
			'max_targets' => self::BULK_MAX_TARGETS,
			'plan'        => [
				'summary'  => sprintf(
					'Would set status=%s on %d target(s): %d to apply, %d already at that status, %d refused.',
					$status,
					count( $state ),
					$counts['will_apply'],
					$counts['will_noop'],
					$counts['will_refuse']
				),
				'changes'  => $changes,
				// Always present, even empty.
				'warnings' => $warnings,
				'counts'   => $counts,
			],
			// A plan carrying refusals still mints a token; the apply refuses the
			// whole run on the same refusals, so the token cannot be used to
			// sneak past them. Withholding it here would instead make a refusal
			// indistinguishable from a malformed request.
			'plan_token'  => self::bulk_plan_token_mint( $operation, $now, $user_id, $params, $state ),
			'recovery'    => self::BULK_RECOVERY_NOTE,
		];
	}

	/**
	 * Apply the status change, one guarded target at a time.
	 *
	 * The per-target loop contract is the most important thing in this file. For
	 * each target, in order: re-load it, re-verify its three bound fields,
	 * re-check permission, take the lock, snapshot, write, mark, invalidate,
	 * record. Steps 1-3 exist because no guard downstream can help -- a bulk
	 * write that overwrites a concurrent edit is stored exactly as requested and
	 * reverts nothing. The only defence against a concurrent edit is the
	 * pre-write re-verification.
	 *
	 * @param string $operation Operation name.
	 * @param array  $params    Normalized parameters.
	 * @param array  $state     Ordered per-target state, read at plan-verify time.
	 * @param string $status    Requested status.
	 * @param string $on_error  Error policy.
	 * @param string $token     Verified plan token, for a correlation fingerprint only.
	 * @return WP_REST_Response
	 */
	private static function bulk_status_apply( string $operation, array $params, array $state, string $status, string $on_error, string $token ) {
		$run = self::rollback_snapshot_run_begin(
			'diviops_' . $operation,
			[ 'tool_operation' => 'bulk.status_change', 'status' => $status ]
		);

		$manifest = [
			'run_id'            => $run['run_id'],
			'operation'         => $operation,
			'on_error'          => $on_error,
			'params'            => $params,
			// A fingerprint for correlation, never the token: it is a MAC, and a
			// readable option is not where one belongs.
			'token_fingerprint' => substr( hash( 'sha256', $token ), 0, 16 ),
			'created_at'        => gmdate( 'c' ),
			'targets'           => [],
			'recovery'          => self::BULK_RECOVERY_NOTE,
		];
		self::bulk_run_manifest_save( $manifest );

		$results = [];
		$counts  = [ 'applied' => 0, 'skipped' => 0, 'failed' => 0, 'not_attempted' => 0 ];
		$stopped = false;

		foreach ( $state as $planned ) {
			$id = (int) $planned['id'];

			if ( $stopped ) {
				$counts['not_attempted']++;
				$results[] = [ 'id' => $id, 'status' => 'not_attempted' ];
				continue;
			}

			$outcome = self::bulk_status_apply_one( $run, $planned, $status );
			$results[] = $outcome['result'];
			$counts[ $outcome['bucket'] ]++;
			$manifest['targets'][] = $outcome['manifest'];
			self::bulk_run_manifest_save( $manifest );

			if ( 'failed' === $outcome['bucket'] && 'stop' === $on_error ) {
				$stopped = true;
			}
		}

		// The flushed chunk ids are the ONLY handle on the snapshots this run
		// created: `rollback_snapshot_get` takes a chunk id, not a run_id. A
		// manifest that named no chunk would describe recovery a caller cannot
		// actually reach.
		$chunks = self::rollback_snapshot_run_flush( $run );

		// The flush hands back OPTION NAMES; `rollback_snapshot_get` takes a
		// snapshot id. Reporting the option name would give a caller a string
		// that looks like a handle and resolves to not_found.
		$chunk_ids = [];
		foreach ( (array) $chunks as $option_name ) {
			$chunk_id = self::rollback_snapshot_id_from_option_name( (string) $option_name );
			if ( is_string( $chunk_id ) && '' !== $chunk_id ) {
				$chunk_ids[] = $chunk_id;
			}
		}

		$manifest['snapshot_chunks'] = $chunk_ids;
		$manifest['counts']          = $counts;
		$manifest['finished_at'] = gmdate( 'c' );
		self::bulk_run_manifest_save( $manifest );

		$record = [
			'run_id'          => $run['run_id'],
			'operation'       => $operation,
			'on_error'        => $on_error,
			'status'          => $status,
			'targets'         => $results,
			'counts'          => $counts,
			'snapshot_chunks' => $chunk_ids,
			'recovery'        => self::BULK_RECOVERY_NOTE,
		];

		// The envelope is false whenever ANY target failed, with the complete run
		// record in error.data. A deliberate divergence from preset_reassign,
		// which returns ok:true carrying a nested success:false -- the harness
		// primer tells every caller to branch on the envelope, so a partial
		// application that satisfies that branch as a success is a defect, and
		// repeating it here would multiply it.
		if ( $counts['failed'] > 0 ) {
			return self::envelope_error(
				'bulk.partial_failure',
				sprintf(
					'%d of %d target(s) failed. %d applied, %d skipped, %d not attempted.',
					$counts['failed'],
					count( $state ),
					$counts['applied'],
					$counts['skipped'],
					$counts['not_attempted']
				),
				'Read data.targets for each failure, then re-plan the ids you still want.',
				409,
				$record
			);
		}

		return self::envelope_success( $record );
	}

	/**
	 * One target's guarded write.
	 *
	 * @param array  $run     Snapshot run, by reference through the caller.
	 * @param array  $planned Plan-time state for this target.
	 * @param string $status  Requested status.
	 * @return array{result:array,bucket:string,manifest:array}
	 */
	private static function bulk_status_apply_one( array &$run, array $planned, string $status ): array {
		$id = (int) $planned['id'];

		// 1. Re-load inside the loop. Never reuse the object loaded at plan time.
		$live = self::bulk_target_state( $id );
		$post = $live['exists'] ? get_post( $id ) : null;

		// 2. Re-verify the three bound fields for THIS target.
		if ( ! $live['exists']
			|| $live['content_checksum'] !== $planned['content_checksum']
			|| $live['post_status'] !== $planned['post_status']
			|| $live['post_modified_gmt'] !== $planned['post_modified_gmt'] ) {
			return [
				'bucket'   => 'failed',
				'result'   => [ 'id' => $id, 'status' => 'failed', 'code' => 'bulk.target_drifted' ],
				'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'failed', null, 'bulk.target_drifted' ),
			];
		}

		// 3. Re-check permission for THIS target, immediately before its write.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return [
				'bucket'   => 'failed',
				'result'   => [ 'id' => $id, 'status' => 'failed', 'code' => 'forbidden' ],
				'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'failed', null, 'forbidden' ),
			];
		}
		if ( self::page_status_requires_publish_capability( $status ) ) {
			$cap = self::bulk_publish_capability_for( (string) $live['post_type'] );
			if ( null === $cap || ! current_user_can( $cap ) ) {
				return [
					'bucket'   => 'failed',
					'result'   => [ 'id' => $id, 'status' => 'failed', 'code' => 'rest_cannot_publish' ],
					'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'failed', null, 'rest_cannot_publish' ),
				];
			}
		}

		// Idempotent on its own output, per the primer's already_<state> convention.
		if ( $live['post_status'] === $status ) {
			return [
				'bucket'   => 'skipped',
				'result'   => [ 'id' => $id, 'status' => 'skipped', 'reason' => 'already_' . $status ],
				'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'skipped', null ),
			];
		}

		if ( ! self::bulk_target_lock_acquire( $id ) ) {
			return [
				'bucket'   => 'failed',
				'result'   => [ 'id' => $id, 'status' => 'failed', 'code' => 'bulk.target_locked' ],
				'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'failed', null, 'bulk.target_locked' ),
			];
		}

		// 4. Snapshot, from the post loaded in step 1 -- never a stale one. A
		// snapshot built from plan-time content would record bytes the page never
		// had at write time, so restoring it would destroy a concurrent edit
		// rather than recover from this run.
		$captured = self::rollback_snapshot_run_capture( $run, $post );
		if ( false === $captured ) {
			self::bulk_target_lock_release( $id );
			return [
				'bucket'   => 'failed',
				'result'   => [ 'id' => $id, 'status' => 'failed', 'code' => 'rollback_snapshot.storage_failed' ],
				'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'failed', null, 'rollback_snapshot.storage_failed' ),
			];
		}

		// 5. Write. NOT through update_post_content_with_integrity_guard(): that
		// writes and verifies post_content, and this operation does not touch
		// post_content at all. Passing content through it here would make this
		// handler a content writer, which is the one thing it must not be.
		$written = wp_update_post( [ 'ID' => $id, 'post_status' => $status ], true );
		if ( is_wp_error( $written ) ) {
			self::rollback_snapshot_run_mark_from_write_error( $run, $id, $written );
			self::bulk_target_lock_release( $id );
			return [
				'bucket'   => 'failed',
				'result'   => [ 'id' => $id, 'status' => 'failed', 'code' => 'write_failed', 'detail' => $written->get_error_message() ],
				'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'failed', null, 'write_failed' ),
			];
		}

		// 6. Mark the snapshot. Not bookkeeping: rollback_snapshot_restore()
		// refuses outright when after.checksum is empty, so a snapshot that is
		// created and never marked is permanently unrestorable.
		$after = get_post( $id );
		self::rollback_snapshot_run_mark( $run, $id, 'write_applied', $after ? (string) $after->post_content : '' );

		// 7. Divi's compiled CSS is keyed per post and a status change moves the
		// post in and out of the front end, so the same invalidation every other
		// write path performs applies here.
		self::invalidate_divi_cache( $id );

		self::bulk_target_lock_release( $id );

		return [
			'bucket'   => 'applied',
			'result'   => [
				'id'     => $id,
				'status' => 'applied',
				'before' => [ 'post_status' => $planned['post_status'] ],
				'after'  => [ 'post_status' => $after ? (string) $after->post_status : $status ],
			],
			'manifest' => self::bulk_run_manifest_entry( $planned, $post, 'applied', $run['run_id'] ),
		];
	}

	/**
	 * Read a bulk run manifest.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function bulk_run_get( $request ) {
		$run_id = (string) $request->get_param( 'run_id' );
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,80}$/', $run_id ) ) {
			return self::envelope_error(
				'invalid_input',
				'run_id is not in the expected form.',
				'Pass the run_id returned by a bulk apply.',
				400,
				[ 'field' => 'run_id' ]
			);
		}

		$manifest = get_option( self::BULK_RUN_OPTION_PREFIX . $run_id, false );
		if ( ! is_array( $manifest ) ) {
			return self::envelope_error(
				'not_found',
				"No bulk run manifest for run_id {$run_id}.",
				'Manifests are per-site and are not created by a dry run.',
				404,
				[ 'run_id' => $run_id ]
			);
		}

		return self::envelope_success( $manifest );
	}


	/**
	 * Adapt a bulk WP_Error to the envelope, preserving its diagnostic data.
	 *
	 * Not `envelope_from_helper_error()`, which is a special-purpose mapper for
	 * the module/section search-miss family and drops any key it does not
	 * recognise; and not `envelope_from_wp_error()`, which keeps only `status`
	 * and `hint`. Every bulk refusal carries data the caller has to act on --
	 * the cap it exceeded, the targets that drifted, the TTL a token missed --
	 * so a mapper that discards it turns an actionable refusal into "no".
	 *
	 * @param WP_Error $error Error to adapt.
	 * @param string   $hint  Hint to attach.
	 * @return WP_REST_Response
	 */
	private static function bulk_envelope_from_error( $error, string $hint ) {
		$data        = $error->get_error_data();
		$http_status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		if ( is_array( $data ) ) {
			unset( $data['status'] );
			if ( [] === $data ) {
				$data = null;
			}
		} else {
			$data = null;
		}

		return self::envelope_error(
			(string) $error->get_error_code(),
			(string) $error->get_error_message(),
			$hint,
			$http_status,
			$data
		);
	}

}
