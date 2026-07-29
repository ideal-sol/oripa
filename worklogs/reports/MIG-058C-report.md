# MIG-058C LINE Friend Reward Configuration Completion 提出用レポート

## 基本情報

- Task ID: `MIG-058C`
- Risk: `R3`
- Issue: `#133`
- Branch: `fix/MIG-058C-line-friend-reward-settings`
- Base: `2834eba8242867a5b7400e4bada7cafe0f91f86c`
- 対象: LINE友だち追加Rewardの有効化、free Point額、有効期限の
  Admin Contract／UI／Domain／DB制約
- 対象外: LINE資格情報、Push系Messaging、Storefront UI、Gacha管理、
  Payment Provider、Domain／Nginx／TLS、Deployment

## MIG-058B Closeout

- Issue `#131` Closed、PR `#132` Squash Merged。
- Final Head: `cc2511973eb49a5d8e4fa088d0361c6fb52e8ab7`
- Squash Commit: `2834eba8242867a5b7400e4bada7cafe0f91f86c`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup、Local main同期、V1非変更を確認。

## 再利用した基盤

- MIG-058BのLINE Login、Webhook署名、Reply Message、Pending Follow、
  Friendship、External Identity照合を変更せず再利用した。
- MIG-043のPoint Service／Wallet／Operation／Lot／Ledgerと、
  Friendship Public ID由来Business Keyを再利用した。
- MIG-042のAppend-only Audit／Transactional Outbox、MIG-053AのFresh MFA、
  MIG-060系のAdmin Shell／API Client／Conflict境界を再利用した。
- 新しいLINE Login Table、別Point実装、別Permission、別Idempotency基盤は
  作成していない。

## Schema／Domain

- Forward-safe Migration:
  `2026_08_07_000020_add_line_friend_reward_enabled.php`
- `reward_enabled` Default: `false`
- `reward_point_amount` Default: `0`
- `reward_expiration_days` Default: `180`
- DB CHECK:
  無効時Amount 0、有効時Amount 1～1,000,000、Expiration 1～3,650日、
  Revision 1以上。
- Dump／Restore時のSchema checksumを安定させるため、`BETWEEN`を使わず、
  `bigint`／`integer`の明示Castを含むCanonical比較とした。
- Point上限1,000,000は承認済みV1 Point管理境界と同期し、V2 Point Domainでも
  `MAX_LINE_FRIEND_REWARD_AMOUNT`としてFail Closedにした。

## Reward処理

- 有効時は設定Amountをfree Pointとして一度だけ付与し、設定日数をLot期限にする。
- Point、Friendship、Audit、Outboxは同一Transactionで確定する。
- 無効時はFriendshipだけを確定し、Wallet、Operation、Lot、Ledger、
  Rewarded状態を作らず、`reward_disabled`をRedaction済みAuditへ記録する。
- Webhook Redelivery、Concurrent Follow、Unfollow／Re-follow、設定変更後も、
  既に付与済みのFriendshipへ差分付与・再付与しない。
- Point失敗時は完了Replyを送らない。Reply失敗は確定済みPointをRollbackしない。

## Admin Contract／UI

- 既存LINE Messaging設定取得／Preview／更新へ
  `reward_enabled`、`reward_point_amount`、`reward_expiration_days`を追加した。
- Owner-only、`identity.line.manage`、Admin Realm、Fresh MFA 5分、
  CSRF、Exact Origin、JSON、Critical Mutation Rate Limit、
  Idempotency-Key、Revision OCC、Canonical Replay、Audit／Outboxを維持した。
- Admin UIへ有効Toggle、free Point表示、Amount、Expiration、現在状態、
  Server Preview、Validation、Conflict、Fresh MFA、Canonical再取得を追加した。
- Secret、Channel Secret、Access TokenはContract／UI／Log／Auditへ出さない。

## Test／検証

- OpenAPI Bundle／Check: PASS。Operation数はAdmin 96、Public 47、Webhook 1。
- Admin Contract生成／生成差分0、Typecheck、Lint、Production Build: PASS。
- Admin Unit／Component: 39 Test PASS。
- Admin Browser E2E: 11 Test PASS、55.6秒。
- LINE Messaging／Login対象: 23 Test／194 Assertion PASS。
- Webhook Concurrent Follow: 1 Test／10 Assertion PASS。
- Storefront Client 14 Test、Site Schema 10 Test、Storefront Testkit
  22 Testを依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASS。
- Persistent Guard: `migrate:fresh` 2回、最新Migration Rollback／Reapply、
  全V2 Suite、PostgreSQL／Redis Health、Schema InventoryがPASS。
- Ephemeral Guard: `migrate:fresh` 2回、全V2／Load／Performance Test、
  Backup／Restore、API／Admin Health、Task Resource CleanupがPASS。
