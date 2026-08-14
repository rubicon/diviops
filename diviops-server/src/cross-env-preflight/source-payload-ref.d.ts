import { type SourceLayoutPayload } from "./header-preflight.js";
export interface SourcePayloadRef {
    handle: string;
    checksum: string;
    algorithm: "sha256";
    storage: "server_local_artifact";
    expires_at?: string;
}
export interface SourcePayloadRefEnvelope {
    type: "diviops.cross_env.source_payload_ref.v1";
    created_at: string;
    expires_at: string | null;
    source_payload: SourceLayoutPayload;
    checksum: {
        algorithm: "sha256";
        computed: string;
    };
}
export declare function createSourcePayloadRef(payload: SourceLayoutPayload): SourcePayloadRef;
export declare function loadSourcePayloadRef(ref: unknown, expectedKind?: string): SourceLayoutPayload;
