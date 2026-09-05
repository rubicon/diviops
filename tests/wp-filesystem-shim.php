<?php
// SPDX-License-Identifier: MIT
/**
 * OPT-IN stub: WordPress' WP_Filesystem Direct transport (#381).
 *
 * `require_once` this AFTER wp-shim.php and BEFORE the plugin file, in the tests that
 * exercise a code path routing its deletes through `WP_Filesystem`. It is deliberately
 * NOT part of `wp-shim.php` — the base harness models a site where `WP_Filesystem()` does
 * not exist, and `tests/test-global-token-cache-invalidation-unavailable.php` depends on
 * that absence to prove the invalidation reports `unavailable` rather than silently
 * looking like a success.
 *
 * ## What is modelled, and from where
 *
 * Transcribed from WordPress **7.1** as installed on the staging site —
 * `wp-admin/includes/class-wp-filesystem-direct.php`, `put_contents()` at :68,
 * `delete()` at :401, `rmdir()` at :613 — not from memory. Only the three methods the
 * code under test calls are modelled, plus the two `is_*` predicates `delete()` itself
 * branches on.
 *
 * Two behaviours here are load-bearing rather than incidental, and both come straight
 * from that source:
 *
 *   1. `delete()` refuses an empty path. Core's own comment explains why — "Some
 *      filesystems report this as /, which can cause non-expected recursive deletion of
 *      all files in the filesystem." A stub that quietly accepted `''` would let a test
 *      pass over a path bug that deletes a site.
 *   2. `rmdir( $path, false )` delegates to `delete( $path, false )` with **no** type
 *      argument, so a non-empty directory reaches the `! $recursive && is_dir` branch and
 *      is a plain `@rmdir` — which fails, leaving the directory in place. The sweep under
 *      test relies on exactly that no-op for directories that still hold preserved files.
 *
 * Anything else on the real class RAISES rather than returning a plausible answer. A shim
 * that approximates produces passing tests on broken code, which this repository has
 * shipped once already — see the shim contract in CONTRIBUTING.md.
 *
 * @package DiviOps
 */

if ( ! class_exists( 'DiviOps_Test_WP_Filesystem_Direct' ) ) {
	/**
	 * The subset of WP_Filesystem_Direct the Divi cache sweep actually calls.
	 *
	 * Deliberately does NOT extend or declare itself `WP_Filesystem_Base`: the plugin
	 * type-hints nothing here, and declaring a base class this file does not model would
	 * be the approximation the shim contract forbids.
	 */
	class DiviOps_Test_WP_Filesystem_Direct {

		/** Every call this stub received, in order — lets a test assert the route taken. */
		public $calls = array();

		/**
		 * Core: wp-admin/includes/class-wp-filesystem-direct.php:401.
		 *
		 * @param string       $file      Path to delete.
		 * @param bool         $recursive Recurse into directories.
		 * @param string|false $type      'f' for file, 'd' for directory, false to detect.
		 * @return bool
		 */
		public function delete( $file, $recursive = false, $type = false ) {
			$this->calls[] = array( 'delete', $file );

			// Core's own guard, verbatim in intent: an empty path can arrive as '/'
			// from some filesystems and would recurse over the whole disk.
			if ( empty( $file ) ) {
				return false;
			}
			$file = str_replace( '\\', '/', $file );

			if ( 'f' === $type || is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Modelling WP_Filesystem_Direct::delete(), which calls unlink() directly.
				return @unlink( $file );
			}
			if ( ! $recursive && is_dir( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Modelling WP_Filesystem_Direct::delete().
				return @rmdir( $file );
			}
			if ( $recursive ) {
				throw new RuntimeException(
					'wp-filesystem-shim delete(): recursive deletion is not modelled. Core walks dirlist() here; '
					. 'model it against wp-admin/includes/class-wp-filesystem-direct.php if a caller starts using it.'
				);
			}
			return false;
		}

		/**
		 * Core: wp-admin/includes/class-wp-filesystem-direct.php:613 — one line,
		 * `return $this->delete( $path, $recursive );`, passing NO type. The missing
		 * type is the whole behaviour: a non-empty directory falls to plain rmdir and
		 * the call fails rather than removing anything.
		 *
		 * @param string $path
		 * @param bool   $recursive
		 * @return bool
		 */
		public function rmdir( $path, $recursive = false ) {
			$this->calls[] = array( 'rmdir', $path );
			return $this->delete( $path, $recursive );
		}

		/**
		 * Core: wp-admin/includes/class-wp-filesystem-direct.php:68. Core opens 'wb',
		 * writes, and returns false when the byte count written differs from the input
		 * length; the trailing chmod is not modelled because nothing under test reads a
		 * mode back.
		 *
		 * @param string $file
		 * @param string $contents
		 * @param int|false $mode
		 * @return bool
		 */
		public function put_contents( $file, $contents, $mode = false ) {
			$this->calls[] = array( 'put_contents', $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Modelling WP_Filesystem_Direct::put_contents().
			$fp = @fopen( $file, 'wb' );
			if ( ! $fp ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$written = fwrite( $fp, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return strlen( $contents ) === $written;
		}

		/**
		 * Anything the real class offers and this stub does not model.
		 *
		 * Raising here is the point: a silently-absent method would return null, read as
		 * falsy, and send a test green down a branch it never exercised.
		 */
		public function __call( $name, $args ) {
			throw new RuntimeException(
				'wp-filesystem-shim: WP_Filesystem_Direct::' . $name . '() is not modelled. '
				. 'Transcribe it from wp-admin/includes/class-wp-filesystem-direct.php rather than approximating it.'
			);
		}
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	/**
	 * Core: wp-admin/includes/file.php. The real function negotiates a transport from
	 * FS_METHOD and credentials; on a host where PHP can write directly it lands on the
	 * Direct transport, which is the only case worth modelling here.
	 *
	 * Core returns true on success and populates the `$wp_filesystem` global; callers —
	 * including `DiviOps_Agent::init_wp_filesystem()` — read the global rather than the
	 * return value, so both are set.
	 */
	function WP_Filesystem( $args = false, $context = false, $allow_relaxed_file_ownership = false ) {
		if ( ! isset( $GLOBALS['wp_filesystem'] ) || ! $GLOBALS['wp_filesystem'] ) {
			$GLOBALS['wp_filesystem'] = new DiviOps_Test_WP_Filesystem_Direct();
		}
		return true;
	}
}
