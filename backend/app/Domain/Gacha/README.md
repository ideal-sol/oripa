# Gacha Domain

LaravelがDraw、Probability Version、Inventory、QA Draw、Bulk Drawの業務Authorityを持つ。

## Bulk Draw

- `100`／`1000`回は1 Request、1 `draw_request`、1 DB Transactionとして処理する。
- 各Drawは`draw_sequence_number`順にStageを解決し、CSPRNGで抽選する。確率から当選数を一括近似しない。
- Gacha、Wallet、対象Prizeを固定順でLockし、Bulk内の在庫変化は次のDrawへ反映する。
- Point、Inventory、`draw_results`、`user_prizes`、最低保証Lot／Ledgerは途中Failure時に全Rollbackする。
- `Idempotency-Key`の再送可能期間は完了から24時間。期限後のKey再利用は拒否し、監査履歴である`draw_requests`は自動削除しない。
- 同じKeyと同じRequestは同一`public_id`の集計結果を返し、異なるRequestへのKey再利用は拒否する。
- Bulk Responseは景品別／Rank別集計と、最小`sort_order` Rankの結果を最大20件返す。個別結果はDBへ全件保存する。
- 既存1〜10回のRequest／Responseは従来経路を維持する。

Lottery LogicをNext.jsへ実装してはならない。
