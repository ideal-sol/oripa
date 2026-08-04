# MIG-061K Report

## Task

- Issue: #190
- PR: pending
- Base: `92716d166bd829a236f25e7c9e36e76c46aebdc2`
- Task Policy SHA-256: `2ad21acf8b9e9f0d0baf613a098c22dbb0159e4f0b875d2d6dcff7db90a3cde3`

## V1移植

- V1のガチャ別Rank設定、Rank画像／抽選演出動画、PrizeのRank・名称・画像・在庫上限・原価・交換Point・状態をRead-only Characterizationした。
- V2ではPublic ID、Catalog Master、Draft Gacha Version relation、Presentation Asset、Idempotency／Revision OCCを正本として接続する。

## 実装／検証／Preview

- 実装中。

## 残課題

- Probability Editor、履歴、Simulation、商品設計Planner、公開操作は対象外。

## 所要時間

- 開始時刻: 2026-08-04 UTC
- Weekly limit: 実行環境から取得可能な値がないため未記録。
