# MIG-062B Report

## Issue／PR／Commit

- Issue: #226
- PR: #227
- Base: `0f5687dce15a197db429c22e8927343caf04d3ce`
- Application Head: `e70087bcb55fe9c4f9a944c034dc6669e1ea21f1`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `bf9052785e3c104781e70270f33c9a735e924a3fce55a538638fea05057387b0`
  （Schema inventory補正前: `c25aae5e272c43a4f78a89a0fe29377bfd34bf3c8e78a54627554558fe92a216`）

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
- Backend 12 tests／201 assertions、Admin Unit 4 files／37 tests、対象Browser 2 tests、Typecheck、対象Lint、Webpack Production Build、OpenAPI lint／bundle、Generated Client、Policy Unit 113 tests、Policy／Quality Gate、`git diff --check`がPASSした。
- GitHub Integration Gateで新規Table 2件のCanonical Schema inventory未登録を検出したため、Policyへ`scripts/db/v2_database.py`と`tests/db/test_v2_database.py`だけをAtomic追加した。Inventoryへ`user_tag_assignments`／`user_tags`を登録し、DB Unit 33 testsとTask DB実Schema 98件の完全一致を確認した。
- 通常Turbopack Local BuildはWorktree外node_modules symlinkを拒否したが、Candidate Docker Buildではpnpm `10.12.1`のFrozen Installと通常Turbopack `pnpm build`がPASSし、`/users/tags`を含むProduction Artifactを作成した。
- Preview DB Safety Guardが`oripa_v2_mig061a`／Marker `MIG-061A`／Purpose `v2-persistent`でPASSした後、Migration `000041`だけを適用した。Migrationは40件から41件となり、適用後GuardもRepository set一致でPASSした。
- Fresh Self-reviewで不正CursorのProblem Details変換漏れを検出し、対象Testを追加して修正した。API Imageを`sha256:e9cd6235b7e6ed88765e98bcf268010e759594d220a0e147fba819fea7a3634a`、Admin Imageを`sha256:32bcb9cb2426f11046fb3186eb7e8765027d88fd4e681deb12defc968ff536ee`へ更新した。Container名、Network、固定IP、Loopback Port、Restart Policy、Environment Key集合は不変である。
- Owner PreviewでTag作成、User付与、Tag無効化後の既存付与維持、Desktop／Mobileを確認した。Console／Page／500・502・504は0件、API HealthとLoginは200、未認証Tag APIは401であった。
- 旧API Image `sha256:f452b1f213a76fd5ada87fdaf229a023215d4dca5f43426c17036b1297ecc705`と旧Admin Image `sha256:6ab94d4c3925df6e4a942b2f2051ae9926c363e3721582e0942530b96c231f73`はRollback用に保持した。Nginx checksum、V1、Storefront、Point、Gacha、Paymentは変更していない。

## 残課題

- Tag限定ポイント購入プランとStorefront Public Contractは後続Task。本TaskではTag MasterとUser Assignment Domainまでを提供する。
- Gate G4／G5は`NOT COMPLETE`を維持する。
