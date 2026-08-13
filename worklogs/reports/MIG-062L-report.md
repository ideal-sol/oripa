# MIG-062L ガチャ管理構造整理／景品所有関係修正

## Task

- Issue: #253
- PR: #254
- Base: `main@a505b36d0a812979a77fc4831bb632d03ad8b782`
- Branch: `feat/MIG-062L-gacha-prize-ownership`
- Risk: R4
- Task Policy SHA-256: `5b564357c977bc75cfdab5dee9cff491ebdb4b50efa4b164e9c5f68d17a5a8d8`
- Policy補正: Required Integrationで実在するConcurrency Fixture Testを対象検証へ追加するため、`apps/api/tests/V2/ZDrawConcurrencyLoadTest.php`だけをAllowed Pathsへ追加した。旧SHAは`a36c7629e2690c4fc7f38f791ce7d3fa84b24d21fa300b6d9aa2e35f4b210b42`。
- Final Head／Squash Commit: Closeout時に確定

## Ownership／Snapshot

- `catalog_prizes.gacha_id`を必須かつ変更不可とし、1 Prizeを1 Gachaへ所有させる。同一GachaのDraft／Published／Historical Versionは同じPrize IDを参照できる。
- Version relationへ景品名、説明、ランク表示、画像Asset、表示価格、交換ポイント、原価、表示状態、数量、並び順をSnapshotとして保持する。Draft側のPrize master編集はPublished／Historical表示を変更しない。
- Cross-Gacha relationはServiceとDB Triggerの両方で拒否する。Published Probabilityは既存Version-Prize relationを参照し続ける。
- Public APIはCanonical Published Versionだけを返す既存Shapeを維持し、Storefront Artifactは更新しない。

## Migration／Backfill

- Migration `000048`は既存Prizeの関連Versionから所有Gachaを一意に解決し、0件または複数Gachaへ解決される場合はFail Closedする。Version relationの既存値をSnapshotへBackfillする。
- Preview適用前検証で既存Prize OCC TriggerとPublished child不変Triggerとの不一致を検出した。Prize所有者Backfillは`revision + 1`と`updated_at`を同時更新し、Published relationのSnapshot初期充填中だけ既存Canonical Migrationと同じ手順で該当Triggerを同一Transaction内で無効化・再有効化するよう補正した。IdentityとProbability参照は変更しない。失敗MigrationはTransaction Rollbackされ、Preview DBは変更されなかった。
- Preview事前CharacterizationではCross-Gacha共有Prize 1件を検出した。人間承認後、関連がSynthetic／QAのみであることを確認し、通常Draw履歴を持つ`TEST 売り切れ`とMIG-062J Fixtureを保持したまま、参照のないSynthetic Gacha 5件だけを整合的に削除した。再確認結果は`Cross-Gacha共有Prize = 0`。
- Production MigrationへSynthetic例外、Prize Public ID特例、破壊的分離処理は追加していない。

## Admin

- Gacha詳細へ「現在公開中の景品ラインナップ」「編集中のランク／景品」「バージョン履歴」を明確に分けて表示する。公開中景品はVersion詳細へ移動せず確認できる。
- 基本情報、公開ID、状態、抽選回数、景品、抽選確率、編集中、公開済み、変更履歴等、今回触るGacha管理画面の主要表示を日本語へ整理した。
- 通常操作はGacha詳細から基本情報編集へ進み、内部Draft／Published immutability、Revision Conflict、Publish Preflightを維持する。

## Verification／Preview

- Task DB Migration fresh／rollback／reapply、Prize ownership、Cross-Gacha拒否、同一Gacha再利用、Published Snapshot不変、Probability／Draw／QA／Reportingの対象Backend TestはPASSした。
- Required Integrationで検出した既存Concurrency FixtureのCross-Gacha Prize共有を、別Gachaごとの別Prize IDへ補正した。Production ImporterのFail Closed条件は維持する。
- Admin Unit、Typecheck、Lint、Production Build、Desktop／Mobile対象Browser Test、Policy Unit／Policy Gate／Quality Gate、`git diff --check`はPASSした。
- Preview Image／Migration／Smoke、Required Checks、CodeQL／Dependency Review、Fresh Self-reviewはCloseout時に確定する。

## Remaining

- Previewでは既存`TEST MIG-062J Partial Draw`の公開中景品`TEST MIG-062J Fixture S景品`をGacha詳細の主画面で確認する。
- Preview反映、Required Checks、Fresh Self-review、Squash Merge、Issue Close、Task Resource Cleanupを実施する。
