<?php
// SPDX-License-Identifier: MIT
/**
 * Every plugin-routed tool is documented in a skill — as a ratchet, not a bar (#506).
 *
 * Five tools shipped in one week and none of them appeared in any skill: the whole
 * #38 bulk family and `page_export`. The tool COUNT stayed correct the entire time,
 * because `tests/test-tool-count-sync.php` keeps it in sync — so nothing looked
 * stale while a third of the surface was undescribed. An agent cannot use a tool it
 * has never been told about, and for the bulk family it cannot use one safely
 * without the plan-token contract.
 *
 * Measuring the gap found 41 of 109 plugin-routed tools undocumented anywhere under
 * `skills/` — entire families (media, menus, revisions, SEO). A gate demanding full
 * coverage would fail on day one and be disabled by the end of the week, which is
 * worse than no gate. So this is a **ratchet**: the known-undocumented set is pinned
 * by NAME below, and the suite fails when a tool joins it.
 *
 * Pinned by name rather than by count on purpose. A count-only ratchet passes when
 * one tool gains documentation and another loses it, which is the exact trade this
 * file exists to catch.
 *
 * The list is a burn-down, not an allowlist: entries leave it as the skills grow,
 * and nothing should ever be added without a reason recorded in the pull request.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$sd_root  = dirname( __DIR__ );
$sd_index = (string) file_get_contents( $sd_root . '/diviops-server/src/index.ts' );

/**
 * Tools registered against a plugin route. `registerLocalTool()` is excluded: those
 * are server-local wrappers (wp-cli and friends) whose documentation lives with the
 * subsystem they wrap rather than in the tool reference.
 */
preg_match_all( '/registerPluginTool\(\s*"([a-z0-9_]+)"/', $sd_index, $sd_matches );
$sd_tools = array_values( array_unique( $sd_matches[1] ) );
sort( $sd_tools );

// The control. Without it, an extraction that silently matched nothing would report
// an empty undocumented set and pass while inspecting no tools at all -- the failure
// mode `tests/run.php` fails on empty discovery to prevent.
assert_true(
	count( $sd_tools ) >= 100,
	'the extraction found the plugin-routed tools in index.ts (' . count( $sd_tools ) . ' found)'
);

/*
 * A floor is not enough, and a pre-merge review proved it. The pattern above
 * requires the tool name to be the NEXT token after the paren, so a registration
 * that wraps, or carries a comment between the two, is invisible to it — and a
 * tool that is invisible here cannot be reported undocumented. The reviewer added
 * one such registration and the whole suite stayed green while the tool had no
 * documentation anywhere.
 *
 * A floor of 100 cannot catch that, because 109 found out of 110 registered is
 * still comfortably over 100. So count the CALL SITES independently — line-anchored,
 * the same shape `test-tool-count-sync.php` uses — and require the two to agree
 * exactly. A registration this file cannot parse now fails it instead of vanishing.
 */
$sd_call_sites = (int) preg_match_all( '/^[ \t]*registerPluginTool\(/m', $sd_index );
assert_true( $sd_call_sites >= 100, 'and found the registerPluginTool() call sites themselves (' . $sd_call_sites . ')' );
assert_same(
	$sd_call_sites,
	count( $sd_tools ),
	'every registerPluginTool() call site yielded a tool name, so none is invisible to this gate'
);

// Every Markdown file under skills/, concatenated. A tool counts as documented if it
// is named anywhere in that corpus -- deliberately generous, because the reference is
// a curated subset by design and some tools are documented in their own topic file.
$sd_blob  = '';
$sd_files = 0;
$sd_iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $sd_root . '/skills' ) );
foreach ( $sd_iter as $sd_entry ) {
	if ( $sd_entry->isFile() && 'md' === strtolower( $sd_entry->getExtension() ) ) {
		$sd_blob .= (string) file_get_contents( $sd_entry->getPathname() );
		$sd_files++;
	}
}

assert_true( $sd_files >= 10, 'and read the skill corpus it checks them against (' . $sd_files . ' file(s))' );

/*
 * A synthetic probe, appended before the matcher closes over the blob.
 *
 * The boundary below is the gate's headline correctness property, and it had no
 * test: a pre-merge review replaced `(?![a-z0-9_])` with a plain substring search
 * and the file still passed, because every nested pair that exists today
 * (`diviops_page_get` / `_get_layout`, `diviops_preset_audit` / `_audit_storage`,
 * `diviops_variable_create` / `_create_fluid_system`) happens to have BOTH halves
 * documented, so the two matchers agree by luck. They would disagree the moment a
 * nested pair splits — which is exactly the case the boundary exists for.
 *
 * The probe manufactures that split: a long name is present in the corpus, its
 * strict prefix is not, and neither is a real tool.
 */
$sd_blob .= "\n<!-- coverage-gate probe --> diviops_gate_probe_name_long\n";

/**
 * Matched on a trailing boundary, never with `strpos()`.
 *
 * Tool names nest: `diviops_page_get` is a prefix of `diviops_page_get_layout`, and
 * `diviops_preset_audit` of `diviops_preset_audit_storage`. A substring search counts
 * the shorter name as documented whenever the longer one is merely mentioned, so the
 * first version of this gate reported coverage it did not have. A mutation that
 * renamed a documented tool to `<name>_REMOVED` went undetected for exactly that
 * reason -- the old name was still a prefix of the new one.
 */
$sd_documented_in_skills = static function ( string $tool ) use ( $sd_blob ): bool {
	return 1 === preg_match( '/' . preg_quote( $tool, '/' ) . '(?![a-z0-9_])/', $sd_blob );
};