- Migration Setは20件、SHA-256は
  `2bbfb220d6fd5412eba404ce2331cbd4df922422ba01c4aff8a9b5f45f49532f`。
- Backup SHA-256は
  `e8ecd6c43cfa8c6c216a7149e9e97e6450c73474590699ffa24c046bb974f193`。
  Source／Restore Schema SHA-256は
  `ac0dcef6f79ed025b50b83c4e57ee90dfdea61c59b4df3934a1a683e8622e025`、
  Migration Row SHA-256は
  `280948a670af7612bc6449adee96e1dae5d22e5cbe756605d0fcc072a875826a`
  で一致した。
- Policy Unit 88件、Policy Gate、Quality Unit 5件、Quality Gate、
  Security Unit 4件、Security GateがPASS。Root Audit 0、Legacy Audit 11、
  Composer既存Baseline 10、新規Critical／High 0、Secret Candidate 0。
- Release Unit 10件、Artifact Source Validation、Legacy Typecheck、
  Legacy Lint既存8 Error／1 Warning完全一致、Composer Manifest、
  `git diff --check`がPASS。

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| Root Frozen Install | 7.7秒 | Worktreeに`node_modules`がなくOpenAPI Lintを開始できなかったため、Lockfile固定で導入 | PASS |
| Admin Browser E2E | 55.6秒 | 既存11 Scenarioを維持しReward設定を同じLINE Flowへ追加 | PASS |
| Persistent Guard初回 | 106.8秒 | TestのWallet非生成期待、Rate Limit状態共有、Carbon型比較を検出 | Test修正 |
| Persistent Guard再試行 | 106.4秒 | Audit Column名を`action`としたTest誤記を検出 | `action_code`へ修正 |
| 対象API Image Build | 約30秒 | 3回目の全回帰前にLINE対象だけを先行確認 | 既存BuilderでPASS |
| LINE対象HTTP Test | 7.1秒 | `vendor/bin/phpunit`とV2設定を明示 | 23 Test PASS |
| Concurrent Follow | 1.1秒 | 全Suite前に二重付与防止を先行確認 | PASS |
| Persistent Final初回 | 約52秒 | Canonical CHECKのReward OR節に閉じ括弧が不足 | 明示Castを維持して修正 |
| Persistent Final | 約126秒 | `migrate:fresh` 2回、全V2 Suite、Rollback／Reapply | PASS |
| Ephemeral Final | 約348秒 | Backup／Restore、全Load／Performance、Cleanup | PASS |
| Admin／First-party Package | 約2分 | 依存Build前の並列実行を避けSerial実行 | 全PASS |
| Push前Secret検査 | 約10秒 | Test用Bearer文字列が高確度Patternに一致 | `test-token`へ短縮、Final Tree 0件 |

- 追加の効率改善指示に従い、既に完了したFull Suite Evidenceを破棄せず、
  3回目の重いGuard前に対象HTTP／Admin Smokeを先行した。
- Root 4.2GB、`/tmp` 3.3GBの空きをRead-only確認した。直前Taskで同水準の
  容量不足が発生していたため、稼働Resource、Image、Named Volumeへ触れず、
  未使用Docker Build Cacheだけを削除して2.33GBを回収した。
- Persistent Final前はRoot 5.1GB、Ephemeral Final前は4.7GBを確認し、
  追加Cleanupは不要と判断した。
- First-party Packageは依存順にSerial実行した。既に完了していたAdmin Test、
  Root／Legacy Install、対象HTTP Testは重複実行していない。
- Runtime API ImageにComposerがないため空になったAudit採取は、
  Dockerfile固定Composer digestで再採取し、Host Toolchain更新を回避した。
- Test用Bearer文字列置換後の単独Test再実行は、常駐Persistent DBの既存Fixtureと
  件数前提が衝突したため正本Evidenceに採用していない。PHP構文とFinal Treeの
  高確度Secret候補0を確認し、置換前にClean DBで成功した全V2 Suiteを維持した。
  Clean DBでの最終回帰は同一HeadのGitHub Checkで確認する。
- Policy Gateの明示Migration集合だけを新Migrationへ同期し、Gate、Baseline、
  Assertion、Security／Quality範囲は縮小していない。

## 非変更

- V1 Migration 40件の正本Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- LINE Login／Webhook／Reply Message Contract、Google OIDC、Draw、
  Payment、Production Resourceは非変更。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証はすべて成功した。Final Head、Squash Commit、GitHub Check、
  Fresh Self-review、CleanupはCloseout時に確定する。
- 次Task候補: `MIG-060F Admin Gacha Version Management`
- MIG-060Fは開始しない。
