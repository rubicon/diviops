<?php
// SPDX-License-Identifier: MIT
/**
 * `UserRole` as it will look once Divi ships the missing method.
 *
 * Exists so the repair's self-retirement can be proven rather than assumed. A
 * class cannot be redefined in a running process, so the fixed shape is only
 * reachable from a child process -- see tests/test-divi-compatibility.php.
 *
 * @package DiviOps
 */

namespace ET\Builder\Framework\UserRole;

class UserRole {
	public static function can_current_user_use_visual_builder(): bool {
		return (bool) ( $GLOBALS['diviops_test_divi_vb_authority'] ?? true );
	}

	public static function can_edit_posts(): bool {
		return true;
	}
}
