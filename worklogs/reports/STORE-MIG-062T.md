# STORE-MIG-062T Banner Publish Admin UI Fix

## Task

- Task ID: `MIG-062T`
- Issue: `#271`
- PR: `#272` (`https://github.com/ideal-sol/oripa/pull/272`)
- Risk: `R3`
- Base: `37035893ad649b17a526f5dd3477f8e30fae1f38`
- Branch: `fix/MIG-062T-banner-publish-admin-ui`
- Worktree: `/var/www/oripa-worktrees/MIG-062T`
- Task Policy SHA-256: `ee149e50e9128152d4fe8c3f0c9378dda6ef252abefff74132695ab46b6f8ac5`
- Application／Preview Head: `2065b48e1ae59dcd9dd3278955ae8ab59787275f`
- Final Head: このReportを含むdocs-only headをFresh CI前に固定し、exact SHAをPR／Self-review／Issue Closeoutへ記録する。
- Squash Commit: gate-compliant merge後にIssue Closeoutへ記録する。Commit済みReport自身へmerge後SHAを後書きしない。

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
- Fresh Head `2065b48e1ae59dcd9dd3278955ae8ab59787275f`の`policy-gate`／`quality-gate`／`security-gate`／`integration-gate`／`ci-gate`、CodeQL、CodeQL JavaScript、Dependency Review: PASS。GitHub App wrapper判定も`passed: true`、failure／missing 0。
- 初回PR Head `52530798be5854225697315d82404b50b1518d88`: Policy／Security／Integration、CodeQL、Dependency Review PASS。QualityはMIG-062T非変更の`catalog-gacha-lifecycle.test.tsx` 1件が単発FAILし、`ci-gate`もFAILした。
- 同じGacha lifecycle testをlocalで5回反復し、全5回／15 testsがPASSした。同一headの公式Workflow再実行ではPolicy／Quality／Security／Integration／ci-gateがすべてPASSした。
- GitHub App wrapperは同一headに残る過去failed check-runもFail Closedするため、失敗履歴と再現結果を記録したdocs-only fresh headでRequired Checksを新規実行し、失敗履歴0を要求する。TestやAssertion、Gateは変更していない。

## Phase B

- Draft PR #272を作成し、初回Headの単発flaky履歴を隠さず記録した。docs-only fresh Head `2065b48e1ae59dcd9dd3278955ae8ab59787275f`ではRequired Checks、CodeQL、Dependency Reviewが失敗履歴0でPASSした。
- GitHub-hosted `linux/amd64` Preview Build Run `31855942152`はPASS。Artifact ID `9239067687`、Artifact digest `sha256:1b65a112919bc5ceedca2cfc40bc64dd4369fc6213049c9b7bba35f093af8822`、manifest SHA-256 `37b66f55c8e78fff3ce386d85e66e637be1a163e6219409d3f9c9ac840578c73`、OCI revision、Architectureを検証した。
- Host Buildは行わず、Adminだけを`oripa-v2-admin:preview-MIG-062T-2065b48e1ae5` (`sha256:e69160e91d1ec0a7336bc9bf6cd5bf71a5eb86d848ae78ea7a11c6ba68f93b13`)へ`--no-build --no-deps`で更新した。APIは既存MIG-062R image、PostgreSQL／Redisは既存healthy containerを維持した。
- Final docs-only headのFresh Required Checks、exact-head Self-review、Squash Merge、Issue／branch／worktree cleanupはReport commit後に実行し、exact値をPR／Issue Closeoutへ記録する。

## Preview

- `admin.luxe-pack.biz`の実Admin UIで安全なPNG Synthetic Bannerを登録し、「トップに表示」ON、Canonical `link_url=/gachas`、`Draft`、Version `v1`、`content.publish`に従う「公開する」Buttonを確認した。
- Admin UIから既存Publish operationを実行し、Canonical一覧再取得で`Published`とPublish Button消失を確認した。Desktop `1440x1000`／Mobile `390x844`のDraft Publish UI、Desktop Published UIを画像Evidenceへ保存した。
- `GET https://test.luxe-pack.biz/api/v2/content/banners`は200、`Cache-Control: max-age=60, public`。強制Cache削除なしでTTL経過後にSynthetic BannerとCanonical `/gachas`を確認した。
- `https://test.luxe-pack.biz/` Carouselへ対象Bannerが表示され、クリック後のpathnameが`/gachas`であることを確認した。Storefront Repository／Runtimeは変更していない。
- Synthetic Bannerは既存Admin削除UIで削除済み。初期Category 0件だったため既存「カテゴリ追加」operationでpublic-safeな`MIG-062T Preview`を作成した。Category delete contractは存在しないため再利用可能なPreview categoryとして保持し、DB直接削除は行わない。
- Functional acceptance 10項目はPASS。Page Error、HTTP 500／502／504は0。既存Admin CSPが登録時`blob:` previewを拒否し、既存4 Bannerの相対`/api/v2/content/assets/{id}`がAdmin originで404となるConsole Errorを観測した。Publish operation、Public Banner Contract、Storefront Carouselには影響せず、Allowed Paths外の既存事象としてAssertionを弱めず記録した。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-062T/preview/result.json`、Desktop／Mobile／Storefront screenshot。Credential値はRepository／Report／通常Logへ記録していない。
- Cache強制削除、DB直接Publish、Storefront変更は行わない。

## Impact

- Migration created: 0。
- Migration applied: 0。
- API Contract shape: 変更なし。既存Admin operationを利用する。
- Backend／DB／Auth policy／Point／Payment／Draw／Inventory／Infrastructure／Storefront: 変更なし。
- Admin authorization: UI exposureはEffective `content.publish`に従い、Backend authorizationを維持する。
- Rollback: Admin imageを直前の検証済みdigestへ戻す。DB／Migration／Public Contract変更はない。

## Cleanup

- Synthetic Banner cleanup: PASS。Preview Category `MIG-062T Preview`は削除Contractがないため保持。
- Issue Close、Remote/local task branch、dedicated worktree cleanup、local／Remote `main` equalityはSquash Merge後にPR／Issue Closeoutへexact値を記録する。
