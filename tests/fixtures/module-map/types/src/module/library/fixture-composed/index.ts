// Fixture standing in for @divi/types' src/module/library/<slug>/index.ts.
// Hand-authored in this repository; not copied from the npm package.
//
// Declares `button` but NOT `title`, while the matching PresetAttrsMap fixture
// produces `title.decoration.fixtureFamily__*` paths. That mismatch is the
// element_absent_from_types disagreement this artifact exists to record.
import { type Element } from '../../element';
import { type InternalAttrs } from '../internal';

export interface ComposedFixtureAttrs extends InternalAttrs {
  css?: Css.Attributes;

  module?: {
    decoration?: Element.Decoration.PickedAttributes<'spacing'>;
  };

  button?: {
    decoration?: Element.Decoration.PickedAttributes<'button'>;
  };
}
