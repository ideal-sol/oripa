# MIG-061E Dashboard V1 Sales Management Layout Report

## Task

- Task ID: `MIG-061E`
- Risk: `R3`
- Base: `main@21648f1d4a00355be6ede17a2de7735a99f949ff`
- Branch: `feat/MIG-061E-dashboard-sales-layout`
- Issue: `#176`
- PR: `#177`（Draft／Final GitHub Check待ち）
- Task Policy SHA-256:
  `da9b9751808b10a12683e9c2c63ac3542a19e53e9bfdea8e39ad95d6ee636522`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061E/`
- Application／Image Source Head: `58b567e439ed7c787be0b406435f2054ee3dae72`
- Final Head／Squash Commit: Closeout時に確定する。

## V1 Characterization結果

- `https://ad.luxe-pack.biz/login`はRead-only Probeで既存V1 Admin RouteへHTTP 302を返す。
  Credential、Cookie、実売上Dataは取得またはEvidenceへ保存していない。
- V1 Route `legacy/v1-frontend/src/app/admin/[[...segments]]/page.tsx`は
  `/admin/sales`と`/admin/payments`を`tab: sales`へ解決する。
- V1正本`legacy/v1-frontend/src/app/admin-dashboard.tsx`の表示順は、月別売上、日別売上、
  月別ポイント消費、日別ポイント消費、返金/CB履歴である。
- 月別売上は対象年月、総売上／返金額／CB額／純売上、曜日Header付きCalendarの順である。
  日別売上は対象日、同4 Summary、決済一覧、返金・チャージバック一覧の順である。
- 月別ポイント消費は対象年月、有償P消費／無償P消費、曜日Header付きCalendar、
  日別ポイント消費は対象日とTableである。
- V1にはCSV、再取得、返金／Chargeback等の操作があるが、V2の対応Contractは本Task時点で
  存在しない。V1 Source／CSSはRead-onlyで、V2へComponentをCopyしていない。

## 再現した項目と表示順

- `/`とMIG-061C／DのAdmin Shell、Sidebar、Header、Breadcrumb、Navigationを維持した。
- `dashboard-sales-layout.tsx`へV1と同じ5 Tab、対象年月／対象日／期間入力、Summary、
  Calendar／Table領域を実装した。
- Monthly Salesは総売上、返金額、CB額、純売上をV1順で表示する。
- Monthly Pointsは有償P消費、無償P消費をV1順で表示する。
- Calendar／TableはSemantic `table`、`th scope=col`、横Scroll Containerを使用する。

## V2 Data／未接続項目

- 新規Backend API、OpenAPI、Generated Client、DB、Migration、実集計処理を追加していない。
- V2でDashboard用集計Dataを取得できないため、売上、返金、CB、Point、件数を数値表示せず、
  全領域を「集計API未接続」とした。`0円`、`0件`、`0pt`等の架空値は表示しない。
- Password-only Session、Backend Effective Permission取得、既存Navigationは実Dataを使用する。
- 後続Backend候補は月別／日別売上、月別／日別有償・無償Point消費、返金／CB履歴の
  Admin集計Contractである。Frontend推測集計やV1 API接続は行わない。

## Disabled／延期した操作

- CSVはV1上の位置を確認できるDisabled Buttonとして表示し、Labelへ「後続Taskで実装」を
  明記した。CSV生成Endpointは追加していない。
- 返金／CB期間検索もDisabled表示とし、動作可能に見える操作を作っていない。
- 再取得、返金／Chargeback Mutation、Point／Payment詳細は本Taskへ含めていない。

## Responsive／Accessibility

- Desktop `1440x1000`、Mobile `390x844`でSummary Cardの4／2／1列折返し、Tab横Scroll、
  Table／Calendar専用横Scroll、Page横溢れ0を確認した。
- Tablist／Tab／Tabpanel、`aria-selected`、Form Label、Heading階層、Table Header、
  Loading `aria-live`、Empty `role=status`、Error `role=alert`、Keyboard／Focusを実装した。
- Fresh Self-reviewで検出したTablistのKeyboard到達性不足を修正し、Arrow Up／Down／Left／Right、
  Home／Endで選択とFocusが同期するWAI-ARIA Tab操作を追加した。
- Headless Browser Hostに日本語FontがないためScreenshotではCJK Glyphが描画されないが、
  DOM Text、Accessible Name、Browser Assertion、Layout寸法はPASSしている。

## Test結果

- Frozen Install: `PASS`（pnpm `10.12.1`、Lockfile差分0）
- Admin Typecheck／Lint: `PASS`
- Admin Unit／Component: `15 files／78 tests` `PASS`
- Dashboard対象Unit: `7 tests` `PASS`（Keyboard Arrow／End操作を含む）
- Dashboard Browser E2E: Desktop／Mobile `PASS`。最初のDesktop実行は`総売上`の曖昧な
  Test Locatorだけが失敗し、完全一致へ修正後に失敗Caseを再実行した。Mobileは初回PASS。
