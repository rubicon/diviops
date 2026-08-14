import { createHash } from "node:crypto";
import { preflightCrossEnvHeaderSync, } from "./header-preflight.js";
export const THEME_BUILDER_LAYOUT_KIND_POST_TYPES = {
    tb_header_layout: "et_header_layout",
    tb_footer_layout: "et_footer_layout",
};
export const THEME_BUILDER_LAYOUT_VALIDATOR_VERSION = "diviops.cross_env.theme_builder_layout.validator.v1";
function sha256(value) {
    return createHash("sha256").update(value).digest("hex");
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
function isSha256Digest(value) {
    if (!value || typeof value !== "object")
        return false;
    const record = value;
    return (record.algorithm === "sha256" &&
        typeof record.computed === "string" &&
        /^[a-f0-9]{64}$/i.test(record.computed));
}
function stableIssues(issues) {
    return issues
        .map((issue) => ({ code: issue.code, detail: issue.detail ?? null }))
        .sort((a, b) => canonicalJson(a).localeCompare(canonicalJson(b)));
}
function expectedPostType(kind) {
    return THEME_BUILDER_LAYOUT_KIND_POST_TYPES[kind];
}
function replaceCacheDestinationKind(target, kind) {
    return target.replace(/^tb_header_layout(?=#|$)/, kind);
}
export function preflightCrossEnvThemeBuilderLayoutSync(input) {
    // The shipped header-v1 engine remains the immutable compatibility analyzer.
    // The generic contract reuses its hazard inventory while owning a distinct
    // report and fingerprint namespace.
    const base = preflightCrossEnvHeaderSync({
        source: { ...input.source, object_kind: "tb_header_layout" },
        target: { ...input.target, destination_kind: "tb_header_layout" },
    });
    const blockers = [...base.blockers];
    const operatorActions = [...base.operator_actions];
    const sourceKind = String(input.source.object_kind ?? "");
    const targetKind = String(input.target.destination_kind ?? "");
    const sourceExpectedType = expectedPostType(sourceKind);
    const targetExpectedType = expectedPostType(targetKind);
    if (!sourceExpectedType) {
        blockers.push({
            code: "unsupported_source_kind",
            message: "Only tb_header_layout and tb_footer_layout sources are supported.",
            detail: { received: sourceKind },
        });
    }
    if (!targetExpectedType) {
        blockers.push({
            code: "unsupported_destination_kind",
            message: "Only tb_header_layout and tb_footer_layout destinations are supported.",
            detail: { received: targetKind },
        });
    }
    if (sourceExpectedType && targetExpectedType && sourceKind !== targetKind) {
        blockers.push({
            code: "layout_kind_mismatch",
            message: "Source and target Theme Builder layout kinds must match.",
            detail: { source_kind: sourceKind, destination_kind: targetKind },
        });
    }
    if (sourceExpectedType && input.source.object_post_type !== sourceExpectedType) {
        blockers.push({
            code: "source_post_type_mismatch",
            message: "Source post type does not match its declared layout kind.",
            detail: {
                kind: sourceKind,
                expected: sourceExpectedType,
                actual: input.source.object_post_type ?? null,
            },
        });
    }
    if (targetExpectedType && input.target.destination_post_type !== targetExpectedType) {
        blockers.push({
            code: "destination_post_type_mismatch",
            message: "Target post type does not match its declared layout kind.",
            detail: {
                kind: targetKind,
                expected: targetExpectedType,
                actual: input.target.destination_post_type ?? null,
            },
        });
    }
    if (!isSha256Digest(input.target.template_linkage_digest)) {
        operatorActions.push({
            code: "missing_template_linkage_digest",
            message: "Provide the canonical target template-linkage digest before apply confirmation.",
        });
    }
    const cachePlan = {
        ...base.cache_plan,
        steps: base.cache_plan.steps.map((step) => ({
            ...step,
            target: replaceCacheDestinationKind(step.target, targetKind),
        })),
    };
    const sourceOriginRule = {
        normalized_origin: base.source.origin,
        rewrite_scope: "same_origin_wp_uploads_only",
    };
    const validatorContract = {
        version: THEME_BUILDER_LAYOUT_VALIDATOR_VERSION,
        serialization: "wordpress_divi_full_content.v1",
    };
    const verdict = blockers.length > 0
        ? "refused"
        : operatorActions.length > 0
            ? "operator_action_required"
            : "safe_dry_run";
    const reportWithoutBinding = {
        type: "cross_env_theme_builder_layout_preflight",
        dry_run: true,
        verdict,
        validator_contract: validatorContract,
        source: {
            ...base.source,
            object_kind: sourceKind,
            object_post_type: input.source.object_post_type,
        },
        target: {
            ...base.target,
            destination_kind: targetKind,
            destination_post_type: input.target.destination_post_type,
            template_linkage: input.target.template_linkage,
            template_linkage_digest: input.target.template_linkage_digest,
        },
        findings: base.findings,
        source_origin_rule: sourceOriginRule,
        rewrite_plan: base.rewrite_plan,
        cache_plan: cachePlan,
        blockers,
        operator_actions: operatorActions,
        notes: [
            "Preflight only: Free/core evidence and CLI do not mutate WordPress.",
            "The generic binding is distinct from the shipped header-v1 fingerprint.",
            "Template linkage is drift evidence only and never authorizes assignment mutation.",
        ],
    };
    const bindingInput = {
        source: {
            origin: reportWithoutBinding.source.origin,
            object_kind: sourceKind,
            object_id: reportWithoutBinding.source.object_id,
            object_post_type: input.source.object_post_type,
            checksum: {
                algorithm: "sha256",
                computed: reportWithoutBinding.source.checksum.computed,
            },
        },
        target: {
            origin: reportWithoutBinding.target.origin,
            destination_kind: targetKind,
            destination_id: reportWithoutBinding.target.destination_id,
            destination_post_type: input.target.destination_post_type,
            destination_checksum: reportWithoutBinding.target.destination_checksum ?? null,
            template_linkage_digest: input.target.template_linkage_digest ?? null,
            cache_scope: reportWithoutBinding.target.cache_scope ?? null,
        },
        source_origin_rule: sourceOriginRule,
        rewrite_plan: reportWithoutBinding.rewrite_plan,
        cache_plan: cachePlan,
        dependency_evidence: {
            attachment_remap_proof: reportWithoutBinding.findings.attachments,
            global_color_resolution: reportWithoutBinding.findings.global_colors,
            module_preset_resolution: reportWithoutBinding.findings.module_presets,
            off_canvas: {
                refused: reportWithoutBinding.findings.off_canvas.length > 0,
                refs: reportWithoutBinding.findings.off_canvas,
            },
        },
        blockers: stableIssues(blockers),
        operator_actions: stableIssues(operatorActions),
        validator_contract: validatorContract,
    };
    const schema = "diviops.cross_env.theme_builder_layout_preflight.confirmation_binding.v1";
    const canonicalInput = canonicalize(bindingInput);
    return {
        ...reportWithoutBinding,
        confirmation_binding: {
            schema,
            algorithm: "sha256",
            fingerprint: sha256(canonicalJson({ schema, input: canonicalInput })),
            input: canonicalInput,
        },
    };
}
