<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Trait DiviOps_Agent_Menu
 *
 * WordPress nav-menu authoring helpers.
 *
 * Part of the diviops-agent monolith split. Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Menu {

	public static function menu_list( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menus = array_map(
			[ __CLASS__, 'menu_term_to_row' ],
			wp_get_nav_menus()
		);

		return self::envelope_success(
			[
				'menus'                => $menus,
				'count'                => count( $menus ),
				'registered_locations' => self::menu_registered_locations(),
				'assigned_locations'   => self::menu_assigned_locations(),
			]
		);
	}

	public static function menu_get( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menu_id = absint( $request['id'] );
		$menu    = self::menu_object_by_id( $menu_id );
		if ( ! $menu ) {
			return self::menu_not_found( $menu_id );
		}

		return self::envelope_success( self::menu_readback( $menu ) );
	}

	public static function menu_create( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$name    = self::menu_clean_label( $request->get_param( 'name' ) );
		$slug    = self::menu_clean_slug( $request->get_param( 'slug' ) );
		$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		if ( '' === $name ) {
			return self::envelope_error(
				'invalid_input',
				'name cannot be empty.',
				'Pass a non-empty menu name.',
				400,
				[ 'field' => 'name' ]
			);
		}

		$existing = self::menu_find_by_name_or_slug( $name, $slug );
		if ( $existing ) {
			return self::envelope_success(
				[
					'noop'   => true,
					'menu'   => self::menu_term_to_row( $existing ),
					'reason' => 'menu_exists',
				]
			);
		}

		$plan = [
			'summary' => "Create nav menu '{$name}'.",
			'changes' => [
				[
					'kind'   => 'menu.create',
					'target' => 'menu:new',
					'before' => null,
					'after'  => [
						'name' => $name,
						'slug' => $slug ?: sanitize_title( $name ),
					],
				],
			],
		];

		if ( $dry_run ) {
			return self::envelope_success(
				[
					'dry_run' => true,
					'plan'    => $plan,
				]
			);
		}

		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return self::envelope_error(
				'wp_error',
				$menu_id->get_error_message(),
				'Resolve the WordPress menu creation error, then retry.',
				500,
				[ 'wp_error_code' => $menu_id->get_error_code() ]
			);
		}

		if ( '' !== $slug ) {
			$slug_update = wp_update_term( (int) $menu_id, 'nav_menu', [ 'slug' => $slug ] );
			if ( is_wp_error( $slug_update ) ) {
				return self::envelope_error(
					'wp_error',
					$slug_update->get_error_message(),
					'The menu was created, but WordPress rejected the requested slug. Inspect nav menus before retrying.',
					500,
					[
						'status'        => 'committed',
						'menu_id'       => (int) $menu_id,
						'requested_slug' => $slug,
						'wp_error_code' => $slug_update->get_error_code(),
					]
				);
			}
		}

		$menu = self::menu_object_by_id( (int) $menu_id );
		if ( ! $menu ) {
			return self::envelope_error(
				'readback_failed',
				"Menu #{$menu_id} was created but could not be read back.",
				'Inspect nav menus in WordPress admin before retrying.',
				500,
				[ 'status' => 'committed', 'menu_id' => (int) $menu_id ]
			);
		}

		return self::envelope_success(
			[
				'menu' => self::menu_term_to_row( $menu ),
			],
			201
		);
	}

	public static function menu_item_add_page( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menu_id        = absint( $request->get_param( 'menu_id' ) );
		$page_id        = absint( $request->get_param( 'page_id' ) );
		$label          = self::menu_clean_label( $request->get_param( 'label' ) );
		$parent_item_id = absint( $request->get_param( 'parent_item_id' ) ?? 0 );
		$dry_run        = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		$menu = self::menu_object_by_id( $menu_id );
		if ( ! $menu ) {
			return self::menu_not_found( $menu_id );
		}

		$parent_error = self::menu_validate_parent_item( $menu_id, $parent_item_id );
		if ( $parent_error ) {
			return $parent_error;
		}

		$post = get_post( $page_id );
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$page_id} not found.",
				'Pass an existing published page ID.',
				404,
				[ 'page_id' => $page_id ]
			);
		}

		if ( ! current_user_can( 'read_post', $page_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot read page #{$page_id}.",
				'Authenticate as a user with read access to this page.',
				403,
				[ 'page_id' => $page_id ]
			);
		}

		if ( 'page' !== (string) $post->post_type || 'publish' !== (string) $post->post_status ) {
			return self::envelope_error(
				'invalid_input',
				'Menu page items require a readable published page.',
				'Pass a page ID whose post_type is page and status is publish.',
				400,
				[
					'page_id'     => $page_id,
					'post_type'   => (string) $post->post_type,
					'post_status' => (string) $post->post_status,
				]
			);
		}

		$title = '' !== $label ? $label : self::menu_clean_label( $post->post_title );
		if ( '' === $title ) {
			return self::envelope_error(
				'invalid_input',
				'label cannot be empty.',
				'Pass a non-empty label or choose a page with a title.',
				400,
				[ 'field' => 'label' ]
			);
		}

		$existing = self::menu_find_existing_page_item( $menu_id, $page_id, $parent_item_id );
		if ( $existing ) {
			if ( (string) $existing->title === $title ) {
				return self::envelope_success(
					[
						'noop'    => true,
						'item'    => self::menu_item_to_row( $existing ),
						'menu'    => self::menu_term_to_row( $menu ),
						'reason'  => 'menu_item_exists',
					]
				);
			}

			return self::envelope_error(
				'conflict',
				"Menu already contains page #{$page_id} under this parent with a different label.",
				'Use the existing item or wait for diviops_menu_item_update in a later slice.',
				409,
				[
					'existing_item_id' => (int) $existing->ID,
					'existing_label'   => (string) $existing->title,
					'requested_label'  => $title,
				]
			);
		}

		$args = [
			'menu-item-title'     => $title,
			'menu-item-object-id' => $page_id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_item_id,
		];

		return self::menu_item_apply_or_plan(
			$menu,
			$args,
			$dry_run,
			"Add page #{$page_id} to menu '{$menu->name}'.",
			'menu.item.add_page'
		);
	}

	public static function menu_item_add_custom( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menu_id        = absint( $request->get_param( 'menu_id' ) );
		$label          = self::menu_clean_label( $request->get_param( 'label' ) );
		$url_result     = self::menu_validate_custom_url( $request->get_param( 'url' ) );
		$parent_item_id = absint( $request->get_param( 'parent_item_id' ) ?? 0 );
		$dry_run        = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		$menu = self::menu_object_by_id( $menu_id );
		if ( ! $menu ) {
			return self::menu_not_found( $menu_id );
		}

		$parent_error = self::menu_validate_parent_item( $menu_id, $parent_item_id );
		if ( $parent_error ) {
			return $parent_error;
		}

		if ( '' === $label ) {
			return self::envelope_error(
				'invalid_input',
				'label cannot be empty.',
				'Pass a non-empty custom menu item label.',
				400,
				[ 'field' => 'label' ]
			);
		}

		if ( empty( $url_result['ok'] ) ) {
			return self::envelope_error(
				'invalid_input',
				(string) $url_result['message'],
				'Use http, https, root-relative paths, same-page hashes, mailto, or tel URLs.',
				400,
				[ 'field' => 'url', 'received' => (string) $request->get_param( 'url' ) ]
			);
		}
		$url = (string) $url_result['url'];

		$existing = self::menu_find_existing_custom_item( $menu_id, $url, $parent_item_id );
		if ( $existing ) {
			if ( (string) $existing->title === $label ) {
				return self::envelope_success(
					[
						'noop'   => true,
						'item'   => self::menu_item_to_row( $existing ),
						'menu'   => self::menu_term_to_row( $menu ),
						'reason' => 'menu_item_exists',
					]
				);
			}

			return self::envelope_error(
				'conflict',
				'Menu already contains this custom URL under this parent with a different label.',
				'Use the existing item or wait for diviops_menu_item_update in a later slice.',
				409,
				[
					'existing_item_id' => (int) $existing->ID,
					'existing_label'   => (string) $existing->title,
					'requested_label'  => $label,
					'url'              => $url,
				]
			);
		}

		$args = [
			'menu-item-title'     => $label,
			'menu-item-url'       => $url,
			'menu-item-type'      => 'custom',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_item_id,
		];

		return self::menu_item_apply_or_plan(
			$menu,
			$args,
			$dry_run,
			"Add custom URL to menu '{$menu->name}'.",
			'menu.item.add_custom'
		);
	}

	public static function menu_location_assign( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menu_id  = absint( $request->get_param( 'menu_id' ) );
		$location = sanitize_key( (string) $request->get_param( 'location' ) );
		$dry_run  = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		$menu = self::menu_object_by_id( $menu_id );
		if ( ! $menu ) {
			return self::menu_not_found( $menu_id );
		}

		$registered = self::menu_registered_locations();
		if ( '' === $location || ! isset( $registered[ $location ] ) ) {
			return self::envelope_error(
				'invalid_input',
				"Theme location '{$location}' is not registered by the current theme.",
				'Call diviops_menu_list and use one of data.registered_locations keys.',
				400,
				[
					'field'                => 'location',
					'received'             => $location,
					'registered_locations' => array_keys( $registered ),
				]
			);
		}

		$assigned = get_nav_menu_locations();
		$current  = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
		if ( $current === (int) $menu->term_id ) {
			return self::envelope_success(
				[
					'noop'      => true,
					'menu'      => self::menu_term_to_row( $menu ),
					'location'  => $location,
					'assigned'  => self::menu_assigned_locations(),
					'reason'    => 'location_already_assigned',
				]
			);
		}

		$plan = [
			'summary' => "Assign menu '{$menu->name}' to theme location '{$location}'.",
			'changes' => [
				[
					'kind'   => 'menu.location.assign',
					'target' => "location:{$location}",
					'before' => $current,
					'after'  => (int) $menu->term_id,
				],
			],
		];

		if ( $dry_run ) {
			return self::envelope_success(
				[
					'dry_run' => true,
					'plan'    => $plan,
				]
			);
		}

		$assigned[ $location ] = (int) $menu->term_id;
		set_theme_mod( 'nav_menu_locations', $assigned );

		return self::envelope_success(
			[
				'menu'      => self::menu_term_to_row( $menu ),
				'location'  => $location,
				'assigned'  => self::menu_assigned_locations(),
			]
		);
	}

	/**
	 * Permanently delete a nav menu.
	 *
	 * A nav menu has no trash (unlike a page or a library layout), so deletion is
	 * irreversible and there is no `force` parameter. The theme locations currently
	 * pointing at the menu are captured before deletion so the response can report
	 * exactly which locations wp_delete_nav_menu freed.
	 */
	public static function menu_delete( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menu_id = absint( $request['id'] );
		$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		$menu = self::menu_object_by_id( $menu_id );
		if ( ! $menu ) {
			return self::menu_not_found( $menu_id );
		}

		$assigned        = get_nav_menu_locations();
		$freed_locations = [];
		foreach ( $assigned as $location => $assigned_menu_id ) {
			if ( (int) $assigned_menu_id === (int) $menu->term_id ) {
				$freed_locations[] = (string) $location;
			}
		}

		$plan = [
			'summary' => "Permanently delete nav menu '{$menu->name}' (#{$menu->term_id}). Nav menus have no trash; this cannot be undone.",
			'changes' => [
				[
					'kind'   => 'menu.delete',
					'target' => 'menu:' . (int) $menu->term_id,
					'before' => (string) $menu->name,
					'after'  => 'deleted',
				],
			],
		];

		if ( $dry_run ) {
			return self::envelope_success(
				[
					'dry_run' => true,
					'plan'    => $plan,
				]
			);
		}

		$result = wp_delete_nav_menu( (int) $menu->term_id );
		if ( is_wp_error( $result ) || false === $result ) {
			return self::envelope_error(
				'wp_error',
				is_wp_error( $result ) ? $result->get_error_message() : "Menu #{$menu->term_id} could not be deleted.",
				'Resolve the WordPress menu deletion error, then retry.',
				500,
				is_wp_error( $result ) ? [ 'wp_error_code' => $result->get_error_code() ] : null
			);
		}

		return self::envelope_success(
			[
				'id'              => (int) $menu->term_id,
				'name'            => (string) $menu->name,
				'deleted'         => true,
				'freed_locations' => $freed_locations,
			]
		);
	}

	/**
	 * Remove one menu item, optionally cascading to its descendants.
	 *
	 * Default (cascade=false): remove only the target and re-parent its direct
	 * children to the target's own parent, so the surviving tree stays connected.
	 * cascade=true: remove the target and every descendant beneath it.
	 */
	public static function menu_item_remove( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$item_id = absint( $request->get_param( 'item_id' ) );
		$cascade = rest_sanitize_boolean( $request->get_param( 'cascade' ) ?? false );
		$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		$menu = self::menu_object_by_id( $menu_id );
		if ( ! $menu ) {
			return self::menu_not_found( $menu_id );
		}

		$target = self::menu_item_in_menu( $menu_id, $item_id );
		if ( ! $target ) {
			return self::envelope_error(
				'not_found',
				"Menu item #{$item_id} not found in menu #{$menu_id}.",
				'Call diviops_menu_get for this menu and pass an item id it lists.',
				404,
				[ 'field' => 'item_id', 'menu_id' => $menu_id, 'item_id' => $item_id ]
			);
		}

		$target_parent = (int) $target->menu_item_parent;

		if ( $cascade ) {
			$remove_ids = array_merge( [ $item_id ], self::menu_item_descendant_ids( $menu_id, $item_id ) );
			$child_ids  = [];
		} else {
			$remove_ids = [ $item_id ];
			$child_ids  = self::menu_item_child_ids( $menu_id, $item_id );
		}

		$plan = [
			'summary' => $cascade
				? "Remove menu item #{$item_id} and its " . ( count( $remove_ids ) - 1 ) . " descendant(s) from menu '{$menu->name}'."
				: "Remove menu item #{$item_id} from menu '{$menu->name}', re-parenting its " . count( $child_ids ) . " direct child(ren) to parent #{$target_parent}.",
			'changes' => [
				[
					'kind'   => 'menu.item.remove',
					'target' => 'menu_item:' . (int) $item_id,
					'before' => [
						'cascade'              => $cascade,
						'removed_count'        => count( $remove_ids ),
						'reparented_to'        => $cascade ? null : $target_parent,
						'reparented_child_ids' => $cascade ? [] : array_values( array_map( 'intval', $child_ids ) ),
					],
					'after'  => 'removed',
				],
			],
		];

		if ( $dry_run ) {
			return self::envelope_success(
				[
					'dry_run' => true,
					'plan'    => $plan,
				]
			);
		}

		$reparented = [];
		if ( ! $cascade ) {
			foreach ( $child_ids as $child_id ) {
				update_post_meta( (int) $child_id, '_menu_item_menu_item_parent', $target_parent );
				$reparented[] = (int) $child_id;
			}
		}

		foreach ( $remove_ids as $remove_id ) {
			wp_delete_post( (int) $remove_id, true );
		}

		$readback                         = self::menu_readback( $menu );
		$readback['removed_item_ids']     = array_values( array_map( 'intval', $remove_ids ) );
		$readback['reparented_child_ids'] = $reparented;

		return self::envelope_success( $readback );
	}

	/**
	 * Reorder the menu items at one level (all items sharing a parent).
	 *
	 * `order` must be a complete permutation of the ids whose parent equals
	 * `parent`: no extras, no omissions, no duplicates. The items are renumbered
	 * with menu_order 1..N in the given sequence.
	 */
	public static function menu_item_reorder( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$menu_id = absint( $request->get_param( 'menu_id' ) );
		$parent  = absint( $request->get_param( 'parent' ) ?? 0 );
		$order   = $request->get_param( 'order' );
		$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		$menu = self::menu_object_by_id( $menu_id );
		if ( ! $menu ) {
			return self::menu_not_found( $menu_id );
		}

		$order     = is_array( $order ) ? array_map( 'absint', array_values( $order ) ) : [];
		$level_ids = self::menu_item_ids_at_level( $menu_id, $parent );

		$error = self::menu_reorder_validate( $order, $level_ids, $parent );
		if ( $error ) {
			return $error;
		}

		$plan = [
			'summary' => 'Reorder ' . count( $order ) . " menu item(s) under parent #{$parent} in menu '{$menu->name}'.",
			'changes' => [
				[
					'kind'   => 'menu.item.reorder',
					'target' => 'menu:' . (int) $menu_id,
					'before' => $level_ids,
					'after'  => $order,
				],
			],
		];

		if ( $dry_run ) {
			return self::envelope_success(
				[
					'dry_run' => true,
					'plan'    => $plan,
				]
			);
		}

		$position = 1;
		foreach ( $order as $item_id ) {
			wp_update_post( [ 'ID' => (int) $item_id, 'menu_order' => $position ] );
			++$position;
		}

		return self::envelope_success( self::menu_readback( $menu ) );
	}

	/**
	 * Remove a nav menu's assignment from a registered theme location.
	 *
	 * The mirror of menu_location_assign: it clears the location rather than
	 * pointing it at a menu. Unassigning a location that is not currently assigned
	 * is an idempotent no-op, not an error.
	 */
	public static function menu_location_unassign( $request ) {
		if ( ! self::menu_can_manage() ) {
			return self::menu_forbidden();
		}

		$location = sanitize_key( (string) $request->get_param( 'location' ) );
		$dry_run  = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );

		$registered = self::menu_registered_locations();
		if ( '' === $location || ! isset( $registered[ $location ] ) ) {
			return self::envelope_error(
				'invalid_input',
				"Theme location '{$location}' is not registered by the current theme.",
				'Call diviops_menu_list and use one of data.registered_locations keys.',
				400,
				[
					'field'                => 'location',
					'received'             => $location,
					'registered_locations' => array_keys( $registered ),
				]
			);
		}

		$assigned = get_nav_menu_locations();
		$current  = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
		if ( 0 === $current ) {
			return self::envelope_success(
				[
					'noop'     => true,
					'location' => $location,
					'assigned' => self::menu_assigned_locations(),
					'reason'   => 'location_not_assigned',
				]
			);
		}

		$plan = [
			'summary' => "Unassign theme location '{$location}' (currently menu #{$current}).",
			'changes' => [
				[
					'kind'   => 'menu.location.unassign',
					'target' => "location:{$location}",
					'before' => $current,
					'after'  => 0,
				],
			],
		];

		if ( $dry_run ) {
			return self::envelope_success(
				[
					'dry_run' => true,
					'plan'    => $plan,
				]
			);
		}

		unset( $assigned[ $location ] );
		set_theme_mod( 'nav_menu_locations', $assigned );

		return self::envelope_success(
			[
				'location' => $location,
				'assigned' => self::menu_assigned_locations(),
			]
		);
	}

	private static function menu_can_manage(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	private static function menu_forbidden() {
		return self::envelope_error(
			'forbidden',
			'Requires edit_theme_options capability.',
			'Authenticate as an administrator or a role allowed to manage menus.',
			403
		);
	}

	private static function menu_not_found( int $menu_id ) {
		return self::envelope_error(
			'not_found',
			"Menu #{$menu_id} not found.",
			'Call diviops_menu_list to discover existing menus.',
			404,
			[ 'menu_id' => $menu_id ]
		);
	}

	private static function menu_object_by_id( int $menu_id ) {
		if ( $menu_id <= 0 ) {
			return null;
		}
		$menu = wp_get_nav_menu_object( $menu_id );
		return $menu && ! is_wp_error( $menu ) ? $menu : null;
	}

	private static function menu_find_by_name_or_slug( string $name, string $slug ) {
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( (string) $menu->name === $name ) {
				return $menu;
			}
			if ( '' !== $slug && (string) $menu->slug === $slug ) {
				return $menu;
			}
		}
		return null;
	}

	private static function menu_term_to_row( $menu ): array {
		return [
			'id'    => (int) $menu->term_id,
			'name'  => (string) $menu->name,
			'slug'  => (string) $menu->slug,
			'count' => isset( $menu->count ) ? (int) $menu->count : 0,
		];
	}

	private static function menu_readback( $menu ): array {
		$items = array_map(
			[ __CLASS__, 'menu_item_to_row' ],
			self::menu_items( (int) $menu->term_id )
		);

		return [
			'menu'  => self::menu_term_to_row( $menu ),
			'items' => $items,
			'tree'  => self::menu_item_tree( $items ),
		];
	}

	private static function menu_items( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'publish' ] );
		return is_array( $items ) ? $items : [];
	}

	private static function menu_item_to_row( $item ): array {
		return [
			'id'        => (int) $item->ID,
			'title'     => (string) $item->title,
			'url'       => (string) $item->url,
			'type'      => (string) $item->type,
			'object'    => (string) $item->object,
			'object_id' => isset( $item->object_id ) ? (int) $item->object_id : 0,
			'parent'    => isset( $item->menu_item_parent ) ? (int) $item->menu_item_parent : 0,
			'order'     => isset( $item->menu_order ) ? (int) $item->menu_order : 0,
		];
	}

	private static function menu_item_tree( array $items ): array {
		$by_parent = [];
		foreach ( $items as $item ) {
			$parent = (int) ( $item['parent'] ?? 0 );
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = [];
			}
			$by_parent[ $parent ][] = $item;
		}

		$build = function ( int $parent ) use ( &$build, &$by_parent ) {
			$children = $by_parent[ $parent ] ?? [];
			foreach ( $children as &$child ) {
				$child['children'] = $build( (int) $child['id'] );
			}
			return $children;
		};

		return $build( 0 );
	}

	private static function menu_registered_locations(): array {
		$locations = get_registered_nav_menus();
		return is_array( $locations ) ? $locations : [];
	}

	private static function menu_assigned_locations(): array {
		$assigned = get_nav_menu_locations();
		return is_array( $assigned ) ? array_map( 'intval', $assigned ) : [];
	}

	private static function menu_clean_label( $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	private static function menu_clean_slug( $value ): string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return '';
		}
		return sanitize_title( (string) $value );
	}

	private static function menu_validate_parent_item( int $menu_id, int $parent_item_id ) {
		if ( 0 === $parent_item_id ) {
			return null;
		}

		foreach ( self::menu_items( $menu_id ) as $item ) {
			if ( (int) $item->ID === $parent_item_id ) {
				return null;
			}
		}

		return self::envelope_error(
			'not_found',
			"Parent menu item #{$parent_item_id} not found in menu #{$menu_id}.",
			'Pass parent_item_id=0 or an item ID from diviops_menu_get for this menu.',
			404,
			[ 'menu_id' => $menu_id, 'parent_item_id' => $parent_item_id ]
		);
	}

	private static function menu_find_existing_page_item( int $menu_id, int $page_id, int $parent_item_id ) {
		foreach ( self::menu_items( $menu_id ) as $item ) {
			if (
				'post_type' === (string) $item->type
				&& 'page' === (string) $item->object
				&& (int) $item->object_id === $page_id
				&& (int) $item->menu_item_parent === $parent_item_id
			) {
				return $item;
			}
		}
		return null;
	}

	private static function menu_find_existing_custom_item( int $menu_id, string $url, int $parent_item_id ) {
		foreach ( self::menu_items( $menu_id ) as $item ) {
			if (
				'custom' === (string) $item->type
				&& (string) $item->url === $url
				&& (int) $item->menu_item_parent === $parent_item_id
			) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Return the menu item with this id when it belongs to this menu, else null.
	 * An item id that exists only in another menu is treated as absent here.
	 */
	private static function menu_item_in_menu( int $menu_id, int $item_id ) {
		if ( $item_id <= 0 ) {
			return null;
		}
		foreach ( self::menu_items( $menu_id ) as $item ) {
			if ( (int) $item->ID === $item_id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Ids of the menu items whose direct parent is $item_id.
	 */
	private static function menu_item_child_ids( int $menu_id, int $item_id ): array {
		$children = [];
		foreach ( self::menu_items( $menu_id ) as $item ) {
			if ( (int) $item->menu_item_parent === $item_id ) {
				$children[] = (int) $item->ID;
			}
		}
		return $children;
	}

	/**
	 * Ids of every descendant of $item_id within the menu, walking the parent tree.
	 * The traversal is bounded by the menu's own item set, so a malformed parent
	 * cycle cannot loop: each item is enqueued at most once as somebody's child.
	 */
	private static function menu_item_descendant_ids( int $menu_id, int $item_id ): array {
		$by_parent = [];
		foreach ( self::menu_items( $menu_id ) as $item ) {
			$by_parent[ (int) $item->menu_item_parent ][] = (int) $item->ID;
		}

		$descendants = [];
		$seen        = [ $item_id => true ];
		$stack       = [ $item_id ];
		while ( $stack ) {
			$current = array_pop( $stack );
			foreach ( $by_parent[ $current ] ?? [] as $child_id ) {
				if ( isset( $seen[ $child_id ] ) ) {
					continue;
				}
				$seen[ $child_id ] = true;
				$descendants[]     = $child_id;
				$stack[]           = $child_id;
			}
		}
		return $descendants;
	}

	/**
	 * Ids of the menu items at one level (sharing $parent), in current menu_order.
	 */
	private static function menu_item_ids_at_level( int $menu_id, int $parent ): array {
		$ids = [];
		foreach ( self::menu_items( $menu_id ) as $item ) {
			if ( (int) $item->menu_item_parent === $parent ) {
				$ids[] = (int) $item->ID;
			}
		}
		return $ids;
	}

	/**
	 * Validate a reorder request: $order must be a non-empty, duplicate-free,
	 * complete permutation of $level_ids. Returns an error envelope on failure,
	 * or null when the order is a valid permutation of the level.
	 */
	private static function menu_reorder_validate( array $order, array $level_ids, int $parent ) {
		if ( [] === $order ) {
			return self::envelope_error(
				'invalid_input',
				'order must be a non-empty array of menu item ids.',
				'Pass every item id at this level, in the desired order.',
				400,
				[ 'field' => 'order', 'parent' => $parent, 'expected' => $level_ids ]
			);
		}

		if ( count( $order ) !== count( array_unique( $order ) ) ) {
			return self::envelope_error(
				'invalid_input',
				'order contains duplicate menu item ids.',
				'Each item id at this level must appear exactly once.',
				400,
				[ 'field' => 'order', 'parent' => $parent, 'expected' => $level_ids, 'received' => $order ]
			);
		}

		$expected = $level_ids;
		$got      = $order;
		sort( $expected );
		sort( $got );
		if ( $expected !== $got ) {
			return self::envelope_error(
				'invalid_input',
				'order must be exactly the set of menu items at this level (a complete reordering).',
				'Pass every item id whose parent matches, and only those. Call diviops_menu_get to list them.',
				400,
				[ 'field' => 'order', 'parent' => $parent, 'expected' => $level_ids, 'received' => $order ]
			);
		}

		return null;
	}

	private static function menu_validate_custom_url( $value ): array {
		$url = trim( (string) $value );
		if ( '' === $url ) {
			return [ 'ok' => false, 'message' => 'url cannot be empty.' ];
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return [ 'ok' => false, 'message' => 'Protocol-relative URLs are not allowed.' ];
		}
		if ( 0 === strpos( $url, '#' ) ) {
			return strlen( $url ) > 1
				? [ 'ok' => true, 'url' => $url ]
				: [ 'ok' => false, 'message' => 'Hash URLs must include a fragment name.' ];
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return [ 'ok' => true, 'url' => $url ];
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme = is_string( $scheme ) ? strtolower( $scheme ) : '';
		if ( ! in_array( $scheme, [ 'http', 'https', 'mailto', 'tel' ], true ) ) {
			return [ 'ok' => false, 'message' => "URL scheme '{$scheme}' is not allowed." ];
		}

		return [ 'ok' => true, 'url' => esc_url_raw( $url ) ];
	}

	private static function menu_item_apply_or_plan( $menu, array $args, bool $dry_run, string $summary, string $kind ) {
		$plan = [
			'summary' => $summary,
			'changes' => [
				[
					'kind'   => $kind,
					'target' => 'menu#' . (int) $menu->term_id,
					'before' => null,
					'after'  => [
						'title'  => (string) ( $args['menu-item-title'] ?? '' ),
						'type'   => (string) ( $args['menu-item-type'] ?? '' ),
						'object' => (string) ( $args['menu-item-object'] ?? '' ),
						'url'    => (string) ( $args['menu-item-url'] ?? '' ),
						'parent' => (int) ( $args['menu-item-parent-id'] ?? 0 ),
					],
				],
			],
		];

		if ( $dry_run ) {
			return self::envelope_success(
				[
					'dry_run' => true,
					'plan'    => $plan,
				]
			);
		}

		$item_id = wp_update_nav_menu_item( (int) $menu->term_id, 0, $args );
		if ( is_wp_error( $item_id ) ) {
			return self::envelope_error(
				'wp_error',
				$item_id->get_error_message(),
				'Resolve the WordPress menu item error, then retry.',
				500,
				[ 'wp_error_code' => $item_id->get_error_code() ]
			);
		}

		$readback = self::menu_readback( $menu );
		$item_row = null;
		foreach ( $readback['items'] as $item ) {
			if ( (int) $item['id'] === (int) $item_id ) {
				$item_row = $item;
				break;
			}
		}
		if ( null === $item_row ) {
			return self::envelope_error(
				'readback_failed',
				"Menu item #{$item_id} was created but could not be read back.",
				'Inspect the WordPress menu before retrying.',
				500,
				[
					'status'  => 'committed',
					'menu_id' => (int) $menu->term_id,
					'item_id' => (int) $item_id,
				]
			);
		}

		return self::envelope_success(
			[
				'menu' => $readback['menu'],
				'item' => $item_row,
				'items_count' => count( $readback['items'] ),
			],
			201
		);
	}
}
