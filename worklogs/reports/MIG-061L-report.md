# MIG-061L Report

## Issue／PR／Commit

- Issue: #192
- PR: #193
- Base: `911dbdd0d2c52c751c1da5874a37e5572d5e5054`
- API Application Head: `10d75df94636755e0e5752fa2b33b7026f3cc43f`
- Admin Application Head: `d7648bfcbfa5d244a173c25d1a6f0437514b6d90`
- Final Head／Squash Commit: Merge結果で確定
- Task Policy SHA-256: `3be0ade2109c60cf6bfb81296ced7a3cdc19e086b3317f1e367cac1b98c96752`

## 履歴一覧・詳細API

- `GET /admin/api/v2/catalog/gachas/{gachaId}/history`で完了済み通常Draw Requestを1 Request 1行で返す。
- `GET /admin/api/v2/catalog/gachas/{gachaId}/history/{drawRequestId}`で利用情報、状態集計、当選景品全件を返す。
- 既存`catalog.read`、Admin Realm、Public ID、暗号化Opaque Cursor、RFC 9457、`private, no-store`を再利用した。内部DB ID、Password、Session、Token、不要PIIは返さない。

## 履歴の対象範囲

- `status=completed`かつ`is_qa=false`の通常Draw Requestだけを対象とする。QA、Failed、処理中、Rollback済みRequest、未完了Requestは除外する。
- 一覧は`completed_at DESC, public_id DESC`のStable Sort。何連ガチャはCanonicalな`executed_count`、ユーザー名未設定時は「未設定」、日時はUTC保存値をAsia/Tokyo表示する。
- 詳細は当該RequestのDraw Result／User Prizeを全件返し、V1の現在保有景品に限定せず、当該成功Requestで確定した取得景品を表示する。

## 状態集計方式

- `stored`は「選択待ち」、`shipping_requested`から`returned`までの配送系列は「配送」、`exchange_processing`／`converted`は「ポイント交換」、既存`expired`だけを「失効」とする。
- 1 Request内で状態が異なる場合は状態別件数を複数Badgeで返し、同一状態だけなら単一Badgeとする。未知状態名や失効条件は新設していない。

## 対象Test

- Task専用Clean DBでBackend 4 tests／40 assertions PASS。1 Request 1行、QA／未完了除外、複数景品／状態件数、Cursor、全Admin Role、401／404、内部情報非露出を確認した。
- 一覧／詳細は各4 Query（認可2＋Domain 2）で件数に依存せず、N+1なしを確認した。
- Admin Unit 1 file／2 tests、Desktop／Mobile Browser E2E 2 tests PASS。CI lint補正後のUnit指定は既存Runnerにより全19 files／99 testsへ転送され全件PASSし、BrowserはPlaywright CLIへ対象Fileを直接指定して2 tests PASSを再確認した。列順、詳細遷移、全景品、戻る導線、Keyboard scroll、横溢れなしを確認した。
- OpenAPI Lint／Bundle／Breaking、Generated Client差分0、Admin Typecheck／全Lint／Production Build、Policy Unit／Gate、Quality Gate、`git diff --check`がPASSした。指定どおりFull V2 Suite、Full Guard、Backup／Restore、Draw負荷、V1全回帰、全Admin E2E、全Local Security Gateは実行していない。

## Preview反映

- DB Target Safety Guardは`oripa_v2_mig061a`、Marker `mig061a`、Purpose `v2-persistent`、Migration 33件一致でPASSした。Migration／DB Writeは実施していない。
- API Imageを`sha256:3e62b5e6...`から`sha256:28b38299...`、Admin Imageを`sha256:4659b7e3...`から`sha256:e3375b47...`へ更新した。OCI RevisionはそれぞれのAPI／Admin Application Headと一致する。
- Container名、Network、固定IP `192.168.61.10/11`、Loopback Port `8611/3611`、Restart Policy、Environment Key集合を維持した。旧ImageはRollback用に保持した。
- Preview DBにGachaがないためSynthetic Drawは投入せず、履歴一覧／詳細の表示はTask DB Browser E2EをEvidence正本とした。Owner Login、Container Health、`admin.luxe-pack.biz/login`、`luxe-pack.biz`を確認し、Console／Page ErrorとHTTP 500／502／504は0だった。
- PostgreSQL／Redis Container ID、Migration数、Nginx checksum、V1は不変。
- 完全なBuild／DB Guard／Container比較／Smoke Evidenceは`/var/lib/oripa-v2-evidence/MIG-061L/`へ保存した。

## 未実装範囲

- 自動ポイント交換Worker、失効条件／失効処理、配送／ポイント交換Mutation、User Prize取消は未実装。既存Dataに確定済みの結果だけをRead Modelへ反映する。

## 所要時間

- 開始時刻: 2026-08-04 UTC。
- API／Admin Image BuildとPreview構成同一性確認が主な所要時間。対象Browser E2Eは初回49.8秒、lint補正後46.4秒だった。
- Weekly limitは実行環境から取得可能な値がないため、開始前／終了後とも未記録。

## Closeout

- Final Documentation HeadでRequired ChecksとFresh Self-reviewを実施し、SEV-0／SEV-1が0件の場合だけSquash Mergeする。
- Preview API／Admin／DBは稼働維持し、Task専用DBはFresh Self-review後にCleanupする。
- Gate G4／G5は`NOT COMPLETE`。MIG-061Mは開始しない。
