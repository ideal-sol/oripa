# オリポケ / オリパV2 — NEW Production 標準反映手順書（Canonical Release Runbook）

- **Version:** 1.0
- **制定:** 2026-10-20
- **対象:** オリパV2 Platformおよび承認済みStorefrontの本番反映
- **方針（Human決定）:** **速度重視**。実績あるCanonical手順・Artifact・Helperを再利用し、短時間停止と一括反映を基本とする。
- **権限:** 運用手順の参照文書であり、個別ReleaseのProduction GO、Migration GO、秘密情報操作を許可するものではない。

## 1. 適用原則

最新の明示的なHuman判断と、Repositoryの確定済みGovernance／Release Gateが本書より優先する。本書は**反映作業の進め方を共通化する文書**であり、Required Checks、決済・データ整合性、Human専用操作の省略やGate迂回を許可しない。

Migrationや複数サービスを含む過去の本番反映で用いた正式経路を再利用する。別の安全確認方式を考案できるという理由だけで、Release専用のContainer Creator、Observer、Gate Validator、多段承認フローを新設しない。既存方式で実際の問題が発生した場合は、**問題箇所だけを最小修正し、完了済み工程を再実行せず復帰**する。

## 2. 標準の一括反映順序

### 停止前：Source・Artifact・Scope確定

1. 機能実装、必要な検証・CI、PR／Merge、OLD Test／Human受入、HandoffをRelease Scopeに応じて完了する。
2. Platform／Storefrontの**Application Runtime Source**を、Workflow Authority、protected main、Contract provenanceと分けて固定する。新しいmainを自動採用しない。
3. 対象のMigration、ENVキー名、API／Admin／Storefront／Agency／Worker／Timerの更新範囲と据置対象、機能ON/OFFを確定する。
4. 正式なGitHub Actionsのアーキテクチャ適合ArtifactをBuild／Verify／Stageする。検証済みArtifactは再利用し、全件テストやBuildを停止後に繰り返さない。
5. 現行RuntimeとDB Ledger、外部Writer、旧Image／Container／Releaseなど必要なRollback資産を**必要な範囲で一度**確認し、一括GOにHuman専用操作をまとめる。

### 停止からActivationまで

6. Humanの一括GO後、対象Timer／Workerを既存方式でDrainし、該当Writer・サービスを短時間停止する。実行中の外部送信・S3書込みで結果不明が残っているときは無理に止めない。
7. Migrationで必要な場合、Humanが対象DBを確認して新規Manual Snapshotを取得し、`available`を確認する。隔離Restore Testの要否は**当該ReleaseのHuman判断**に従い、勝手に標準必須化しない。SnapshotはDB以外の外部状態を復元するものではない。
8. Migrationがあるときだけ、承認済みの既存Human専用One-shot経路から**exact対象ファイルだけ**適用する。Ledger／必要なSchemaの前後照合と依存順序を守る。`migrate:fresh`、未承認の最新main Migration、自動再試行はしない。Migrationなしなら工程ごと省略する。
9. Secret／Production ENVはHumanが管理・注入する。対象キーだけを反映し、各サービス固有ENVを保持する。Codexは実値の表示・記録、サービス間のENV全体コピーを行わない。
10. Stage済みArtifactと承認済みのDocker／Storefront切替経路を使う。基本順序はAPI・Health→必要な依存サービス・Storefront→対象Worker／Timer。ただし依存関係による例外は個別Handoffで明記する。据置サービスは触らない。
11. 対象サービスだけSource／Image／Build／Ledger／API・DB・Redis・Storage等のHealthを**一度**確認する。ログインを要する画面と実SMSなど必要な実機動作はHumanが確認する。
12. Source／Artifact／Migration結果、変更したサービス、Health／Human受入、保全中のRollback資産と残課題を一括報告する。未実施の確認をPASSにしない。

## 3. HumanとCodexの責任分担

- **Human:** 正式なProduction／Migration GO、AWS SnapshotやDB Restoreの判断、秘密ENVの編集・注入、ログインを伴うBrowser受入、決済・実SMS等の実動作判断、重大なScope変更。
- **Codex:** 承認範囲内のREAD ONLY調査、Artifact Stage、非秘密の通常停止／起動・Technical Health確認、必要最小限のEvidence、完了報告。
- 一括GOに含まれる通常工程は追加GOを繰り返さず連続実行する。Human専用操作が必要な地点だけ入力を依頼する。
- **実障害のHOLD:** DB・対象Sourceが不明、必要なSnapshot不足、外部Writerが動作中、送信やPutの結果不明、重大データ不整合、Migration状態不明、必須Credential不一致、継続する重大Health異常。
- HOLD後は既存資産とEvidenceを保持し、原因を限定して復帰する。不可逆処理の無条件再試行、Ledger改ざん、forward-only Migrationの自動Rollbackはしない。

## 4. 通常追加しない作業

- 原因のないRelease専用Container Creator／READ ONLY Observer／Gate Validator新規開発。
- 検証済みArtifactの再Build、NEWホストでのlocal Build、無関係な全CI／全Mock Test再実行。
- 既知のDocker正規化差分を契機とする全体の作り直しや、済んだStage／Snapshot／Container作成の重複実行。
- Humanが求めていない隔離Restore Testの必須化、通常工程の細かい追加承認。
- 更新対象外のサービス、Worker／Timer／Featureを勝手に変更・起動すること。
- 未処理Outboxや実行履歴を0にするための削除・再送や、Migrationを無条件再実行すること。

## 5. Release Handoffテンプレート

```text
Release ID: <exact>
Platform Runtime Source / Artifact / Image: <exact>
Storefront Runtime Source / Artifact / Build ID: <exact or NONE>
Workflow Authority / Contract: <exact>
Migration: NONE or exact files/order/expected ledger
ENV: NONE or Human担当の対象KEY名のみ（値は記載しない）
Activation scope: API/Admin/Storefront/Agency/Workers/Timersのexact対象
Unchanged services: <exact>
Backup: 当該ReleaseのHuman判断・確認結果
Feature ON/OFF: <expected>
Technical/Human Acceptance: 対象・担当・実際の結果
Rollback assets / unresolved issues: <exact>
```

## 6. 過去の反映・更新の扱い

Migrationを必要とする**初回機能反映**と、Schema適用後の**機能単体有効化**は別工程。片方でMigrationが不要だった事実を、その機能の初回反映にも適用しない。

過去のSource SHA、Snapshot ID、Container ID、Image ID、試行Marker、個別のHuman waiverは**履歴情報**であって次回の実行Authorityではない。Release固有の証跡は個別Handoffに保存し、本書には手順だけを記録する。

標準手順の変更はHumanに理由・追加工数・効果を説明し、明示的な指示を受けて文書を改訂する。通常のReleaseでは本書を複製せず、差分をHandoffに記載する。

**Codex:** 起点となる指示はRepositoryの`AGENTS.md`。本書はそこから参照されるProduction運用の共通手順であり、単独でデプロイを開始しない。
