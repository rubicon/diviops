// SPDX-License-Identifier: MIT
/**
 * Server-local artifact store for page exports (#382).
 *
 * `GET /diviops/v1/page/export/<id>` returns Divi's own portability artifact —
 * the schema the Visual Builder's Export button produces — and that artifact
 * embeds every image as base64: local attachments are read off disk and
 * `base64_encode`d, remote ones are fetched and base64'd too. A photo-heavy page
 * is megabytes. Handing that to the calling agent by default would spend its
 * entire context on bytes it cannot read and does not want, so
 * `diviops_page_export` writes the artifact here and returns a reference.
 *
 * Modelled on `cross-env-preflight/source-payload-ref.ts` — handle + sha256 +
 * TTL + mode 0600 + a handle pattern that rejects traversal — deliberately,
 * rather than accepting a caller-supplied destination path. A caller-supplied
 * path would add arbitrary-filesystem-write to this server's tool surface; the
 * handle scheme already exists and already solved that.
 *
 * Four deliberate differences from that sibling:
 *
 *   1. **Its own root.** `.diviops-tmp/page-exports/`, never the cross-env
 *      directory. The prune below deletes files, and a store that deletes must
 *      not be able to reach another store's artifacts.
 *   2. **The file is the raw artifact, not an envelope.** The sibling's file is
 *      re-loaded by this same server, so it wraps the payload in a typed
 *      envelope carrying its own expiry. Nothing loads this one back — it exists
 *      so a human or a VB Import can consume it verbatim. Wrapping it would
 *      force every consumer to unwrap first, and would make "the bytes on disk
 *      are the bytes that were hashed" false. The version marker moves to the
 *      returned ref (`format`), where the caller actually reads it.
 *   3. **Expiry is enforced by pruning, not at load.** With no loader there is
 *      no load-time TTL check, so an `expires_at` nobody acts on would be a
 *      promise the server does not keep. Each write sweeps its own root first.
 *   4. **TTL default stays 24h**, matching cross-env. These are scratch
 *      artifacts measured in megabytes each; a longer default trades disk for a
 *      convenience a caller can buy back with the env var below.
 *
 * Overrides: `DIVIOPS_PAGE_EXPORT_REF_DIR`,
 * `DIVIOPS_PAGE_EXPORT_REF_TTL_SECONDS` — named to match the existing
 * `DIVIOPS_CROSS_ENV_PAYLOAD_REF_*` pair.
 */
import { createHash, randomUUID } from "node:crypto";
import { mkdirSync, readdirSync, statSync, unlinkSync, writeFileSync } from "node:fs";
import { join } from "node:path";

/** Reference handed back to the caller in place of the artifact bytes. */
export interface PageExportRef {
  handle: string;
  checksum: string;
  algorithm: "sha256";
  storage: "server_local_artifact";
  /** What the bytes at `handle` are, so a consumer need not guess. */
  format: "diviops.page_export.artifact.v1";
  expires_at?: string;
}

/**
 * There is no cross-language hashing contract, and that is deliberate.
 *
 * The plugin returns `data.artifact_json` — a STRING holding the exact bytes it
 * hashed — rather than an object. This side never re-serialises it: it hashes
 * those bytes, compares, and writes those same bytes. Only one side ever
 * encodes, so there is nothing for the two encoders to disagree about.
 *
 * That matters because the obvious design fails in two independent ways. PHP's
 * `json_encode` at default flags escapes `/` as `\/` and every non-ASCII
 * character as `\uXXXX`; `JSON.stringify` escapes neither, so on an artifact
 * made mostly of URLs the two digests never agree and every export refuses. And
 * `artifact.data` is keyed by post id, which `JSON.parse` reorders into
 * ascending numeric order while PHP preserves insertion order — so even a
 * matched encoder pair drifts as soon as more than one id is present.
 *
 * `createHash().update(string)` defaults to utf8, which is the same bytes the
 * plugin measured, so `Buffer.byteLength` is not needed for the digest.
 */
function assertArtifactJson(value: unknown): string {
  if (typeof value !== "string" || value.length === 0) {
    throw new Error(
      "page export response is missing data.artifact_json, or it is not a string. The plugin returns the artifact as the exact bytes it hashed; an object here means the two halves are out of step.",
    );
  }
  return value;
}

function sha256(value: string): string {
  return createHash("sha256").update(value, "utf8").digest("hex");
}

const DEFAULT_TTL_SECONDS = 24 * 60 * 60;
const MAX_TTL_SECONDS = 10 * 365 * 24 * 60 * 60;

/**
 * Handles this store mints, and the only shape it will resolve to a path. The
 * `pe-` prefix is load-bearing for the prune below: it is what keeps the sweep
 * to files this store wrote, even if the root is pointed at a shared directory.
 */
