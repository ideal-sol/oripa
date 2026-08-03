# MIG-061F Dashboard Sales Aggregation API／Frontend Connection Report

## Task

- Task ID: `MIG-061F`
- Risk／Profile: `R3`／`DATA-R3-TARGETED`
- Base: `main@550051a78bae4d0aadbb7a02119fa211237bc28b`
- Branch: `feat/MIG-061F-dashboard-sales-aggregation`
- Issue／PR: `#178`／`#179`
- Task Policy SHA-256:
  `12d1a78a61514f680e7767b89919ec8eac6694af3405dbd35d0cd7d41e1ef022`
- Application／Image Source Head: `c6c455a0d1f74d882a0e107e07025b2ce886329b`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061F/`
- Final Head／Squash Commit: CloseoutのGitHub merge結果を正本とする。

## 集計定義

- 総売上は`payments.status=succeeded`だけを`succeeded_at`で集計する。Failed、Pending、
  Cancelledは含めない。
- 返金額／Chargeback額は`payment_adjustments.status=succeeded`かつ、それぞれ
  `type=refund`／`type=chargeback`だけを`succeeded_at`で集計する。
- 純売上は`総売上 - 返金額 - Chargeback額`。Chargeback reversalは履歴上の別種別として保持し、
  純売上へ自動加算しない。
- Point消費は不変な`point_ledger_entries.entry_type=spend`を正本とし、`point_type=paid/free`を
  整数で分離集計する。Wallet残高やFrontend計算は使用しない。
- `point_operations.is_qa=false`だけを対象としQA Drawを除外する。Payment SchemaにはQA／Testの
  Canonical識別子がないためProvider名等による推測除外は行わない。QA DrawはPayment売上を生成しない。
- UTC保存値を`Asia/Tokyo`の月初・月末・日付境界へ変換する。API日時はISO 8601で返す。
- 一覧は内部`id`昇順で安定化し、既存`V2ReportingCursor`のBase64url Opaque Cursorを使用する。

## API／Permission

- Read-only GET Contractを追加した。
  `/reports/dashboard/sales/monthly`、`/sales/daily`、`/points/monthly`、`/points/daily`、
  `/reports/dashboard/reversals`の5系統である。
- 既存Admin Realm、Session、`reporting.financial.read`を再利用し、Owner／Adminを許可、
  Operator／未認証をFail Closedにした。
- `private, no-store`、Request ID、RFC 9457 Problem、Public ID、Cursor上限を維持した。
  内部DB ID、Provider Payment ID、Credential、Email等の不要なPIIは返さない。
- Read Auditは既存`report.viewed`へ最小限の期間／表示種別を記録する。Read-onlyのためMutation、
  Outbox、Idempotency、Migration、Indexは追加していない。
- Public／Webhook／Storefront ContractとV1 API／DBは変更していない。

## Admin

- MIG-061Eの5タブと表示順を維持し、Generated Admin Client経由でV2 APIへ接続した。
- 対象月／日／期間変更、再取得、Loading／Empty／Error、Calendar／Table、JST日時、整数の金額／
  Point Format、Cursor追読を実装した。
- Data 0件時は数値0を実Dataとして捏造せずEmpty Stateを表示する。
- CSV、返金／Chargeback MutationはDisabledまたは非表示のままで、Endpointを追加していない。

## Test／性能

- Task専用DB `oripa_v2_mig061f`、Task Network `mig061f-test`、PHP `8.4.23`で対象Testを実行した。
  Dashboard Domain／HTTP／既存Reporting回帰は機能16件PASS、性能1件PASS、合計135 Assertion。
- 月初／日付跨ぎ、成功状態、返金／CB／純売上、Paid／Free Point、QA除外、Operator 403、
  未認証401、RFC 9457、Cursor、内部ID非露出を確認した。
- 性能Fixture 100件で月別売上10 Query、月別Point 8 Query、日別Point 8 Query、合計26 Query、
  54.57ms。各Endpoint 12 Query以下、合計30以下で、行数依存N+1はない。
- Admin Unit／Componentは15 files／80 testsを同一Application Headで確認し、対象Dashboard 7件の
  最終再実行がPASS。Browser E2EはDesktop／MobileともPASSした。
- OpenAPI Unit 4件、Lint／Bundle／Breaking、Admin生成差分0、Typecheck、Lint、Production Build、
  Policy Unit 93件、Quality Unit 5件、Policy／Quality／Security Gate、`git diff --check`がPASS。
- Security GateはRoot／Legacy pnpm Finding 0、Workspace pnpm Finding 0、Composer既存Baseline 10件、
  期限`2026-08-07`、Secret Candidate 0でPASSした。
- Persistent／Ephemeral Full Guard、Backup／Restore、Draw負荷、V1全回帰、Storefront Testは、
  Migration／Draw／V1／Storefront差分がないためProfile指示どおり重複実行していない。

## Preview反映

- Compose Project `mig061a-v2-preview`のAPI／Adminだけを`--no-deps --no-build`で更新した。
- API Imageは`sha256:465994e8...`から`sha256:0bbb6c3b...`、Admin Imageは
  `sha256:a80018dd...`から`sha256:f348dbb2...`へ更新した。OCI revisionはApplication Headと一致する。
- Container名、Network、固定IP `192.168.61.10/11`、Loopback Port `8611/3611`、Restart Policy、
  Environment Key集合を維持した。PostgreSQL／Redis Container IDは切替前後一致する。
- Preview DB `oripa_v2_mig061a`、Marker `mig061a`、Migration 30件を維持し、Migration／Data破壊はない。
- 実DomainでPassword-only Login、Dashboard 5タブ、全Dashboard API 200、Desktop／Mobileを確認した。
  Console Critical Error、Page Error、HTTP 500／502／504は0件。
- Nginx設定集合checksumは前後とも`6b3ec7ba...`。`luxe-pack.biz` HTTP 200、
  `ad.luxe-pack.biz/login`の既存V1 Redirectを維持し、V1、Nginx／TLS／DNS、Payment Providerは非変更。
- Rollbackは不要。切替前Imageは削除していない。

## 時間を要した作業

- Host PHP 8.3はRepository要件PHP 8.4を満たさないため更新せず、既存PHP 8.4 API Imageを対象Testへ
  再利用した。Host Toolchain変更を避け、Task DBへだけ接続した。
- 最初の対象PHPUnitは既定Configが共有DB名を強制したためDDL前に停止し、`phpunit.v2.xml`とTask DBを
  明示して再実行した。共有Persistent DBは使用していない。
- Frontend Test scriptは対象File指定を全15 filesへ転送したため、その成功Evidenceを維持した。
  Locale通貨記号と重複表示に対するstrict locatorだけをAccessible領域へ限定し、成功済みMobileや
  Backend Testを重複実行しなかった。Playwright設定がBuildを内包する再試行だけは重複を回避できなかった。
- 集計性能は3 Endpoint合算で認可／Read Audit固定Queryも数えたため、Endpoint単位へ分解して
  10／8／8 Queryと確認した。2秒Timeoutや各Endpoint 12 Query上限は緩和していない。
- Root空きはBuild前4.0GB、Build後3.6GB、Build Cache 0BだったためCleanupは行わず、Classic Builderを維持した。
- 初回PR本文は必須Headingを満たしたが、Canonical bullet metadataの`Task ID`／`Risk`と
  実Diff完全一致の`Changed files`／`Allowed paths`が不足しPolicy CheckだけがFailした。
  ApplicationやGateを変更せずPR本文を修正した。同一Head再実行では旧Failure Check Runが残るため、
  この再試行経緯を本Reportへ記録する実変更を新HeadとしてPushし、空Commitを使用しなかった。
- Integration GateはV1 Migrationだけを適用して既存Backend Baselineを照合するため、新規V2集計Unitが
  V2 Schema不在を新規Failureとして検出された。V2 `admins` Tableの存在確認を追加し、Task専用Clean
  V2 DBでは全Assertionを維持しながらV1 Baseline Suiteでは明示的にSkipする構成へ修正した。
  既存`AdminPaymentApiTest` 2件は期限付きBaselineと一致し、Baselineの追加・変更は行っていない。

## Final／Gate

- Fresh Self-review、GitHub Required Checks、Final Head、Squash Commit、Task Resource Cleanupは
  Closeoutで確定する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061G以降は開始しない。
