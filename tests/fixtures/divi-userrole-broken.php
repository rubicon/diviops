<?php
// SPDX-License-Identifier: MIT
/**
 * Divi 5.10.x-5.11.x `UserRole` as it actually ships: no `can_edit_posts()`.
 *
 * Verified against Divi 5.11.1 on the reference install -- the class declares
 * exactly one public static method, and `PostFilterProductPriceRangeController::
 * index_permission()` calls the one it does not declare.
 *
 * @package DiviOps
 */

namespace ET\Builder\Framework\UserRole;

class UserRole {
	public static function can_current_user_use_visual_builder(): bool {
		return (bool) ( $GLOBALS['diviops_test_divi_vb_authority'] ?? true );
	}
}
