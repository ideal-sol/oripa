export interface SiteManifestValidationIssue {
  readonly path: string;
  readonly keyword: string;
  readonly message: string;
}

export class SiteManifestValidationError extends Error {
  readonly issues: readonly SiteManifestValidationIssue[];

  constructor(issues: readonly SiteManifestValidationIssue[]) {
    super("Site Manifest validation failed");
    this.name = "SiteManifestValidationError";
    this.issues = issues;
  }
}
