# MIG-062I ガチャごとのDraw Count設定

## Task

- Issue: #247
- PR: #248
- Base: `main@1f4c452dcfb9631530f7b1d3f400637165646e00`
- Branch: `feat/MIG-062I-gacha-draw-count-configuration`
- Risk: R3
- Task Policy SHA-256: `cff9e1c7add6643789f66788039533e27f45e491e37f7122e39a07f10bb115fd`
- Application Head: `09a340ed34adcc971f121a4d18a752d13c1672e5`
- Preview Image Source Head: `73326e85cd67b83bf941602979b5ae651d9826a6`
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
- Integration Gateで検出したQA Draw制限順と1000件Fixtureを補正し、既存QA DrawのPlatform対応Countおよび通常DrawのGacha制限を両立した。
- Concurrency Fixtureには1000回を明示許可し、既存の並列Draw／Lock／Transaction検証をGacha設定導入後も維持した。
- Draw Load対象2 tests（36 assertions）は失敗0、未解決Deadlock 0でPASSした。
- Exact Head `73326e85cd67b83bf941602979b5ae651d9826a6`でRequired Checks 7件（Policy／Quality／Security／Integration／CI／CodeQL／Dependency Review）がPASSした。
- GitHub-hosted amd64 Workflow Run `31604800009`でAPI／Admin ImageをBuildした。Artifact外側SHA-256、Manifest SHA-256、内部Checksum、Image ID、`linux/amd64`、OCI Revisionの完全一致をHost側Helperで検証し、Production Host上ではBuildしていない。
- DB Target Safety Guardの前後PASSを確認し、Preview DB `oripa_v2_mig061a`へMigration `000045`だけを適用した。既存7 Versionは全件`[1,5,10]`となり、DB Constraintも有効である。
- API／Adminだけを`--no-build --no-deps`で更新し、Network、固定IP、Environment、PostgreSQL、Redis、Nginxを維持した。API／Admin Health、Public Session、V1 Public／AdminのHTTP 200を確認した。
- Preview Admin APIで既存Draftを`[1,10,100]`へ更新して再取得し、直後に元の`[1,5,10]`へ復元した。Public Presentationは`[1,5,10]`を返し、無効な`100`の直接Drawは`422 / INVALID_DRAW_REQUEST`で拒否され、残数と日次状態は不変だった。
- Final文書HeadのRequired ChecksとFresh Self-reviewはCloseout時に確定する。
- 全Suite、Storefront Repository、V1、Nginx、Point／Payment、Draw Algorithm変更はTask対象外で実施しない。
- G4／G5はNOT COMPLETEを維持する。
