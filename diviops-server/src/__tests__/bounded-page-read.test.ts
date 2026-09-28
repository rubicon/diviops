// SPDX-License-Identifier: MIT
/**
 * The bounded page-read wire contract (#516).
 *
 * Adopted from upstream `oaris-dev/diviops@e1a4d59`. This validator's job is
 * narrow and worth stating: it decides whether a bounded response is coherent
 * enough to hand to a caller, and it forwards NO upstream diagnostics when the
 * answer is no. A malformed or oversized payload must not reach the caller by
 * riding inside an error string, so the "no leakage" assertions below use a
 * recognisable marker in the upstream message and assert it is absent from the
 * output rather than merely checking that a code was mapped.
 *
 * The size-coherence test is the one that earns its keep over time. The chunk
 * width and the text limit are a tuned pair — worst-case JSON escaping is about
 * 7x per byte across both layers, and 4096 * 7 = 28,672 fits under 32 KiB — so
 * raising either number alone silently makes every chunk fail validation. That
 * is a maintenance mistake a human would make while trying to improve
 * throughput, which is exactly the kind a test should hold.
 */
import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import {
  BOUNDED_PAGE_CAPABILITY,
  BOUNDED_PAGE_CHUNK_BYTES,
  BOUNDED_PAGE_ESCAPE_FACTOR,
  BOUNDED_PAGE_TEXT_LIMIT,
  assertBoundedSizesCoherent,
  serializeBoundedPageRead,
} from "../bounded-page-read.js";

const sum = (s: string) => `sha256:${createHash("sha256").update(Buffer.from(s, "utf8")).digest("hex")}`;

/** A well-formed single-call complete chunk for `content`. */
function completeChunk(content: string, id = 4242) {
  const bytes = Buffer.byteLength(content, "utf8");
  return {
    ok: true as const,
    data: {
      id,
      encoding: "utf-8" as const,
      content_raw: content,
      content_checksum: sum(content),
      total_bytes: bytes,
      offset: 0,
      chunk_bytes: bytes,
      next_offset: null,
      complete: true,
    },
  };
}

/** A well-formed non-final first chunk of a longer page. */
function firstChunk(total: number, id = 4242) {
  const content = "a".repeat(BOUNDED_PAGE_CHUNK_BYTES);
  return {
    ok: true as const,
    data: {
      id,
      encoding: "utf-8" as const,
      content_raw: content,
      content_checksum: sum("irrelevant, the walk is not complete"),
      total_bytes: total,
      offset: 0,
      chunk_bytes: BOUNDED_PAGE_CHUNK_BYTES,
      next_offset: BOUNDED_PAGE_CHUNK_BYTES,
      complete: false,
    },
  };
}

const req = (over: Partial<{ page_id: number; offset: number; expected_checksum?: string }> = {}) => ({
  page_id: 4242,
  offset: 0,
  ...over,
});

function decode(text: string) {
  return JSON.parse(text) as { ok: boolean; data?: Record<string, unknown>; error?: { code: string; message: string } };
}

describe("bounded page read: sizes are a coupled pair", () => {
  it("states the invariant and holds it", () => {
    assert.equal(assertBoundedSizesCoherent(), true);
    assert.ok(
      BOUNDED_PAGE_TEXT_LIMIT > BOUNDED_PAGE_CHUNK_BYTES * BOUNDED_PAGE_ESCAPE_FACTOR,
      "the text limit must exceed the chunk size times the worst-case escaping factor; raising either alone fails every chunk",
    );
  });

  it("names the capability key exactly, because a typo removes the feature with no error", () => {
    assert.equal(BOUNDED_PAGE_CAPABILITY, "page_get_bounded_utf8_v1");
  });
});