- Production Build: `PASS`（Browser E2EのStandalone BuildおよびImage Build）
- Keyboard修正後の対象Browser E2E: Desktop／Mobile `2 tests` `PASS`
- Admin生成差分: `0`
- Policy Unit: `92 tests` `PASS`
- Policy／Quality Gate: `PASS`
- Security Unit／Gate: `6 tests`／`PASS`。Root／Legacy pnpm Finding 0、Composer既存Baseline
  10件、期限`2026-08-07`、Secret Candidate 0。
- 新規Admin FileとTask専用登録集合は`3／3`で完全一致。Wildcard、Directory、将来Path、
  Gate条件変更はない。
- `git diff --check`: `PASS`
- Backend全回帰、DB Guard、Migration、Draw性能、Backup／Restore、V1全回帰は、
  Backend／DB／V1差分がないためTask指示どおり実行していない。

## Preview Deployment

- `mig061a-v2-preview`のAdminだけを`--no-deps --no-build`で更新した。Container名、Network
  `mig061a-v2-preview_v2_private`、固定IP `192.168.61.11`、Loopback `127.0.0.1:3611`、
  Restart Policy、Environment Key集合を維持した。
- Admin Imageは
  `sha256:fe685fc99eba2f13a4c3355afa39eff28c1ed366398eebfdab33261a9fb0c0cf`から
  `sha256:a80018dd0aec36f283fbdc47cfe3a830a156bf3c8bd791f88021a25fafc1ad4e`へ更新した。
- Rollback Image `oripa-v2-admin:mig061d-9b1e3c0e046b`は保持し、Rollbackは不要だった。
- 実DomainでPassword-only Login、Dashboard、5 Tab順、Desktop／Mobile、Sidebar、
  Keyboard Tab移動、API Session接続を確認した。Console Critical Error 0、Page Error 0、
  HTTP 500／502／504 0である。
- ComposeのLoopback `127.0.0.1:3611` Port Binding宣言はHostConfigへ維持されるが、`internal: true`
  の既存NetworkではDockerがHost側NATを公開せず、Loopback Probeは接続不可だった。Nginxは
  既存固定IP `192.168.61.11:3000`を使用し、Domain HealthとBrowser SmokeはPASSしている。
  Network／Compose／Nginx変更は本Taskの境界外のため行っていない。
- API、PostgreSQL、RedisのContainer IDは前後一致する。DB／Migration、Nginx／TLS／DNS、
  V1、Storefront、Paymentは変更していない。Nginx設定集合checksumは前後とも
  `d87556c77d433637b958826d41b9af9e69316fdb218089e9a626fca9d61aa1e1`である。
- `luxe-pack.biz`はHTTP 200、`ad.luxe-pack.biz/login`は既存V1 RouteのHTTP 302を維持する。

## 実画面で残った課題

- 集計値、Calendar Cell、決済／Point／返金履歴はBackend集計Contract実装後に接続する。
- CSV、検索、再取得、返金／CB操作は後続TaskでContractと権限境界を確定する必要がある。
- Headless Evidence Hostへ日本語Fontがないため、Glyphを含むVisual Regressionは別の
  Japanese Font利用可能なBrowser環境で補完する余地がある。

## 時間を要した作業

- Browser E2EのProduction Buildを伴う実行は約48秒。Desktopの曖昧Locatorだけを完全一致へ
  修正し、成功済みMobileを重複実行せずDesktop 1件だけ再試行した。
- Security Gateの初回呼出しは既存Baseline引数が不足してCLI usageで終了した。監査と
  Security Unitは再実行せず、そのEvidenceを維持してGate本体だけ正しい引数で再実行した。
- Candidate Image BuildはClassic Builderで52秒。開始時Root空き4.3GB、Build Cache 0Bで、
  Rollback Image、稼働Container、Named Volumeを保護しCleanupは行っていない。
- Fresh Self-reviewでTablistの矢印Key操作不足を検出したため、許可済み3 Pathだけを修正した。
  Typecheck、Lint、Unit 7件、Browser E2E 2件、Production Buildを対象再実行し、Admin Imageだけを
  再Build／再配置した。成功済みBackend／DB／V1 Evidenceは重複実行していない。

## Final／Gate

- Preview DeploymentとLocal Gateは完了し、GitHub ChecksはFinal Headで再評価する。
- Fresh Self-review、GitHub Checks、Final Head、Squash Commit、Branch／Worktree Cleanupは
  Closeoutで確定する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061F以降は開始しない。
