# MIG-061D Admin Sidebar Hierarchy／Route Scaffold Report

## Task

- Task ID: `MIG-061D`
- Risk: `R3`
- Base: `main@fcc782daee4e6303858e412d9fe4372dc9919c7c`
- Branch: `feat/MIG-061D-admin-sidebar-hierarchy`
- Issue: `#172`
- PR: `#174`（Draft／Policy Registry更新待ち）
- Task Policy SHA-256:
  `ada7789d9af064659c1b7d11c12565cf714f79264e13de057fe18e70883dca89`
  （Atomic再発行前:
  `b9cb11f732882a34674c633051be8dbebe67a25c5a795078445249019ccc0dd5`）
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061D/`
- Final Head／Squash Commit: Closeout時に確定する。

## メニュー階層と表示順

1. ダッシュボード
2. ユーザー: 一覧／履歴
3. ガチャ: 一覧／登録／シミュレーション／カテゴリ／タグ／履歴
4. 配送: 一覧
5. ポイント購入: 一覧／登録
6. お知らせ: 一覧／登録
7. バナー: 一覧／登録
8. お問い合わせ: 一覧
9. 各種設定: ページ設定／演出設定／紹介ポイント設定／LINE設定

- Dashboardは単独Link、他は遷移先を持たないButton Parentとした。
- Accordionは同時に1 Parentだけを展開し、Current Routeを含むParentは初期表示と
  Browser Reload後に自動展開する。
- Catalog概要、景品管理、QA管理、管理者認証設定はSidebarから外したが、Route、Component、
  Permission、Direct Accessを削除していない。

## Route Mapping

既存Routeをそのまま使用する。

- `/`: Dashboard
- `/catalog/gachas`: ガチャ一覧
- `/catalog/categories`: カテゴリ
- `/catalog/tags`: タグ
- `/shipping`: 配送一覧
- `/contacts`: お問い合わせ一覧
- `/catalog/presentation-assets`: 演出設定
- `/settings/line`: LINE設定

## 新規Scaffold Route

- `/users`、`/users/history`
- `/catalog/gachas/new`、`/catalog/gachas/simulation`、`/catalog/gachas/history`
- `/purchase-plans`、`/purchase-plans/new`
- `/announcements`、`/announcements/new`
- `/banners`、`/banners/new`
- `/settings/pages`、`/settings/referral`

ScaffoldはPage Title、階層Breadcrumb、機能名、
「詳細画面は後続Taskで実装します。」だけを表示する。架空Data、件数、Form、Button、
Backend API、Permission文字列は追加していない。

## Parent／Child Active判定

- Static Routeの完全一致を優先し、その後に最長Path Prefixを使用する。
- `/catalog/gachas/new`と`/catalog/gachas/history`は専用Routeが一覧より優先される。
- ParentとChildを同時にActive表示し、`aria-expanded`／`aria-controls`を付与した。
- 新規Scaffoldで既存Canonical Permissionを利用できる項目は既存Permissionを使用し、
  対応PermissionがないUser、Point Purchase、Simulation、ReferralはBackend Role結果に基づく
  Owner-only Preview Boundaryとした。Permission Providerは変更していない。

## Desktop／Mobile／Accessibility

- Desktop SidebarとMobile Drawerは同じRegistryを使用する。
- Compact SidebarのParent操作はSidebarを展開してからChildrenを表示するため、操作不能に
  ならない。
- Mobileは子Link選択後にDrawerを閉じ、Escape CloseとTriggerへのFocus復帰を維持する。
- Browser Smokeで既存OverlayがDrawerより前面にありLinkを遮断する状態を検出したため、
  Mobile Sidebarの`z-index`だけを補正した。

## Test結果

- Frozen Install: `PASS`
- Admin Typecheck／Lint: `PASS`
- 対象Unit／Component: `3 files／15 tests` `PASS`
- 全Admin Unit: `14 files／72 tests` `PASS`
- Browser E2E: 最終全2件 `PASS`（Desktop全Route／Mobile Drawer、`1m14s`）。
- Production Build／生成差分: `PASS`。
- Policy Unit: `91 tests` `PASS`。
- Quality Unit／Gate: `PASS`。
- Policy Gate: `PASS`。Task Policyを指定2 Pathだけ追加してAtomic再発行し、新規Scaffold
  Route／専用Testの15 Pathだけを中央`ADMIN_SKELETON_FILES`へ登録した。
- 新規Admin FileとTask専用登録集合は`added=15／registered=15／exact=True`。Wildcard、
  将来Path、Gate条件変更はない。
- Security Gate、Secret Scan、最終`git diff --check`: Policy Registry整合後に実行する。
- Backend全回帰、DB Guard、Migration、Draw性能、Backup／Restore、V1全回帰は、Task指示と
  変更Pathに基づき実行しない。

## Preview Deployment

- 切替前Admin Image:
  `sha256:bd49b3460d024534b8b13676cd661ed00ded210edd9e27acbeb7b9ca2f2c9f71`
- Rollback Tag: `oripa-v2-admin:mig061c-a6cab0d029ca`
- Network `mig061a-v2-preview_v2_private`、固定IP `192.168.61.11`、Loopback
  `127.0.0.1:3611`、Restart Policy、Environment Key集合を記録した。
- Candidate Build／Preview反映は全Local Gate成功後に実施する。API、PostgreSQL、Redis、DB、
  Migration、Nginx、TLS／DNS、V1、Storefront、Paymentは変更しない。

## 残っているUI課題

- 新規Scaffoldの業務一覧、登録Form、履歴、Simulation、設定保存機能は後続Task範囲である。
- Dashboard売上管理構成はMIG-061Eへ延期し、本Taskでは既存Session／Permission表示を維持する。
- 既存Catalog／QA等のTable・Form内部の追加改修は行っていない。

## 時間を要した作業

- Browser E2EはStandalone Production Buildを各実行前に行うため時間を要する。初回Desktopは
  PASSし、MobileでOverlay遮断を検出した。CSS修正後はMobile 1件だけを先行再実行し、最終変更
  確定後に全2件を1回実行してPASSした。
- Root空きは開始時`4.4GB`、Docker Build Cacheは`0B`だった。Rollback Imageを保護し、
  Image／Volume Cleanupは行っていない。

## Final／Gate

- Policy再発行と中央Admin Skeleton登録のBlockerは解消済み。Application変更は
  `d9fa73f77caf185f7506ceb9fc925942f5073f09`から不変のため、成功済みのTypecheck、Lint、
  Build、全Unit、Browser E2E Evidenceを再利用した。
- Fresh Self-review、GitHub Checks、Preview反映、Final Head、Squash Commit、Cleanupは
  Closeoutで確定する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061Eは開始しない。