describe("bounded page read: accepts a coherent chunk", () => {
  it("passes a single-call complete read through and preserves the bytes", () => {
    const body = decode(serializeBoundedPageRead(completeChunk("<!-- wp:divi/text /-->"), req()));
    assert.equal(body.ok, true);
    assert.equal(body.data?.content_raw, "<!-- wp:divi/text /-->");
    assert.equal(body.data?.complete, true);
    assert.equal(body.data?.next_offset, null);
  });

  it("passes a full-width non-final chunk", () => {
    const body = decode(serializeBoundedPageRead(firstChunk(BOUNDED_PAGE_CHUNK_BYTES * 3), req()));
    assert.equal(body.ok, true);
    assert.equal(body.data?.complete, false);
    assert.equal(body.data?.next_offset, BOUNDED_PAGE_CHUNK_BYTES);
  });

  it("accepts a non-final chunk shortened by up to three bytes, which is the UTF-8 walk-back", () => {
    const c = firstChunk(BOUNDED_PAGE_CHUNK_BYTES * 3);
    c.data.chunk_bytes = BOUNDED_PAGE_CHUNK_BYTES - 3;
    c.data.content_raw = "a".repeat(BOUNDED_PAGE_CHUNK_BYTES - 3);
    c.data.next_offset = BOUNDED_PAGE_CHUNK_BYTES - 3;
    assert.equal(decode(serializeBoundedPageRead(c, req())).ok, true);
  });
});

describe("bounded page read: refuses an incoherent chunk", () => {
  const cases: Array<[string, () => unknown, Partial<{ offset: number; expected_checksum?: string }>]> = [
    ["a different page id than was asked for", () => completeChunk("x", 9999), {}],
    ["an offset that is not the one requested", () => {
      const c = completeChunk("x");
      c.data.offset = 5;
      return c;
    }, {}],
    ["chunk_bytes disagreeing with the bytes actually sent", () => {
      const c = completeChunk("xyz");
      c.data.chunk_bytes = 2;
      return c;
    }, {}],
    ["a chunk end past total_bytes", () => {
      const c = completeChunk("xyz");
      c.data.total_bytes = 2;
      return c;
    }, {}],
    ["complete:true while bytes remain", () => {
      const c = completeChunk("xyz");
      c.data.total_bytes = 99;
      return c;
    }, {}],
    ["next_offset set on a complete chunk", () => {
      const c = completeChunk("xyz");
      (c.data as Record<string, unknown>).next_offset = 3;
      return c;
    }, {}],
    ["a short non-final chunk, which would let a plugin dribble bytes and inflate the walk", () => {
      const c = firstChunk(BOUNDED_PAGE_CHUNK_BYTES * 3);
      c.data.chunk_bytes = 10;
      c.data.content_raw = "a".repeat(10);
      c.data.next_offset = 10;
      return c;
    }, {}],
    ["a checksum that does not match what the caller pinned", () => completeChunk("xyz"), {
      offset: 0,
      expected_checksum: sum("something the caller reviewed earlier"),
    }],
    ["an oversized chunk that no amount of escaping room covers", () => {
      const c = completeChunk("x");
      c.data.content_raw = "y".repeat(BOUNDED_PAGE_CHUNK_BYTES + 1);
      return c;
    }, {}],
    ["an unexpected extra field, because the contract is strict", () => {
      const c = completeChunk("xyz") as unknown as { data: Record<string, unknown> };
      c.data.surprise = true;
      return c;
    }, {}],
  ];

  for (const [label, build, over] of cases) {
    it(`refuses ${label}`, () => {
      const body = decode(serializeBoundedPageRead(build(), req(over)));
      assert.equal(body.ok, false);
      assert.equal(body.error?.code, "page.invalid_bounded_response");
      assert.equal(body.data, undefined, "and forwards no content alongside the refusal");
    });
  }

  it("refuses a continuation the caller never pinned", () => {
    const c = firstChunk(BOUNDED_PAGE_CHUNK_BYTES * 3);
    c.data.offset = BOUNDED_PAGE_CHUNK_BYTES;
    c.data.next_offset = BOUNDED_PAGE_CHUNK_BYTES * 2;
    const body = decode(
      serializeBoundedPageRead(c, { page_id: 4242, offset: BOUNDED_PAGE_CHUNK_BYTES }),
    );
    assert.equal(body.error?.code, "page.invalid_bounded_response");
  });

  it("catches a lying checksum on a single-call complete read, by hashing the bytes itself", () => {
    const c = completeChunk("the real bytes");
    c.data.content_checksum = sum("bytes that were never sent");
    const body = decode(serializeBoundedPageRead(c, req()));
    assert.equal(body.error?.code, "page.invalid_bounded_response");
  });

  it("ACCEPTS a full-width chunk of maximally-escaping bytes, which is what the size pair exists to guarantee", () => {
    // Every byte here escapes to `\u0001` (6 bytes) and the outer layer escapes
    // that backslash again (7). This is the worst case the pair was sized for:
    // 4096 * 7 = 28,672 under 32,768. Asserting acceptance pins the sizing
    // empirically rather than restating the arithmetic — if someone raises the
    // chunk width without the limit, this is the test that goes red.
    const nasty = "\u0001".repeat(BOUNDED_PAGE_CHUNK_BYTES);
    const c = completeChunk(nasty);
    const body = decode(serializeBoundedPageRead(c, req()));
    assert.equal(body.ok, true, "worst-case escaping must still fit inside the text limit");

    // And show the headroom is real rather than accidental.
    const text = JSON.stringify(c);
    assert.ok(
      Buffer.byteLength(text, "utf8") < BOUNDED_PAGE_TEXT_LIMIT,
      "the escaped worst case sits under the limit, which is the invariant",
    );
  });

  it("the text-size cap is a backstop a valid chunk cannot trip, and that is deliberate", () => {
    // chunk_bytes is capped at the chunk width and must equal the real byte
    // length, so bytes <= 4096 always holds; times the 7x worst case that is
    // 28,672, under the 32,768 limit. The cap therefore only ever fires on a
    // response that already failed another check, or on a plugin that lies about
    // its own sizes. Stated here so a later reader does not mistake the absence
    // of a refusal case for missing coverage.
    assert.ok(BOUNDED_PAGE_CHUNK_BYTES * BOUNDED_PAGE_ESCAPE_FACTOR < BOUNDED_PAGE_TEXT_LIMIT);
  });
});