$sd_undocumented = array();
foreach ( $sd_tools as $sd_tool ) {
	if ( ! $sd_documented_in_skills( $sd_tool ) ) {
		$sd_undocumented[] = $sd_tool;
	}
}

// The matcher's own control: a name that is definitely present, and one that is
// definitely not. Without these, a regex that never matched would report every tool
// undocumented, and a regex that always matched would report none -- and the pinned
// list below would silently absorb either.
assert_true( $sd_documented_in_skills( 'diviops_page_update_content' ), 'the matcher finds a tool the skills definitely document' );
assert_true( ! $sd_documented_in_skills( 'diviops_not_a_real_tool_name' ), 'and does not find one that does not exist' );
assert_true( $sd_documented_in_skills( 'diviops_gate_probe_name_long' ), 'the matcher finds the synthetic probe that was appended to the corpus' );
assert_true(
	! $sd_documented_in_skills( 'diviops_gate_probe_name' ),
	'and a STRICT PREFIX of a documented name is not counted as documented, which is the boundary this matcher exists for'
);

/**
 * Known undocumented, pinned at #506. Burn this down; do not grow it.
 */
$sd_known = array(
	'diviops_canvas_orphan_audit',
	'diviops_cross_env_source_export_get',
	'diviops_cross_env_target_context_get',
	'diviops_global_color_audit_storage',
	'diviops_global_font_audit_storage',
	'diviops_library_delete',
	'diviops_media_get',
	'diviops_media_list',
	'diviops_media_set_featured_image',
	'diviops_media_update_meta',
	'diviops_media_upload',
	'diviops_menu_create',
	'diviops_menu_delete',
	'diviops_menu_get',
	'diviops_menu_item_add_custom',
	'diviops_menu_item_add_page',
	'diviops_menu_item_remove',
	'diviops_menu_item_reorder',
	'diviops_menu_list',
	'diviops_menu_location_assign',
	'diviops_menu_location_unassign',
	'diviops_module_clone',
	'diviops_module_lock',
	'diviops_module_unlock',
	'diviops_page_block_insert',
	'diviops_page_duplicate',
	'diviops_page_trash',
	'diviops_preset_audit_storage',
	'diviops_revision_diff',
	'diviops_revision_get',
	'diviops_revision_list',
	'diviops_revision_restore',
	'diviops_rollback_snapshot_delete',
	'diviops_rollback_snapshot_list',
	'diviops_seo_metadata_get',
	'diviops_seo_metadata_update',
	'diviops_seo_provider_list',
	'diviops_tb_layout_block_insert',
	'diviops_theme_options_update',
	'diviops_variable_update',
	'diviops_variable_used_on_page',
);

$sd_new = array_values( array_diff( $sd_undocumented, $sd_known ) );
assert_same(
	array(),
	$sd_new,
	'no plugin-routed tool is undocumented beyond the set pinned at #506 -- a tool an agent is never told about is a tool that does not exist to it, and the tool-count gate will not notice'
);

// The other direction: a name that left the undocumented set must leave this list
// too, so the baseline cannot rot into a list of tools that no longer exist or are
// now documented. This is what makes it a ratchet rather than a suppression file.
$sd_stale = array_values( array_diff( $sd_known, $sd_undocumented ) );
assert_same(
	array(),
	$sd_stale,
	'and every pinned name is still both registered and undocumented -- one that gained documentation must be removed from the list here, which is how the ratchet tightens'
);

// The five this issue was opened for, asserted by name. The ratchet above would pass
// if they were quietly returned to the pinned list; these will not.
foreach ( array(
	'diviops_content_search',
	'diviops_bulk_status_change',
	'diviops_bulk_run_get',
	'diviops_bulk_find_replace',
	'diviops_page_export',
) as $sd_required ) {
	assert_true(
		$sd_documented_in_skills( $sd_required ),
		$sd_required . ' is documented in a skill'
	);
}

/**
 * What this file cannot measure, asserted separately.
 *
 * Coverage above is NAME coverage: it proves an agent can discover a tool, not that
 * it was told how to use it safely. For most tools a one-line entry is enough. For
 * the bulk family it is not — the plan token, the 25 cap and the partial-run envelope
 * are the difference between a reviewed change and 25 pages rewritten from a plan
 * nobody read. That contract lives once in the primer, so its presence is pinned here
 * by the facts it must state rather than by its heading, which is renameable.
 */
$sd_primer = (string) file_get_contents( $sd_root . '/skills/diviops/SKILL.md' );
foreach ( array(
	'plan_token'              => 'the token that binds the plan to the apply',
	'bulk.target_drifted'     => 'the refusal a caller gets when a page changed under it',
	'bulk.partial_failure'    => 'the envelope a partially failed run returns',
	'bulk.too_many_targets'   => 'the refusal above the cap, which is not a truncation',
	// Not a bare '25': that matches any 25 anywhere in the file, and a mutation
	// that removed the cap sentence passed against it. The needle has to be the
	// claim, not a character that happens to appear inside it.
	'cap is 25 targets'       => 'the cap itself, as a claim rather than a loose digit',
) as $sd_fact => $sd_why ) {
	assert_true(
		false !== strpos( $sd_primer, $sd_fact ),
		'the primer states ' . $sd_why . ' (' . $sd_fact . ')'
	);
}

printf(
	"skill tool coverage: %d of %d plugin-routed tool(s) documented across %d skill file(s); %d pinned undocumented\n",
	count( $sd_tools ) - count( $sd_undocumented ),
	count( $sd_tools ),
	$sd_files,
	count( $sd_undocumented )
);
