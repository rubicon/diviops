// SPDX-License-Identifier: MIT
import { z } from "zod";

/**
 * The Free/core semantic SEO field allowlist for `diviops_seo_metadata_get`/
 * `diviops_seo_metadata_update`, mirroring the plugin's own allowlist
 * (`DiviOps_SEO_TSF_Adapter::FIELD_KEYS`, `trait-seo.php`) field for field. Kept
 * in its own module (rather than inline in index.ts, which is not structured
 * for isolated import) so the discriminated-union validation behavior below is
 * directly testable — see src/__tests__/seo-schema.test.ts.
 */
export const SEO_FIELD = z.enum([
  "seo_title",
  "meta_description",
  "og_title",
  "og_description",
  "twitter_title",
  "twitter_description",
]);

export const SEO_PROVIDER = z.enum(["auto", "tsf"]);

export const SEO_CHANGE = z.discriminatedUnion("action", [
  z.strictObject({
    field: SEO_FIELD,
    action: z.literal("set"),
    value: z.string(),
  }),
  z.strictObject({
    field: SEO_FIELD,
    action: z.literal("clear"),
  }),
]);

export const SEO_CHANGES = z
  .array(SEO_CHANGE)
  .min(1)
  .max(2)
  .superRefine((changes, context) => {
    const seen = new Set<string>();
    changes.forEach((change, index) => {
      if (seen.has(change.field)) {
        context.addIssue({
          code: "custom",
          message: `Duplicate operation for semantic field '${change.field}'.`,
          path: [index, "field"],
        });
      }
      seen.add(change.field);
    });
  });
