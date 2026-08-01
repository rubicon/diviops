export type CrossEnvObjectKind = "tb_header_layout";
export type CrossEnvVerdict = "safe_dry_run" | "operator_action_required" | "refused";
export interface SourceAttachmentInventoryItem {
    id: number;
    url?: string;
    path?: string;
    filename?: string;
    mime?: string;
}
export interface SourceLayoutPayload {
    origin: string;
    object_kind: CrossEnvObjectKind | string;
    object_id?: number | string;
    object_title?: string;
    object_post_type?: string;
    markup: string;
    checksum?: string;
    attachments?: SourceAttachmentInventoryItem[];
    module_preset_ids?: unknown;
}
export interface TargetAttachmentInventoryItem {
    id?: number;
    target_id?: number;
    source_id?: number;
    source_attachment_id?: number;
    url?: string;
    path?: string;
    filename?: string;
    proof?: string;
}
export interface Sha256Checksum {
    algorithm: "sha256";
    computed: string;
    input?: string;
}
export interface TargetLayoutContext {
    origin: string;
    destination_kind: CrossEnvObjectKind | string;
    destination_id?: number | string;
    destination_title?: string;
    destination_post_type?: string;
    destination_checksum?: string | Sha256Checksum;
    template_linkage?: unknown;
    template_linkage_digest?: Sha256Checksum;
    attachment_remaps?: Record<string, unknown>;
    attachments?: TargetAttachmentInventoryItem[];
    global_colors?: unknown;
    global_color_value_evidence?: unknown;
    builtin_customizer_color_ids?: unknown;
    module_preset_ids?: unknown;
    cache_scope?: string;
}
export interface CrossEnvHeaderPreflightInput {
    source: SourceLayoutPayload;
    target: TargetLayoutContext;
}
export interface PreflightIssue {
    code: string;
    message: string;
    detail?: Record<string, unknown>;
}
export interface UrlOccurrence {
    url: string;
    host: string;
    path: string;
    query_redacted?: boolean;
    hash_redacted?: boolean;
    credentials_redacted?: boolean;
    classification: "source_upload_rewrite" | "source_non_upload_operator_review" | "third_party_no_rewrite" | "target_origin";
    rewrite_to?: string;
}
export interface AttachmentReference {
    source_id: number;
    property: string;
    status: "proven_remap" | "missing_remap" | "ambiguous_remap";
    target_id?: number;
    proof?: string;
}
export interface GcidReference {
    id: string;
    status: "target_global_color" | "builtin_customizer_color" | "missing_target_context" | "missing_definition";
    value_evidence?: GlobalColorValueEvidence;
}
export interface GlobalColorValueEvidence {
    id: string;
    source?: string;
    digest: Sha256Checksum;
}
export interface OffCanvasReference {
    token: string;
    reason: string;
}
export interface ModulePresetReference {
    id: string;
    status: "target_module_preset" | "missing_target_context" | "missing_definition";
}
export interface CrossEnvHeaderPreflightReport {
    type: "cross_env_header_preflight";
    dry_run: true;
    verdict: CrossEnvVerdict;
    source: {
        origin: string;
        object_kind: string;
        object_id?: number | string;
        object_title?: string;
        checksum: {
            algorithm: "sha256";
            provided?: string;
            computed: string;
            matches: boolean | null;
        };
    };
    target: {
        origin: string;
        destination_kind: string;
        destination_id?: number | string;
        destination_title?: string;
        cache_scope?: string;
        destination_checksum?: Sha256Checksum;
    };
    findings: {
        urls: {
            by_host: Record<string, string[]>;
            occurrences: UrlOccurrence[];
        };
        attachments: AttachmentReference[];
        global_colors: GcidReference[];
        module_presets: ModulePresetReference[];
        off_canvas: OffCanvasReference[];
    };
    rewrite_plan: {
        url_rewrites: Array<{
            from: string;
            to: string;
        }>;
        attachment_replacements: Array<{
            from_source_id: number;
            to_target_id: number;
            proof?: string;
        }>;
        third_party_urls_kept: string[];
        missing_attachment_remaps: number[];
    };
    cache_plan: {
        scope: string;
        steps: Array<{
            kind: string;
            target: string;
            note: string;
        }>;
    };
    blockers: PreflightIssue[];
    operator_actions: PreflightIssue[];
    notes: string[];
    confirmation_binding: {
        schema: "diviops.cross_env.header_preflight.confirmation_binding.v1";
        algorithm: "sha256";
        fingerprint: string;
        input: Record<string, unknown>;
    };
}
export declare function preflightCrossEnvHeaderSync(input: CrossEnvHeaderPreflightInput): CrossEnvHeaderPreflightReport;
