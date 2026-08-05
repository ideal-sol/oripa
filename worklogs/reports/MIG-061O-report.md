# MIG-061O Report

## Issue／PR／Commit

- Issue: #198
- PR: #199
- Base: `e8a1cad9ca7cf46b7248bd3178cec28c0617c99b`
- Final Head／Squash Commit: Closeoutで確定
- Task Policy SHA-256: `26a1842369992c72337da3733f13fb61c2a769d5bedfb9a54590d07aa761161b`

## V1 Characterization／移植

- 対象: `legacy/v1-frontend/src/app/admin-dashboard.tsx`、V1 Contact Controller／Request／Resource／Model／Migration。
- V1一覧はID、氏名、メール、電話番号、状態、受付日時、操作の順で、本文冒頭、状態・メール絞り込み、詳細、返信を備える。状態は`new`／`replied`／`closed`。
- V2へ同じ列順、本文抜粋、詳細項目、返信導線を移植し、既存の`new`／`in_progress`／`replied`／`closed`状態遷移と対応履歴を利用する。
- V2ではPublic ID、Admin Realm、`contact.read`／`contact.manage`、RFC 9457、`private, no-store`、Idempotency、UTC保存／Asia/Tokyo表示を使用する。暗号化PIIのためメール検索はCorrelation HMACによる完全一致とし、V1の部分一致は移植しない。

## 返信／状態管理／API

- 既存Contact一覧／詳細／状態変更／返信要求APIを拡張し、一覧へV1列、詳細へ返信要求履歴、MutationへCanonical Replayを追加する。Migrationは追加しない。
- 返信本文は暗号化保存し、Transactional Outboxへ送信要求を記録する。V1の同期メール送信はV2でCanonicalな配送Workerが確定していないため推測実装しない。
- Adminは`/contacts`と`/contacts/{public_id}`を実画面化し、Cursor Pagination、Loading／Empty／Error、返信、状態変更、履歴、Desktop／Mobileへ対応する。

## Verification／Preview

- Task DBで対象BackendとContent回帰の計18 tests／139 assertions（一覧3 Query、詳細10 Query上限）、Admin Unit 3件、Desktop／Mobile E2E 2件がPASSした。OpenAPI Bundle／Breaking、Generated Client、Typecheck、Lint、Production Build、Policy／Quality、`git diff --check`もPASSした。
- Preview結果、GitHub Required Checks、Final HeadのSelf-reviewはCloseoutで確定する。
- Preview更新対象はAPI／Adminのみ。DB Schema／Migration、PostgreSQL／Redis設定、Nginx、V1、Storefrontは変更しない。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061O/`

## 移植しなかった機能／所要時間

- V1のメール部分一致検索と同期メール送信は、V2の暗号化PIIおよびOutbox境界を維持するため移植しない。外部メール配送の最終処理は後続課題とする。
- Characterization、後方互換Contract、対象検証、Preview反映が主な作業。所要時間はCloseoutで確定する。
