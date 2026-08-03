# MIG-061G Admin User List／Detail／Gacha History Report

## Task

- Task ID: `MIG-061G`
- Risk／Profile: `R3`／`DATA-R3-TARGETED`
- Base: `main@35ba11a1762a574ad1cf7528e825d92a2ed69a2c`
- Branch: `feat/MIG-061G-admin-user-management-read-model`
- Issue／PR: `#180`／Draft PR作成後に追記する。
- Task Policy SHA-256:
  - Initial: `ad1a7a279def2d189ecc818fc42202ba06f02f00cdfc63b782ed457c7b72fa10`
  - Final corrected: `e94dbac9d014fff7293f73e024617a5a2640dfa894bdf0dc2e722922af634c2b`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061G/`
- Final Head／Squash Commit: Closeoutで確定する。

## Policy補正

- 既存Allowed Pathsを維持し、次の5 Pathだけを完全一致で追加した。
  - `apps/api/app/Models/V2/User.php`
  - `apps/api/database/migrations-v2/2026_08_18_000031_add_display_name_to_v2_users.php`
  - `apps/api/tests/V2/IdentitySchemaTest.php`
  - `apps/admin/src/lib/permissions/admin-navigation.ts`
  - `apps/admin/test/permissions-navigation.test.tsx`
- Wildcard、Directory単位、中央Permission Matrix、`/users/history`、V1、Storefront、Payment、
  Nginx／TLS／DNSは追加していない。

## Characterization

- V1 `users.name`はLaravel既定長255の必須文字列で、追加の正規化Constraintはない。
  最新の人間決定に従い、V2では既存Userを破壊しないnullable `display_name`として移植する。
- V2のPassword登録とGoogle／LINE外部Identity作成には表示名入力Contractが存在しない。
  EmailやProvider情報から推測せず、User作成Serviceへ変更を加えない。
- Walletの`paid_balance`／`free_balance`がCurrent Canonical Balanceであり、合計残高はBackendで
  両者を整数加算する。Reserved残高は別Columnで、表示残高から再計算または控除しない。
- V1の「ユーザー保有景品」は`user_prizes`をUserで絞り、Statusで除外せず新しい順に表示する。
  このためV2の「ユーザーガチャ履歴」も現在状態に限定せず、過去を含む取得景品履歴を表す。
- V1詳細の状態変更、ポイント調整、QA操作、配送／景品操作はMutationであり本Taskへ移植しない。
  ポイント調整は後続Task候補として記録する。

## Implementation／Verification／Preview

実装、対象Test、Query性能、Preview反映、Fresh Self-review、Closeout結果を確定後に追記する。

## Gate

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- `/users/history`、ポイント調整Mutation、MIG-061H以降は開始しない。
