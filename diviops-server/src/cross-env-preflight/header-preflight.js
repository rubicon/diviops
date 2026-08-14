import { createHash } from "node:crypto";
const SUPPORTED_KIND = "tb_header_layout";
const UPLOADS_PREFIX = "/wp-content/uploads/";
const ABSOLUTE_URL_RE = /https?:\/\/[^\s"'<>\\)]+/g;
const GCID_RE = /\bgcid-[A-Za-z0-9_-]+\b/g;
const OFF_CANVAS_PATTERNS = [
    { re: /\bcanvas-portal\b/gi, reason: "canvas portal reference" },
    { re: /\boff[-_]?canvas\b/gi, reason: "off-canvas reference" },
    {
        re: /"_divi_off_canvas_data"/gi,
        reason: "_divi_off_canvas_data meta reference",
    },
    {
        re: /"_divi_dynamic_assets_canvases_used"/gi,
        reason: "_divi_dynamic_assets_canvases_used meta reference",
    },
    {
        re: /"(canvasId|canvas_id|canvasUUID|canvas_uuid)"\s*:/gi,
        reason: "canvas UUID attribute",
    },
];
const SECRET_KEY_RE = /(app[_-]?password|password|secret|token|nonce|cookie|authorization|x[_-]?wp[_-]?nonce|x[_-]?et[_-]?nonce)/i;
const DEFAULT_BUILTIN_CUSTOMIZER_COLOR_IDS = new Set([
    "gcid-primary-color",
    "gcid-secondary-color",
    "gcid-heading-color",
    "gcid-body-color",
    "gcid-link-color",
]);
function decodeMarkup(markup) {
    return markup.replace(/\\u([0-9a-fA-F]{4})/g, (_match, hex) => String.fromCharCode(Number.parseInt(hex, 16)));
}
function sha256(value) {
    return createHash("sha256").update(value).digest("hex");
}
function normalizeChecksum(checksum) {
    if (!checksum)
        return undefined;
    return checksum.trim().replace(/^sha256:/i, "").toLowerCase();
}
function isSha256Hex(value) {
    return /^[a-f0-9]{64}$/.test(value);
}
function normalizeDestinationChecksum(checksum) {
    if (checksum === undefined || checksum === null)
        return undefined;
    if (typeof checksum === "string") {
        const computed = normalizeChecksum(checksum);
        if (!computed)
            return undefined;
        return { algorithm: "sha256", computed };
    }
    if (typeof checksum !== "object")
        return undefined;
    const algorithm = typeof checksum.algorithm === "string" ? checksum.algorithm.toLowerCase() : "";
    const computed = normalizeChecksum(checksum.computed);
    if (algorithm !== "sha256" || !computed)
        return undefined;
    return {
        algorithm: "sha256",
        computed,
        input: checksum.input === "post_content" ? "post_content" : undefined,
    };
}
function normalizeOrigin(origin, field) {
    try {
        const url = new URL(origin);
        return new URL(url.origin);
    }
    catch {
        throw new Error(`${field} must be an absolute origin URL.`);
    }
}
function safeUrlForReport(parsed, origin = parsed.origin) {
    const query = parsed.search ? "?[redacted]" : "";
    const hash = parsed.hash ? "#[redacted]" : "";
    return `${origin}${parsed.pathname}${query}${hash}`;
}
function uniqueSorted(values) {
    return [...new Set(values)].sort((a, b) => a.localeCompare(b));
}
function collectIds(value) {
    if (value === undefined)
        return null;
    const ids = new Set();
    const walk = (node) => {
        if (typeof node === "string") {
            if (node.startsWith("gcid-"))
                ids.add(node);
            return;
        }
        if (Array.isArray(node)) {
            for (const item of node)
                walk(item);
            return;
        }
        if (node && typeof node === "object") {
            const record = node;
            for (const [key, nested] of Object.entries(record)) {
                if (key.startsWith("gcid-"))
                    ids.add(key);
                if ((key === "id" || key === "name") &&
                    typeof nested === "string" &&
                    nested.startsWith("gcid-")) {
                    ids.add(nested);
                }
                walk(nested);
            }
        }
    };
    walk(value);
    return ids;
}
function isGcid(value) {
    return value.startsWith("gcid-");
}
function compactEvidenceSource(value) {
    if (typeof value !== "string")
        return undefined;
    const normalized = value.trim().toLowerCase();
    return /^[a-z0-9_.:-]+$/.test(normalized) ? normalized : undefined;
}
function normalizeEvidenceDigest(value) {
    if (!value || typeof value !== "object")
        return undefined;
    const record = value;
    const algorithm = typeof record.algorithm === "string" ? record.algorithm.toLowerCase() : "";
    const computed = normalizeChecksum(typeof record.computed === "string" ? record.computed : undefined);
    if (algorithm !== "sha256" || !computed || !isSha256Hex(computed))
        return undefined;
    return {
        algorithm: "sha256",
        input: compactEvidenceSource(record.input) ?? "resolved_color",
        computed,
    };
}
function normalizeGlobalColorEvidenceRecord(id, record, fallbackSource) {
    if (!id || !isGcid(id) || !record || typeof record !== "object")
        return undefined;
    const candidate = record;
    const digest = normalizeEvidenceDigest(candidate.digest);
    const color = typeof candidate.color === "string" ? candidate.color : undefined;
    const value = typeof candidate.value === "string" ? candidate.value : color;
    const computedDigest = digest ??
        (value
            ? {
                algorithm: "sha256",
                input: "resolved_color",
                computed: sha256(value),
            }
            : undefined);
    if (!computedDigest)
        return undefined;
    return {
        id,
        source: compactEvidenceSource(candidate.source) ?? fallbackSource,
        digest: computedDigest,
    };
}
function collectGlobalColorValueEvidence(target) {
    const evidence = new Map();
    const add = (item) => {
        if (item && !evidence.has(item.id))
            evidence.set(item.id, item);
    };
    const explicit = target.global_color_value_evidence;
    if (Array.isArray(explicit)) {
        for (const item of explicit) {
            const id = item && typeof item === "object" && typeof item.id === "string"
                ? item.id
                : undefined;
            add(normalizeGlobalColorEvidenceRecord(id, item));
        }
    }
    else if (explicit && typeof explicit === "object") {
        for (const [key, item] of Object.entries(explicit)) {
            const recordId = item && typeof item === "object" && typeof item.id === "string"
                ? item.id
                : undefined;
            add(normalizeGlobalColorEvidenceRecord(recordId ?? key, item));
        }
    }
    const colors = target.global_colors;
    if (Array.isArray(colors)) {
        for (const item of colors) {
            if (!item || typeof item !== "object")
                continue;
            const record = item;
            const id = typeof record.id === "string"
                ? record.id
                : typeof record.name === "string"
                    ? record.name
                    : undefined;
            add(normalizeGlobalColorEvidenceRecord(id, record, "global_colors"));
        }
    }
    else if (colors && typeof colors === "object") {
        for (const [key, item] of Object.entries(colors)) {
            add(normalizeGlobalColorEvidenceRecord(key, item, "global_colors"));
        }
    }
    return evidence;
}
function collectSecretLikeKeys(value, path = "$") {
    if (!value || typeof value !== "object")
        return [];
    if (Array.isArray(value)) {
        return value.flatMap((item, index) => collectSecretLikeKeys(item, `${path}[${index}]`));
    }
    const hits = [];
    for (const [key, nested] of Object.entries(value)) {
        const nextPath = `${path}.${key}`;
        if (SECRET_KEY_RE.test(key))
            hits.push(nextPath);
        hits.push(...collectSecretLikeKeys(nested, nextPath));
    }
    return hits;
}
function canonicalize(value) {
    if (Array.isArray(value))
        return value.map(canonicalize);
    if (!value || typeof value !== "object")
        return value;
    return Object.fromEntries(Object.entries(value)
        .filter(([, nested]) => nested !== undefined)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([key, nested]) => [key, canonicalize(nested)]));
}
function canonicalJson(value) {
    return JSON.stringify(canonicalize(value));
}
function findUrls(markup, sourceOrigin, targetOrigin) {
    const decoded = decodeMarkup(markup);
    const hits = [];
    const seen = new Set();
    let match;
    ABSOLUTE_URL_RE.lastIndex = 0;
    while ((match = ABSOLUTE_URL_RE.exec(decoded)) !== null) {
        let parsed;
        try {
            parsed = new URL(match[0]);
        }
        catch {
            continue;
        }
        const key = parsed.href;
        if (seen.has(key))
            continue;
        seen.add(key);
        const sameSourceHost = parsed.host === sourceOrigin.host;
        const sameTargetHost = parsed.host === targetOrigin.host;
        const isUpload = parsed.pathname.startsWith(UPLOADS_PREFIX);
        let classification;
        let rewriteTo;
        if (sameSourceHost && isUpload) {
            classification = "source_upload_rewrite";
            rewriteTo = safeUrlForReport(parsed, targetOrigin.origin);
        }
        else if (sameSourceHost) {
            classification = "source_non_upload_operator_review";
        }
        else if (sameTargetHost) {
            classification = "target_origin";
        }
        else {
            classification = "third_party_no_rewrite";
        }
        hits.push({
            url: safeUrlForReport(parsed),
            host: parsed.host,
            path: parsed.pathname,
            query_redacted: parsed.search ? true : undefined,
            hash_redacted: parsed.hash ? true : undefined,
            credentials_redacted: parsed.username || parsed.password ? true : undefined,
            classification,
            rewrite_to: rewriteTo,
        });
    }
    return hits;
}
function findAttachmentRefs(markup) {
    const decoded = decodeMarkup(markup);
    const refs = new Map();
    const addRef = (property, sourceId) => {
        if (!Number.isFinite(sourceId) || sourceId <= 0)
            return;
        refs.set(`${property}:${sourceId}`, { property, source_id: sourceId });
    };
    collectAttachmentRefsFromDiviAttrs(decoded, addRef);
    return [...refs.values()].sort((a, b) => a.source_id - b.source_id);
}
function collectAttachmentRefsFromDiviAttrs(markup, addRef) {
    const blockRe = /<!--\s+(?:\/)?wp:([A-Za-z0-9_-]+\/[A-Za-z0-9_-]+)(.*?)(?:\/)?-->/gs;
    let match;
    while ((match = blockRe.exec(markup)) !== null) {
        const blockName = match[1];
        if (!blockName.startsWith("divi/"))
            continue;
        const attrsText = jsonObjectFromBlockCommentTail(match[2] ?? "");
        if (!attrsText)
            continue;
        try {
            collectAttachmentRefsFromValue(JSON.parse(attrsText), "", false, addRef);
        }
        catch {
            continue;
        }
    }
}
function jsonObjectFromBlockCommentTail(tail) {
    const start = tail.indexOf("{");
    const end = tail.lastIndexOf("}");
    if (start < 0 || end < start)
        return undefined;
    return tail.slice(start, end + 1);
}
function collectAttachmentRefsFromValue(value, parentKey, inMediaContext, addRef) {
    if (Array.isArray(value)) {
        for (const nested of value) {
            collectAttachmentRefsFromValue(nested, parentKey, inMediaContext, addRef);
        }
        return;
    }
    if (!value || typeof value !== "object")
        return;
    const record = value;
    const objectMediaContext = inMediaContext || isMediaContextObject(record, parentKey);
    for (const [key, nested] of Object.entries(record)) {
        const sourceId = numericSourceId(nested);
        if (isMediaAttachmentIdKey(key) && sourceId) {
            addRef(key, sourceId);
            continue;
        }
        if (key === "id" && objectMediaContext && sourceId) {
            addRef(key, sourceId);
            continue;
        }
        collectAttachmentRefsFromValue(nested, key, objectMediaContext, addRef);
    }
}
function isMediaContextObject(value, parentKey) {
    if (/(?:image|media|attachment|gallery|video|audio|poster|logo|avatar|icon)/i.test(parentKey)) {
        return true;
    }
    return Object.entries(value).some(([key, nested]) => {
        if (isMediaAttachmentIdKey(key))
            return true;
        return typeof nested === "string" && nested.includes(UPLOADS_PREFIX);
    });
}
function isMediaAttachmentIdKey(key) {
    return [
        "imageId",
        "mediaId",
        "attachmentId",
        "backgroundImageId",
        "videoId",
        "audioId",
    ].includes(key);
}
function numericSourceId(value) {
    if (typeof value === "number" && Number.isInteger(value) && value > 0)
        return value;
    if (typeof value === "string" && /^\d+$/.test(value))
        return Number.parseInt(value, 10);
    return undefined;
}
function numericTargetId(value) {
    if (typeof value === "number" && Number.isInteger(value) && value > 0)
        return value;
    if (typeof value === "string" && /^\d+$/.test(value))
        return Number.parseInt(value, 10);
    return undefined;
}
function resolveAttachment(sourceId, target) {
    const direct = target.attachment_remaps?.[String(sourceId)];
    const directId = numericTargetId(direct);
    if (directId) {
        return {
            status: "proven_remap",
            target_id: directId,
            proof: "attachment_remaps",
        };
    }
    if (Array.isArray(direct)) {
        const ids = direct.map(numericTargetId).filter((id) => id !== undefined);
        if (ids.length === 1) {
            return {
                status: "proven_remap",
                target_id: ids[0],
                proof: "attachment_remaps",
            };
        }
        if (ids.length > 1)
            return { status: "ambiguous_remap", proof: "attachment_remaps" };
    }
    if (direct && typeof direct === "object") {
        const record = direct;
        const targetId = numericTargetId(record.target_id);
        if (targetId) {
            return {
                status: "proven_remap",
                target_id: targetId,
                proof: typeof record.proof === "string" ? record.proof : "attachment_remaps",
            };
        }
        if (Array.isArray(record.target_ids)) {
            const ids = record.target_ids
                .map(numericTargetId)
                .filter((id) => id !== undefined);
            if (ids.length === 1) {
                return {
                    status: "proven_remap",
                    target_id: ids[0],
                    proof: "attachment_remaps",
                };
            }
            if (ids.length > 1)
                return { status: "ambiguous_remap", proof: "attachment_remaps" };
        }
    }
    const candidates = (target.attachments ?? []).filter((attachment) => {
        return (attachment.source_id === sourceId ||
            attachment.source_attachment_id === sourceId);
    });
    const targetIds = uniqueSorted(candidates
        .map((candidate) => numericTargetId(candidate.target_id ?? candidate.id))
        .filter((id) => id !== undefined)
        .map(String)).map((id) => Number.parseInt(id, 10));
    if (targetIds.length === 1) {
        return {
            status: "proven_remap",
            target_id: targetIds[0],
            proof: candidates.find((candidate) => candidate.proof)?.proof ?? "target_attachments",
        };
    }
    if (targetIds.length > 1)
        return { status: "ambiguous_remap", proof: "target_attachments" };
    return { status: "missing_remap" };
}
function findGcidRefs(markup, target) {
    const decoded = decodeMarkup(markup);
    const refs = new Set();
    let match;
    GCID_RE.lastIndex = 0;
    while ((match = GCID_RE.exec(decoded)) !== null)
        refs.add(match[0]);
    const targetColors = collectIds(target.global_colors);
    const builtinColors = collectIds(target.builtin_customizer_color_ids) ??
        DEFAULT_BUILTIN_CUSTOMIZER_COLOR_IDS;
    const valueEvidence = collectGlobalColorValueEvidence(target);
    return uniqueSorted(refs).map((id) => {
        const evidence = valueEvidence.get(id);
        if (targetColors?.has(id)) {
            return { id, status: "target_global_color", value_evidence: evidence };
        }
        if (builtinColors.has(id)) {
            return { id, status: "builtin_customizer_color", value_evidence: evidence };
        }
        if (targetColors === null)
            return { id, status: "missing_target_context" };
        return { id, status: "missing_definition" };
    });
}
function isSafePresetId(value) {
    return value !== "" && value !== "default" && /^[A-Za-z0-9_-]+$/.test(value);
}
function collectPresetIdsFromList(value) {
    if (value === undefined || value === null)
        return null;
    const ids = new Set();
    const add = (candidate) => {
        if (typeof candidate === "string" && isSafePresetId(candidate)) {
            ids.add(candidate);
        }
    };
    if (Array.isArray(value)) {
        for (const item of value)
            add(item);
    }
    else if (value && typeof value === "object") {
        for (const [key, item] of Object.entries(value)) {
            add(key);
            if (item && typeof item === "object") {
                add(item.id);
            }
        }
    }
    else {
        add(value);
    }
    return ids;
}
function findModulePresetRefs(source, target) {
    const refs = collectPresetIdsFromList(source.module_preset_ids) ?? new Set();
    const decoded = decodeMarkup(source.markup);
    if (decoded.includes('"modulePreset"')) {
        const blockRe = /<!--\s+(?:\/)?wp:([A-Za-z0-9_-]+\/[A-Za-z0-9_-]+)(.*?)(?:\/)?-->/gs;
        let match;
        while ((match = blockRe.exec(decoded)) !== null) {
            const blockName = match[1];
            if (!blockName.startsWith("divi/"))
                continue;
            const attrsText = jsonObjectFromBlockCommentTail(match[2] ?? "");
            if (!attrsText)
                continue;
            try {
                const attrs = JSON.parse(attrsText);
                const values = Array.isArray(attrs.modulePreset)
                    ? attrs.modulePreset
                    : [attrs.modulePreset];
                for (const id of values) {
                    if (typeof id === "string" && isSafePresetId(id))
                        refs.add(id);
                }
            }
            catch {
                continue;
            }
        }
    }
    const targetIds = collectPresetIdsFromList(target.module_preset_ids);
    return uniqueSorted(refs).map((id) => {
        if (targetIds === null)
            return { id, status: "missing_target_context" };
        if (targetIds.has(id))
            return { id, status: "target_module_preset" };
        return { id, status: "missing_definition" };
    });
}
function findOffCanvasRefs(markup) {
    const decoded = decodeMarkup(markup);
    const refs = new Map();
    for (const pattern of OFF_CANVAS_PATTERNS) {
        pattern.re.lastIndex = 0;
        let match;
        while ((match = pattern.re.exec(decoded)) !== null) {
            refs.set(`${pattern.reason}:${match[0]}`, {
                token: match[0],
                reason: pattern.reason,
            });
        }
    }
    return [...refs.values()].sort((a, b) => a.token.localeCompare(b.token));
}
function groupUrlPathsByHost(urls) {
    const grouped = {};
    for (const url of urls) {
        grouped[url.host] ??= new Set();
        grouped[url.host].add(url.path);
    }
    return Object.fromEntries(Object.entries(grouped)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([host, paths]) => [host, uniqueSorted(paths)]));
}
function cachePlan(target, offCanvasRefs) {
    const scope = target.cache_scope ?? "theme_builder_global";
    const destination = target.destination_id
        ? `${target.destination_kind}#${target.destination_id}`
        : target.destination_kind;
    const steps = [
        {
            kind: "divi_static_resources_remove",
            target: scope,
            note: "Run Divi static-resource removal for the affected Theme Builder/global rendering scope.",
        },
        {
            kind: "delete_post_meta",
            target: `${destination}::_divi_dynamic_assets_cached_feature_used`,
            note: "Delete stale dynamic-assets feature usage metadata after a future write.",
        },
    ];
    if (offCanvasRefs.length > 0) {
        steps.push({
            kind: "delete_post_meta",
            target: `${destination}::_divi_dynamic_assets_canvases_used`,
            note: "Required only when a future off-canvas implementation owns canvas remapping.",
        });
    }
    return { scope, steps };
}
function stableIssueSummaries(issues) {
    return [...issues]
        .map((issue) => ({
        code: issue.code,
        detail: issue.detail ?? null,
    }))
        .sort((a, b) => canonicalJson(a).localeCompare(canonicalJson(b)));
}
function buildConfirmationBinding(report) {
    const input = {
        source: {
            origin: report.source.origin,
            object_kind: report.source.object_kind,
            object_id: report.source.object_id,
            checksum: {
                algorithm: "sha256",
                computed: report.source.checksum.computed,
            },
        },
        target: {
            origin: report.target.origin,
            destination_kind: report.target.destination_kind,
            destination_id: report.target.destination_id,
            destination_checksum: report.target.destination_checksum ?? null,
        },
        rewrite_plan: report.rewrite_plan,
        cache_plan: report.cache_plan,
        blockers: stableIssueSummaries(report.blockers),
        operator_actions: stableIssueSummaries(report.operator_actions),
        off_canvas: {
            refused: report.findings.off_canvas.length > 0,
            refs: report.findings.off_canvas,
        },
        attachment_remap_proof: report.findings.attachments
            .map((attachment) => ({
            source_id: attachment.source_id,
            status: attachment.status,
            target_id: attachment.target_id,
            proof: attachment.proof,
        }))
            .sort((a, b) => canonicalJson(a).localeCompare(canonicalJson(b))),
        global_color_resolution: report.findings.global_colors
            .map((color) => ({
            id: color.id,
            status: color.status,
            value_evidence: color.value_evidence ?? null,
        }))
            .sort((a, b) => canonicalJson(a).localeCompare(canonicalJson(b))),
        ...(report.findings.module_presets.length > 0
            ? {
                module_preset_resolution: report.findings.module_presets
                    .map((preset) => ({
                    id: preset.id,
                    status: preset.status,
                }))
                    .sort((a, b) => canonicalJson(a).localeCompare(canonicalJson(b))),
            }
            : {}),
    };
    const schema = "diviops.cross_env.header_preflight.confirmation_binding.v1";
    const fingerprint = sha256(canonicalJson({ schema, input }));
    return {
        schema,
        algorithm: "sha256",
        fingerprint,
        input: canonicalize(input),
    };
}
export function preflightCrossEnvHeaderSync(input) {
    const sourceOrigin = normalizeOrigin(input.source.origin, "source.origin");
    const targetOrigin = normalizeOrigin(input.target.origin, "target.origin");
    const computedChecksum = sha256(input.source.markup);
    const providedChecksum = normalizeChecksum(input.source.checksum);
    const destinationChecksum = normalizeDestinationChecksum(input.target.destination_checksum);
    const checksumMatches = providedChecksum === undefined ? null : providedChecksum === computedChecksum;
    const blockers = [];
    const operatorActions = [];
    const secretLikeKeys = collectSecretLikeKeys(input);
    if (secretLikeKeys.length > 0) {
        blockers.push({
            code: "secret_like_input_key",
            message: "Preflight inputs must be secret-free.",
            detail: { keys: secretLikeKeys },
        });
    }
    if (input.source.object_kind !== SUPPORTED_KIND) {
        blockers.push({
            code: "unsupported_source_kind",
            message: "MVP only supports tb_header_layout source payloads.",
            detail: { received: input.source.object_kind },
        });
    }
    if (input.target.destination_kind !== SUPPORTED_KIND) {
        blockers.push({
            code: "unsupported_destination_kind",
            message: "MVP only supports tb_header_layout destinations.",
            detail: { received: input.target.destination_kind },
        });
    }
    if (input.target.destination_id === undefined || input.target.destination_id === "") {
        operatorActions.push({
            code: "missing_destination_id",
            message: "Provide the existing target Theme Builder header layout ID.",
        });
    }
    if (input.target.cache_scope === undefined || input.target.cache_scope === "") {
        operatorActions.push({
            code: "missing_cache_scope",
            message: "Provide the expected cache scope after apply.",
        });
    }
    if (providedChecksum === undefined) {
        operatorActions.push({
            code: "missing_source_checksum",
            message: "Provide a sha256 checksum for the source markup payload.",
        });
    }
    else if (!checksumMatches) {
        blockers.push({
            code: "source_checksum_mismatch",
            message: "Source markup checksum does not match the provided checksum.",
            detail: { provided: providedChecksum, computed: computedChecksum },
        });
    }
    if (!destinationChecksum) {
        operatorActions.push({
            code: "missing_destination_checksum",
            message: "Provide the current target layout sha256 checksum before future apply confirmation.",
        });
    }
    else if (!isSha256Hex(destinationChecksum.computed)) {
        blockers.push({
            code: "invalid_destination_checksum",
            message: "Target destination checksum must be a sha256 hex digest.",
            detail: { algorithm: destinationChecksum.algorithm },
        });
    }
    const urls = findUrls(input.source.markup, sourceOrigin, targetOrigin);
    for (const url of urls) {
        if (url.classification === "source_non_upload_operator_review") {
            operatorActions.push({
                code: "source_url_not_upload_path",
                message: "Source-origin absolute URL is not a compatible WordPress uploads URL.",
                detail: { url: url.url, path: url.path },
            });
        }
    }
    const attachments = findAttachmentRefs(input.source.markup).map((ref) => {
        const resolution = resolveAttachment(ref.source_id, input.target);
        return { ...ref, ...resolution };
    });
    for (const attachment of attachments) {
        if (attachment.status === "missing_remap") {
            operatorActions.push({
                code: "missing_attachment_remap",
                message: "Provide a proven target attachment remap before apply.",
                detail: { source_id: attachment.source_id, property: attachment.property },
            });
        }
        else if (attachment.status === "ambiguous_remap") {
            operatorActions.push({
                code: "ambiguous_attachment_remap",
                message: "Attachment remap resolves to multiple possible target IDs.",
                detail: { source_id: attachment.source_id, property: attachment.property },
            });
        }
    }
    const globalColors = findGcidRefs(input.source.markup, input.target);
    for (const color of globalColors) {
        if (color.status === "missing_target_context") {
            operatorActions.push({
                code: "missing_global_color_context",
                message: "Provide target global colors or built-in customizer color IDs.",
                detail: { id: color.id },
            });
        }
        else if (color.status === "missing_definition") {
            blockers.push({
                code: "missing_gcid_definition",
                message: "Referenced gcid-* is not defined on the target and is not a built-in customizer color.",
                detail: { id: color.id },
            });
        }
        else if (!color.value_evidence) {
            operatorActions.push({
                code: "missing_global_color_value_evidence",
                message: "Provide target global color value evidence before apply confirmation.",
                detail: { id: color.id, status: color.status },
            });
        }
    }
    const modulePresets = findModulePresetRefs(input.source, input.target);
    for (const preset of modulePresets) {
        if (preset.status === "missing_target_context") {
            operatorActions.push({
                code: "missing_module_preset_context",
                message: "Provide target D5 module preset IDs before apply.",
                detail: { id: preset.id },
            });
        }
        else if (preset.status === "missing_definition") {
            operatorActions.push({
                code: "missing_module_preset_definition",
                message: "Referenced modulePreset is not defined on the target.",
                detail: { id: preset.id },
            });
        }
    }
    const offCanvas = findOffCanvasRefs(input.source.markup);
    if (offCanvas.length > 0) {
        blockers.push({
            code: "off_canvas_wiring_unsupported",
            message: "Off-canvas/canvas wiring requires a 3-piece remap and is refused in this MVP.",
            detail: { refs: offCanvas },
        });
    }
    const urlRewrites = urls
        .filter((url) => url.classification === "source_upload_rewrite" && url.rewrite_to)
        .map((url) => ({ from: url.url, to: url.rewrite_to }));
    const attachmentReplacements = attachments
        .filter((attachment) => attachment.status === "proven_remap" && attachment.target_id)
        .reduce((replacements, attachment) => {
        const next = {
            from_source_id: attachment.source_id,
            to_target_id: attachment.target_id,
            proof: attachment.proof,
        };
        if (!replacements.some((existing) => existing.from_source_id === next.from_source_id)) {
            replacements.push(next);
        }
        return replacements;
    }, []);
    const missingAttachmentRemaps = attachments
        .filter((attachment) => attachment.status !== "proven_remap")
        .map((attachment) => attachment.source_id);
    const verdict = blockers.length > 0
        ? "refused"
        : operatorActions.length > 0
            ? "operator_action_required"
            : "safe_dry_run";
    const report = {
        type: "cross_env_header_preflight",
        dry_run: true,
        verdict,
        source: {
            origin: sourceOrigin.origin,
            object_kind: input.source.object_kind,
            object_id: input.source.object_id,
            object_title: input.source.object_title,
            checksum: {
                algorithm: "sha256",
                provided: providedChecksum,
                computed: computedChecksum,
                matches: checksumMatches,
            },
        },
        target: {
            origin: targetOrigin.origin,
            destination_kind: input.target.destination_kind,
            destination_id: input.target.destination_id,
            destination_title: input.target.destination_title,
            cache_scope: input.target.cache_scope,
            destination_checksum: destinationChecksum,
        },
        findings: {
            urls: {
                by_host: groupUrlPathsByHost(urls),
                occurrences: urls,
            },
            attachments,
            global_colors: globalColors,
            module_presets: modulePresets,
            off_canvas: offCanvas,
        },
        rewrite_plan: {
            url_rewrites: urlRewrites,
            attachment_replacements: attachmentReplacements,
            third_party_urls_kept: urls
                .filter((url) => url.classification === "third_party_no_rewrite")
                .map((url) => url.url),
            missing_attachment_remaps: uniqueSorted(missingAttachmentRemaps.map(String)).map((id) => Number.parseInt(id, 10)),
        },
        cache_plan: cachePlan(input.target, offCanvas),
        blockers,
        operator_actions: operatorActions,
        notes: [
            "Preflight only: no WordPress write/apply path exists in this slice.",
            "The rewrite plan is descriptive and does not mutate markup.",
            "Fail-closed behavior is intentional for missing attachment remaps, missing gcid definitions, and off-canvas wiring.",
        ],
    };
    return {
        ...report,
        confirmation_binding: buildConfirmationBinding(report),
    };
}