describe("bounded page read: forwards no upstream diagnostics", () => {
  const MARKER = "UPSTREAM-INTERNAL-DETAIL-ZQ9";

  for (const code of [
    "not_found",
    "forbidden",
    "invalid_input",
    "page.content_drift",
    "page.invalid_encoding",
  ]) {
    it(`maps ${code} to our own wording and drops the upstream message`, () => {
      const text = serializeBoundedPageRead(
        { ok: false, error: { code, message: `boom ${MARKER}`, data: { secret: MARKER } } },
        req(),
      );
      const body = decode(text);
      assert.equal(body.error?.code, code, "the code is preserved so a caller can branch on it");
      assert.ok(!text.includes(MARKER), "but no upstream text reaches the caller");
      assert.ok((body.error?.message ?? "").length > 0, "and our own message is non-empty");
    });
  }

  it("collapses an unrecognised code rather than passing it through", () => {
    const text = serializeBoundedPageRead(
      { ok: false, error: { code: "page.some_future_code", message: MARKER } },
      req(),
    );
    const body = decode(text);
    assert.equal(body.error?.code, "page.bounded_read_failed");
    assert.ok(!text.includes(MARKER));
  });

  it("control: the marker really would have been visible if it were forwarded", () => {
    // Guards the three assertions above from passing because the marker could
    // never have appeared in the output for an unrelated reason.
    assert.ok(JSON.stringify({ message: `boom ${MARKER}` }).includes(MARKER));
  });
});
