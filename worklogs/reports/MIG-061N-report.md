# MIG-061N Report

## Issue／PR／Commit

- Issue: #196
- PR: #197
- Base: `d3b8e645916f576ce4a59ec505acf0ea47a852c7`
- Final Head／Squash Commit: Closeoutで確定
- Task Policy SHA-256: `3bdb3ae9e8ca226062b7e1d602de0f9aee41612953b07ab2410aadc94ae806a3`

## V1 Characterization／移植

- 対象: `legacy/v1-frontend/src/app/admin-dashboard.tsx`、V1 Notice Controller／Request／Model／Migration。
- V1一覧はID、サムネイル、タイトル、状態、公開日時、更新日時、操作の順。登録／編集はタイトル、本文、サムネイル、トップ表示、状態、公開日時の順だった。
- V1の`draft`／`published`／`hidden`、サムネイル、トップ表示、公開開始をV2の既存Content Notice／immutable Version／Asset／`is_important`／Publish Flowへ移植した。
- V2ではPublic ID、Admin Realm、`content.read`／`content.manage`、RFC 9457、`private, no-store`、Idempotency-Key、UTC保存／Asia/Tokyo表示を使用する。
- V1にカテゴリ列・公開終了・HTML Sanitizerはなかった。カテゴリは新Schemaを作らず固定の「お知らせ」とし、V2既存の公開終了とServer-side Sanitizerを安全境界として利用する。LP一覧表示はV1のトップ表示を`is_important`へ対応付けた。

## API／Migration／画面

- 既存Notice一覧／詳細／Version／Publish／Unpublishへ、最新Version付き一覧、Server Preview、作成・更新のCanonical Replayを追加する。
- Adminは`/announcements`、`/announcements/new`、`/announcements/{public_id}`を実画面化し、一覧、登録、編集、Preview、公開期間、Cursor Pagination、Loading／Empty／Errorへ対応する。
- 既存Migration 000013に全保存項目があるためMigrationは追加しない。Public／Webhook Contractは変更しない。

## Verification／Preview

- Task DBで対象Backendと既存Content回帰の計15 tests／107 assertions、Admin Unit 3件、Desktop／Mobile E2E 2件がPASSした。OpenAPI Bundle／Breaking、Generated Client、Typecheck、全Lint、Production Build、Policy／Quality、`git diff --check`もPASSした。
- Application Head `8fc08a6bffc1960e8ae4cdfad7991de9f52fe70e`からPreview APIを`sha256:28b38299...`から`sha256:c9cf11b9...`、Adminを`sha256:8245dcfe...`から`sha256:f9c94d86...`へ更新した。旧ImageはRollback用に保持した。
- Owner Login、一覧、Sanitized Preview、Synthetic Draft 1件、API／Admin HealthがPASSし、Console／Page ErrorとHTTP 500／502／504は0だった。Preview DBは33 migrationsのまま、PostgreSQL／Redis設定、Nginx checksum、V1、Storefrontは不変。Secret Candidateは0。Evidenceは`/var/lib/oripa-v2-evidence/MIG-061N/`へ保存した。
- GitHub Required ChecksとFinal HeadのSelf-reviewはCloseoutで確定する。

## 残課題／所要時間

- Storefront公開画面、商品設計プランナー、左メニューのシミュレーション、MIG-061O以降は対象外。
- Characterization、後方互換Contract整合、対象検証、Preview反映が主な作業。Weekly limitは実行環境から取得できないため未記録。