const HANDLE_PATTERN = /^pe-[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/;

function exportRoot(): string {
  return (
    process.env.DIVIOPS_PAGE_EXPORT_REF_DIR ||
    join(process.cwd(), ".diviops-tmp", "page-exports")
  );
}

function ttlSeconds(): number {
  const raw = process.env.DIVIOPS_PAGE_EXPORT_REF_TTL_SECONDS;
  if (!raw) return DEFAULT_TTL_SECONDS;
  const parsed = Number(raw);
  if (!Number.isFinite(parsed) || parsed < 0 || parsed > MAX_TTL_SECONDS) {
    return DEFAULT_TTL_SECONDS;
  }
  return Math.floor(parsed);
}

function normalizeChecksum(value: unknown): string {
  if (typeof value !== "string") return "";
  return value.trim().replace(/^sha256:/i, "").toLowerCase();
}

/**
 * Reject anything that is not a handle this store minted. `..` is checked
 * separately from the pattern because a reader should be able to see the
 * traversal refusal without first proving the pattern excludes it.
 */
export function assertPageExportHandle(handle: string): void {
  if (!HANDLE_PATTERN.test(handle) || handle.includes("..")) {
    throw new Error("page_export artifact_ref.handle is invalid.");
  }
}

export function pageExportPath(handle: string): string {
  assertPageExportHandle(handle);
  return join(exportRoot(), `${handle}.json`);
}

/**
 * Delete this store's own expired artifacts. Best-effort and never fatal: a
 * failed sweep must not cost the caller the export they asked for.
 *
 * ponytail: no locking, so two concurrent exports can race on the same unlink.
 * `ENOENT` from the loser is swallowed with everything else; add a lock only if
 * this ever runs somewhere that cares.
 */
function prune(root: string, ttl: number): void {
  if (ttl <= 0) return;
  const cutoff = Date.now() - ttl * 1000;
  let entries: string[];
  try {
    entries = readdirSync(root);
  } catch {
    return;
  }
  for (const entry of entries) {
    if (!entry.startsWith("pe-") || !entry.endsWith(".json")) continue;
    if (!HANDLE_PATTERN.test(entry.slice(0, -".json".length))) continue;
    try {
      if (statSync(join(root, entry)).mtimeMs < cutoff) unlinkSync(join(root, entry));
    } catch {
      // Swept next time.
    }
  }
}

function handleFor(pageId: number, checksum: string): string {
  const slug = String(pageId).replace(/[^A-Za-z0-9._-]+/g, "-").slice(0, 32) || "page";
  return `pe-${slug}-${checksum.slice(0, 16)}-${randomUUID().slice(0, 8)}`;
}

/** What a checksum refusal carries, so the two sides can be compared directly. */
export interface PageExportChecksumMismatch {
  declared: string;
  computed: string;
  declared_byte_length: unknown;
  computed_byte_length: number;
}

export class PageExportChecksumError extends Error {
  public readonly mismatch: PageExportChecksumMismatch;

  constructor(mismatch: PageExportChecksumMismatch) {
    super(
      `page export artifact checksum does not match the manifest (declared ${mismatch.declared || "<missing>"}, computed ${mismatch.computed}).`,
    );
    this.name = "PageExportChecksumError";
    this.mismatch = mismatch;
  }
}

/**
 * Verify the artifact against the manifest's declared digest, then write it.
 *
 * Order matters and is the whole point: a mismatch throws BEFORE `mkdirSync` or
 * `writeFileSync` runs, so a refusal never leaves a half-written or wrong-bytes
 * artifact behind for someone to import later. `source-payload-ref.ts` does the
 * same thing for the same reason.
 */
export function createPageExportRef(
  pageId: number,
  artifactJson: unknown,
  declaredChecksum: unknown,
  declaredByteLength?: unknown,
): PageExportRef {
  const json = assertArtifactJson(artifactJson);
  const computed = sha256(json);
  const declared = normalizeChecksum(declaredChecksum);
  if (declared !== computed) {
    throw new PageExportChecksumError({
      declared,
      computed,
      declared_byte_length: declaredByteLength,
      computed_byte_length: Buffer.byteLength(json, "utf8"),
    });
  }

  const ttl = ttlSeconds();
  const expiresAt = ttl > 0 ? new Date(Date.now() + ttl * 1000).toISOString() : null;
  const handle = handleFor(pageId, computed);
  const root = exportRoot();

  prune(root, ttl);
  mkdirSync(root, { recursive: true });
  writeFileSync(join(root, `${handle}.json`), json, { encoding: "utf8", mode: 0o600 });

  return {
    handle,
    checksum: computed,
    algorithm: "sha256",
    storage: "server_local_artifact",
    format: "diviops.page_export.artifact.v1",
    ...(expiresAt ? { expires_at: expiresAt } : {}),
  };
}
