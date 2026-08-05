# MIG-061R Report

## Issue／PR／Commit

- Issue: #204
- PR: #205
- Base: `dd970361ecab9515d5b455dbfa3798cc57009823`
- Application Head: `a6f405b8e4d926073ba70ae9657bf0e7e690f781`
- Final Head／Squash Commit: PR #205のCloseout記録を正本とする
- Task Policy SHA-256: `17b67c2bdc1751b15d9f737168768ff36710caa6ec8f0358efbfb9e4b8220f83`

## 実装

- Master編集はモーダルを廃止し、Canonical Route `/gachas/{gacha_public_code}/edit`へ移した。登録／編集で同じFormを使用し、サムネイル、タイトル、カテゴリ、タグ、消費ポイント、総口数、日次上限、対象ユーザー、開始／終了日時、説明、注意事項を編集する。
- 未公開は既存Draft、公開済みでDraftありはそのDraft、DraftなしはPublished Versionから冪等に作成したDraftを編集する。保存だけでは公開せず、Published Version、Draw、Inventory、Point、売上履歴は変更しない。
- ガチャ外部Public IDはCSPRNG生成のcase-sensitive英数字11文字とし、DBの形式制約／Unique制約、既存行Backfill、Collision Retry、UUID互換Resolverを実装した。Canonical Linkと通常UIは11文字コードを使用し、内部UUIDは維持する。
- サムネイルは新規／編集の共通Upload Componentで管理者が画像を直接選択する。GIF／JPEG／PNG／WebP、最大5 MB、実MIME照合、選択Previewを実装した。編集で未選択なら既存Assetを維持し、差し替え時も旧Assetを即時物理削除しない。Banner API／Category／Relationは使用しない。
- 景品一覧から景品Public ID列だけを外し、編集・Revision・Audit用IDは維持した。ガチャ詳細を景品一覧と同じContent幅へ広げ、Mobile横溢れを防止した。

## Migration／Test／Preview

- Migration `000036`で`catalog_gachas.public_code`、Draft／Published VersionごとのCategory snapshot、Tag relationを追加した。Task DBのfresh／rollback／reapplyに加え、既存Published Gachaを持つ状態でrollback／reapplyし、既存Gacha保持、Published不変、Trigger再有効化、Legacy UUID Resolverを確認した。
- Backend対象37 tests／400 assertions、Admin Unit 2 files／9 tests、Desktop／Mobile Browser 2 tests、Typecheck、Lint、Production Build、OpenAPI lint／bundle、Generated Client、Policy／Quality、DB Guard、`git diff --check`がPASSした。
- Preview DB Target Safety Guardを通過後、既存Dataを保持してMigration `000036`だけを適用した。Migration数は36、既存Gacha 1件の11文字Backfillと保護Trigger有効を確認した。
- Preview API Imageは`sha256:cc516763...`から`sha256:282878b1...`、Admin Imageは`sha256:4b9d4370...`から`sha256:4db76c8c...`へ更新した。固定IP、Network、Restart Policy、Environment Key集合はImage以外一致した。
- Owner Login、11文字Canonical Route、Legacy UUID互換、全基本項目、専用Thumbnail直接Upload、Banner選択／Category API非依存、景品ID非表示、詳細／景品幅一致、Desktop／Mobileを確認した。Critical Console Error、Page Error、HTTP 500／502／504は0。既存Synthetic Prizeの欠損Asset URLによる非Critical 404が1件あり、Task外のPreview Data課題として残した。
- Nginx、V1、Storefront、Payment Providerは変更していない。残課題はOrphan Asset回収を既存Asset Lifecycleへ委ねることと、既存Synthetic Prizeの欠損Assetを別Taskで整理すること。ランク演出、紹介ポイント、ポイント購入、Storefrontは対象外。
- 主な所要作業はDraft／Published境界のCharacterization、Version単位Category／Tag保持、直接UploadのCSP対応、Migration回帰。
- Gate G4／G5は`NOT COMPLETE`を維持する。
