import Ajv2020 from "ajv/dist/2020.js";
import type { ErrorObject } from "ajv";

import { SiteManifestValidationError } from "./errors.js";
import type { SiteManifestValidationIssue } from "./errors.js";
import type { SiteManifest } from "./generated/site-manifest.js";
import { siteManifestSchema } from "./generated/schema.js";

const ajv = new Ajv2020({
  allErrors: true,
  strict: true,
});
const validator = ajv.compile<SiteManifest>(siteManifestSchema);

function issueFromError(error: ErrorObject): SiteManifestValidationIssue {
  return {
    path: error.instancePath || "$",
    keyword: error.keyword,
    message: error.message ?? "invalid value",
  };
}

export function validateSiteManifest(value: unknown): value is SiteManifest {
  return validator(value);
}

export function parseSiteManifest(value: unknown): SiteManifest {
  if (validator(value)) {
    return value;
  }
  throw new SiteManifestValidationError(
    (validator.errors ?? []).map(issueFromError),
  );
}
