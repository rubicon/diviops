/**
 * Tests for seo-schema.ts — the Zod validation gate in front of
 * `diviops_seo_metadata_update`'s `changes` array (#37).
 *
 * This is the SERVER-side allowlist enforcement, separate from the plugin's own
 * `DiviOps_SEO_TSF_Adapter::FIELD_KEYS`/`seo_fields()` (trait-seo.php,
 * tests/test-seo.php). Both must agree on the same six field names, or a caller
 * would be rejected by one layer while the other layer would have accepted it —
 * these tests exist so extending the plugin's allowlist without extending this
 * one (or a typo between the two) fails loudly here rather than silently at the
 * MCP boundary.
 */
import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { SEO_CHANGES, SEO_FIELD, SEO_PROVIDER } from "../seo-schema.js";

describe("SEO_FIELD", () => {
  it("accepts exactly the six semantic fields the plugin allowlists", () => {
    const allowed = [
      "seo_title",
      "meta_description",
      "og_title",
      "og_description",
      "twitter_title",
      "twitter_description",
    ];
    for (const field of allowed) {
      assert.equal(SEO_FIELD.parse(field), field);
    }
  });

  it("rejects a raw provider metadata key", () => {
    assert.throws(() => SEO_FIELD.parse("_open_graph_title"));
  });

  it("rejects a field outside the allowlist", () => {
    assert.throws(() => SEO_FIELD.parse("canonical"));
  });
});

describe("SEO_PROVIDER", () => {
  it("accepts auto and tsf", () => {
    assert.equal(SEO_PROVIDER.parse("auto"), "auto");
    assert.equal(SEO_PROVIDER.parse("tsf"), "tsf");
  });

  it("rejects an unrecognized provider", () => {
    assert.throws(() => SEO_PROVIDER.parse("yoast"));
  });
});

describe("SEO_CHANGES", () => {
  it("accepts a single set operation on a new (og/twitter) field", () => {
    const parsed = SEO_CHANGES.parse([
      { field: "og_title", action: "set", value: "New OG Title" },
    ]);
    assert.deepEqual(parsed, [{ field: "og_title", action: "set", value: "New OG Title" }]);
  });

  it("accepts a clear operation with no value property", () => {
    const parsed = SEO_CHANGES.parse([{ field: "twitter_description", action: "clear" }]);
    assert.deepEqual(parsed, [{ field: "twitter_description", action: "clear" }]);
  });

  it("rejects a set operation missing its value", () => {
    assert.throws(() => SEO_CHANGES.parse([{ field: "og_title", action: "set" }]));
  });

  it("rejects a clear operation carrying a value (strict object, unknown key)", () => {
    assert.throws(() =>
      SEO_CHANGES.parse([{ field: "og_title", action: "clear", value: "x" }]),
    );
  });

  it("rejects an empty changes list", () => {
    assert.throws(() => SEO_CHANGES.parse([]));
  });

  it("rejects more than two changes", () => {
    assert.throws(() =>
      SEO_CHANGES.parse([
        { field: "og_title", action: "clear" },
        { field: "og_description", action: "clear" },
        { field: "twitter_title", action: "clear" },
      ]),
    );
  });

  it("rejects two operations naming the same field (duplicate detection)", () => {
    assert.throws(() =>
      SEO_CHANGES.parse([
        { field: "og_title", action: "set", value: "a" },
        { field: "og_title", action: "clear" },
      ]),
    );
  });

  it("accepts two operations across different fields, old and new mixed", () => {
    const parsed = SEO_CHANGES.parse([
      { field: "seo_title", action: "set", value: "Title" },
      { field: "og_title", action: "set", value: "OG Title" },
    ]);
    assert.equal(parsed.length, 2);
  });

  it("rejects a field outside the allowlist inside a changes entry", () => {
    assert.throws(() => SEO_CHANGES.parse([{ field: "canonical", action: "set", value: "x" }]));
  });
});
