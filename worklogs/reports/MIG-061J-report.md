# MIG-061J Report

## Task

- Issue: #188
- PR: #189
- Base: `ebb111830b767fba0d64ae562f84b853d7fafa38`
- Implementation Commit: `49b476343ad7255baa78a31af63056defc68fbc7`
- Task Policy SHA-256: `c3dc2801064941c7f7f7183124dc89a5eccbf718047a5576636340f485edf5c3`
- Verification: `TARGETED-DRAW`

## 判定規則

- `all_users`は通常Drawを許可する。
- `first_time_users`は`draw_requests.status = completed`かつ`is_qa_draw = false`の成功済み通常Drawが0件の場合だけ許可する。失敗、Rollback、処理中、QA Draw、Idempotent Replayは新しい成功履歴として数えない。
- `line_users`は未失効のLINE External Identityと、同一User／Subjectの`line_friendships.status = friend`が両方存在する場合だけ許可する。
- `daily_draw_limit = 0`は無制限。正数はUser／Gacha Master／Asia/Tokyo日単位で成功済み通常Drawの`executed_count`を集計し、1／5／10／100／1000回を実Draw数として扱う。
- Audience／日次上限の拒否はPoint、Inventory、販売口数、Draw Result、User Prize更新前に行い、RFC 9457の`GACHA_AUDIENCE_NOT_ELIGIBLE`または`DAILY_DRAW_LIMIT_EXCEEDED`を返す。

## Concurrency

- 既存順序のIdempotency Lock、Gacha Master Lock、Active Draw State Lockを維持し、通常Drawの判定前にUser RowをLockする。
- 同一User／同一GachaはGacha Lockで直列化し、別Gachaの初回ユーザー競合はUser Lockで直列化する。
- Process Concurrency Testは日次上限10に対する10回Draw 2並列で、成功1件、Typed拒否1件、Draw Result／販売口数10を確認した。

## Test

- Task DB: `oripa_v2_mig061j`、Marker `MIG-061J`、Purpose `v2-task-ephemeral`、Migration 32件。DB Target Safety Guard PASS。
- 対象Domain／HTTP／QA: 11 tests、99 assertions、PASS、7.373秒。
- Process Concurrency: 1 test、9 assertions、PASS、1.262秒。
- Syntax、Local Policy Gate、Local Quality Gate、`git diff --check`: PASS。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061J/`
- Schema／Migration、Admin／Storefront、Preview、V1は変更していない。

## 所要時間

- 主な時間はPublished Version immutableを維持したFixture拡張、Task専用PHPUnit DB固定、JST境界のOffset付き`timestamptz`比較確認に要した。
