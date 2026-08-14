# Synthetic `PresetAttrsMap` fixtures

Hand-authored stand-ins for Divi's `PresetAttrsMap.php` classes, used by
`tests/test-preset-attrs-map-extractor.php` (per-module maps) and
`tests/test-shared-preset-attrs-map.php` (shared family maps). They mirror the *shape*
Divi's own files use so the resolvers exercise the same code paths they will meet on a
real install.

Per-module fixtures follow `public static function get_map( array $map, string
$module_name )`, with an early return for a non-matching module name, an optional
`$keys_to_unset` loop, and an `array_merge()` of additions.

Family fixtures follow the two shapes Divi's own families come in: `get_map( string
$attr_name )`, which keys its whole return on the element prefix it is handed, and the
argument-less `get_map()` that returns absolute paths.

No Divi source is copied here. The only material taken from Divi is dot-path *strings*
in `CtaShapedFixture`, which are attribute identifiers rather than code, reproduced so a
regression test can assert that paths CTA's own `get_map()` unsets never reach the
output.

The directory is laid out as a `Packages/` root because that is what the resolver takes:

```
Packages/
  Module/Options/FixtureFamily/…         family keyed on the prefix it is handed
  Module/Options/FixtureComposite/…      family that delegates to FixtureFamily twice
  Module/Options/FixtureAbsolute/…       family taking no prefix, absolute paths
  Module/Options/FixtureEmptyFamily/…    family returning nothing, for the guard
  ModuleLibrary/<Name>Fixture/…          per-module maps
```

`FixtureComposite` is the one that earns its keep: like Divi's own `Button` family, its
delegated keys appear nowhere in its own source, so a text scan of the file misses them
and only running `get_map()` finds them. `FixtureEmptyFamily` has no counterpart in Divi
at all — every family Divi ships contributes at least one key — and exists solely to
assert that an empty resolution fails rather than being reported as a result.

Fixture namespaces are `DiviOps\Fixtures\Packages\…`, not `ET\Builder\Packages\…`, so a
fixture can never be mistaken for Divi code. The resolver's fallback autoloader maps any
namespace on the segment `\Packages\`, which covers both.
