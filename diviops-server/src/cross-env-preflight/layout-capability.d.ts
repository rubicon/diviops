export declare const CROSS_ENV_FOOTER_LAYOUT_EVIDENCE_CAPABILITY: "cross_env_footer_layout_evidence";
export type CrossEnvEvidenceLayoutKind = "tb_header_layout" | "tb_footer_layout";
export declare function crossEnvEvidenceLayoutKinds(capabilities: Record<string, boolean>): ["tb_header_layout"] | ["tb_header_layout", "tb_footer_layout"];
