# MIG-061R Report

## Issue／PR／Commit

- Issue: #204
- PR: #205
- Base: `dd970361ecab9515d5b455dbfa3798cc57009823`
- Application Head: `a9ae8c6944622bf6c491a8deba6a83cf6f8c63ed`
- Final Head／Squash Commit: PR #205のCloseout記録を正本とする
- Task Policy SHA-256: initial `17b67c2bdc1751b15d9f737168768ff36710caa6ec8f0358efbfb9e4b8220f83`; final `bb43efa4a581dd35628de508067393f02b67d326aedb1228f0ceba64eaa7369b`

## 実装

- Master編集はモーダルを廃止し、Canonical Route `/gachas/{gacha_public_code}/edit`へ移した。登録／編集で同じFormを使用し、サムネイル、タイトル、カテゴリ、タグ、消費ポイント、総口数、日次上限、対象ユーザー、開始／終了日時、説明、注意事項を編集する。
- 未公開は既存Draft、公開済みでDraftありはそのDraft、DraftなしはPublished Versionから冪等に作成したDraftを編集する。保存だけでは公開せず、Published Version、Draw、Inventory、Point、売上履歴は変更しない。
- ガチャ外部Public IDはCSPRNG生成のcase-sensitive英数字11文字とし、DBの形式制約／Unique制約、既存行Backfill、Collision Retry、UUID互換Resolverを実装した。Canonical Linkと通常UIは11文字コードを使用し、内部UUIDは維持する。
- サムネイルは新規／編集の共通Upload Componentで管理者が画像を直接選択する。GIF／JPEG／PNG／WebP、最大5 MB、実MIME照合、選択Previewを実装した。編集で未選択なら既存Assetを維持し、差し替え時も旧Assetを即時物理削除しない。Banner API／Category／Relationは使用しない。
- 景品一覧から景品Public ID列だけを外し、編集・Revision・Audit用IDは維持した。ガチャ詳細を景品一覧と同じContent幅へ広げ、Mobile横溢れを防止した。

## Migration／Test／Preview

- Migration `000036`で`catalog_gachas.public_code`、Draft／Published VersionごとのCategory snapshot、Tag relationを追加した。Task DBのfresh／rollback／reapplyに加え、既存Published Gachaを持つ状態でrollback／reapplyし、既存Gacha保持、Published不変、Trigger再有効化、Legacy UUID Resolverを確認した。
- Backend対象37 tests／400 assertions、Admin Unit 2 files／9 tests、Desktop／Mobile Browser 2 tests、Typecheck、Lint、Production Build、OpenAPI lint／bundle、Generated Client、Policy／Quality、DB Guard、`git diff --check`がPASSした。
- OpenAPI Breaking Checkで検出したResponse required追加は、Fieldを後方互換なoptional Contractとして追加し、現行Backendでは常時返す構成へ補正した。N-1 Clientは維持し、現行Adminは11文字コードを優先、欠落時だけLegacy UUID Resolverへfallbackし、通常UIにはUUIDを表示しない。補正後のBreaking Check、Generated Client、Unit 9件、Typecheck、Lint、Build、Browser 2件がPASSした。
- GitHub Integration Gateで、負荷試験が基底Fixtureを20件へ複製する際に新規`public_code`も複製してUnique制約へ抵触することを検出した。Policyへ`ZDrawConcurrencyLoadTest.php`だけをAtomic追加し、複製行ごとに形式適合する決定的一意コードを設定した。併せて基底Fixtureの正本`expected_record_count`へ複製分だけを加算し、旧固定件数への依存を除去した。Application／Previewの再変更はない。
- 補正後の対象負荷試験は1 test／15 assertions、58.634秒でPASSした。同一ガチャ10並行の最終完了8.604秒、20並行16.902秒、別ガチャ20並行17.553秒、Failure／未解決Deadlockはいずれも0だった。
- Preview DB Target Safety Guardを通過後、既存Dataを保持してMigration `000036`だけを適用した。Migration数は36、既存Gacha 1件の11文字Backfillと保護Trigger有効を確認した。
- Preview API Imageは`sha256:cc516763...`から`sha256:282878b1...`、Admin Imageは`sha256:4b9d4370...`から最終`sha256:0de7ad66...`へ更新した。互換補正後はAdminだけを再反映し、固定IP、Network、Restart Policy、Environment Key集合はImage以外一致した。
- Owner Login、11文字Canonical Route、Legacy UUID互換、全基本項目、専用Thumbnail直接Upload、Banner選択／Category API非依存、景品ID非表示、詳細／景品幅一致、Desktop／Mobileを確認した。Critical Console Error、Page Error、HTTP 500／502／504は0。既存Synthetic Prizeの欠損Asset URLによる非Critical 404が1件あり、Task外のPreview Data課題として残した。
- Nginx、V1、Storefront、Payment Providerは変更していない。残課題はOrphan Asset回収を既存Asset Lifecycleへ委ねることと、既存Synthetic Prizeの欠損Assetを別Taskで整理すること。ランク演出、紹介ポイント、ポイント購入、Storefrontは対象外。
- 主な所要作業はDraft／Published境界のCharacterization、Version単位Category／Tag保持、直接UploadのCSP対応、Migration回帰。
- Gate G4／G5は`NOT COMPLETE`を維持する。
