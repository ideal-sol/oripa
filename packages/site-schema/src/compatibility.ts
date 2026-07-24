import { compare, major, valid } from "semver";

import { validateSiteManifest } from "./validator.js";

export const CURRENT_CORE_COMPATIBILITY_FAMILY = 2;
export const CURRENT_SITE_SCHEMA_VERSION = "2.0.0-alpha.1";

export interface PlatformRuntimeCompatibility {
  readonly compatibility_family: number;
  readonly minimum_storefront_client_version: string;
  readonly capabilities: readonly string[];
}

export interface SiteSchemaSupportPolicy {
  readonly tested_schema_versions: readonly string[];
}

export type CompatibilityFailureCode =
  | "INVALID_MANIFEST"
  | "INVALID_RUNTIME_VERSION"
  | "FAMILY_MISMATCH"
  | "SCHEMA_VERSION_UNSUPPORTED"
  | "STOREFRONT_CLIENT_FAMILY_MISMATCH"
  | "STOREFRONT_CLIENT_TOO_OLD"
  | "REQUIRED_CAPABILITY_MISSING";

export interface CompatibilityFailure {
  readonly code: CompatibilityFailureCode;
  readonly detail: string;
}

export interface CompatibilityAssessment {
  readonly compatible: boolean;
  readonly failures: readonly CompatibilityFailure[];
}

export const DEFAULT_SITE_SCHEMA_SUPPORT: SiteSchemaSupportPolicy = {
  tested_schema_versions: [CURRENT_SITE_SCHEMA_VERSION],
};

export function assessSiteCompatibility(
  value: unknown,
  runtime: PlatformRuntimeCompatibility,
  support: SiteSchemaSupportPolicy = DEFAULT_SITE_SCHEMA_SUPPORT,
): CompatibilityAssessment {
  if (!validateSiteManifest(value)) {
    return failure("INVALID_MANIFEST", "Site ManifestがSchemaに適合しない");
  }

  const failures: CompatibilityFailure[] = [];
  if (!valid(runtime.minimum_storefront_client_version)) {
    failures.push({
      code: "INVALID_RUNTIME_VERSION",
      detail: "Platformのminimum_storefront_client_versionがExact SemVerではない",
    });
  }
  if (
    value.compatibility.family !== runtime.compatibility_family ||
    runtime.compatibility_family !== CURRENT_CORE_COMPATIBILITY_FAMILY
  ) {
    failures.push({
      code: "FAMILY_MISMATCH",
      detail: "SiteとPlatformのCore Compatibility Familyが一致しない",
    });
  }
  if (!support.tested_schema_versions.includes(value.schema_version)) {
    failures.push({
      code: "SCHEMA_VERSION_UNSUPPORTED",
      detail: "Site Schema Versionが明示されたTest対象外である",
    });
  }
  if (
    major(value.compatibility.storefront_client_version) !==
    value.compatibility.family
  ) {
    failures.push({
      code: "STOREFRONT_CLIENT_FAMILY_MISMATCH",
      detail: "Storefront Client MajorがCore Compatibility Familyと一致しない",
    });
  }
  if (
    valid(runtime.minimum_storefront_client_version) &&
    compare(
      value.compatibility.storefront_client_version,
      runtime.minimum_storefront_client_version,
    ) < 0
  ) {
    failures.push({
      code: "STOREFRONT_CLIENT_TOO_OLD",
      detail: "Storefront ClientがPlatformのMinimum Versionを満たさない",
    });
  }

  const available = new Set(runtime.capabilities);
  const missing = value.compatibility.required_capabilities.filter(
    (capability) => !available.has(capability),
  );
  if (missing.length > 0) {
    failures.push({
      code: "REQUIRED_CAPABILITY_MISSING",
      detail: `Required Capabilityが不足している: ${missing.join(", ")}`,
    });
  }

  return {
    compatible: failures.length === 0,
    failures,
  };
}

function failure(
  code: CompatibilityFailureCode,
  detail: string,
): CompatibilityAssessment {
  return {
    compatible: false,
    failures: [{ code, detail }],
  };
}
