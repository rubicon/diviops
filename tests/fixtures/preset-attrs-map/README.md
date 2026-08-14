# Synthetic `PresetAttrsMap` fixtures

Hand-authored stand-ins for Divi's `<Module>PresetAttrsMap.php` classes, used by
`tests/test-preset-attrs-map-extractor.php`. They mirror the *shape* Divi's own files
use (namespace, `ABSPATH` guard, `public static function get_map( array $map, string
$module_name )`, an early return for a non-matching module name, an optional
`$keys_to_unset` loop, an `array_merge()` of additions) so the resolver exercises the
same code paths it will meet on a real install.

No Divi source is copied here. The only material taken from Divi is dot-path *strings*
in `CtaShapedFixture`, which are attribute identifiers rather than code, reproduced so a
regression test can assert that paths CTA's own `get_map()` unsets never reach the
output.

The directory is laid out as a `Packages/` root because that is what the resolver takes:

```
Packages/
  Module/Options/FixtureFamily/FixtureFamilyPresetAttrsMap.php   shared family map
  ModuleLibrary/<Name>Fixture/<Name>FixturePresetAttrsMap.php     per-module maps
```

Fixture namespaces are `DiviOps\Fixtures\Packages\…`, not `ET\Builder\Packages\…`, so a
fixture can never be mistaken for Divi code. The resolver's fallback autoloader maps any
namespace on the segment `\Packages\`, which covers both.
