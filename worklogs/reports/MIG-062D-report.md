# MIG-062D Report

## Issue／PR／Commit

- Issue: #233
- PR: 作成後に確定
- Base: `e4bd8ecb9bc6e95785142411b2a0cadc63336d5f`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `060f0c443dd8e1783a38d53f553828adbd633050c393fce119e8eeb2f96a8081`

## Schema／Eligibility

- Migration `2026_08_29_000043_add_v2_point_purchase_plan_target_tag.php`で、`point_purchase_plans.target_user_tag_id`をnullable FKとして追加した。Tag未指定は既存商品の挙動を維持し、1商品につき最大1 Tag、Tag削除はrestrict、既存商品はNULLのまま保持する。
- EligibilityはMIG-061Vの`all_users`／`first_purchase_users`と独立したTag条件をAND適用する。MIG-062Bの`user_tag_assignments`をCanonical Sourceとし、Payment開始時と成功確定時の双方でUser lock後に再検証する。複数Tag保持は許容し、対象Tag 1件の保持だけを判定する。
- 無効Tagは新規商品／更新で選択不可。既存商品と既存User割当は保持し、既に割り当てられたUserのEligibilityを勝手に失効させない。

## Admin API／UI

- 商品一覧／詳細Responseへ内部DB IDを含まない`target_user_tag`を追加し、登録／編集Inputへoptional nullableなTag Public IDを追加した。省略時はTagなしとして後方互換を維持する。
- 一覧へ「対象タグ」列、登録／編集へ「指定なし」をDefaultとする単一選択を追加した。無効Tagは状態付きで表示するが選択不可。Operatorは一覧を閲覧できるが編集導線を表示せず、既存API PermissionでもMutationを403にする。
- Tag参照は既存Admin Tag APIを全Cursor取得して再利用し、商品一覧はTagをleft joinしてN+1を発生させない。

## Public Filter／Cache

- V2 Publicポイント商品一覧／購入開始Routeは現行Repositoryに存在しないため、本Taskで推測新設していない。V1 Public Routeは変更していない。
- 将来のPublic一覧は本TaskのCanonical EligibilityでServer-side filterし、User固有Responseを`private, no-store`とする必要がある。FrontendでUser Tagを解釈する実装は追加していない。

## Verification／Preview

- Task DB `oripa_v2_mig062d`でTarget Safety Guard、Migration 43件、`000043` rollback／reapply、既存商品NULL Backfill、restrict FKを確認した。
- Backend 8 tests／54 assertions、Admin Unit 3 tests、Desktop／Mobile Browser 2 tests、Typecheck、対象Lint、Production Build、OpenAPI bundle／Generated Client、Policy Unit／Gate、Quality Gate、`git diff --check`がPASSした。
- Preview反映結果は反映後に確定する。

## 残課題

- Storefront Publicポイント商品一覧／購入開始Contractは未実装。Payment Provider接続、Point付与量／有効期限、期間限定Bonusは本Taskで変更していない。
- Gate G4／G5は`NOT COMPLETE`を維持する。
