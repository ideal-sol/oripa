# V1 Early Release Worklog

## MIG-045 Closeout

- Issue `#89`はClosed、PR `#90`はMergedをGitHub APIで再確認した。
- Final Headは`c68ff796fe1edf4c05ba7644950a9865c20a294f`、Squash Commitは`07d9da4c8a482a806c092ec8ef19ac62d901dbec`。
- Required 5 Check、CodeQL、Dependency Reviewを含む8 Checkはすべて成功した。
- Final Head固定後のFresh Self-reviewは同一SHAを参照し、SEV-0／SEV-1は0件。
- `platform-v2.0.0-alpha.1`はPre-releaseとして作成済みで、Gate G3は`COMPLETE`。
- MIG-045のRemote／Local Task BranchとWorktreeはCleanup済み。
- Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、V1本番Resource、`archive/v1-current`、Annotated Tagは変更されていない。

## V1-NOTICE-001 お知らせ／LP拡張

### Task

- Task ID: `V1-NOTICE-001`
- Risk: `R3`
- Issue: `#91`
- Base／PR Base: `v1/early-release`
- Base SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Branch: `feature/V1-NOTICE-001-content-extension`
- V1 Runtime Worktreeは固定Commitかつcleanのまま維持した。

### 実装

- `announcements.category`へ`notice`／`lp`の固定値とDB Constraintを追加した。既存Recordは`notice`をDefaultとし、NULL／未定義値を拒否する。
- `published_until`をNULL可で追加し、JST基準の公開開始／終了条件をModel Scopeへ一元化した。
- Public一覧は公開期間内の`notice`だけを返し、公開期間内の`lp`は既存詳細URLからだけ取得可能とした。
- LP詳細へ`noindex`、`nofollow`、`noarchive`を設定した。現行RepositoryにSitemap、RSS、関連記事、Public検索の実装はなく、既存の唯一の一覧SurfaceからLPを除外した。
- `ezyang/htmlpurifier` `4.19.0`をExact Versionで追加し、Server-side Sanitizationを実装した。
- `script`、Event属性、`javascript:` URL、`iframe`、`form`、`style`を除去し、段落、見出し、強調、List、Link、Tableを許可した。
- 既存Plain Textは保存値を変更せず、改行とHTML Escapeを適用して互換表示する。
- 管理画面へ必須カテゴリ選択、公開終了日時、同一Sanitizerを使うPreviewを追加した。
- LPはトップスライダー対象へ設定できない。
- 新規V1 Migrationだけを追加し、既存Migrationは変更していない。

### 検証

- 対象Feature Test: 13 Test、47 Assertion。カテゴリ、公開期間、LP直接表示、404、無期限、Admin権限、Preview一致、Stored XSS防止、DB Constraintを確認した。
- Full Backend Regression: 実装前は332 Warning／2既知Failure、実装後は344 Warning／同一2既知Failure。新規Failureなし。
- 既知Failureは`AdminPaymentApiTest`の既存Refund／Chargeback Fixture不整合であり、本Taskの変更範囲外。
- 非Production Migration: `fresh`、Rollback、既存Record作成、Apply、Rollback、Reapplyを実行した。
- Migration前後のRecord数は`1／1／1`、既存本文SHA-256は全段階で一致し、既存Recordの`category=notice`を確認した。
- Frontend Typecheck／Buildは成功した。
- Frontend Lintは実装前後とも既存`8 Error／1 Warning`で、新規Findingなし。
- 非Production Smoke Test: Public Top 200、LP詳細200、Public一覧からLP除外、LP robots 3指定を確認した。
- Composer Auditは既存10 Finding、Frontend Auditは既存18 Finding（High 12／Moderate 6）。追加したHTMLPurifier由来の新規Findingは0件で、Frontend Lockfileは変更していない。
- `git diff --check`、PHP Syntax、JSON Parse、Secret／PII Candidate、Scopeを確認した。
- Production Deployment、V1本番DB Migration／Rollback／Seed、Nginx変更は未実行。

### 保護対象

- `/var/www/oripa-v1-runtime`、`luxe-pack.biz`、`admin.luxe-pack.biz`は変更していない。
- V1本番DB／Redis／Storage、Nginx、Production Containerは変更していない。
- `archive/v1-current`と`v1-before-productization-2026-07-22`は固定Commitのまま。
- V2 `main`、V2実装、MIG-045後続Taskは変更・開始していない。

### 次Task候補

- `V1-DRAW-1000A Bulk Draw 1000 Backend・Transaction・性能基盤`
- 主目的は1000回ガチャとし、100回対応は同じBulk基盤を利用する副次要件とする。
