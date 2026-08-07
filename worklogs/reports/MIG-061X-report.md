# MIG-061X Report

## Issue／PR／Commit

- Issue: #216
- PR: #217
- Base: `6cf5f2735e7d60915938064ba32c649a9d8b45d6`
- Application Head: `b8e355a4055fe54867be92ab352bf8073281a402`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `25fea2b8bef62fa3701ee3eb40635aa11fe57bb3b9cb63f19710b756e317c7fe`

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
- SEC-008後の`origin/main@83c573724601b7459fc35d3a73591008f908836c`を取り込み、CommonMark 2.9.0とRoot／Legacy `js-yaml 4.3.1`を維持した。
- Git Wrapperの固定Base累積検査に必要なため、Task PolicyへSEC-008由来の実在6 Pathだけを追加した（中間SHA `e7464621e2874372106fbe71247064c61748fc94ab760e6fd84c2983ea9030b0`、最終SHAは上記）。Repository差分とTask実装範囲は拡張していない。
- `AuthenticationFlowTest`の旧期限前提を補正し、Admin Sessionは16分後も有効、最後のActivityから6時間1分で失効すること、Storefront Sessionは12時間1分のIdleで失効することを確認した（15 tests／95 assertions PASS）。
- Nginx、V1、Storefront Repository、Payment Provider、Production／Preview Runtimeは非変更。
- 残課題: GitHub Required ChecksとCloseout。Runtime反映は本Taskでは実施しない。
