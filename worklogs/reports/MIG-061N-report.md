# MIG-061N Report

## Issue／PR／Commit

- Issue: #196
- PR: Closeoutで確定
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

- 対象Backend、Admin Unit／Desktop・Mobile E2E、OpenAPI／Generated Client、Typecheck／Lint／Build、Policy／Quality、`git diff --check`を実行する。
- Previewは最終Application HeadからAPI／Adminだけを更新し、DB／Migration、Nginx、V1、Storefront、Payment Providerを変更しない。
- 結果、Image Digest、Required Checks、Self-reviewはCloseoutで追記する。Evidenceは`/var/lib/oripa-v2-evidence/MIG-061N/`へ保存する。

## 残課題／所要時間

- Storefront公開画面、商品設計プランナー、左メニューのシミュレーション、MIG-061O以降は対象外。
- Characterization、Contract整合、対象検証、Preview反映が主な作業。所要時間とWeekly limitはCloseoutで確定する。
