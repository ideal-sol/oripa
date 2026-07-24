import type {
  PlatformRuntimeCompatibility,
  SiteManifest,
} from "@oripa/site-schema";
import type { StorefrontResponseMetadata } from "@oripa/storefront-client";

export {
  PUBLIC_CONTRACT_FIXTURE,
} from "./generated/public-contract.js";

export const MINIMAL_SITE_MANIFEST_FIXTURE = Object.freeze(
  {
    schema_version: "2.0.0-alpha.1",
    site_version: "1.0.0-alpha.1",
    compatibility: {
      family: 2,
      storefront_client_version: "2.0.0-alpha.1",
      required_capabilities: [],
    },
    public: {
      locale: "ja-JP",
      timezone: "Asia/Tokyo",
      features: {
        enabled: [],
      },
    },
  } as const satisfies SiteManifest,
);

export const CAPABILITY_SITE_MANIFEST_FIXTURE = Object.freeze(
  {
    ...MINIMAL_SITE_MANIFEST_FIXTURE,
    compatibility: {
      ...MINIMAL_SITE_MANIFEST_FIXTURE.compatibility,
      required_capabilities: ["auth.session.v2"],
    },
    public: {
      ...MINIMAL_SITE_MANIFEST_FIXTURE.public,
      features: {
        enabled: ["auth.session.v2"],
      },
    },
  } as const satisfies SiteManifest,
);

export const PLATFORM_COMPATIBILITY_FIXTURE = Object.freeze({
  compatibility_family: 2,
  minimum_storefront_client_version: "2.0.0-alpha.1",
  capabilities: ["auth.session.v2"],
}) satisfies PlatformRuntimeCompatibility;

export const PUBLIC_RESPONSE_METADATA_FIXTURE = Object.freeze({
  status: 200,
  request_id: "request-fixture-001",
  api_version: "2",
  idempotency_replayed: false,
}) satisfies StorefrontResponseMetadata;
