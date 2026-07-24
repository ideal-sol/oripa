export {
  CURRENT_CORE_COMPATIBILITY_FAMILY,
  CURRENT_SITE_SCHEMA_VERSION,
  DEFAULT_SITE_SCHEMA_SUPPORT,
  assessSiteCompatibility,
} from "./compatibility.js";
export type {
  CompatibilityAssessment,
  CompatibilityFailure,
  CompatibilityFailureCode,
  PlatformRuntimeCompatibility,
  SiteSchemaSupportPolicy,
} from "./compatibility.js";
export {
  SiteManifestValidationError,
} from "./errors.js";
export type {
  SiteManifestValidationIssue,
} from "./errors.js";
export type {
  CapabilityName,
  SiteManifest,
} from "./generated/site-manifest.js";
export {
  parseSiteManifest,
  validateSiteManifest,
} from "./validator.js";
