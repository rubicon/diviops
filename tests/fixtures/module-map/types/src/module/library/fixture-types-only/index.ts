// Fixture standing in for @divi/types' src/module/library/<slug>/index.ts.
// Hand-authored in this repository; not copied from the npm package.
//
// A module @divi/types declares that has no per-module PresetAttrsMap at all —
// the population #148's spec counted as "20 divi/* with no module-specific
// source". Recorded as modules_without_preset_map, never silently dropped.
import { type Element } from '../../element';
import { type InternalAttrs } from '../internal';

export interface TypesOnlyFixtureAttrs extends InternalAttrs {
  module?: {
    decoration?: Element.Decoration.PickedAttributes<'spacing'>;
  };
}
