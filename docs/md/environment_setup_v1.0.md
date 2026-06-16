**オリパサイト\
Codex環境構築指示書\
Laravelバックエンド版**

Version 1.0 / 2026年6月10日

  -------------------------------------------------------------------------------------------------------------------------------------------
  **項目**                            **内容**
  ----------------------------------- -------------------------------------------------------------------------------------------------------
  対象                                Codexにローカル開発環境を構築させるための実行指示

  参照仕様                            オリパサイト構築 仕様書 v1.4

  今回の変更                          バックエンドをLaravel APIに固定する。Next.jsのみでAPI/抽選を実装しない。

  構成                                frontend: Next.js / backend: Laravel API / DB: PostgreSQL / cache・queue: Redis / storage: MinIO /
                                      mail: Mailpit

  重要制約                            抽選・ポイント・在庫・確率バージョン・決済WebhookはLaravel側の責務。フロントは表示とAPI呼び出しのみ。
  -------------------------------------------------------------------------------------------------------------------------------------------

# **Codexへ渡す最初の指示**

あなたはオリパサイトの開発環境を構築するエージェントです。\
添付の「オリパサイト構築 仕様書
v1.4」を必ず読み、以下の方針でローカル開発環境のみを構築してください。\
\
- フロントエンド: Next.js / TypeScript / App Router\
- バックエンド: Laravel API\
- DB: PostgreSQL\
- キャッシュ・キュー: Redis\
- ローカルS3互換ストレージ: MinIO\
- メール検証: Mailpit\
\
このタスクでは、抽選ロジック・決済処理・管理画面の本実装までは行わないでください。\
ただし、後続実装が安全に進められるよう、ディレクトリ構成、Docker
Compose、環境変数、ヘルスチェック、Laravel/Next.jsの接続、テスト実行、READMEを整備してください。

# 1. 本指示書の目的

本書は、オリパサイト仕様書v1.4を前提に、CodexへLaravelバックエンド版の環境構築を依頼するための作業指示書である。仕様書v1.4では、Next.js/React、Laravel/Rails/NestJS、PostgreSQL、Redis、S3互換ストレージ等が推奨構成として整理されている。本指示書では、そのうちバックエンドをLaravel
APIに固定する。

-   Codexの担当範囲は、ローカル開発環境・基盤コード・接続確認・README整備までとする。

-   抽選、ポイント台帳、決済Webhook、管理画面などの業務実装は、別紙「Codex実装指示書」に従う。

-   ただし、後続実装のために必要な空ディレクトリ、インターフェース、ヘルスチェック、テスト雛形は作成してよい。

# 2. 固定する技術スタック

  ---------------------------------------------------------------------------------------------------------------------------------------
  **領域**                **採用技術**                      **Codexへの指定**
  ----------------------- --------------------------------- -----------------------------------------------------------------------------
  フロントエンド          Next.js / TypeScript / App Router frontend ディレクトリに作成。APIはLaravelを呼び出す。

  バックエンド            Laravel API                       backend
                                                            ディレクトリに作成。抽選・ポイント・在庫・確率バージョンはLaravel側の責務。

  DB                      PostgreSQL                        ローカルDockerで起動。SQLite/MySQLにしない。

  キャッシュ/キュー       Redis                             Laravel queue/cache/sessionの接続先。後続でqueue:workを利用。

  ストレージ              MinIO                             S3互換ローカルストレージ。景品画像・CSV取込検証用。

  メール                  Mailpit                           会員登録、通知、保管期限通知のローカル検証用。

  認証                    Laravel Sanctum想定               SPA認証またはBearer Token API認証を後続実装可能な状態にする。

  決済                    Stripe/GMO/KOMOJU等のモック前提   本タスクでは実決済連携しない。Webhook受信用の空ルートまで可。

  テスト                  PHPUnitまたはPest / Vitest任意    Laravel側のFeature TestとNext側のlint/typecheckを通す。
  ---------------------------------------------------------------------------------------------------------------------------------------

バージョン方針:
Laravelは実行時点の公式最新に合わせる。2026年6月時点ではLaravel
13.xを第一候補とする。Next.jsはNode.js
20.9以上を要求するため、ローカルDockerではNode 22 LTS以上を推奨する。

