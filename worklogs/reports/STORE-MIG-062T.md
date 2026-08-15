# STORE-MIG-062T Banner Publish Admin UI Fix

## Task

- Task ID: `MIG-062T`
- Issue: `#271`
- PR: Phase Bで記録する。
- Risk: `R3`
- Base: `37035893ad649b17a526f5dd3477f8e30fae1f38`
- Branch: `fix/MIG-062T-banner-publish-admin-ui`
- Worktree: `/var/www/oripa-worktrees/MIG-062T`
- Task Policy SHA-256: `ee149e50e9128152d4fe8c3f0c9378dda6ef252abefff74132695ab46b6f8ac5`
- Final Head: Phase Bで固定する。
- Squash Commit: Merge後のCloseoutで記録する。

## Phase A

- Platform `main`、Open Issue／PR、Remote branch、local worktree、既存Task ID履歴を確認した。Active Platform Taskは0件で、Open項目はDependabot PRだけだった。
- 最新採番はmerge順に`MIG-062R`、既存Task系列の最大が`MIG-062S`であり、未使用の次番号`MIG-062T`を採番した。番号は推測せずGitHub全Issue履歴とTask Policy一覧を照合した。
- Allowed PathsはAdmin生成検証、Client、Banner UI、Unit／E2E、Worklog／Reportの8 exact path。ForbiddenはStorefront、Backend、OpenAPI source／bundle、DB／Migration、Cache、Dependency、V1、Infrastructure、他Task worktreeである。
- DependabotのOpen dependency PRとはApplication sourceの直接競合がなく、Dependency manifest／lockfileを変更しないためScope Gate／Conflict GateはPASSした。

## Implementation

- `publishAdminContentBannerVersion`をAdmin contract generatorのrequired operation集合へ追加し、既存Admin OpenAPI operationとの一致を生成時に検証する。
- `AdminApiClient.publishContentBanner(contentId, versionId)`を既存`POST /content/banners/{contentId}/versions/{versionId}/publish`へ接続した。Opaque ID validationと既存`AdminContentDetail` responseを再利用する。
- Banner一覧へCurrent Status、Version番号／ID、Publish列を追加した。Draftは`Draft`、公開後は`Published`として表示する。
- Effective `content.publish`がある場合だけDraftへ「公開する」Buttonを表示する。権限なしUserには操作を表示せず、Backend authorizationを正本として維持する。
- 同期`ref` Guardとsubmitting disabledで二重送信を防止し、成功ResponseをFrontendで状態変換せず、既存Banner management一覧を再取得してCanonical Published状態を表示する。
- 403／409／422／429を含む既存`AdminApiError`表示を再利用する。Banner登録／編集は従来どおりDraft Version作成であり、自動公開へ変更していない。

## Changed Files

- `apps/admin/e2e/admin-banner-management.spec.ts`
- `apps/admin/scripts/generate-admin-contract.mjs`
- `apps/admin/src/components/banners/banner-management-workspace.tsx`
- `apps/admin/src/lib/admin-api/client.ts`
- `apps/admin/test/admin-banner-management.test.tsx`
- `worklogs/new_ver_main.md`
- `worklogs/reports/STORE-MIG-062T.md`

## Checks

- Admin generated contract check: PASS。
- Admin Unit: 158 tests PASS。最終対象Banner Unit: 6 tests PASS。
- Admin Typecheck／Lint／Production Build: PASS。
- Banner Browser E2E: Desktop／Mobile 2 tests PASS。Console Error、Page Error、HTTP 500／502／504は0。
- 初回Browser E2E: FAIL。既存cross-origin Synthetic Asset fixtureがAdmin CSPで遮断された。Assertionは弱めず、same-origin Public API fixtureへ補正後PASSした。
- Policy: Unit 125 tests、Local `policy-gate` PASS。
- Quality: Unit 5 tests、Local `quality-gate` PASS。
- Security: Unit 10 tests、Composer／Root pnpm／Legacy pnpm Fresh Audit各0件、Secret candidate 0、Local `security-gate` PASS。
- GitHub Required Checks、CodeQL、Dependency Review: Phase Bでexact headに固定して記録する。
- 初回PR Head `52530798be5854225697315d82404b50b1518d88`: Policy／Security／Integration、CodeQL、Dependency Review PASS。QualityはMIG-062T非変更の`catalog-gacha-lifecycle.test.tsx` 1件が単発FAILし、`ci-gate`もFAILした。
- 同じGacha lifecycle testをlocalで5回反復し、全5回／15 testsがPASSした。同一headの公式Workflow再実行ではPolicy／Quality／Security／Integration／ci-gateがすべてPASSした。
- GitHub App wrapperは同一headに残る過去failed check-runもFail Closedするため、失敗履歴と再現結果を記録したdocs-only fresh headでRequired Checksを新規実行し、失敗履歴0を要求する。TestやAssertion、Gateは変更していない。

## Phase B

- PR作成、exact Final Head固定、Required Checks、GitHub-hosted Preview Artifact、Preview受入、Fresh Self-review、Squash Merge、Cleanupを実施して追記する。

## Preview

- Synthetic Banner登録、Top ON、Canonical `link_url`、Draft確認、Admin Publish、Canonical再取得Published、Public Banner一覧、Cache TTL後の`https://test.luxe-pack.biz/` Carousel、click遷移、Desktop／MobileをPhase Bで確認する。
- Cache強制削除、DB直接Publish、Storefront変更は行わない。

## Impact

- Migration created: 0。
- Migration applied: 0。
- API Contract shape: 変更なし。既存Admin operationを利用する。
- Backend／DB／Auth policy／Point／Payment／Draw／Inventory／Infrastructure／Storefront: 変更なし。
- Admin authorization: UI exposureはEffective `content.publish`に従い、Backend authorizationを維持する。
- Rollback: Admin imageを直前の検証済みdigestへ戻す。DB／Migration／Public Contract変更はない。

## Cleanup

- Issue Close、Remote/local task branch、dedicated worktree cleanup、local／Remote `main` equalityはSquash Merge後に記録する。
