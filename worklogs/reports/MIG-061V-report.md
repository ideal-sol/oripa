# MIG-061V Report

## Issue／PR／Commit

- Issue: #212
- PR: Preview反映前のDraft PR作成時に確定
- Base: `7e689487a93cfac1e0e4f0a9bc81ef3f74d3a59c`
- Final Head／Squash Commit: Closeout結果として確定
- Task Policy SHA-256: `ed4f2a3f8f1b02f674f16588fa1b13abfd18007d38e73917093031a49e64919f`

## V1 Characterization／移植

- 正本は`legacy/v1-frontend/src/app/admin-dashboard.tsx`の購入プラン一覧、登録／編集Form、Laravelの既存Point Purchase Plan Controller／Request。V1一覧順はID、プラン名、支払金額、有償P、無償P、販売期間、並び順、状態、操作で、V2では内部IDをPublic IDへ置き換え、確定追加仕様の対象カテゴリを操作前へ追加した。
- 登録／編集は商品名、支払金額、付与有償P、付与無償P、並び順、販売期間、有効／無効を移植した。V1に説明、削除、実決済接続はないため追加していない。
- Owner／Adminは更新可能、OperatorはRead-onlyとし、`payment.plan.read`／`payment.plan.manage`を分離した。Admin APIは一覧、詳細、作成、更新だけを提供する。

## 対象カテゴリ／初回ユーザー

- Canonical値は`all_users`と`first_purchase_users`。初期値と既存商品Backfillは`all_users`で、DB Constraintでも許可値を固定する。
- 初回資格はV2 `payments.status = succeeded`の存在だけで判定する。`created`、`processing`、`failed`、`canceled`、`expired`は含めず、refund／chargeback後も成功購入が成立した事実を残して資格を復活させない。
- Eligibility ServiceはPayment作成時と成功確定Transaction内で再判定する。User Lock下で同時成功を1件に制限し、Frontend判定やガチャ利用回数は使用しない。Public購入開始Endpointは本Taskで新設していない。

## API／Migration／整合性

- `GET／POST /admin/api/v2/point-purchase-plans`、`GET／PUT /admin/api/v2/point-purchase-plans/{plan_id}`をAdmin OpenAPI／Generated Clientへ追加した。Public ID、Cursor、Revision OCC、Fresh MFA、Idempotency、Audit、RFC 9457、`private, no-store`を適用する。
- Migration `2026_08_25_000038_add_v2_point_purchase_management.php`は`sort_order`、`audience_code`、`revision`とIndex／Constraintだけを追加する。Forward-safe、rollback／reapply可能で、既存商品は`all_users`へBackfillする。
- 公開済み商品の顧客向け値は既存Versionを直接変更せず、旧Versionをretiredとして新Versionを作る。商品管理ではWallet、Lot、Operation、Ledgerを変更しない。

## Verification／Preview

- Task DB `oripa_v2_mig061v`でTarget Safety Guard、Migration fresh、latest rollback／reapply、既存商品Backfill `all_users|1|1`がPASSした。
- Backend対象28 tests／181 assertions、Admin Unit 3 files／30 tests、Desktop／Mobile Browser 2 tests、OpenAPI bundle、Generated Client、Typecheck、対象Lint、Policy exact-path unit、`git diff --check`がPASSした。
- Admin production buildはHost実行でcompile完了後にprocess sessionが閉じたため、最終Candidate Image buildをproduction buildの正本として記録する。
- Preview API／Admin Image、Migration 000038、Owner/Admin管理画面、対象カテゴリ、Wallet／Ledger不変、Console／Page／Gateway ErrorはPreview反映後に確定する。
- Nginx、V1、Storefront Repository、Public API Route、`V2_PUBLIC_ORIGIN`、Payment Providerは変更しない。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061V/`
- 残課題: 実決済／Storefront購入開始Endpointと対象商品の公開表示は後続Task。`first_purchase_users`のDomain判定は本Taskで再利用可能な形まで完成した。
- 所要時間: Closeout時に確定する。
- Gate G4／G5は`NOT COMPLETE`を維持する。
