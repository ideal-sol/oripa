# V2 Architecture Index

## Purpose

This directory contains the committed architecture authorities for the Oripa
V2 Platform Repository. Resolve conflicts using the priority in the root
`AGENTS.md` and the latest explicit human decision.

## Reading Order

1. [Codex, Git, and CI Governance Revision 2](V2_CODEX_GIT_CI_GOVERNANCE_FINAL_REV2_2026-07-23.md)
2. [Release Gates Revision 1](V2_RELEASE_GATES_FINAL_REV1_2026-07-23.md)
3. [Autonomous GitHub Operations ADR](V2_AUTONOMOUS_GITHUB_OPERATIONS_ADR_FINAL_2026-07-23.md)
4. [V1 to V2 Migration Plan](V1_TO_V2_MIGRATION_PLAN_FINAL_2026-07-22.md)
5. [Package Version and Compatibility Policy](V2_PACKAGE_VERSION_COMPATIBILITY_POLICY_FINAL_2026-07-22.md)
6. [Identity, Authorization, and Security Baseline Revision 1](V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md)
7. [Data, Point, and Payment Baseline](V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md)
8. [API v2 and Storefront Client Contract](API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md)

## Supporting Artifact

- [Release Gate example](release-gate.example.yaml) is a non-secret example
  manifest. It does not record an actual release decision or deployment.

## Status

- Governance Revision 2, Release Gates Revision 1, and the Autonomous GitHub
  Operations ADR contain the latest governance decisions.
- The five 2026-07-21/22 baseline documents are copied byte-for-byte from the
  approved external evidence directory.
- Only the Revision 1 identity, authorization, and security baseline is
  authoritative. Do not use the obsolete non-revision security document.
- V1 specifications and implementation remain behavioral references rather
  than V2 architecture authorities.

## Excluded Context

The chat handoff and project-status documents are context records, not
architecture authorities, and are intentionally not copied here. The
2026-07-22 Governance and Release Gates documents are superseded by the
committed revisions above and are also intentionally excluded.