# 3. 作成するリポジトリ構成

oripa/\
├─ frontend/ \# Next.js App Router\
├─ backend/ \# Laravel API\
├─ infra/\
│ ├─ docker/\
│ │ ├─ backend/Dockerfile\
│ │ └─ frontend/Dockerfile\
│ └─ scripts/\
├─ docs/\
│ ├─ environment.md\
│ └─ implementation-notes.md\
├─ docker-compose.yml\
├─ .env.example \# ルート共通サンプル。実値はコミットしない\
├─ Makefile\
├─ README.md\
└─ AGENTS.md \# Codex/AIエージェント向け開発規約

-   既存リポジトリがある場合は、上記構成に合わせる前に破壊的変更を避け、差分を確認する。

-   vendor、node_modules、.env、storage内の生成物、MinIO/PostgreSQLのvolumeはコミットしない。

-   backendとfrontendを別リポジトリに分ける場合でも、ローカルでは1つのdocker-compose.ymlで起動できる状態にする。

# 4. Codex作業範囲

  -----------------------------------------------------------------------------------------------------------------------------------------------------
  **区分**                **Codexが行うこと**                                                        **行わないこと**
  ----------------------- -------------------------------------------------------------------------- --------------------------------------------------
  Laravel                 プロジェクト生成、.env.example、PostgreSQL/Redis接続、health               抽選ロジック本実装、決済本実装、確率計算の本実装
                          API、テスト雛形、ディレクトリ構成作成                                      

  Next.js                 プロジェクト生成、環境変数、APIクライアント雛形、トップ/health簡易ページ   ガチャUIの本実装、演出、管理画面の本実装

  Docker                  全サービスをdocker composeで起動できるようにする                           本番用ECS/EKS等のIaC作成

  DB                      Laravel migrationsが動く状態にする。空の基礎migration作成は可              仕様書全テーブルの完全実装は別タスク

  README                  初回セットアップ、起動、停止、テスト、トラブルシュートを明記               運用マニュアルの完全版作成
  -----------------------------------------------------------------------------------------------------------------------------------------------------

# 5. Docker Compose構成

Codexは、最低限以下のサービスを docker compose
で起動できるようにする。Laravel
Sailのみへ依存せず、Next.js、MinIO、Mailpitを含むルートcomposeとして整備する。

  --------------------------------------------------------------------------------------------------------------
  **Service**       **Port**          **用途**              **必須設定**
  ----------------- ----------------- --------------------- ----------------------------------------------------
  frontend          3000              Next.js dev server    NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api

  backend           8000              Laravel API dev       APP_URL=http://localhost:8000 / DB_CONNECTION=pgsql
                                      server                

  queue             \-                Laravel queue worker  QUEUE_CONNECTION=redis

  scheduler         \-                Laravel scheduler     schedule:run を1分間隔で実行できる構成
                                      runner                

  postgres          5432              PostgreSQL            database=oripa / user=oripa /
                                                            password=oripa_password

  redis             6379              cache/session/queue   永続化volumeを設定

  minio             9000/9001         S3互換ストレージ      bucket=oripa-local

  mailpit           1025/8025         メール送信検証        SMTP=mailpit:1025 / UI=http://localhost:8025
  --------------------------------------------------------------------------------------------------------------

期待する起動確認

\# docker compose の検証コマンド例\
make setup\
make up\
make migrate\
make test\
curl http://localhost:8000/api/health\
curl http://localhost:3000/api/health \# Next側にRoute
Handlerを作る場合\
open http://localhost:3000\
open http://localhost:8025\
open http://localhost:9001

# 6. Laravel API環境の作成指示

-   backend ディレクトリにLaravelプロジェクトを作成する。

-   DBはPostgreSQLに固定し、SQLite/MySQLを使わない。

-   Redisをcache、queue、sessionに利用できるように設定する。sessionは開発初期ではdatabaseでもよいが、後続実装ではRedisへ寄せる。

-   API用に /api/health を作成し、app/db/redis の状態を返す。

-   Laravel Sanctumを導入可能な状態にする。導入する場合は SPA ドメイン
    localhost:3000 を設定する。

