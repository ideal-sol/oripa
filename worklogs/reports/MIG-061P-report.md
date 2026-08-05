# MIG-061P Report

## Issue／PR／Commit

- Issue: #200
- PR: #201
- Base: `5fb796bf9d7b64c7c7e597c1cd5b1564f9b7715a`
- Final Head／Squash Commit: Closeoutで確定
- Task Policy SHA-256: `9815c728f730d26b50670711e465f18f17382cb254a5b367a57356f51c7ca093` -> `acf47baf0d684eb0f9752f0a7bb0c18ce58f48923b5d3c56240b835e6bb04597`

## カテゴリ／バナー管理

- 既存Content／Asset基盤を再利用し、カテゴリ一覧／作成、バナー一覧／作成／更新／論理削除のAdmin APIを追加した。
- `/banners`へ登録Form、カテゴリ追加Modal、Server-sideカテゴリ絞り込み、Cursor Pagination、指定7列の一覧、編集Modal、削除確認を実装した。`/banners/new`は同画面の登録FormへRedirectする。
- 画像はGIF／JPEG／PNG／WebP、最大5 MiBをServerで検証し、Public IDとPublic URLだけを返す。内部Storage Pathは非露出とした。
- 削除はBanner ParentをArchiveして公開Pointerを解除する論理削除とし、Versionと共有Assetは保持する。Assetの物理削除は行わない。

## Migration／Verification

- Migration `000034`で`content_banner_categories`とVersion単位のCategory relationを追加した。既存Versionはnullableのまま維持し、非Banner VersionへのFK付替えをTriggerで拒否する。
- Task DB `oripa_v2_mig061p`、Marker `MIG-061P`、Purpose `v2-task-ephemeral`、34 migrationでDB Target Safety、Fresh 2回、rollback／reapply、Schema Inventory 92 objectがPASSした。
- 対象Backend 3 tests／33 assertions、Admin Unit 3件、Desktop／Mobile Browser 2件、OpenAPI Bundle／Breaking 181 Admin operations、Generated Client、Typecheck、Lint、Production Build、Policy Unit 133件、Policy／Quality GateがPASSした。Public／Webhook Contractは非変更。GitHub ChecksはCloseoutで確定する。
- GitHub初回確認で、PR本文のCanonical Metadata不足と、更新後一覧を待たず削除Buttonを取得するUnit Testの同期不足を検出した。本文をCanonical Schemaへ補正し、Unit Testは再取得後のAccessible Buttonを待つよう修正して対象Testを再度PASSさせた。Application挙動とPreview Artifactは変更していない。

## Preview／残課題／所要時間

- Application Head `ed99068412fbac68d484634dc7b4aeda923b44a7`からPreview APIを`sha256:54656a8c...`から`sha256:4b528540...`、Adminを`sha256:310701b5...`から`sha256:8afac4e4...`へ更新し、Preview DBへ000034だけを適用した。旧ImageはRollback用に保持した。
- Owner Login、カテゴリ1件とSynthetic Banner 1件の登録、7列、カテゴリ絞り込み、Public ID画像Content HTTP 200、API／Admin HealthがPASSした。最終再読込ではConsole／Page ErrorとHTTP 500／502／504は0、Application log Critical Errorも0だった。
- One-shot MigrationでCompose固定IP overrideを誤用してPreview Networkが再作成されたため、Containerを停止せず元のinternal subnet `192.168.61.0/24`、API `.10`、Admin `.11`、service aliasへ復旧した。復旧後のDomain Health、DB Target、固定IPを再確認した。
- PostgreSQL／Redis設定、Nginx checksum `9832e492...`、V1、Storefront、Paymentは非変更。
- 公開状態、掲載期間、表示順、リンク先URL、Storefront表示、カテゴリ編集／削除は明示対象外。共有されないOrphan Assetの回収も既存Asset Lifecycleの後続課題とする。
- 主な所要作業は既存Content Version境界へのCategory relation追加、画像Validation、対象DB／Admin検証。Weekly limitは実行環境から取得できないため未記録。
