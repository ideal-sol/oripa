# MIG-061K Report

## Task

- Issue: #190
- PR: #191
- Base: `92716d166bd829a236f25e7c9e36e76c46aebdc2`
- Application Commit: `9099e4dd66e9524c26f3dc09c35083a1dc55fa2b`
- Final Head／Squash Commit: Closeoutで確定
- Task Policy SHA-256: 初回`2ad21acf8b9e9f0d0baf613a098c22dbb0159e4f0b875d2d6dcff7db90a3cde3`、Strict Schema Test追加後`91b35654d8c299484fa1cd87402d9efc60604cd80db5c533d9beab1ff4039482`

## V1移植

- V1のガチャ別Rank設定、Rank画像／抽選演出動画、PrizeのRank・名称・画像・在庫上限・原価・交換Point・状態をRead-only Characterizationした。
- V2ではPublic ID、Catalog Master、編集可能Draft Version、Presentation Asset、Idempotency、Revision OCCへ接続した。Published Versionは直接変更しない。

## Rank／Prize API・画面

- Draft Version単位のRank一覧／作成／更新とPrize一覧／作成／更新をAdmin OpenAPIへ追加した。既存`catalog.manage`、Admin Realm、RFC 9457、`private, no-store`、Audit／Outbox／Idempotencyを再利用した。
- Rank Modalはキー、表示、説明、画像、抽選演出動画を扱う。削除は安全な正本がないため追加していない。
- Prize ModalはRank、名称、Thumbnail、総在庫、交換Point、原価、状態を扱い、指定された10列順で一覧表示する。原価はDraft Gacha Prize専用Contractだけに限定し、汎用Prize APIでは非露出を維持した。
- 保存後はVersion Revisionを含むCanonical一覧を再取得する。Desktop／Mobile、Escape、Focus復帰、二重送信防止を確認した。

## 在庫／Migration

- 現在個数はActivated inventoryがある場合`initial_quantity - won_count`、未Activation DraftではVersion relationの`initial_inventory`を正本とし、Frontend計算を行わない。
- 総在庫を確定済み`won_count`未満へ減らす変更は409で拒否する。Inventory、Prize、Version relationを同一Transactionと既存Lock順で更新する。
- Migration 000033でRank説明、Prize原価、Draft VersionとRankの明示RelationをForward-safeに追加した。既存Prize relationをBackfillし、Published参照とDraft境界をTriggerで保護する。Task DBで33件Fresh、000033 rollback／reapplyを確認した。

## 対象Test

- Backend 3 tests／26 assertions PASS。Rank／Prize CRUD、Asset media、Public ID、Permission、Idempotent Replay、Version Revision、Published直接変更拒否、DB Guard、在庫を確認した。既存Catalog Strict Schema Testは`cost_price`の正式追加へ期待値を更新し、1 test／35 assertions PASSした。
- Admin対象Unit 3件PASS。初回対象指定が全Unit Suiteへ転送されたRunも18 files／97 tests PASSし、同一Headの重複全Suiteは避けた。
- Admin Typecheck、全Lint、Production Build、対象Browser E2E 1件PASS。Desktop／Mobile、列順、Modal、Escape／Focus、横溢れなしを確認した。
- OpenAPI Lint／Bundle／Breaking、Generated Client差分0、Policy Unit 98件／Gate、Quality Gate、PHP構文、`git diff --check`がPASSした。GitHub初回Policy Unitで検出したV2 Identity Migration fixtureへの000033不足を完全一致で補正し、全98件を再実行した。Integration Gateで検出したCatalog Strict Schema Testの旧`cost_price`不存在期待値は、既存Test Path 1件だけをTask PolicyへAtomic追加して補正した。FAST-TRACK指定どおりLocalのFull V2 Suite、Full Guard、Backup／Restore、Draw負荷、V1全回帰、全Admin E2E、全Local Security Gateは実行していない。

## Preview反映

- DB Target Safety Guardで`oripa_v2_mig061a`、Marker `mig061a`、Purpose `v2-persistent`、000033だけ未適用を確認し、既存Dataを保持したまま同Migrationだけを適用した。Migration集合は32件から33件となった。
- API Imageを`sha256:42d971aa...`から`sha256:3e62b5e6...`、Admin Imageを`sha256:fb9d2497...`から`sha256:4659b7e3...`へ更新した。OCI RevisionはApplication Commitと一致する。
- Container名、Network、固定IP `192.168.61.10/11`、Loopback Port `8611/3611`、Restart Policy、Environment Key集合は前後一致した。旧ImageはRollback用に保持した。
- Owner Login、Gacha一覧Empty State、登録、Category／Tag、Mobileを確認した。Preview Dataが空のためSynthetic Gachaは投入せず、Rank／Prize実操作はTask DBと対象Browser E2EをEvidence正本とした。
- Container内API／Admin Health、`admin.luxe-pack.biz/login` 200、`luxe-pack.biz` 200を確認した。Console／Page Error、HTTP 500／502／504は0。PostgreSQL／Redis Container ID、Nginx checksum、V1は不変。

## 残課題

- Probability Editor、履歴、Simulation、商品設計Planner、公開操作は対象外。
- Rank削除、確率Editor、利用履歴実処理、Simulation Algorithm、商品設計Planner、公開操作は後続Task候補。

## 所要時間

- 開始時刻: 2026-08-04 UTC
- API／AdminのClassic BuilderはCache安全Cleanup後を含め約4分、Browser E2Eは既存ConfigがProduction Buildを内包し各約46秒を要した。N+1とPolicy cost非露出境界はFresh reviewで補正した。
- Weekly limit: 実行環境から取得可能な値がないため未記録。

## Closeout

- Fresh Self-review、GitHub Required Checks、Final Head、Squash Commit、CleanupはCloseout結果を追記する。
- Gate G4／G5は`NOT COMPLETE`。MIG-061Lは開始しない。
