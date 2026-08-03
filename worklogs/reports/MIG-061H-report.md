# MIG-061H Admin User Point Adjustment Report

## Task

- Task ID: `MIG-061H`
- Risk／Profile: `R4`／`FINANCIAL-MUTATION-R4-TARGETED`
- Base: `main@3f6ee49d8fa7690e8cd4693e04439f65efed14e9`
- Branch: `feat/MIG-061H-admin-user-point-adjustment`
- Issue／PR: `#182`／`#183`
- Task Policy SHA-256: `4191b144fd33c50b97c515e5c47c42189d23b6e2e31c9efcf50a3aa665656f53`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061H/`
- Final Head／Squash Commit: CloseoutのGitHub merge結果を正本とする。

## Permission／Contract

- Canonical Permission `point.adjustment.manage`を追加し、Owner／Adminだけへ付与した。Operatorには付与せず、
  UIのButtonを非表示にするとともにAPI／Service境界で403へFail Closedする。
- `POST /admin/api/v2/users/{user_public_id}/point-adjustments`をAdmin OpenAPIへ追加した。
  Admin Realm、JSON、CSRF、Exact Origin、Fresh Authentication、Current Password、Critical Mutation
  Rate Limit、`Idempotency-Key`、Public ID、RFC 9457、Request ID、`private, no-store`を維持する。
- Public／Webhook Contract、Storefront Client、内部DB ID、User向けPoint Mutationは変更していない。

## Point Domain

- Canonical Wallet、Point Lot、Point Operation、Point Ledger Entry、Point Adjustmentを再利用する。
- 有償P加算は期限なしのPaid Lot、無償P加算は
  `config('oripa.free_point_expiration_days', 180)`に従う180日有効のFree Lotとして記録する。
- 減算は指定Point種別の利用可能Lotだけを既存Canonical順でLock／消費し、別種別へFallbackしない。
  Reservedと期限切れFree Lotを利用可能残高へ含めず、負残高となるRequestは409で拒否する。
- Point数は正の整数、方向は加算／減算を別Field、理由は必須NFC文字列とし、Unknown Field、制御文字、
  HTML境界文字を拒否する。

## Transaction／Idempotency／Audit

- Admin、対象User、Idempotency Record、Wallet、Point Lotを既存順でLockし、Wallet／Lot、Operation、
  Ledger、Adjustment、Append-only Audit、Idempotency完了を1 Transactionで確定する。
- 同一Key／同一Requestは保存済みCanonical ResponseをReplayし、残高を二重更新しない。同一Key／異内容は409。
  Transaction Runnerの既存最大3回Retryを使用する。
- Auditには管理者／対象User／Adjustment／OperationのPublic ID、Point種別、方向、量、前後残高、理由、
  Request ID、Key Hash、時刻だけを記録する。Password、Hash、Session、Cookie、Credential、内部DB IDは保存しない。

## Admin UI

- ユーザー詳細へOwner／Admin専用の「ポイント調整」ButtonとModalを追加した。対象User、Paid／Free残高、
  種別、加算／減算、正整数量、予定残高、理由、Current Password、確認／取消を表示する。
- 二重送信防止、Validation／API／Conflict／429、Fresh Authentication Dialog、Focus Trap、Escape、
  Mobile表示を既存UI基盤で扱い、成功後にCanonical User Detailを再取得する。

## Verification

- Task DB: `oripa_v2_mig061h`、Marker `MIG-061H`、Purpose `v2-task-ephemeral`。DB Target Safety Guard、
  V2 Migration Fresh 31件、Repository Migration集合照合がPASS。Migration追加はない。
- Backend対象: Domain／HTTP／Permission 14 tests／169 assertions PASS。追加Limiter確認は6 tests／69 assertions PASS。
- Process Concurrency: 1 test／12 assertions PASS。同時同一Keyは実行1回＋Replay 1回、Wallet／Adjustment／
  Operation／Ledger／Audit／Idempotency各1件を確認した。
- Admin対象Unit、Typecheck、Lint、Production Build、対象Browser E2E 2件がPASSした。
- OpenAPI Lint／Bundle／Base Breaking Check、OpenAPI Unit、Admin Generated Client差分0がPASSした。
- Policy／Quality／Security、Secret／PII、GitHub Required ChecksはCloseoutで追記する。
- Persistent／Ephemeral Full Guard、Backup／Restore、Draw負荷、V1全回帰、Storefront、全Admin E2Eは
  Profileに従い実行していない。Browser Scriptの引数が一度全24件へ転送され、旧Sidebar名を期待する既存
  Baseline 4件を確認後に停止し、Playwright直接指定でTask対象2件を実行した。

## Preview／Closeout

- DB Target Safety Guardで`oripa_v2_mig061a`、Marker `mig061a`、Purpose `v2-persistent`、
  Repository Migration 31件を切替前後に確認した。Migration、`migrate:fresh`、Schema変更は実行していない。
- Application／Image Source Head `9cc1fb0b5f50dbb504b1a75897682b1afe218cb4`から、API Imageを
  `sha256:562e666f...`から`sha256:d3d2b2b8...`、Admin Imageを`sha256:9d3c0e70...`から
  `sha256:d5c38bb3...`へ更新した。旧ImageはRollback用に保持する。
- Container名、Network、固定IP `192.168.61.10/11`、Loopback Port `8611/3611`、Restart Policy、
  Environment Key集合を維持した。PostgreSQL／Redis Container IDとNginx 3 vhost checksumは前後一致する。
- 専用Synthetic UserへOwner UIから無償Pを0→7加算し、Wallet／Adjustment／Operation／Ledger／Audit／
  Idempotency各1件を照合した。同一Key Replayは200 Canonical Replayで残高7を維持した。
- Synthetic OperatorはButton非表示かつ直接API 403。意図した403のBrowser Network Error 1件を除き、
  Console Critical Error 0、Page Error 0、HTTP 500／502／504 0である。CredentialはRepository／Reportへ
  保存せず、Repository外0600 Evidenceだけに保持する。
- API／Admin Container内Health 200、`admin.luxe-pack.biz/login` 200、User Detail 200、
  `luxe-pack.biz` 200、`ad.luxe-pack.biz/login` 302を確認した。V1、Nginx／TLS／DNS、Paymentは非変更。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Task Resource CleanupはCloseout後に追記する。

## Time／Gate

- 時間を要した作業はTask専用API ImageのClassic Build、CSRF Fixtureの形式・Cookie状態の決定化、
  Browser Runnerの全Suite転送切り分けである。Assertion、Timeout、Security Gateは緩和していない。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061I以降は開始しない。