-   OpenAPIは必須ではないが、routes/api.phpとテストを読みやすく整理する。

Laravel初期化例

\# Laravel作成コマンド例。Codex実行環境に合わせて調整可\
composer create-project laravel/laravel backend\
cd backend\
composer require laravel/sanctum predis/predis
league/flysystem-aws-s3-v3\
php artisan key:generate\
php artisan migrate

Laravel .env.example

\# backend/.env.example に含める主要項目\
APP_NAME=Oripa\
APP_ENV=local\
APP_DEBUG=true\
APP_URL=http://localhost:8000\
FRONTEND_URL=http://localhost:3000\
\
DB_CONNECTION=pgsql\
DB_HOST=postgres\
DB_PORT=5432\
DB_DATABASE=oripa\
DB_USERNAME=oripa\
DB_PASSWORD=oripa_password\
\
CACHE_STORE=redis\
QUEUE_CONNECTION=redis\
SESSION_DRIVER=redis\
REDIS_HOST=redis\
REDIS_PORT=6379\
\
MAIL_MAILER=smtp\
MAIL_HOST=mailpit\
MAIL_PORT=1025\
MAIL_FROM_ADDRESS=no-reply\@example.local\
MAIL_FROM_NAME=\"Oripa Local\"\
\
FILESYSTEM_DISK=s3\
AWS_ACCESS_KEY_ID=minio\
AWS_SECRET_ACCESS_KEY=minio_password\
AWS_DEFAULT_REGION=ap-northeast-1\
AWS_BUCKET=oripa-local\
AWS_ENDPOINT=http://minio:9000\
AWS_USE_PATH_STYLE_ENDPOINT=true\
\
SANCTUM_STATEFUL_DOMAINS=localhost:3000,frontend:3000\
SESSION_DOMAIN=localhost\
CORS_ALLOWED_ORIGINS=http://localhost:3000

# 7. Laravelディレクトリ規約

後続実装でCodexが迷わないよう、Laravel側には以下のドメイン別ディレクトリを作成する。中身は空クラスまたはREADMEでよい。

backend/app/\
├─ Domain/\
│ ├─ Gacha/\
│ │ ├─ Services/\
│ │ ├─ Actions/\
│ │ ├─ DTOs/\
│ │ └─ README.md\
│ ├─ Point/\
│ ├─ Probability/\
│ ├─ Payment/\
│ ├─ Shipping/\
│ └─ Admin/\
├─ Http/\
│ ├─ Controllers/Api/V1/\
│ ├─ Controllers/Admin/Api/V1/\
│ ├─ Middleware/\
│ └─ Requests/\
├─ Jobs/\
├─ Console/Commands/\
└─ Support/

-   抽選系は app/Domain/Gacha または app/Domain/Probability に置く。

-   ポイント台帳は app/Domain/Point に置く。

-   決済Webhookは app/Domain/Payment に置く。

-   LaravelのService層やAction層でトランザクション境界を明確にする。

# 8. Next.js環境の作成指示

-   frontend ディレクトリに Next.js / TypeScript / App Router / Tailwind
    / ESLint 構成で作成する。

-   APIエンドポイントはLaravelを呼び出す。抽選・ポイント・管理APIをNext.js
    Route Handlerで実装しない。

-   src/lib/api.ts に APIクライアント雛形を置く。

-   src/app/page.tsx にはLaravel
    APIの疎通状態を確認できる簡易表示を作る。

-   NEXT_PUBLIC_API_BASE_URL を .env.example に定義する。

Next.js初期化例

\# Next.js作成コマンド例\
pnpm create next-app\@latest frontend \--ts \--tailwind \--eslint \--app
\--src-dir \--import-alias \"@/\*\"\
cd frontend\
pnpm install

Next.js .env.example

\# frontend/.env.example\
NEXT_PUBLIC_APP_NAME=Oripa\
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api\
NEXT_PUBLIC_FRONTEND_URL=http://localhost:3000

# 9. ポイント仕様を壊さない環境前提

