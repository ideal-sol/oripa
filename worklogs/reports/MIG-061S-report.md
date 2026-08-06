# MIG-061S Report

## Issue／PR／Commit

- Issue: #206
- PR: Draft作成後に確定
- Base: `d019570d085b189b9577265787d0925b10e43b5f`
- Application Head／Squash Commit: Closeoutで確定
- Task Policy SHA-256: `9c86e7ca35f834c70f74d5a3d5d95d96635f0ef8213e3156012dfb8700220f30`

## V1 Characterization

- V1 Routeは`/admin/settings/rank-assets`、`/new`、`/{id}/edit`。一覧はID、種別、タイトル、Preview、状態、更新日時、操作の順で、新規登録／編集はタイトル、画像／動画種別、直接Upload、状態を扱う。
- 画像はGIF／JPEG／PNG／WebP、最大5 MB、動画はMP4／WebM／QuickTime、最大50 MB。画像／動画を管理画面内でPreviewする。
- V1の演出Masterには削除機能、表示順入力、Rank選択がなく、Rank編集側で複数演出を紐付ける。V2では既存`catalog_rank_assets`のRank relationと`sort_order`を同じ画面で管理し、削除は追加していない。

## 実装

- 既存Presentation Asset／Asset／Rank基盤を再利用し、ランク演出一覧、詳細、作成、更新のAdmin APIと画面を実装した。Admin Realm、`catalog.read`／`catalog.manage`、Public ID、RFC 9457、`private, no-store`、Idempotency、Audit／Outbox、Revision競合を維持する。
- 画像／動画は画面から直接Uploadし、実MIMEと容量をBackendで検証する。Public Content URLだけを返し、内部Storage Pathは露出しない。編集でファイル未選択なら既存Assetを維持し、差し替え時も旧Asset／Objectを即時物理削除しない。
- Rankは既存Rank Masterから複数選択でき、各relationの表示順と有効／無効を保存する。一覧は種別、タイトル、Rank、Preview、表示順、状態、更新日時、操作の順。画像は縦横比を維持し、動画は再生／停止、muted、playsInlineに対応する。
- Migrationは不要。V1 API／V1 DB、Banner API／Category、Storefront、Payment Providerには接続しない。

## Test／Preview

- Backend対象3 tests／29 assertions、Admin Unit 3 files／29 tests、Desktop／Mobile Browser 2 tests、Frozen Install、OpenAPI lint／bundle、Generated Client、Typecheck、Lint、Production BuildがPASSした。
- Policy／Quality Gate、Preview API／Admin反映、Required Checks、Self-review、Squash CommitはCloseoutで追記する。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061S/`
- 残課題はOrphan Asset回収を既存Asset Lifecycleへ委ねること。紹介ポイント、ポイント購入、LINE、Storefrontは対象外。
- 主な所要作業はV1演出MasterとV2 Rank relationの差異確認、実MIMEを含むUpload境界、既存Asset維持のTransaction設計。
- Gate G4／G5は`NOT COMPLETE`を維持する。
