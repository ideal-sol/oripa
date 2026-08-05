# MIG-061Q Report

## Issue／PR／Commit

- Issue: #202
- PR: #203
- Base: `b4d60ae123fff584e3209b363a833e83ec0c701a`
- Application Head: `78ad60136c4919e2505117ab786e02d614d45cfc`
- Final Head／Squash Commit: GitHub Closeout結果を提出時に確定
- Task Policy SHA-256: `5b0a599bb9a3dc52bfe94760f47cf9407289edd519178733283292dd33352260`

## V1移植／API／Migration

- V1の一覧順「ページ／URL／更新日時／操作」と編集項目「タイトル／本文内容」を正本として移植し、人間決定のカテゴリ、slug、表示状態を追加した。
- V2向けにPublic ID、Cursor、Admin Realm、`content.read`／`content.manage`、RFC 9457、Idempotency、Append-only Audit、immutable Content Versionを使用した。V1 API／DBは参照しない。
- 管理専用にカテゴリ一覧／作成、ページ一覧／詳細／作成／更新の6 Endpointを追加した。削除Endpoint、Storefront URL生成は追加していない。
- slugは前後空白と先頭／末尾slashを除去し、lowercase英数字とhyphenだけを許可して一意にする。表示は既存公開Pointerにより`visible=published`、`hidden=draft`とし、過去の公開Versionは保持する。
- Migration `000035`で`content_page_categories`とVersion単位のnullable Page Category relationを追加した。カテゴリは表示／非表示を保持し、非Static Page VersionへのRelationをTriggerで拒否する。

## Test／Preview／残課題

- Task DB `oripa_v2_mig061q`、Marker `MIG-061Q`、Purpose `v2-task-ephemeral`でTarget Guard、35 migrations、fresh、rollback／reapplyがPASSした。
- Backend 3 tests／23 assertions、Admin Unit 24 files／116 tests、Desktop／Mobile Browser 2件、Typecheck、Lint、Production Build、OpenAPI 187 operations、Generated Client、Policy／Quality、`git diff --check`がPASSした。Vitestの対象指定は全24 filesへ展開される挙動を記録し、同一Headで再実行しない。
- GitHub初回Checkで検出したPolicy Unit FixtureのMigration 000035不足と、既存Banner Categoryのoptional `created_at`を移動していたOpenAPI挿入位置を補正した。Policy Unit 104件、OpenAPI Breaking、Generated Client、Policy／Quality Gateを再確認し、Gate条件は緩和していない。
- GitHub Integration Gateで検出した新規`content_page_categories`の厳密Schema Inventory未登録を、DB GuardとUnit Testへ完全一致で追加した。WildcardやInventory判定の緩和は行っていない。
- DB Guard補正Push直後のWorkflowが更新前PR本文を参照してChanged files不一致となったため、PR本文23 Pathと実Diffの一致をGitHub APIで確認した。Canonical retrigger後も同一SHAへ旧Failure Runが残るため、この記録を含む新HeadでChecksを実行する。
- Preview DB `oripa_v2_mig061a`へMigration `000035`だけを適用し、Target Guardは35 migrationsでPASSした。既存Dataは保持し、`migrate:fresh`は実行していない。
- Preview APIを`sha256:4b528540...`から`sha256:cc516763...`、Adminを`sha256:8afac4e4...`から`sha256:4b9d4370...`へ更新した。両ImageはApplication HeadをOCI revisionに保持する。
- Owner Login後に一覧、新規登録、カテゴリ即時反映、編集Routeを確認した。Synthetic Category 1件と非表示Synthetic Page 1件に限定し、Desktop／Mobile、Console／Page Error 0、HTTP 500／502／504 0、横溢れなしでPASSした。
- PostgreSQL／Redis設定、Network／固定IP、Nginx checksum、V1、Storefront、Payment Providerは変更していない。`admin.luxe-pack.biz/login`と`luxe-pack.biz`はHTTP 200を維持した。
- GitHub Checks、Fresh Self-review、Squash CommitはCloseoutで確定する。
- カテゴリ編集／削除、ページ削除、Storefront URL生成、Version履歴UIは対象外。Weekly limitは実行環境から取得できないため未記録。
- 主な所要作業はV1 Characterization、既存Content VersionへのカテゴリRelation追加、Task DB Target整合、対象UI／Contract検証。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Rは開始しない。
