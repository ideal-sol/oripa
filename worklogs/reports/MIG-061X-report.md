# MIG-061X Report

## Issue／PR／Commit

- Issue: #216
- PR: Closeout時に確定
- Base: `6cf5f2735e7d60915938064ba32c649a9d8b45d6`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `b7163f42976e42a7427994bb08dbeb16b741d1f62248e84c2983ea9030d4ea26`

## Session Policy

- Admin: Idle 6時間、Absolute 12時間。
- Storefront: Idle 12時間、Absolute 24時間。
- Backend正本は`apps/api/config/v2_identity.php`。既存GuardのIdle延長とAbsolute上限処理を再利用する。
- Migration `2026_08_27_000040_update_v2_session_timeout_constraints.php`で、Session TableのDB Duration Checkを同じ上限へ更新する。
- Admin Remember me禁止、Storefront Remember me有効、Cookie名／属性、CSRF、Session rotation、認証Contractは変更しない。
- OpenAPIにはSession timeoutの固定値記述がないため変更しない。

## Verification

- 専用PostgreSQLでMigration fresh、既存Sessionを含むrollback／reapplyがPASSした。Rollback時はUser Sessionを旧Idle上限へ縮め、旧Absolute上限を超えるAdmin SessionをFail Closedで失効する。
- `RealmSeparationTest`は6 tests／42 assertionsがPASSし、設定値、Session Managerの実発行期限、Realm分離、Cookie／Guard継続動作を確認した。
- Policy Unit 111 tests、Local Policy Gate、Local Quality Gate、PHP構文、`git diff --check`がPASSした。
- Nginx、V1、Storefront Repository、Payment Provider、Production／Preview Runtimeは非変更。
- 残課題: GitHub Required ChecksとCloseout。Runtime反映は本Taskでは実施しない。
