# MIG-061Q Report

## Issue／PR／Commit

- Issue: #202
- PR: Closeoutで確定
- Base: `b4d60ae123fff584e3209b363a833e83ec0c701a`
- Final Head／Squash Commit: Closeoutで確定
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
- Preview API／AdminとMigration反映結果、GitHub Checks、Self-review、Squash CommitはCloseoutで確定する。
- カテゴリ編集／削除、ページ削除、Storefront URL生成、Version履歴UIは対象外。Weekly limitは実行環境から取得できないため未記録。
- 主な所要作業はV1 Characterization、既存Content VersionへのカテゴリRelation追加、Task DB Target整合、対象UI／Contract検証。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Rは開始しない。
