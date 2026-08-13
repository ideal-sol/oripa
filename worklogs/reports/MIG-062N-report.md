# MIG-062N 管理者向け保有景品一覧／詳細

## Task

- Issue: #257
- PR: #258
- Base: `main@51209de02bff0e00078777c094147e568f403ff5`
- Branch: `feat/MIG-062N-admin-user-prize-management`
- Risk: R3
- Task Policy SHA-256: `17488d28cde665620e3d271a7b62e12e02827a459b7b8d61fb0b5bb2843af3`

## Admin Read Model／API

- `GET /admin/api/v2/user-prizes`と`GET /admin/api/v2/user-prizes/{user_prize_public_id}`を追加した。
- User Prize、Draw Resultの景品Snapshot、Draw Request、Canonical Gacha Version、Shipping、Point Exchange、Payment HoldをBackendで結合するRead Modelを正本とした。
- 景品名、画像、Rank、交換Pointは取得時Snapshotから返し、MIG-062LのPrize ownershipや後続Draft編集によって過去取得内容が変化しない。
- Allowed Actionsは既存`V2PrizeShippingService`のDomain判定を公開Service Methodとして再利用し、Frontendで状態・期限・交換Pointから推測しない。
- ResponseはPublic IDだけを返し、内部DB ID、Secret、不要PIIを露出しない。`private, no-store`、RFC 9457、Read Auditを維持する。

## 一覧／Filter

- User、景品名、Gacha、User Prize状態の必要最小限FilterをBackend Queryへ接続した。
- `ownership.id DESC`をStable Sortとし、既存Reporting CursorによるOpaque Cursor Paginationを使用する。
- 一覧にUser、景品Snapshot、Rank、画像、取得元Gacha、取得日時、現在状態、配送／Point交換状態、詳細導線を表示する。
- `shipping_request_items.user_prize_id`と`prize_exchange_request_items.user_prize_id`はいずれもUniqueであり、Fulfillment Joinによる一覧重複は発生しない。

## 詳細表示

- User／Prize／Gacha／DrawのPublic ID、requested／executed count、消費Point、取得時Snapshot、現在状態、Allowed Actions、関連日時を表示する。
- Shippingの現在状態と配送先、Point Exchangeの状態と交換Point、User Prize状態履歴をCanonical Read Modelから表示する。
- 本TaskはRead-onlyとし、AdminからShipping／Point Exchange／Address Mutationを追加していない。

## Permission／Contract

- 既存`shipping.request.manage`を再利用し、Owner／Admin／Operatorの既存閲覧境界を維持した。新PermissionやRole分岐は追加していない。
- Admin OpenAPI、Bundled Contract、Generated Clientへ一覧／詳細SchemaとClient Methodを同期した。
- Public OpenAPI、Storefront Client、Site Schema、Testkit、Storefront Artifactは変更しない。

## Verification

- Backend: 対象4 tests／93 assertions PASS。全Role、401／404／422、Cursor／Filter、Snapshot、Fulfillment、Query件数一定、内部ID非露出を確認した。
- Admin: 対象Unit 40 tests PASS、Desktop／Mobile Browser 2 tests PASS、Typecheck／Lint／Production Build PASS。
- Contract: Admin OpenAPI lint／bundle／Breaking Check、Generated Client同期 PASS。Public contract差分なし。
- Policy Unit、Policy Gate、Quality Gate、`git diff --check`: PASS。実Diff 27 PathとAllowed Paths 27 Pathの完全一致を機械確認した。
- 全V2 Suite、全Admin E2E、Storefront Test、DB Migration TestはScope外のため実行しない。

## Preview／Closeout

- GitHub Required Checks成功後、exact PR HeadのGitHub-hosted amd64 API／Admin Imageだけを検証・loadする。Host BuildとMigrationは行わない。
- Admin保有景品一覧／詳細、Filter、Snapshot、Fulfillment、Desktop／Mobile、Console／HTTP Errorを既存Preview Dataで確認する。
- Nginx、V1、Storefront Repository、DB Schema、Draw、Point、Payment、Fulfillment Mutationは変更しない。
- Final Head、Squash Commit、Preview Image、Required Checks、Fresh Self-review、CleanupはCloseout時に追記する。
- G4／G5はNOT COMPLETEを維持する。
