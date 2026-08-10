# MIG-062B Report

## Issue／PR／Commit

- Issue: #226
- PR: Draft PRを初回Push後に接続
- Base: `0f5687dce15a197db429c22e8927343caf04d3ce`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `c25aae5e272c43a4f78a89a0fe29377bfd34bf3c8e78a54627554558fe92a216`

## Schema／Admin API

- Migration `2026_08_28_000041_create_v2_user_tag_management.php`で`user_tags`、`user_tag_assignments`、`users.tag_assignment_revision`を追加した。Tag Public ID、正規化名Unique、Master revision、User単位のTag-set revision、複合Primary Keyで重複付与をDBでも拒否する。
- Admin APIはTag一覧／作成／更新と、User単位のTag一覧／付与／解除を追加した。Public ID、Cursor、Revision OCC、Idempotency、Fresh MFA、Append-only Audit、RFC 9457、`private, no-store`を適用し、内部DB IDを返さない。
- `user.tag.read`はOwner／Admin／Operator、`user.tag.manage`はOwner／Adminだけへ付与した。OperatorのMutationはAPI／Serviceで403となる。

## Admin UI／無効Tag

- 左メニューの「ユーザー ＞ 会員タグ」へTag Master一覧、作成、編集、有効／無効を実装した。User詳細には複数Tag表示と付与／解除Dialogを追加し、保存後にCanonical User詳細を再取得する。
- 無効Tagの既存割当は表示・解除可能なまま維持し、新規付与だけをBackendとUIの両方で拒否する。Tag Masterを無効化しても既存Assignmentは削除しない。
- Desktop／Mobile、Table横スクロール境界、Escape、Focus trap／復帰、Loading／Empty／Error／Conflictを対象Testで確認した。

## Verification／Preview

- Task DB `oripa_v2_mig062b`／Marker `MIG-062B`でTarget Safety Guard、41 Migration fresh、最新Migration rollback／reapplyがPASSした。
- Backend 11 tests／197 assertions、Admin Unit 4 files／37 tests、対象Browser 2 tests、Typecheck、対象Lint、Webpack Production Build、OpenAPI lint／bundle、Generated Client、Policy Unit 113 tests、Policy／Quality Gate、`git diff --check`がPASSした。
- 通常Turbopack Local BuildはWorktree外node_modules symlinkを拒否したため、Application Errorではなく検証環境制約として停止した。Preview Candidate ImageのFrozen Install／通常`pnpm build`を正本として確認する。
- Preview反映、Image Digest、Migration 000041適用、Owner／Admin／Operator Smokeは最終Application Headで1回実施する。Nginx、V1、Storefront、Point、Gacha、Paymentは変更しない。

## 残課題

- Tag限定ポイント購入プランとStorefront Public Contractは後続Task。本TaskではTag MasterとUser Assignment Domainまでを提供する。
- Gate G4／G5は`NOT COMPLETE`を維持する。
