import { type CrossEnvVerdict, type GcidReference, type ModulePresetReference, type OffCanvasReference, type PreflightIssue, type Sha256Checksum, type SourceLayoutPayload, type TargetLayoutContext, type AttachmentReference, type UrlOccurrence } from "./header-preflight.js";
export declare const THEME_BUILDER_LAYOUT_KIND_POST_TYPES: {
    readonly tb_header_layout: "et_header_layout";
    readonly tb_footer_layout: "et_footer_layout";
};
export type CrossEnvThemeBuilderLayoutKind = keyof typeof THEME_BUILDER_LAYOUT_KIND_POST_TYPES;
export declare const THEME_BUILDER_LAYOUT_VALIDATOR_VERSION: "diviops.cross_env.theme_builder_layout.validator.v1";
export interface CrossEnvThemeBuilderLayoutPreflightInput {
    source: SourceLayoutPayload;
    target: TargetLayoutContext;
}
export interface CrossEnvThemeBuilderLayoutPreflightReport {
    type: "cross_env_theme_builder_layout_preflight";
    dry_run: true;
    verdict: CrossEnvVerdict;
    validator_contract: {
        version: typeof THEME_BUILDER_LAYOUT_VALIDATOR_VERSION;
        serialization: "wordpress_divi_full_content.v1";
    };
    source: {
        origin: string;
        object_kind: string;
        object_id?: number | string;
        object_title?: string;
        object_post_type?: string;
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
        destination_post_type?: string;
        cache_scope?: string;
        destination_checksum?: Sha256Checksum;
        template_linkage?: unknown;
        template_linkage_digest?: Sha256Checksum;
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
    source_origin_rule: {
        normalized_origin: string;
        rewrite_scope: "same_origin_wp_uploads_only";
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
        schema: "diviops.cross_env.theme_builder_layout_preflight.confirmation_binding.v1";
        algorithm: "sha256";
        fingerprint: string;
        input: Record<string, unknown>;
    };
}
export declare function preflightCrossEnvThemeBuilderLayoutSync(input: CrossEnvThemeBuilderLayoutPreflightInput): CrossEnvThemeBuilderLayoutPreflightReport;
