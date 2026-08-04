# MIG-061M Report

## Issue／PR／Commit

- Issue: #194
- PR: #195
- Base: `ce602a45d90ed618830f1089f03b11ab306a4a57`
- Final Head／Squash Commit: Closeoutで確定
- Task Policy SHA-256: `c09fe95a0b6d80b8a02ffa3911b4d5ffa647c07e24d49896a9e0308e1622ba32`

## V1計算元

- UI: `legacy/v1-frontend/src/app/admin-dashboard.tsx` の `ProfitSimulationPanel`
- Formula: `apps/api/app/Domain/Gacha/Services/GachaProfitSimulationService.php`
- V1の完売時売上、最大原価、利益／粗利率、目標差分、公開確率ベース期待値をV2へ移植する。

## 移植内容

- 入力: V2 Draft Versionの消費Point／総口数、景品の総在庫／現在個数／原価、試算用の保証原価／目標粗利。
- 出力: V1と同じ順序の完売時売上、景品原価総額、最低保証最大原価、最大原価合計、想定利益、想定粗利率、目標利益、目標差分、確率期待値。
- Formula／丸め: V1の整数乗算、PHP `round`相当の四捨五入、ppm期待原価、販売区間別期待原価を移植する。
- API追加: なし。既存V2 Admin Catalog APIのみをRead-only利用する。

## Verification／Preview

- V1同等性Golden 3パターンを含む対象Unit 5件がPASSした。Desktop／Mobile対象E2E 2件、Typecheck、全Lint、Production Build、Policy Unit 100件／Gate、Quality Gate、`git diff --check`もPASSした。
- OpenAPI／Generated ClientはAPI追加がないため変更・再生成なし。Full V2 Suite、Full Guard、Backup／Restore、Draw負荷、V1全回帰、全Admin E2E、全Local Security Gateは指定どおり実行していない。
- Application Head `e264bb45c2dfc9b3d8f92bdbdb5d0556040c6b7b`からPreview Admin Imageを`sha256:e3375b47...`から`sha256:8245dcfe...`へ更新した。Container名、Network、固定IP `192.168.61.11`、Loopback Port `3611`、Restart Policy、Environment Key集合を維持し、旧ImageはRollback用に保持した。
- Owner Login、Gacha一覧、Admin Health、`admin.luxe-pack.biz/login`、`luxe-pack.biz`を確認し、Console／Page ErrorとHTTP 500／502／504は0だった。Preview DBにGachaがないためSynthetic Dataは投入せず、利益画面の表示正本は対象Browser E2Eとした。
- API／PostgreSQL／Redis Container ID、Nginx checksum、DB／Migration、V1は不変。Evidenceは`/var/lib/oripa-v2-evidence/MIG-061M/`へ保存した。

## 残課題／所要時間

- 商品設計プランナー、左メニューのシミュレーション、試算値の保存は対象外。
- CharacterizationとGolden fixture作成、対象Production Buildが主な所要作業。Weekly limitは実行環境から取得できないため未記録。
