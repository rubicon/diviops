# WordPress Nav Menus

Ten `diviops_menu_*` tools that read and author the WordPress nav-menu objects a
theme location serves. This is the WP side of navigation, not the Divi side.

**These are not Divi mega menus.** The pattern in [mega-menu-pattern.md](mega-menu-pattern.md)
is hand-built from `divi/group` / `divi/text` / `divi/dropdown` modules inside a Theme
Builder header and never reads a WP nav menu. When someone asks for "site navigation",
settle which one they mean before you start: a Divi header you author with
`diviops_tb_layout_update`, or a WP menu you author with the tools below (which a
classic theme location, or a Divi module that pulls a nav menu, then renders).

**Every one of the ten requires `edit_theme_options`** — including the two read tools,
which is unusual enough to catch you out. A token authenticated as an Editor can read
and rewrite page content all day and still get `forbidden` on `diviops_menu_list`. If
the whole family refuses at once, it is the capability, not the menu.

**The eight writes all accept `dry_run`** and return the standard plan shape.

## Read

- `diviops_menu_list` — every nav menu, every theme location the active theme registers,
  and the current location→menu assignments. Takes no parameters. **Start here for any
  location work**: `data.registered_locations` keys are the only strings
  `diviops_menu_location_assign` / `_unassign` accept — they reject an arbitrary location
  name rather than creating one, so a plausible-looking guess like `primary-menu` fails
  against a theme that registered `primary`
- `diviops_menu_get` — one menu as both a normalized flat `items` array and a nested
  `tree`. **This is where item ids come from**, and every other item-level tool
  (`_remove`, `_reorder`, and the `parent_item_id` on both `_add_*` tools) needs one.
  Missing `menu_id` returns `not_found`

## Write

- `diviops_menu_create` — create a menu by name, optionally with a requested slug. An
  existing menu with the same name **or the same slug** returns `ok: true` with
  `noop: true` rather than creating a duplicate, so a retry is safe — but read the
  payload before assuming you created anything, because a slug collision against a
  differently-named menu returns that same quiet no-op. Creating a menu does not put it
  anywhere: follow with `diviops_menu_location_assign` or it renders nowhere
- `diviops_menu_delete` — **irreversible.** Nav menus are terms, not posts: there is no
  trash, no `force` flag, and no undo, so this is the one tool in the family where the
  dry run is worth running every time. Theme locations pointing at the menu are freed
  and named in `data.freed_locations` — check that list, because a deleted menu takes
  the location's assignment with it and the location then renders the theme's fallback
- `diviops_menu_item_add_page` — append a published page to a menu. The gate is narrower
  than "post": the target's `post_type` must be `page` **and** its status `publish`, so a
  blog post, a CPT entry and a draft page are all refused the same way. Link a post from
  a menu with `diviops_menu_item_add_custom` and its permalink instead. Adding the same
  page under the same parent
  returns `noop: true`, but adding it **with a different label returns `conflict`** —
  there is no item-update tool, so relabelling an existing item means
  `diviops_menu_item_remove` then add again, and that new item lands at the end of its
  level (fix the order with `diviops_menu_item_reorder`)
- `diviops_menu_item_add_custom` — append a custom-URL item. The URL allowlist is
  `http`, `https`, root-relative paths, same-page `#hash`, `mailto:` and `tel:`.
  Protocol-relative (`//example.com`), `javascript:` and `data:` URLs are rejected —
  so a URL copied from a browser's address bar is fine and one copied out of existing
  markup may not be. Same URL + same parent + same label is a `noop`
- `diviops_menu_item_remove` — remove one item. **The default is not a subtree delete**:
  with `cascade: false` (the default) only the target goes and its direct children are
  re-parented to the target's own parent, so removing a mid-level item promotes its
  children rather than orphaning them. Pass `cascade: true` to take the descendants too.
  The item must exist *and* belong to `menu_id`, otherwise `not_found` with
  `error.data.field = "item_id"`. The response carries `removed_item_ids[]` and
  `reparented_child_ids[]` — read the second one, it is the only place the promotion is
  reported
- `diviops_menu_item_reorder` — renumber `menu_order` 1..N across one level. **`order`
  must be a complete permutation of exactly that level's item ids** — every sibling,
  only those siblings, each exactly once. Passing just the two ids you want to swap is
  the obvious call and it refuses: `invalid_input`, with `error.data.expected` listing
  the level's ids in their current order, so the refusal hands you the array to permute.
  `parent` selects the level (`0` is top level) and nesting is never changed by this
  tool — to re-nest an item, remove it and re-add it under the new `parent_item_id`
- `diviops_menu_location_assign` / `diviops_menu_location_unassign` — point a theme
  location at a menu, or clear it. Both take a location key from
  `diviops_menu_list().registered_locations` and reject anything else. Re-assigning the
  menu already there returns `noop: true`; unassigning a location that was never
  assigned returns `ok: true` with `noop: true` and `reason: "location_not_assigned"` —
  neither is an error, so branch on `noop`, not on `ok`

## Ordering that actually works

The four steps a new menu needs, in the only order that does not backtrack:

1. `diviops_menu_create` — mint the menu, note `data.id`.
2. `diviops_menu_item_add_page` / `diviops_menu_item_add_custom` — add items **in the
   order you want them**. Both tools append, so building top-to-bottom means you never
   have to reorder. Add a parent before its children, because `parent_item_id` needs an
   id that already exists.
3. `diviops_menu_get` — read back the ids, and only then reorder anything you got wrong.
4. `diviops_menu_location_assign` — last, because a half-built menu assigned early is a
   half-built menu on the live site.

Flushing Divi's compiled CSS is not part of this — menus are markup, not CSS, so
`diviops_meta_flush_cache` is not needed after menu changes.
