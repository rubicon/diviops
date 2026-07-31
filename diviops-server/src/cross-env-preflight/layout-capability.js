export const CROSS_ENV_FOOTER_LAYOUT_EVIDENCE_CAPABILITY = "cross_env_footer_layout_evidence";
export function crossEnvEvidenceLayoutKinds(capabilities) {
    return capabilities[CROSS_ENV_FOOTER_LAYOUT_EVIDENCE_CAPABILITY] === true
        ? ["tb_header_layout", "tb_footer_layout"]
        : ["tb_header_layout"];
}
