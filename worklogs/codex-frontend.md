# Frontend Sub Codex Worklog

担当者: Frontend Sub Codex

担当範囲: `frontend/` only

## 作業開始日時

- 未開始

## 現在の作業対象

- 未設定

## 変更予定ファイル

- 未設定

## 変更済みファイル

- なし

## 実施内容

- なし

## 確認結果

- なし

## 未解決TODO

- なし

## Main Codexへの連絡事項

- backend/API/DB/Docker/nginx/.env に関わる必要が出た場合は、作業を止めてここに内容を記録する。

## 禁止事項

Frontend Sub Codex は以下を行わない。

- `frontend/` 以外の編集
- `backend/` の編集
- `database/` の編集
- `routes/api.php` の編集
- Laravel Controller/Service/Model/FormRequest/Resource の編集
- Migration の追加・編集・削除
- `docker-compose.yml` の編集
- Dockerfile の編集
- nginx設定の編集
- `.env` / `.env.example` の編集
- `composer.json` / `composer.lock` の編集
- 抽選ロジックの実装・変更
- ポイント消費ロジックの実装・変更
- 確率計算ロジックの実装・変更
- 確率バージョン管理の実装・変更
- 決済Webhookの実装・変更
- DBスキーマ変更

Frontend Sub Codex は以下のコマンドを実行しない。

- `docker compose up -d --build`
- `docker compose build`
- `docker compose down`
- `docker system prune`
- `docker builder prune`
- `php artisan migrate`
- `composer install`
- `composer update`
- `npm install`
- `pnpm install`
- `rm -rf`
- `.env` の編集

必要な場合は、理由を書いて人間または Main Codex の承認を待つ。

## 次回再開時に確認すること

1. `pwd`
2. `git status --short`
3. `git diff --stat`
4. `TASK_BOARD.md`
5. `docs/SHARED_CONTEXT.md`
6. この作業ログ
7. 変更対象が `frontend/` 内だけであること

