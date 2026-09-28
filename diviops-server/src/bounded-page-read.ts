// SPDX-License-Identifier: MIT
/**
 * Wire-contract validation for optional bounded page reads (#516).
 *
 * Adopted from upstream `oaris-dev/diviops@e1a4d59` (v1.5.66), reshaped in two
 * ways and otherwise deliberately identical so a client written against either
 * plugin behaves the same:
 *
 *   1. The chunk width is a named constant instead of the literal 4096 repeated
 *      in four places. It is coupled to BOUNDED_PAGE_TEXT_LIMIT and raising one
 *      alone makes every chunk fail validation here, so the number that must
 *      move together is written once. `assertBoundedSizesCoherent()` states the
 *      invariant and the test suites on both sides assert it.
 *   2. `z.strictObject` rather than the deprecated `.strict()`, this tree being
 *      on zod 4.
 *
 * Everything else is upstream's, including the choice to forward no upstream
 * diagnostics on failure: a bounded read that goes wrong returns this module's
 * own message, never the plugin's, so a malformed or oversized payload cannot
 * reach the caller by riding inside an error string.
 */
import { createHash } from "node:crypto";
import { z } from "zod";
import { serializeEnvelope } from "./envelope.js";

export const BOUNDED_PAGE_CAPABILITY = "page_get_bounded_utf8_v1";

/**
 * Raw bytes the plugin puts in one chunk. Must equal the plugin's
 * `page_bounded_chunk_bytes()`; a disagreement fails every chunk.
 */
export const BOUNDED_PAGE_CHUNK_BYTES = 4096;

/**
 * Ceiling on the serialized text block, measured through both JSON escaping
 * layers. Worst-case escaping is about 7x per byte — a control byte becomes
 * `\u0001`, and the outer layer escapes that backslash again — so this has to
 * stay above BOUNDED_PAGE_CHUNK_BYTES * 7 plus MCP metadata.
 */
export const BOUNDED_PAGE_TEXT_LIMIT = 32 * 1024;

/** Worst-case bytes one raw byte can occupy after both escaping layers. */
export const BOUNDED_PAGE_ESCAPE_FACTOR = 7;

/**
 * The coupling between the two sizes, as a checkable statement.
 *
 * Exported rather than asserted at import time: a module that throws while
 * loading takes the whole server down, and the thing being guarded is a
 * maintenance mistake, which a test catches before it ships.
 */
export function assertBoundedSizesCoherent(): boolean {
  return BOUNDED_PAGE_TEXT_LIMIT > BOUNDED_PAGE_CHUNK_BYTES * BOUNDED_PAGE_ESCAPE_FACTOR;
}

const TOOL = "diviops_page_get";
const checksum = z.string().regex(/^sha256:[a-f0-9]{64}$/);
const byteCount = z.number().int().min(0).max(Number.MAX_SAFE_INTEGER);

const chunkEnvelope = z.strictObject({
  ok: z.literal(true),
  data: z.strictObject({
    id: z.number().int().positive().max(Number.MAX_SAFE_INTEGER),
    encoding: z.literal("utf-8"),
    content_raw: z.string().max(BOUNDED_PAGE_CHUNK_BYTES),
    content_checksum: checksum,
    total_bytes: byteCount,
    offset: byteCount,
    chunk_bytes: byteCount.max(BOUNDED_PAGE_CHUNK_BYTES),
    next_offset: byteCount.nullable(),
    complete: z.boolean(),
  }),
});

export function boundedPageError(code: string, message: string): string {
  return serializeEnvelope({ ok: false, error: { code, message } }, TOOL);
}

/**
 * The refusal codes this path may pass through, each with our own wording.
 * A code absent from this map becomes the generic failure below, so a new
 * upstream code cannot leak its message text to the caller.
 */
const errors: Record<string, string> = {
  not_found: "Page not found.",
  forbidden: "Page read permission denied.",
  invalid_input:
    "Invalid bounded read parameters; use the returned byte offset and checksum.",
  "page.content_drift":
    "Page content changed; discard prior chunks and restart at offset zero.",
  "page.invalid_encoding":
    "Page content is not valid UTF-8; no bytes were returned.",
};

/** Validate only this optional wire contract; never forward upstream diagnostics. */
export function serializeBoundedPageRead(
  result: unknown,
  request: { page_id: number; offset: number; expected_checksum?: string },
): string {
  const invalid = () =>
    boundedPageError(
      "page.invalid_bounded_response",
      "Plugin returned an invalid or oversized bounded page response; no content was forwarded.",
    );

  if (typeof result === "object" && result !== null && "ok" in result && result.ok === false) {
    const error = "error" in result ? result.error : null;
    const code =
      typeof error === "object" && error !== null && "code" in error ? error.code : null;
    if (typeof code === "string" && Object.hasOwn(errors, code)) {
      return boundedPageError(code, errors[code]);
    }
    return boundedPageError(
      "page.bounded_read_failed",
      "Bounded page read failed; no upstream payload was forwarded.",
    );
  }

  const parsed = chunkEnvelope.safeParse(result);
  if (!parsed.success) return invalid();

  const data = parsed.data.data;
  const bytes = Buffer.from(data.content_raw, "utf8");
  const end = data.offset + data.chunk_bytes;

  if (
    data.id !== request.page_id ||
    data.offset !== request.offset ||
    bytes.toString("utf8") !== data.content_raw ||
    bytes.length !== data.chunk_bytes ||
    !Number.isSafeInteger(end) ||
    end > data.total_bytes ||
    data.complete !== (end === data.total_bytes) ||
    data.next_offset !== (data.complete ? null : end) ||
    // A non-final chunk must be full width, less at most the three bytes the
    // plugin walks back off a UTF-8 continuation byte. Without this a plugin
    // could dribble one byte per call and inflate the walk without limit.
    (!data.complete && data.chunk_bytes < BOUNDED_PAGE_CHUNK_BYTES - 3) ||
    (request.offset > 0 && request.expected_checksum === undefined) ||
    (request.expected_checksum !== undefined &&
      data.content_checksum !== request.expected_checksum)
  ) {
    return invalid();
  }

  // Only a single-call complete read lets us verify the digest ourselves; past
  // that the checksum covers content we have not seen in full.
  if (
    data.offset === 0 &&
    data.complete &&
    data.content_checksum !== `sha256:${createHash("sha256").update(bytes).digest("hex")}`
  ) {
    return invalid();
  }

  const text = serializeEnvelope(parsed.data, TOOL);
  // Measure the surrounding text block too, including its second escaping layer.
  if (
    Buffer.byteLength(text, "utf8") > BOUNDED_PAGE_TEXT_LIMIT ||
    Buffer.byteLength(JSON.stringify({ content: [{ type: "text", text }] }), "utf8") >
      BOUNDED_PAGE_TEXT_LIMIT
  ) {
    return invalid();
  }
  return text;
}
