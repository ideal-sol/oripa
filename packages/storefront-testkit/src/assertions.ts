import {
  ApiProblemError,
  type StorefrontResponseMetadata,
} from "@oripa/storefront-client";
import {
  assessSiteCompatibility,
  parseSiteManifest,
  type PlatformRuntimeCompatibility,
  type SiteManifest,
} from "@oripa/site-schema";

import { TestkitAssertionError } from "./errors.js";
import type { MockRequestRecord } from "./mock.js";

function requireValue(condition: boolean, message: string): void {
  if (!condition) {
    throw new TestkitAssertionError(message);
  }
}

export function assertBrowserRequestBoundary(
  request: MockRequestRecord,
  expected: {
    readonly client_version: string;
    readonly site_version: string;
  },
): void {
  requireValue(
    request.credentials === "include",
    "Browser request must use credentials include",
  );
  requireValue(
    request.headers["x-oripa-client-version"] === expected.client_version,
    "Browser request must include the expected Client Version header",
  );
  requireValue(
    request.headers["x-oripa-site-version"] === expected.site_version,
    "Browser request must include the expected Site Version header",
  );
  requireValue(
    request.headers.authorization === undefined,
    "Storefront request must not include Authorization",
  );
  assertPublicRequestBoundary(request);
}

export function assertPublicRequestBoundary(request: MockRequestRecord): void {
  const path = request.url.toLowerCase();
  requireValue(
    !/(?:^|\/)(?:admin|webhooks?)(?:\/|$)/.test(path),
    "Storefront request must remain on the Public surface",
  );
}

export function assertServerSafeRequest(request: MockRequestRecord): void {
  requireValue(
    request.method === "GET" || request.method === "HEAD",
    "Server Storefront request must use GET or HEAD",
  );
}

export function assertResponseMetadata(
  metadata: StorefrontResponseMetadata,
): void {
  requireValue(
    typeof metadata.request_id === "string" &&
      metadata.request_id.length > 0,
    "Response metadata must include a Request ID",
  );
}

export function assertProblemDetails(error: unknown): asserts error is ApiProblemError {
  requireValue(
    error instanceof ApiProblemError,
    "Error must be converted from RFC 9457 Problem Details",
  );
}

export function assertCompatibleSiteManifest(
  value: unknown,
  runtime: PlatformRuntimeCompatibility,
): SiteManifest {
  const manifest = parseSiteManifest(value);
  const assessment = assessSiteCompatibility(manifest, runtime);
  requireValue(
    assessment.compatible,
    "Site Manifest is incompatible with the Platform Contract",
  );
  return manifest;
}