今回の運用方針として、有償ポイントは有効期限なし、無償ポイントのみ有効期限ありとする。環境構築段階では本実装をしないが、DB設計・設定値・テスト方針をこの前提と矛盾しないようにする。

  -------------------------------------------------------------------------------------------------------------------------------------------------
  **項目**                            **環境構築時の扱い**
  ----------------------------------- -------------------------------------------------------------------------------------------------------------
  有償ポイント                        expire_at = null を許容する設計にする。日次未使用残高集計を後続実装できるようにする。

  無償ポイント                        expire_at を持つロットを前提にする。期限切れバッチを後続実装できるようにする。

  消費順                              無償ポイント優先、無償内では期限が近い順、有償内ではFIFOを後続実装できるようにする。

  最低保証ポイント                    無償ポイントとして扱う前提のコメントをREADME/AGENTS.mdに記載する。

  資金決済法対応                      有償ポイント未使用残高の日次・基準日集計を後続で実装できるよう、PostgreSQLとscheduler/queueを必ず用意する。
  -------------------------------------------------------------------------------------------------------------------------------------------------

# 10. Laravelで必ず守る禁止事項

-   抽選処理をNext.jsやフロントエンドで実装しない。

-   DBをSQLiteやMySQLで初期化しない。仕様書v1.4の前提に合わせてPostgreSQLを使う。

-   確率をfloat/doubleで保存する前提のコードを書かない。将来の確率はppm整数で扱う。

-   gacha_prizesテーブルにprobabilityカラムを作る前提にしない。確率は確率バージョン配下で管理する。

-   公開済み確率バージョンを上書き編集する設計を作らない。

-   draw_sequence_numberの欠番・重複を許容する設計にしない。

-   .env、秘密鍵、決済APIキー、MinIO実パスワードをコミットしない。

-   本タスク中に本番決済APIへ接続しない。

# 11. Makefile/コマンド指示

Codexは、READMEに加えてMakefileを用意し、開発者が同じコマンドで起動・停止・検証できるようにする。

Makefile例

setup:\
cp .env.example .env \|\| true\
cp backend/.env.example backend/.env \|\| true\
cp frontend/.env.example frontend/.env.local \|\| true\
docker compose build\
docker compose run \--rm backend composer install\
docker compose run \--rm frontend pnpm install\
\
up:\
docker compose up -d\
\
down:\
docker compose down\
\
logs:\
docker compose logs -f \--tail=200\
\
migrate:\
docker compose exec backend php artisan migrate\
\
api-test:\
docker compose exec backend php artisan test\
\
web-lint:\
docker compose exec frontend pnpm lint\
\
health:\
curl -s http://localhost:8000/api/health\
curl -s http://localhost:3000 \|\| true

# 12. ヘルスチェック仕様

  -------------------------------------------------------------------------------------------
  **エンドポイント**    **担当**          **返却内容**      **合格条件**
  --------------------- ----------------- ----------------- ---------------------------------
  GET /api/health       Laravel           app, db, redis,   200
                                          storage,          OK。DB/Redis疎通が確認できる。
                                          timestamp         

  GET /                 Next.js           画面表示          API接続状況が簡易表示される。

  GET                   Next.js           Nextアプリ状態    200
  /api/health（任意）                                       OK。Laravelを呼び出してもよい。
  -------------------------------------------------------------------------------------------

