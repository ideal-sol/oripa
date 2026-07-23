# V2 Architecture Index

## Authority

The latest explicit human decision is always the highest authority. This index
records the current committed V2 authorities; it does not create a new product
decision or change the finalized document bodies.

## Reading Order

| Priority | Document | Document ID | Status | Applies to | Authority and supersession |
| --- | --- | --- | --- | --- | --- |
| 1 | [Codex, Git, and CI Governance Revision 2](V2_CODEX_GIT_CI_GOVERNANCE_FINAL_REV2_2026-07-23.md) | `V2-CODEX-GIT-CI-GOVERNANCE-001` | FINAL / Architecture Baseline 2.0 / Revision 2 | Platform Repository governance, Codex, GitHub, and CI operations | Supersedes `V2_CODEX_GIT_CI_GOVERNANCE_FINAL_2026-07-22.md`. |
| 2 | [Release Gates Revision 1](V2_RELEASE_GATES_FINAL_REV1_2026-07-23.md) | `V2-RELEASE-GATES-001` | FINAL / Architecture Baseline 1.1 / Revision 1 | Platform and Site release, deployment, and migration gates | Supersedes `V2_RELEASE_GATES_FINAL_2026-07-22.md`. |
| 3 | [Autonomous GitHub Operations ADR](V2_AUTONOMOUS_GITHUB_OPERATIONS_ADR_FINAL_2026-07-23.md) | `V2-ADR-GITHUB-AUTONOMY-001` | FINAL | Autonomous GitHub operations for `ideal-sol/oripa` | Implements the latest autonomous-operations decision and replaces the earlier human-only GitHub lifecycle. |
| 4 | [V1 to V2 Migration Plan](V1_TO_V2_MIGRATION_PLAN_FINAL_2026-07-22.md) | `V2-V1-MIGRATION-PLAN-001` | FINAL / Architecture Baseline 1.0 | V1 assets, V2 Platform, Site Template, Luxe Pack, and future customer Sites | Governs migration below the current Governance, Release Gates, and autonomous-operations ADR. |
| 5 | [Package Version and Compatibility Policy](V2_PACKAGE_VERSION_COMPATIBILITY_POLICY_FINAL_2026-07-22.md) | `V2-PACKAGE-VERSION-COMPATIBILITY-POLICY-001` | FINAL / Architecture Baseline 1.0 | V2 Platform, all customer Sites, first-party packages, and container images | Extends the API contract with version and compatibility rules; remains below the Migration Plan. |
| 6 | [Identity, Authorization, and Security Baseline Revision 1](V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md) | `V2-IDENTITY-AUTHORIZATION-SECURITY-BASELINE-001` | FINAL / Architecture Baseline 1.0 / Revision 1 | Every fully isolated V2 Site environment | The sole current security baseline. Its Revision 1 decisions override lower Data and API baselines for identity, authorization, and security. |
| 7 | [Data, Point, and Payment Baseline](V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md) | `V2-DATA-POINT-PAYMENT-BASELINE-001` | FINAL / Architecture Baseline 1.0 | Every independently deployed V2 Site database and payment environment | V2-new decisions override the corresponding tentative V1 data design; provider-specific decisions remain deferred to an approved Provider ADR. |
| 8 | [API v2 and Storefront Client Contract](API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md) | `V2-API-CLIENT-CONTRACT-001` | FINAL / Architecture Baseline 1.0 | V2 Platform, all customer Storefronts, and the common Admin application | Contract baseline refined by the higher-priority Data, Security, Compatibility, Migration, Release, and Governance authorities. |

V1 specifications and implementation are behavioral references only. They do
not override the V2 authorities above and are not the V2 architecture source of
truth.

## Source Integrity

MIG-010 copied the five previously uncommitted FINAL documents and the example
artifact byte-for-byte from `/home/ec2-user/oripa_v2/`.

| Repository file | SHA-256 |
| --- | --- |
| `API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md` | `85fa0433cf08c51aee9f58a3fe96836ea9bd82c2764415e2c0556e34a945a43e` |
| `V1_TO_V2_MIGRATION_PLAN_FINAL_2026-07-22.md` | `9b1f53a5a5876a6083d73ff3668f60684b8369934992d42b4da5ebc20bc0bdd8` |
| `V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md` | `fea9e3df7ec12bd3750d8453d2626eb2476ea2169fce1beda0098def7f427ebe` |
| `V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md` | `697ce626ad24646abf83bdeeef8ef7025a827b26b4bb238ce333c40e1283e655` |
| `V2_PACKAGE_VERSION_COMPATIBILITY_POLICY_FINAL_2026-07-22.md` | `8b9602c16886345c6fe4915b46c51146505ac17a886f4c59e5721aa0b651c843` |
| `release-gate.example.yaml` | `050b83c26209c37504f0443f5436993486ab6f4ddee340e0bb1c6cec5442c1dd` |

## Supporting Artifact

[Release Gate example](release-gate.example.yaml) is a non-secret example
manifest. It is not an architecture authority, release decision, or deployment
record.

## Excluded Documents

- `ORIPA_V2_NEW_CHAT_HANDOFF_2026-07-22.md` and
  `PROJECT_STATUS_FOR_CHATGPT_2026-07-21.md` are context records, not
  architecture authorities.
- `V2_CODEX_GIT_CI_GOVERNANCE_FINAL_2026-07-22.md` and
  `V2_RELEASE_GATES_FINAL_2026-07-22.md` are superseded historical documents.
- The obsolete non-revision Identity, Authorization, and Security document is
  not authoritative and is not committed. Only the `REV1` document above may
  be used.
