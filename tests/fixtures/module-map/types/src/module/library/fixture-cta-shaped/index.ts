// Fixture standing in for @divi/types' src/module/library/<slug>/index.ts.
// Hand-authored in this repository; not copied from the npm package.
//
// Exercises: an inline PickedAttributes<> union spread over several lines, a
// second interface in the same file that must NOT be mistaken for the module's
// (only the one extending InternalAttrs counts), a reference-only element whose
// decoration groups live behind a type name, and a non-PickedAttributes generic
// carrying string literals that must not be read as decoration groups.
import { type Element } from '../../element';
import { type InternalAttrs } from '../internal';

// Extends something other than InternalAttrs, the way @divi/types' own helper
// interfaces do (blurb's `BlurbCssAttr extends Css.AttributeValue`). A locator that
// accepted any `extends` would find this one too.
export interface CtaShapedFixtureCssAttr extends Css.AttributeValue {
  decoration?: Element.Decoration.PickedAttributes<'neverMine'>;
}

export interface CtaShapedFixtureAttrs extends InternalAttrs {
  css?: Css.Attributes;

  module?: {
    meta?: Element.Meta.Attributes;
    decoration?: Element.Decoration.PickedAttributes<
      | 'animation'
      | 'spacing'
      | 'zIndex'
    >;
  };

  button?: Element.Types.Button.Attributes & {
    decoration?: Element.Decoration.PickedAttributes<'button' | 'sizing'>;
  };

  title?: Element.Types.TitleLink.Attributes;

  contentContainer?: Element.Types.Wrapper.Attributes<'sizing'>;
}