// Laravel health response example\
{\
\"app\": \"ok\",\
\"db\": \"ok\",\
\"redis\": \"ok\",\
\"storage\": \"ok\",\
\"timestamp\": \"2026-06-10T00:00:00+09:00\"\
}

# 13. 初回セットアップ手順

-   リポジトリ直下で README.md と AGENTS.md を確認する。

-   docker compose build を実行する。

-   backend/.env と frontend/.env.local を .env.example から作成する。

-   docker compose up -d を実行する。

-   Laravelのapp key生成、migrate、testを実行する。

-   Next.jsのlint/typecheckを実行する。

-   http://localhost:8000/api/health、http://localhost:3000、Mailpit、MinIOの疎通を確認する。

\# CodexがREADMEに記載する起動例\
cp backend/.env.example backend/.env\
cp frontend/.env.example frontend/.env.local\
docker compose up -d \--build\
docker compose exec backend php artisan key:generate\
docker compose exec backend php artisan migrate\
docker compose exec backend php artisan test\
docker compose exec frontend pnpm lint\
curl http://localhost:8000/api/health

# 14. 受け入れ基準

  -------------------------------------------------------------------------------------------------------------------
  **No**                  **確認項目**            **合格条件**
  ----------------------- ----------------------- -------------------------------------------------------------------
  1                       Docker起動              docker compose up -d \--build で全サービスが起動する。

  2                       Laravel API             http://localhost:8000/api/health が200 OKを返す。

  3                       PostgreSQL              Laravelからpgsql接続でmigrationが実行できる。

  4                       Redis                   LaravelからRedisへ接続でき、queue/cacheの設定が有効。

  5                       Next.js                 http://localhost:3000 が表示され、API Base
                                                  URLが環境変数で管理されている。

  6                       MinIO                   ローカルS3互換ストレージが起動し、bucket作成手順がREADMEにある。

  7                       Mailpit                 http://localhost:8025 でメールUIが開く。

  8                       テスト                  Laravel test、Next lint/typecheckがREADME通りに実行できる。

  9                       README                  初回セットアップ、起動、停止、トラブルシュートが記載されている。

  10                      禁止事項                SQLite/MySQL化、フロント抽選、float確率保存、.envコミットがない。
  -------------------------------------------------------------------------------------------------------------------

# 15. AGENTS.mdに記載する開発規約

\# AGENTS.md に入れる要点\
- Backend is Laravel API. Do not implement core business APIs in Next.js
Route Handlers.\
- All draw, point ledger, inventory, probability versioning, and payment
webhook logic must live in Laravel.\
- PostgreSQL is the only local DB for this project. Do not switch to
SQLite/MySQL.\
- Probability must be stored as integer ppm. Do not use float for
persisted probability.\
- gacha_prizes must not contain a probability column.\
- draw_sequence_number must be generated under DB lock and must be
unique per gacha.\
- Paid points have no expiration. Free points have expiration. Minimum
guarantee points are free points.\
- Do not commit .env, secrets, vendor, node_modules, or local volume
data.\
- Before finishing a task, run backend tests and frontend lint/typecheck
when touched.

# 16. 本番環境は本指示書の対象外

本指示書はローカル開発環境構築を対象とする。本番環境では、HTTPS、WAF、CDN、DBマネージドサービス、S3本番バケット、Secrets
Manager、監視、バックアップ、Blue/Greenデプロイ、決済Webhookの署名検証、キュー監視、スケジューラ冗長化を別途設計する。

# 17. 参考資料

  --------------------------------------------------------------------------------------------------
  **資料**                            **用途**
  ----------------------------------- --------------------------------------------------------------
  オリパサイト構築 仕様書 v1.4        システム仕様、MVP範囲、DB/API、抽選・ポイント制約の元資料。

  Laravel 公式 Installation           Laravelプロジェクト生成、PHP/Composer/Node等の公式手順確認。

  Laravel 公式 Sail                   Docker開発環境、Sail/Composeの考え方確認。

  Next.js 公式 Installation           Next.js作成、Node.js要件、App Router構成確認。
  --------------------------------------------------------------------------------------------------

# 18. Codexへの最終指示文

以下を実行してください。\
\
1. 仕様書v1.4を読み、バックエンドをLaravel
APIに固定した環境構成へ変更してください。\
2.
frontend=Next.js、backend=Laravel、PostgreSQL、Redis、MinIO、Mailpitをdocker
composeで起動できるようにしてください。\
3. Laravel側に /api/health
を作成し、DB/Redisの疎通を確認できるようにしてください。\
4. Next.js側はLaravel
APIを呼び出す前提で、NEXT_PUBLIC_API_BASE_URLを環境変数化してください。\
5. README.md、AGENTS.md、.env.example、Makefileを整備してください。\
6. 抽選・ポイント・決済・管理画面の本実装には入らないでください。\
7. ただし、後続実装で迷わないように
app/Domain/Gacha、Point、Probability、Payment、Shipping
等のディレクトリを用意してください。\
8. docker compose up、Laravel test、Next
lint/typecheck、/api/healthの確認結果を作業完了報告に含めてください。

以上
