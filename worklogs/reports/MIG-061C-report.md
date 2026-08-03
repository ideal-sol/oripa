# MIG-061C TailAdmin Admin Shell Foundation Report

## Task

- Task ID: `MIG-061C`
- Risk: `R3`
- Base: `main@d1e24394b8449ad801398a6cb22033195ab0c833`
- Branch: `feat/MIG-061C-tailadmin-admin-shell-foundation`
- Issue: `#167`
- PR: `#168`
- Task Policy SHA-256:
  `d0610940de114be3ac816228c7a70abab40e35c0f007c36d39f2cd8c56b3d21a`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061C/`
- Final Head／Squash Commit: Closeout時にGitHub上で確定し、最終完了報告と
  Repository外Evidenceへ記録する。

## TailAdmin参考方針

- TailAdmin Next.js FREE版の情報密度、白いSidebar、Header、Card、Form、Table、
  Responsive Drawerを視覚・構造上の参考とした。
- TailAdmin Source、Pro素材、Packageは取り込まず、既存Next.js、React、CSS、Lucide、
  Admin Componentを再利用した。Package／Lockfileは変更していない。
- 青・白・グレーを中心に、Card radiusは8px以下、過度な装飾やAnimationを追加せず、
  Dark Modeも新規追加していない。

## Login変更

- 既存Password-only Loginを、Desktopでは認証Formとブランド文脈を分けた2-column、
  Mobileでは単一columnのLayoutへ整理した。
- Email、Password、Loading、Validation／API Error、Invitation導線の既存挙動を維持した。
  通常LoginにInvitation Token入力は追加していない。
- Session、MFA Policy、Enrollment／Challenge、CSRF、Host Guardは変更していない。

## Shell／Sidebar／Header変更

- Desktop固定Sidebar、Mobile Drawer、折りたたみ、Header、User Menu、Logout、
  Main Content Containerを共通Shellとして再構成した。
- DrawerはEscape、Focus Trap、Close後のFocus復帰に対応し、User Menuは型付き
  `menu`／`menuitem` Roleを使用する。
- BreadcrumbはLucide Chevronへ統一し、Page Header、Loading、Error、Forbidden、
  Not Foundの既存境界を新Shell内で維持した。

## Navigation

- 実装済みRouteだけを表示し、Dashboard、Catalog概要、ガチャ管理、景品管理、QA管理、
  管理者認証、LINE設定をBackend Effective Permissionに従って表示する。
- `/catalog/gachas`と`/catalog/prizes`を独立導線とし、最長Path一致でActive Navigationを
  一意に判定する。Shipping、Reporting、Content、Contactの準備中RouteはRegistryと
  Direct Route Guardを維持するが、共通Menu／Dashboardには表示しない。
- Role名による独自判定、架空Menu、Client側だけのSecurity境界は追加していない。

## Dashboard

- Current Admin、Role、MFA Policy、Server取得済みEffective Permission、利用可能Moduleを
  Card／Section Layoutへ再配置した。
- Moduleは実Permissionと実Routeだけを表示し、利用可能Moduleがない場合はEmpty Stateを
  表示する。架空の売上、会員数、Draw数、Chart、新規集計APIは追加していない。

## Responsive／Accessibility

- Desktop `1440x900`、Mobile `390x844`でSidebar、Drawer、Card、Form、Table Container、
  横溢れなしを確認した。
- Navigation、User Menu、Drawer、Logout、BreadcrumbにAccessible Name／Roleを付与し、
  Keyboard Open、Escape Close、Focus移動を維持した。
- Headless Browser実行Hostに日本語FontがないためEvidence ScreenshotではCJK glyphが
  描画されないが、DOM text、Computed Style、Accessible Name、Browser Assertionは正常である。

## Test結果

- Frozen Install: `PASS`
- Admin Typecheck／Lint／Production Build: `PASS`
- Admin Unit／Component: `13 files / 64 tests` `PASS`
- Shell対象Unit: `4 files / 14 tests` `PASS`
- Admin Browser E2E: `16 tests` `PASS`
- Candidate Container Login／Static Asset: Desktop／Mobile `PASS`
- Policy Gate／Quality Gate／Security Gate／Secret・PII Scan: `PASS`。Composer既存
  Baseline `10件`、pnpm Finding `0件`、Secret Candidate `0件`、Baseline期限
  `2026-08-07`を確認した。Dependency差分がないためMIG-061BのAudit JSONを再利用し、
  Security Gateは本Task Headの全tracked fileを再走査した。
- GitHub Required Checks: Final Headで確定する。
- `git diff --check`: `PASS`
- Backend全回帰、DB Guard、Migration、Draw性能、Backup／Restore、V1全回帰は、
  Backend／DB／V1差分がないためTask指示どおり実行していない。

## Preview Deployment

- `mig061a-v2-preview`のAdminだけを`--no-deps --no-build`で更新した。Network
  `mig061a-v2-preview_v2_private`、固定IP `192.168.61.11`、Loopback
  `127.0.0.1:3611`、Restart Policy、Environment key集合を維持した。
- Admin Imageは
  `sha256:22d15912a09861ed97fd1441dac0f50af480c40d4edd38661d183ca8a4a8d29f`から
  `sha256:bd49b3460d024534b8b13676cd661ed00ded210edd9e27acbeb7b9ca2f2c9f71`へ更新した。
  Source SHAは`a6cab0d029cae7c0ff10cb742c1582418d1281d3`である。
- Rollback Image `oripa-v2-admin:mig061b-74578e799a22`は削除せず保持した。
- 実DomainでPassword-only Login、Dashboard、Gacha、Prize、QA、管理者認証、Desktop
  Sidebar、Mobile Drawerを確認した。API Health 200、Console Critical Error 0、
  HTTP 500／502／504 0、横溢れ0である。
- API、PostgreSQL、RedisのContainer IDは切替前後で一致する。V2 DB／Migration、Nginx、
  TLS／DNS、V1 Runtime、`luxe-pack.biz`、Storefront、Paymentは変更していない。
  Nginx checksumは
  `9832e492f8995db08a45d72f22566d09111d44539524b6509a79b986909f7347`で不変、
  `luxe-pack.biz`はHTTP 200を維持する。

## 実画面で残っているUI課題

- Catalog、Gacha、Prize、QA、管理者認証のTable／Form内部は既存Componentのままであり、
  TailAdmin基準への全面的な密度・余白・操作配置の統一は後続Task範囲である。
- Dashboardは既存APIで取得可能なSession／Permission／Module情報だけを表示するため、
  業務KPIやGraphは表示しない。

## 時間を要した作業

- Admin Browser E2Eは各回約1分20秒を要した。Shell変更後の全16件を1回、Navigation
  独立化後の最終16件を1回実行し、同一Headで理由のない再実行はしていない。
- Headless Screenshotで日本語だけが不可視だったため、DOMのText、Computed Color、Display、
  OpacityとAccessible Assertionを追加確認し、CSSの隠蔽ではなくHost Font制約と切り分けた。
- Candidate Smokeの最初のLocatorは実見出しと1文字異なりTimeoutした。Applicationは変更せず、
  実Accessible Nameへ修正してDesktop／Mobileを1回再試行した。
- Root空きはBuild前`4.4GB`、Docker Build Cacheは`0B`だった。Candidate Buildに必要な
  容量があり、Rollback Image保護を優先してImage／Volume Cleanupは行っていない。

## Final／Gate

- Fresh Self-review、GitHub Checks、Final Head、Squash Commit、Branch／Worktree Cleanupは
  Closeoutで確定する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061D以降は開始しない。
