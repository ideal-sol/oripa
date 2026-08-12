# MIG-062I ガチャごとのDraw Count設定

## Task

- Issue: #247
- PR: #248
- Base: `main@1f4c452dcfb9631530f7b1d3f400637165646e00`
- Branch: `feat/MIG-062I-gacha-draw-count-configuration`
- Risk: R3
- Task Policy SHA-256: `207ff4561a3b8f63cccd93b8fa823774ee413b6bd5f1adc05f7b36729d686704`
- Application Head: `52de91b627f36dc51a19514a27edb612d226c598`
- Final Head／Squash Commit: Closeout時に確定

## Schema／Admin

- Migration `000045`で`catalog_gacha_versions.allowed_draw_counts`をJSONBとして追加した。Defaultは`[1,5,10]`で、既存Versionも同値へ移行する。
- DB制約は`1`を必須とし、Platform対応値`1／5／10／100／1000`の正規順・重複なしの組合せだけを許可する。rollback／reapply可能である。
- Admin登録／Master編集の共通FormへCheckboxを追加した。`1`は常時ONかつ変更不可、`5／10／100／1000`は複数選択でき、保存後のCanonical Versionを再取得する。

## Public／Draw

- Public `allowed_draw_counts`は既存Presentation Serviceで、Platform対応値、Gacha Version設定、Sale State、Eligibility、JST日次上限、残口数を交差して算出する。
- Public DrawはTransaction内でPublished Versionの設定を再検証し、無効Countを既存Typed Error `INVALID_DRAW_REQUEST`でMutation前に拒否する。
- 有効Countの既存Draw、Idempotency Replay、Point／Inventory／Draw履歴のTransaction不変条件は維持する。Admin QA DrawのPlatform対応Count検証は変更しない。
- Public OpenAPI／Storefront packagesは既存Contractで表現可能なため変更せず、Admin OpenAPI／Generated Clientだけを同期した。Artifact更新はない。

## Verification／Preview

- Task DBでMigration fresh、rollback、既存Gacha行へのreapply、Default、DB Constraint拒否を確認した。
- Backend対象46 tests（492 assertions。通常Draw、QA Draw、1000件景品Fixtureを含む）、Admin Unit 6 tests、Policy Unit 120 tests、Admin Typecheck／Lint、OpenAPI lint／bundle／check、`git diff --check`がPASSした。
- Preview Image／Migration／Smoke、Required Checks、Fresh Self-reviewはCloseout時に追記する。
- 全Suite、Storefront Repository、V1、Nginx、Point／Payment、Draw Algorithm変更はTask対象外で実施しない。
- G4／G5はNOT COMPLETEを維持する。
