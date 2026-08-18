<?php
// SPDX-License-Identifier: MIT
/**
 * dump_all docblock placement guard (#154).
 *
 * The docblock documenting `schema_get_module_dump_all()` had drifted away from the
 * function it documents. The native-module schema helpers (#57 / #61 / #66) were
 * inserted between the two, which left the docblock sitting immediately above
 * `native_module_schemas_all_from_dir()`'s own docblock, 42 lines from the declaration
 * it belongs to.
 *
 * Nothing about the plugin's behavior changed, which is exactly why it survived: every
 * consumer that binds a docblock to the declaration that follows it (Intelephense
 * hovers, doc extraction, a reader scrolling the file) silently attributed the dump-all
 * contract to a private helper that returns a plain keyed array, and reported
 * `schema_get_module_dump_all()` as undocumented.
 *
 * Adjacency is the whole property, so that is what this asserts: the docblock's
 * terminator is followed by the dump-all declaration and nothing else, and the helper
 * still carries its own separate block. Both halves matter — moving the docblock onto
 * the right function while clobbering the helper's would trade one defect for another.
 *
 * @package DiviOps
 */

$dumpall_doc_trait = dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-module-schema.php';

assert_true( is_file( $dumpall_doc_trait ), 'trait-module-schema.php exists where this test expects it' );

$dumpall_doc_src = (string) file_get_contents( $dumpall_doc_trait );

/*
 * Anchor on the docblock's own opening sentence rather than on a line number, so a
 * later insertion above it moves the anchor instead of silently invalidating the test.
 */
$dumpall_doc_summary = strpos( $dumpall_doc_src, "Dump every registered Divi module's schema in one call" );
assert_true( false !== $dumpall_doc_summary, 'the dump-all docblock was located by its opening sentence' );

$dumpall_doc_terminator = false !== $dumpall_doc_summary
	? strpos( $dumpall_doc_src, "\n\t */\n", $dumpall_doc_summary )
	: false;
assert_true( false !== $dumpall_doc_terminator, 'the dump-all docblock has a locatable terminator' );

// The property under test: the terminator is followed by the declaration itself, with
// no intervening second docblock and no intervening function.
$dumpall_doc_follows = false !== $dumpall_doc_terminator
	? substr( $dumpall_doc_src, $dumpall_doc_terminator + strlen( "\n\t */\n" ) )
	: '';
assert_same(
	0,
	strpos( $dumpall_doc_follows, "\tpublic static function schema_get_module_dump_all(" ),
	'the dump-all docblock sits immediately above schema_get_module_dump_all()'
);

/*
 * The helper the docblock used to be stranded above keeps its own. Find the last block
 * opener before its declaration and read what that block actually documents: the
 * helper's `@param`, not the dump-all summary.
 */
$helper_decl = strpos( $dumpall_doc_src, "\tprivate static function native_module_schemas_all_from_dir(" );
assert_true( false !== $helper_decl, 'native_module_schemas_all_from_dir() was located' );

$helper_doc_start = false !== $helper_decl
	? strrpos( substr( $dumpall_doc_src, 0, $helper_decl ), "\t/**\n" )
	: false;
assert_true( false !== $helper_doc_start, 'native_module_schemas_all_from_dir() is preceded by a docblock opener' );

$helper_doc = false !== $helper_decl && false !== $helper_doc_start
	? substr( $dumpall_doc_src, $helper_doc_start, $helper_decl - $helper_doc_start )
	: '';

assert_true(
	false !== strpos( $helper_doc, '@param string $components_dir' ),
	'the docblock directly above native_module_schemas_all_from_dir() is its own, documenting its parameter'
);
assert_true(
	false === strpos( $helper_doc, "Dump every registered Divi module's schema in one call" ),
	'the dump-all docblock is no longer stranded above native_module_schemas_all_from_dir()'
);
