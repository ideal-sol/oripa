# MIG-069 Canonical Gacha Admin UI Integration

## Task

- Issue: `#338`
- Risk: `R3`
- Base: `4d3eb24ec5450088bd2df6b8cfe1bc1b06173ddd`
- Branch: `fix/MIG-069-canonical-gacha-admin-ui`
- Worktree: `/var/www/oripa-worktrees/MIG-069-canonical-gacha-admin-ui`

## Scope

- The gacha edit screen places the basic form, Rank management, and prize management in that order.
- Published gacha core fields retain the MIG-067 whitelist: title, thumbnail, category, tags, description, notices, status, and publish end. Other visible sales/draw inputs remain disabled.
- The normal gacha UI no longer renders Probability selection, draft, stage, server validation, or Publish Preflight controls. Legacy API and compatibility code remain unchanged.
- Stable MIG-068 publish codes map to actionable Japanese messages without rendering request IDs or internal codes.
- Prize forms label `変更理由`, compute canonical total-count remainder including the current edit value exactly once, and block negative UI submissions while retaining Backend enforcement.
- Saved prize previews use the canonical `presentation_asset_id` and the authenticated presentation-asset content endpoint if no public path is available. No asset is copied or created by preview rendering.

## Validation

- PASS: `pnpm --dir apps/admin test catalog-read.test.tsx catalog-gacha-lifecycle.test.tsx catalog-gacha-rank-prize.test.tsx` — 3 files, 37 tests.
- PASS: `pnpm --dir apps/admin typecheck`.
- PASS: `pnpm --dir apps/admin lint`.
- PASS: `pnpm --dir apps/admin build`.
- Not run: Browser/E2E, shared Preview, runtime activation, deployment, and artifact release; all are outside this Admin-only task.

## Impact

- Migration: none created or applied.
- API, OpenAPI, generated contract, Storefront, Backend domain, payment, point, draw, and inventory semantics: unchanged.
- Preview/Runtime/Production: unchanged.
