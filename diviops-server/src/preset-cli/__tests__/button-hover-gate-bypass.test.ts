// SPDX-License-Identifier: MIT
/**
 * Regression: `bypass_hover_padding_gate` must write the corners Divi reads (#414).
 *
 * Divi's button style pass emits a hover padding normalisation when a button
 * has no horizontal padding of its own. The documented way to suppress it is
 * to set a padding corner on `button.decoration.button`, and the emitter wrote
 * `{ top: "0px" }`.
 *
 * That worked on Divi 5.12.0, where the gate was
 * `'off' === $enable && ! $has_desktop_padding` and `$has_desktop_padding` was
 * true if ANY corner was set — one corner suppressed it.
 *
 * Divi 5.12.1 refactored `Packages/Module/Options/Button/Style/StyleDeclarations.php`.
 * The branch now computes `$effective_right_padding` / `$effective_left_padding`
 * from ONLY the `right` and `left` keys and emits `padding-right` / `padding-left`
 * under two independent guards. Its own comment says it deliberately avoids the
 * shorthand fallback so hover top/bottom custom values are preserved. `top` is
 * no longer read at all, so the bypass suppressed nothing.
 *
 * Confirmed in a live frontend render rather than by direct invocation — a
 * scratch page on staging under Divi 5.13, seven `divi/button` instances on one
 * `icon.enable: "off"` group preset, reading compiled CSS out of
 * `wp-content/et-cache/` and matching rules against each button's
 * `et_pb_button_N` order class:
 *
 *   preset carrying no padding + bypass `{top}`          -> gate FIRES
 *   preset carrying no padding + bypass `{left, right}`  -> gate silent
 *
 * So the fix is the corners, not the mechanism: write `left` and `right`.
 *
 * `top` is kept alongside them. Divi 5.12.1 stopped reading it, but
 * `$has_desktop_padding` still exists tree-wide in the 5.12.0 shape —
 * `WooCommerceProductAddToCartModule.php:734` defines it and `:739` is the
 * verbatim old branch. The old shape survives in that clone while the shared
 * Button path was refactored, so dropping `top` would fix `divi/button` and
 * regress anything still on the pre-refactor branch. Writing all three corners
 * satisfies both shapes and costs nothing: a corner Divi does not read is inert.
 */

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { composeButtonAttrs } from '../button-emitter.js';

/** The padding map the bypass writes, or undefined if it wrote nothing. */
function bypassPadding(attrs: unknown): Record<string, unknown> | undefined {
  const button = (attrs as any)?.button?.decoration?.button;
  return button?.desktop?.value?.padding;
}

describe('bypass_hover_padding_gate (#414)', () => {
  it('writes the left and right corners Divi 5.12.1+ actually reads', () => {
    const attrs = composeButtonAttrs({ bypass_hover_padding_gate: true } as any);
    const padding = bypassPadding(attrs);

    assert.ok(padding, 'the bypass writes a padding map');
    assert.equal(
      padding!.right,
      '0px',
      'right is set — Divi 5.12.1 computes $effective_right_padding from this key alone',
    );
    assert.equal(
      padding!.left,
      '0px',
      'left is set — the second of the two independent guards',
    );
  });

  it('keeps the top corner for the pre-refactor branch that still reads it', () => {
    const attrs = composeButtonAttrs({ bypass_hover_padding_gate: true } as any);
    const padding = bypassPadding(attrs);

    assert.equal(
      padding!.top,
      '0px',
      'top is retained: WooCommerceProductAddToCartModule.php still carries the 5.12.0 ' +
        '$has_desktop_padding shape, and a corner Divi does not read is inert',
    );
  });

  it('writes nothing at all unless the bypass is explicitly opted into', () => {
    for (const input of [{}, { bypass_hover_padding_gate: false }, { bypass_hover_padding_gate: undefined }]) {
      const attrs = composeButtonAttrs(input as any);
      assert.equal(
        bypassPadding(attrs),
        undefined,
        `no padding is written for ${JSON.stringify(input)} — the bypass is opt-in only`,
      );
    }
  });

  it('does not let the bypass clobber a caller-supplied button decoration', () => {
    // The bypass assigns `decoration.button` wholesale. If a future emitter
    // input also writes into that slot, the assignment must not silently drop
    // it — this pins that the bypass is the only writer today, so a later
    // change that adds a second one fails here rather than in a render.
    const attrs = composeButtonAttrs({ bypass_hover_padding_gate: true } as any);
    const button = (attrs as any).button.decoration.button;
    assert.deepEqual(
      Object.keys(button.desktop.value),
      ['padding'],
      'the bypass writes exactly one key into the button decoration slot',
    );
  });
});
