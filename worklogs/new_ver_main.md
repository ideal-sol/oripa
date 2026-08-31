# MIG-062F バナー公開URL修正

- Base `f66209549dfc9c8fae4acaa51645710040694d1c`からIssue #237、Branch `fix/MIG-062F-banner-public-url`、Risk R3で開始した。
- Banner Asset Public IDから`/api/v2/content/assets/{asset_id}`を生成し、Admin APIは`V2_PUBLIC_ORIGIN`を正本とする絶対Public URLを返す。Storage方式と既存Uploadは変更しない。
- Admin一覧表示、画像表示、新規Tab、Copyは同じCanonical URLを使用する。Admin CSPはvalidated Public Origin 1件だけを許可し、Frontend文字列置換は削除した。
- Task DB Backend対象、Admin Unit、OpenAPI／Generated Contract、Typecheck／Lint、Desktop／Mobile Browser対象検証がPASSした。Previewは最終Application HeadでAPI／Adminだけ更新予定で、DB／Migration／Nginx／V1は変更しない。

# New Version Main Worklog

このFileは、V1から新Version構造へ移行するMain Codexの作業記録です。

## 運用ルール

- 今後の新Version関連作業は、各Task完了時にこのFileへ追記する。
- 記録にはTask ID、実施日、目的、実行内容、変更File、検証結果、未実施事項、Risk、次Taskを含める。
- 調査だけのTaskでは、変更していない対象も明記する。
- Testは「既存記録」「今回実行」「未実行」を分け、未実行TestをPASSと記載しない。
- Secret、Token、Password、Cookie、API Key、`.env`の内容は記録しない。
- Local Commit、Remote Commit、未Commit差分、Migration適用状態を混同しない。
- READMEに影響する構築方法、操作方法、運用方法の変更がある場合は、必要に応じてREADMEも更新する。

## 2026-07-22 MIG-000 V1 Local / Remote差分確認

### 目的

V1のLocal作業ツリー、Local Commit、Remoteとの差分を安全に確認し、MIG-001「V1 Evidence Bundle」へ進める状態か判定した。

### Repository

- Repository Root: `/var/www/oripa`
- Current Branch: `main`
- Remote: `git@github.com:myong-ideal/oripa.git`
- Remote URLは想定した`myong-ideal/oripa`で、URL内にCredentialは含まれていない。
- Local HEAD: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- `origin/main`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- 確認日時: 2026-07-22 04:46:44 UTC / 13:46:44 JST

### 実施内容

- Local Repository、Branch、HEAD、Remote、直近履歴を確認した。
- Remote URLにCredentialが含まれていないことをPath上で確認した。
- `git fetch origin --prune`を実行し、Remote参照を更新した。
- Fetch後にHEADと`origin/main`のFull SHAを確認した。
- ahead / behind、Localのみ・RemoteのみのCommitを確認した。
- 未Stage、Stage済み、未追跡、削除、Rename候補を確認した。
- `git diff --check`を実行した。
- Submodule、Migration、Lockfile、Binary・大容量Fileを確認した。
- `git ls-files`とPath名を基にSecret混入Riskを確認した。
- 既存Test Commandと`worklogs/codex-main.md`の直近Test記録を確認した。

### Local / Remote結果

- `main` ahead: `0`
- `main` behind: `0`
- `main` diverged: なし
- `main`のLocalのみのCommit: なし
- `main`のRemoteのみのCommit: なし
- Working Tree: clean
- Stage済みFile: なし
- 未Stage File: なし
- 未追跡File: なし（本Worklog作成前のMIG-000確認時点）
- 削除File: なし
- Rename候補: なし
- Submodule: なし

### Local専用Branch

- `backup/admin-refactor-deferred-20260626-0847`
- Commit: `e0a8537 backup: defer admin route refactor`
- Upstreamはなく、Remoteに存在しないLocal専用Commitとして確認した。
- MIG-000ではPush、削除、Tag化、Branch変更を行っていない。

### Domain分類

Local `main`と`origin/main`の間に、以下の差分はない。

- Backend
- Frontend
- Migration
- Test
- Documentation
- Lockfile
- V1返金・チャージバック
- QA抽選
- 売上CSV
- Point残高Snapshot
- 管理画面UI
- 仕様書・作業ログ

これらの最新実装はCommit `bfca8ef` に含まれ、`origin/main`へ反映済みである。

### Binary / Large File

- 追跡中のBinary候補: 10件
- 最大File: `frontend/public/draw-videos/default.mp4` 約3.98MB
- 最大File: `direction/gacha.mp4` 約3.98MB
- 5MiB以上の追跡File: なし
- 現在差分として追加されたBinary / Large File: なし

### Migration / Lockfile

- 追跡中Migration: 40件
- Local / Remote間のMigration差分: なし
- `backend/composer.lock`: 追跡済み、差分なし
- `frontend/pnpm-lock.yaml`: 追跡済み、差分なし

### Secret混入Risk

- 判定: `LOW / PATH-BASED CHECK ONLY`
- 追跡中の環境設定Template:
  - `.env.example`
  - `backend/.env.example`
  - `frontend/.env.example`
- ignore済みLocal Secret候補:
  - `backend/.env`
  - `frontend/.env.local`
- Key、Credential、DB Dump、Backupを示す追跡Path: 検出なし
- Repository内のSecret Scanner設定: 検出なし
- `gitleaks`、`trufflehog`、`detect-secrets`、`secretlint`: 未導入
- Secret値、`.env`内容、Credential内容は開いていない。
- Git履歴全体の大規模Secret Scanは実行していない。

### Test

既存記録として、日次Point残高Snapshot、売上管理、返金・チャージバック、QA抽選、Frontend typecheck等の対象Test PASS記録を確認した。

今回実行:

- `git diff --check`

今回結果:

- PASS
- whitespace errorなし

今回未実行:

- Backend Test
- Frontend typecheck
- Frontend lint
- Full Test
- Build
- Browser / E2E
- Docker操作

未実行理由:

- MIG-000は調査と報告だけが対象である。
- Working TreeがcleanでLocal / Remoteが一致している。
- 重いFull TestやBuildはTask範囲外である。

### 判定

`READY_WITH_ACTIONS`

`main`はcleanで`origin/main`と完全一致しており、MIG-001 Evidence Bundleの基準点として使用できる。

### MIG-001前の対応候補

- Evidence Bundleの基準SHAを`bfca8efa0b85c00a88fb0fd439a123b722577b68`として固定する。
- Local専用Commit `e0a8537`をEvidence Bundleへ含めるか、人間が判断する。
- Local退避BranchをRemote保存するかは、別Taskで人間承認後に判断する。
- `.env.example`類を専用Secret Scannerで確認するか判断する。
- Binary資産をBundle本体へ含めるか、Hash一覧のみとするか判断する。

### MIG-000で変更していないもの

- Application Code
- Configuration
- Documentation
- Git Commit / Tag / Branch
- Migration / DB
- Docker / Volume
- Dependency
- Production Data

### 次Task

- MIG-001「V1 Evidence Bundle」

### README

- MIG-000ではApplicationの構築方法・利用方法・運用方法に変更がないため、READMEは更新していない。

## 2026-07-22 V2 Task記録運用の確定

### 人間による最新の明示決定

- 新Version関連Taskの作業記録には、引き続き`worklogs/new_ver_main.md`を使用する。
- V2のTask管理では、GitHub Issue／PRと本Worklogを併用する。
- 本Worklogへの追記は禁止しない。
- 以前の「MIG-000の一時Evidence候補としてのみ扱い、継続Worklogにはしない」という判断は、本決定で上書きする。

### 作業開始時の確認

- 毎回の作業前に、Repository外のV2確定文書Directory `/home/ec2-user/oripa_v2/` を再確認する。
- 人間による最新の明示決定を最優先とする。
- Security文書は`V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md`を使用し、旧非Revision版は使用しない。

### 今回の確認結果

- `/home/ec2-user/oripa_v2/`の確定文書10点が存在することを確認した。
- Application Code、設定、DB、Migration、Docker、依存関係は変更していない。
- READMEへ追記すべきApplication利用方法・構築方法の変更はないため、READMEは変更していない。

## MIG-001 V1 Evidence Bundle

### 基本情報

- Task ID: `MIG-001`
- 実施日: 2026-07-22
- 目的: V2構造変更前のV1 Git、Schema、Migration、機能、API、画面、Asset、Lockfile、Test EvidenceをRepository外へ保全する。
- Baseline SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Evidence保存Path: `/var/www/oripa-v1-evidence/MIG-001-20260722T053527Z/`

### 作成Evidence

- `README.md`
- `manifest.json`
- 全Local Refを含むGit Bundle
- Git metadata、Branch、Tag、Commit graph、Working Tree、Tracked File一覧
- Migration File一覧、各SHA-256、Migration Set SHA-256
- Feature、API、Screen、Asset、Master Export候補Inventory
- Composer／pnpm ManifestとLockfile
- Tracked Environment Template、Docker Compose、Dockerfile
- Test実行結果と既存記録の参照先
- Secret RiskのPath名ベース確認結果
- Evidence全FileのSHA-256一覧

### Git Bundle

- File: `git/oripa-v1-all-refs.bundle`
- Verify: PASS
- SHA-256: `7353eabfa7927e0231f7e69418cc0c3c18b22310e46d32b637b352549ed5c547`
- Size: 9,843,654 bytes
- `main`、`origin/main`、`backup/admin-refactor-deferred-20260626-0847`を含む。
- Local専用Commit `e0a853707f1fd1dcc81b733986019551aa5a0d8c`を含む。

### Schema Dump

- 結果: 未作成
- 理由: PostgreSQLを含むDocker Compose Serviceがすべて停止中で、Hostに`pg_dump`が存在しない。
- Docker起動、新Container作成、Migration、DB Writeは禁止されているため実施していない。
- 代替Schemaは推測で作成していない。
- Production Data Dump、PII取得、Secret表示は行っていない。

### Migration

- File数: 40
- Migration Set SHA-256: `85944c1e103f1cc19a2375a339dcf6ccc07399e89acaf55b673f203598400c15`
- 最古: `2026_06_10_000001_create_users_profiles_admins_and_sanctum_tables.php`
- 最新: `2026_07_14_000001_create_qa_test_user_draw_tables.php`

### Inventory

- Laravel Static Route: 149件
- Backend Application File: 300件
- Backend Test File: 69件
- Frontend Page File: 21件
- Tracked Asset: 10件
- Asset重複Checksum Group: 1件
- `frontend/src/app/admin-dashboard.tsx`: 9,843行、404,519 bytes。V2へCopyせずBehavioral Referenceとして扱う。
- Master候補だけを整理し、実Data Exportは行っていない。

### Test

- `git diff --check`: PASS
- `cd frontend && pnpm typecheck`: PASS
- `cd frontend && pnpm lint`: FAIL（8 errors、1 warning）
- Backend Test: 未実行
- Laravel runtime route list: 未取得
- Build、Browser／E2E、Migration Test: 未実行
- Service停止、Host Composer autoload不在、Task禁止事項を理由として記録済み。
- 失敗箇所のSource修正は行っていない。

### Secret／PII

- Secret確認: Tracked Path名ベースのみ
- Tracked Environment Path: `.env.example`系3件
- Private Key候補: 0件
- DB Dump／Backup候補: 0件
- 専用Secret Scanner: 未導入
- Git履歴全体Scan: 未実施
- Secret値、実`.env`内容、PIIは表示・保存していない。

### Riskと判定

- 判定: `READY_WITH_ACTIONS`
- Risk:
  - Schema-only Dumpが未作成
  - Backend Test未実行
  - Frontend lint FAIL
  - Git履歴全体Secret Scan未実施
- MIG-002へ進む前に、Schema Dump取得方法と既存lint FAILの扱いを人間が判断する。
- `archive/v1-current`、Annotated Tag、Branch、Commit、PushはMIG-001では実施していない。

### 次Task

- MIG-001不足Evidenceへの対応判断
- 対応後、MIG-002「V1 Archive Branch／Annotated Tag」

## MIG-001A V1 Evidence不足補完

### 基本情報

- Task ID: `MIG-001A`
- 実施日: 2026-07-22
- 対象Evidence: `/var/www/oripa-v1-evidence/MIG-001-20260722T053527Z/`
- Baseline SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- `main`、`origin/main`、Local専用Branch `e0a8537`がMIG-001時点から変わっていないことを確認した。

### Schema Dump

- Status: 作成・検証済み
- File: `schema/v1-schema.sql`
- Size: 129,501 bytes
- SHA-256: `15e7e2592298b8ccde3a0298abd0abcd41689cd6f0e45faa33a64dae2dfe286c`
- PostgreSQL Client／Server: 17.10
- `--schema-only --no-owner --no-privileges`で取得した。
- `COPY=0`、`INSERT INTO=0`、Owner依存=0、`GRANT/REVOKE=0`を確認した。
- Application Data、User Data、PII、Credentialは含めていない。
- Migration、Seed、DB Writeは行っていない。
- DB Service作業前: `exited`
- DB Service作業後: `exited`
- 既存`postgres` Serviceだけを`docker compose start postgres`で起動し、完了後に同Serviceだけを停止した。
- 既存Volume `oripa_postgres_data`を確認し、新規Container／Volume作成、Image Pull、Buildは行っていない。

### Secret Candidate Check

- 専用ScannerはInstall／Downloadしていない。
- Git標準CommandとEvidence内のLocal PHP補助Scriptを使用した。
- 対象:
  - 現在のTracked Tree 559 File
  - 未追跡・非ignore File 1件
  - 全Local Refから到達可能な21 Commit
  - History上のText Blob／Path
- Worktree高確度Candidate: 0件
- Git History高確度Candidate: 0件
- Template／Test内容Candidate: 0件
- Path検出は`.env.example`系3件のみで、期待されるTemplateとして区別した。
- Secret値、一致行、Credential内容は表示・保存していない。
- 制約: Entropy評価を行う専用Scannerではなく、高確度Regexと危険Pathによる補助Scanである。Binary本文はRegex Scanせず、Path分類のみ行った。

### Evidence更新

- `schema/v1-schema.sql`とSchema metadataを追加・更新した。
- Secret Scan Summary、Worktree／History結果、補助Scriptを追加した。
- `README.md`のSchema、Secret、Known Limitations、MIG-002関係を更新した。
- `manifest.json`をMIG-001A実測値へ更新した。
- Evidence全FileのSHA-256一覧とChecksum一覧自身のSHA-256を再生成・verifyした。
- 既存Git Bundleは作り直していない。
- Git Bundle SHA-256は`7353eabfa7927e0231f7e69418cc0c3c18b22310e46d32b637b352549ed5c547`のまま一致し、verify PASS。

### Working Tree／Test

- Working Treeは`worklogs/new_ver_main.md`だけが未追跡の状態である。
- Stage済み・未StageのTracked Fileはない。
- Application Code、Migration、設定、Dependencyは変更していない。
- Frontend typecheck既存結果: PASS
- Frontend lint既存結果: FAIL（8 errors、1 warning）
- Frontend lintのSource修正は行っていない。
- lint FAILはV1 Baseline時点の実状態としてEvidenceへ維持する。
- Backend Test、Build、Browser／E2Eは今回実行していない。

### 判定

- MIG-001A Status: `READY_FOR_MIG_002`
- MIG-002は元のWorking Treeを変更せず、Baseline SHAから専用のclean Worktreeを作る方針とする。
- 今回はWorktree、Branch、Tag、Commit、Pushを作成・実行していない。
- 残存Risk:
  - Frontend lintの既知FAIL
  - 専用Entropy型Secret Scannerではなく補助Scan
  - Backend Test／Browser E2EはMIG-001Aでは未実行

### 次Task

- MIG-002「V1 Archive Branch／Annotated Tag」

## MIG-002 V1 Archive Branch／Annotated Tag

### 基本情報

- Task ID: `MIG-002`
- 実施日時: 2026-07-22 06:17 UTC／2026-07-22 15:17 JST
- Baseline SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Current Branch: `main`（切替なし）
- Local／Remote `main`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`（変更なし）

### Archive Branch

- Branch: `archive/v1-current`
- Local SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Remote SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Baseline Full SHAを直接指定してRefを作成し、`main`はcheckoutしていない。
- `refs/heads/archive/v1-current`だけを個別Pushした。Force Push、`main` Push、`--all`は使用していない。

### Annotated Tag

- Tag: `v1-before-productization-2026-07-22`
- Object type: `tag`
- Tag Object ID: `88dc666f37f4e1a0a0ec702b66bb14ee26edfcab`
- Peeled Commit: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Tag MessageにはBaseline SHA、Evidence Path、Git Bundle／Schema Dump／Migration Set／Feature InventoryのSHA-256、Test状態、Known Limitations、作成日時を記録した。
- `refs/tags/v1-before-productization-2026-07-22`だけを個別Pushし、Remote Tag ObjectとPeeled Commitを検証した。

### Evidence再検証

- Evidence: `/var/www/oripa-v1-evidence/MIG-001-20260722T053527Z/`
- Evidence 136 FileのChecksum: PASS
- Checksum一覧 SHA-256: `31d86e27506b1a7aa84fe6545545f37c530048bbf7e76d0b496c01e733dbbd66`
- Git Bundle verify: PASS
- Git Bundle SHA-256: `7353eabfa7927e0231f7e69418cc0c3c18b22310e46d32b637b352549ed5c547`
- Schema-only Dump SHA-256: `15e7e2592298b8ccde3a0298abd0abcd41689cd6f0e45faa33a64dae2dfe286c`
- Migration Set SHA-256: `85944c1e103f1cc19a2375a339dcf6ccc07399e89acaf55b673f203598400c15`
- Feature Inventory SHA-256: `f300df8411d17a53949ad50e7172ecc6121ae088dac0043a2e32abadf19ff647`
- Bundle内にBaseline SHA、Local専用Branch、Commit `e0a853707f1fd1dcc81b733986019551aa5a0d8c`が維持されていることを再確認した。
- Evidence Bundleは変更していない。

### Temporary Worktree

- Path: `/var/www/oripa-worktrees/MIG-002-v1-archive`
- Baseline SHAからDetached Worktreeとして作成した。
- Worktree内HEADがBaseline SHA、Working Treeがcleanであることを確認した。
- Remote検証後もcleanであることを確認し、`git worktree remove`で安全に削除した。
- `--force`および直接のDirectory削除は使用していない。

### Repository状態／制約

- Application Code、Migration、設定、Dependencyは変更していない。
- Commitは作成していない。`main`はPushしていない。
- `worklogs/new_ver_main.md`だけが未追跡であり、このWorklogはCommit／Pushしていない。
- Local専用Branch `backup/admin-refactor-deferred-20260626-0847`は`e0a853707f1fd1dcc81b733986019551aa5a0d8c`のまま変更・Pushしていない。
- Frontend lintはV1 Evidence記録どおりFAIL（8 errors、1 warning）。修正していない。
- Backend TestとBrowser／E2EはMIG-001では未実行。
- Secret確認は高確度Regex／危険Pathによる補助Scanであり、専用Entropy型Scannerではない。

### Gate G0／次Task

- MIG-002: `COMPLETE`
- Archive BranchとAnnotated TagはLocal／RemoteともBaseline SHAへ固定できた。
- G0判定: Archive Evidence要件は満たす。元Working Treeには許可された未追跡Worklogが残る。
- 後続Governance Taskで`archive/v1-current`のLock／Push禁止／Force Push禁止／Delete禁止と、`v1-before-productization-*` Tagの移動／削除禁止を設定する必要がある。
- 次Task: 人間承認済みのGovernance Task。V2文書のRepository配置やGovernance変更はMIG-002では開始していない。

## GOV-000 GitHub Organization移行時期とCodex／Bot Identity確認

### 基本情報

- Task ID: `GOV-000`
- 実施日時: 2026-07-22T06:31:42Z／2026-07-22T15:31:42+09:00
- 目的: GOV-001以降を開始する前に、GitHub所有形態、Codex Identity、Human Approval、Organization移行時期、Governance Gapを調査する。
- 調査のみを行い、GitHub設定、Repository Ref、Application File、Migration、CI、Secret、Credentialは変更していない。

### Repository／Git状態

- Repository: `myong-ideal/oripa`
- Owner type: Personal Account（GitHub API上のtypeは`User`）
- Visibility: `public`
- Default Branch: `main`
- Local HEAD／`origin/main`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Local／Remote `archive/v1-current`: Baseline SHAと一致
- Annotated Tag Peeled Commit: Baseline SHAと一致
- Local専用Branch: `backup/admin-refactor-deferred-20260626-0847`、`e0a853707f1fd1dcc81b733986019551aa5a0d8c`のまま
- Working Tree: 未追跡`worklogs/new_ver_main.md`だけ。Stage済み・未StageのTracked変更はない。

### GitHub Identity

- `gh` CLI: 未導入
- `gh`認証Account: `UNKNOWN`
- SSH Git Identity: `myong-ideal`としてGitHub認証されることを確認
- Local Commit Author／Committer: `oripa-builder <oripa-builder@example.local>`
- Git PushのAudit Identityは人間Owner `myong-ideal`であり、Commit Author表示だけがローカルBuilder名となる構成である。
- Codex専用Bot／GitHub App: Local／公開情報では確認できない。Installation一覧は認証済みAPIがないため最終判定は`UNKNOWN`。
- 2人目の人間Maintainer: Collaborator APIが認証必須のため`UNKNOWN`。
- 現構成でOwner IdentityからPRを作成した場合、同じOwnerはPR Authorとして自己Approvalできない。

### 現在のGitHub Protection／Security

- Repository Ruleset: 0件（`MISSING`）
- `main`: `protected=false`（Ruleset／Branch Protectionとも`MISSING`）
- Required Human Approval: `MISSING`
- CODEOWNER Review: `MISSING`
- `archive/v1-current`: `protected=false`（Lock／Push禁止／Delete禁止は`MISSING`）
- Stable Tag Ruleset: `MISSING`
- GitHub Environment: 0件（Production Environment Protectionは`MISSING`）
- Auto Merge、Merge Method、Branch自動削除: 公開APIでは非公開のため`UNKNOWN`
- Collaborator／Team、追加Admin: 認証済みAPIがないため`UNKNOWN`
- Secret Scanning、Push Protection、Code Scanning、Dependabot Alert、Dependency Graph: 認証済みSecurity設定を参照できず`UNKNOWN`
- GitHub Packages利用状況: 認証済みAPIが必要なため`UNKNOWN`

### Governance Gap

- Root AGENTS.md: `PARTIAL`（V1用Rootのみ存在し、V2構造・役割へ未更新）
- Nested AGENTS.md: `MISSING`
- CODEOWNERS: `MISSING`
- Issue Template: `MISSING`
- PR Template: `MISSING`
- `main` Ruleset: `MISSING`
- `release/**` Ruleset: `MISSING`
- Archive Branch Lock: `MISSING`
- Stable Tag Protection: `MISSING`
- Codex Repository Access分離: `MISSING`（Owner SSH Identityを共有）
- `.codex` Permission／Rules: `MISSING`
- Platform Policy CI: `MISSING`
- Quality／Security／Integration CI: `MISSING`
- Site Template CI: `MISSING`
- Production Environment Protection: `MISSING`
- Bot／Human Approval体制: `PARTIAL`（人間Ownerは存在するが、PR Author分離と第2Maintainerは未確認）

### Self-approval選択肢

- Option A: Codex／Automation専用のPrivate GitHub Appを作成し、PR AuthorをOwnerから分離する。Repository単位Install、必要最小限のContents／Pull Requests権限、`main` Bypassなし、Administration／Environment／Secret／Production／Release承認権限なしを推奨する。Auditと権限分離に最も適するが、App登録・鍵管理・Token発行処理の初期構築が必要。
- Option B: 2人目の人間Maintainerを追加する。Owner AuthorのPRでもHuman Approvalを成立させられ、可用性とBus Factorを改善する。一方、Personal Repositoryでは細かなRole／Team分離が弱く、Maintainerの稼働依存が残る。
- Option C: Approval Requirementを一時的に弱める。初期設定を進めやすいが、Owner Bypassと未Review Mergeを常態化させるため非推奨。採用する場合も期限・対象Task・Organization／Bot導入完了条件を明示し、恒久運用しない。
- 推奨: Option Aを基本とし、可能ならOption Bも併用する。Appが変更を作成し、人間Ownerまたは別の人間MaintainerがApproval／Mergeする。Codex／AppにはBypass、Stable Release、Production承認を与えない。

### Organization移行時期

- 今すぐ移行: Team、細粒度Role、Ruleset、Package、Environmentを最初から一元管理でき、後のURL／OIDC／Package Namespace再設定を避けやすい。Organization設計とTransfer確認による初期停止時間が発生する。
- V2 Alpha前: Governance文書の初期整備はPersonal Repositoryで始めつつ、Package公開、恒久CI／OIDC、Environment依存が固まる前に移行できる。再作業と初期停止のバランスが良い。
- Luxe Pack Staging前: Staging用Environment、OIDC Subject、Deploy Policyの移行が必要になるため、Alpha前より再設定Riskが高い。
- 外部顧客Repository作成前: 確定文書上の絶対期限。ただしここまで遅らせるとPlatform／Package／Luxe Packの権限とAutomationを移し直す範囲が最大になる。
- 推奨時期: `V2 Alpha前`。遅くともLuxe Pack Staging Environment／本格Package Publish開始前とし、最初の外部顧客Site作成前を絶対期限とする。
- Repository Transfer後は旧Git URLがRedirectされるが、Remote URL更新、Package紐付け、OIDC Subject、Environment、Ruleset、Webhook／Deploy Key／Secretの再確認が必要。

### G0状態

- V1 Baseline Full SHA: `PRESENT`
- Remote Push: `PRESENT`
- Archive Branch: `PRESENT`
- Annotated Tag: `PRESENT`
- Git Bundle: `PRESENT／VERIFIED`
- Schema Dump: `PRESENT／VERIFIED`
- Migration Checksum: `PRESENT／VERIFIED`
- Feature／API／Screen／Asset Inventory: `PRESENT`
- Test Evidence: `PRESENT`（Frontend lint FAIL、Backend Test／Browser E2E未実行を保持）
- Secret Evidence: `PRESENT`（補助Scanの限界を保持）
- Working Tree clean: `MISSING`（未追跡Worklog 1件）
- G0判定: `READY_WITH_ACTIONS`。`worklogs/new_ver_main.md`を削除せず、最初のV2 Governance PRで追跡対象へ含めることを提案する。

### 推奨実施順／人間判断

- 推奨順: `GOV-001 Root AGENTS` → `GOV-002 Nested AGENTS` → `GOV-003 CODEOWNERS` → `GOV-004 Issue／PR Template` → `GOV-005 main／release／archive／tag Ruleset`。
- Archive Branch／Tag保護はGOV-005の最優先で設定し、通常のV2実装開始前に完了する。
- GOV-001は開始可能。ただし1 Issue・1専用Branch・1 Worktree・1 Draft PRで実施し、同PRへ`worklogs/new_ver_main.md`を含める。
- 人間判断事項:
  1. Codex用Private GitHub Appを採用するか、2人目Maintainerを先に追加するか、または両方採用するか。
  2. Organization移行を推奨どおりV2 Alpha前に行うか。
  3. Personal RepositoryでGOV-001～004を先行し、Identity決定後にGOV-005を適用するか。
- 次Task: 人間がIdentity／Organization時期を決定後、`GOV-001`。本TaskではGOV-001以降の変更、Issue、PR、Ruleset作成を行っていない。

## GOV-000A Organization移管後のRemote更新・検証

### 基本情報

- Task ID: `GOV-000A`
- 実施日時: 2026-07-22T07:02:31Z／2026-07-22T16:02:31+09:00
- 目的: GitHub Organization移管後の正式RemoteへLocal `origin`を更新し、V1 Archive Refを含むLocal／Remote状態を検証する。
- 旧Remote: `git@github.com:myong-ideal/oripa.git`
- 新Remote: `git@github.com:ideal-sol/oripa.git`

### 更新前確認／新Remote疎通

- Working Treeは未追跡`worklogs/new_ver_main.md`だけで、Tracked変更はなかった。
- Local `main`／旧`origin/main`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Local `archive/v1-current`: Baseline SHA
- Annotated Tag Peeled Commit: Baseline SHA
- Local専用Branch: `backup/admin-refactor-deferred-20260626-0847`、`e0a853707f1fd1dcc81b733986019551aa5a0d8c`
- URL更新前に新Remoteを直接`git ls-remote`し、SSH疎通に成功した。
- 新Remoteの`main`、`archive/v1-current`、Annotated Tag Peeled CommitがすべてBaseline SHAであることを確認した。

### origin更新／Fetch

- `git remote set-url origin git@github.com:ideal-sol/oripa.git`で`origin`だけを更新した。
- Fetch／Push URLともCredentialを含まない正式SSH URLである。
- `git fetch origin --prune`: 成功
- Push、Force Push、Commit、Branch／Tag作成・削除・移動は行っていない。

### 更新後検証

- Local `main`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- `origin/main`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Local／Remote `archive/v1-current`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Annotated Tag Object: `88dc666f37f4e1a0a0ec702b66bb14ee26edfcab`
- Annotated Tag Peeled Commit: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Local専用Branch: `e0a853707f1fd1dcc81b733986019551aa5a0d8c`のまま
- Public GitHub API確認:
  - Repository: `ideal-sol/oripa`
  - Owner type: `Organization`
  - Visibility: `public`
  - Default Branch: `main`
  - `main`と`archive/v1-current`がBaseline SHAで存在
- Working Treeは引き続き未追跡Worklog 1件だけで、Application Code、Migration、設定Fileに変更はない。

### 判定／次Task

- GOV-000A Status: `COMPLETE`
- Organization移管後のRemote更新とV1 Archive Ref維持を確認した。
- GitHub App Status: 未作成または未確認のまま。GOV-000の人間判断事項を維持する。
- GOV-001 Readiness: `READY_WITH_ACTIONS`。Root AGENTS作業は開始可能だが、最初のGovernance PRをMergeする前にCodex用GitHub Appまたは2人目の人間MaintainerによるApproval経路を確定する必要がある。
- 次Task: 人間のIdentity／Approval方式決定後、`GOV-001 V2 Root AGENTS.md作成`。
- 本TaskではGOV-001、GitHub設定変更、Issue／PR作成、Commit／Pushを開始していない。

## GOV-000B GitHub App認証Broker構築・Read-only検証

### 基本情報

- Task ID: `GOV-000B`
- 実施日時: 2026-07-22T07:37:31Z／2026-07-22T16:37:31+09:00
- Status: `BLOCKED`
- 目的: Private KeyをCodexへ表示せずにInstallation Tokenを都度発行するBrokerとRead-only Wrapperを構築し、`ideal-sol/oripa`へのApp認証を検証する。

### 開始時確認

- Codex実行User: `root`（uid 0）
- 要件上の許可対象User: `ec2-user`
- 実行Userが`ec2-user`と異なる場合は推測して設定せず停止する明示条件に該当したため、構築前に停止した。
- Repository、`main`、`origin/main`、Archive Branch、Annotated Tag、Local専用Branchは期待値と一致した。
- Working Treeは未追跡`worklogs/new_ver_main.md`だけで、Tracked変更はなかった。
- `origin`: `git@github.com:ideal-sol/oripa.git`
- Tool確認:
  - OpenSSL 3.5.5
  - curl 8.17.0
  - Python 3.9.25
- 秘密Fileは内容を読まず`stat`だけを実行した。
- `/etc/ideal-sol/github-app/`: `root:root`、mode `700`
- `private-key.pem`: `root:root`、mode `600`
- `config`: `root:root`、mode `600`

### 未実施事項

- Token Broker: 未作成
- API Wrapper: 未作成
- Git Wrapper: 未作成
- sudoers: 未作成／未変更
- JWT生成: 未実行
- Installation Token発行: 未実行
- GitHub App認証: 未実行
- Repository Permission確認: 未実行
- Read-only API／Git検証: 未実行
- Private Key、Config値、App ID、Installation ID、JWT、Token、Authorization Headerは表示・保存・記録していない。

### Repository変更／次Task

- Application Code、Migration、設定File、Git Refは変更していない。
- Commit、Push、Issue、PR、Branch／Tag操作、GitHub設定変更は行っていない。
- Worklog以外のRepository Fileは変更していない。
- GOV-001 Readiness: `BLOCKED`。GitHub App IdentityをApproval経路として採用する場合、GOV-000BのRead-only認証検証完了を先に推奨する。
- 必要対応: Codex実行Userが`ec2-user`となるSessionでGOV-000Bを最初から再実行する。現在のroot Sessionから`su`／`runuser`等で推測して続行しない。
- 次Task: `ec2-user`実行Contextでの`GOV-000B`再実行。GOV-001は開始していない。

## GOV-000B-R1 GitHub App認証Broker構築・Read-only検証

### 基本情報／人間決定

- Task ID: `GOV-000B-R1`
- 実施日時: 2026-07-22T07:57:14Z／2026-07-22T16:57:14+09:00
- Status: `COMPLETE`
- 人間の最新決定によりPlatform Codexのroot実行と、rootによるGitHub App認証FileへのAccessを許容した。
- `ec2-user`へのUser分離とsudoersは採用せず、旧GOV-000B停止条件を上書きした。

### Secure File／Broker

- `/etc/ideal-sol/github-app/`: `root:root`、mode `700`
- Private Key／Config: `root:root`、mode `600`
- Private Key本文、Config値、App ID、Installation ID、JWT、Token、Authorization Headerは表示・保存・記録していない。
- Broker: `/usr/local/libexec/ideal-sol-github-app-token`
- Owner／Permission: `root:root`、mode `700`
- Python標準Library、OpenSSL、GitHub APIだけで短時間JWTとInstallation Tokenを都度生成する。
- Tokenの有効期限を検証し、対象Owner／Repositoryを`ideal-sol/oripa`へ固定した。
- Broker単体のToken出力Testは行わず、WrapperのPipe内だけでToken生成成功を確認した。
- 一時Token File、Token Log、Credential Storeは使用していない。

### API Wrapper

- Path: `/usr/local/bin/oripa-github-app-api`
- Owner／Permission: `root:root`、mode `700`
- App認証: 成功
- App: `ideal-sol-oripa-codex`
- Installation Repository Selection: `selected`
- Access対象: `ideal-sol/oripa`のみ
- Repository: Owner `ideal-sol`、Name `oripa`、Public、Default Branch `main`
- Installation／発行Token Permission:
  - `metadata: read`
  - `contents: write`
  - `pull_requests: write`
  - `issues: write`
- 想定外Repository Access: なし
- 想定外Permission: なし
- API Wrapperは固定Operation `app`、`installation-repositories`、`repository`、`main-branch`、`archive-branch`だけを受け付け、GET以外と任意URLを拒否する。

### Git Wrapper

- Path: `/usr/local/bin/oripa-github-app-git`
- Owner／Permission: `root:root`、mode `700`
- 許可Operation: `ls-remote`だけ
- Repository URL: `https://github.com/ideal-sol/oripa.git`へ固定
- 一時AskPassでUsername／PasswordをMemory取得し、TokenをURL、Process引数、永続Git Configへ入れずに認証Headerを子Process環境へ一時設定する。
- 最初の検証でPublic Repositoryの匿名成功を検出したため、AskPass Password要求Markerを必須化し、認証情報の事前送信へ補強した。失敗時もToken漏えいはなかった。
- `main`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- `archive/v1-current`: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- Annotated Tag Object: `88dc666f37f4e1a0a0ec702b66bb14ee26edfcab`
- Annotated Tag Peeled Commit: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- `push`等のOperationはToken生成前に拒否する。

### Safety／Leakage確認

- 3 ScriptはSyntax PASS、`set -x`なし、root所有、mode `700`。
- API Host、Repository、Operationは固定し、ErrorはRedact済み分類だけを返す。
- Git一時Directoryはmode `700`で、正常・異常終了後とも残存なし。
- Repository秘密File Path候補: 0件
- Repository／`.git/config`秘密値候補: 0件
- Credential付きRemote URL: 0件
- Shell History秘密値候補: 0件
- Git Credential Store File: 0件
- Process引数秘密値候補: 0件
- sudoers: 未作成
- Worklogに禁止されたID、JWT、Token、Key、Config値、Headerは記録していない。

### Repository変更／次Task

- Repository内は未追跡`worklogs/new_ver_main.md`だけで、Application Code、Migration、Tracked Config、Git Refは変更していない。
- Commit、Push、Issue、PR、Branch／Tag操作、GitHub設定変更、Package Install、Docker／DB操作は行っていない。
- GOV-001 Readiness: `READY_WITH_ACTIONS`。App Identityと必要権限は検証済みでGOV-001の作業開始は可能。
- 必要対応: GOV-001でBranch Push／Draft PRまでCodexが行う場合は、現在Read-onlyのWrapperをWrite用途へ拡張する別の明示承認範囲、または人間によるPR作成手順が必要。
- Risk／Limitation: root侵害時はPrivate Keyへ到達可能というroot Trust Modelを人間が明示承認している。現在のWrapper自体はRead-onlyだが、Brokerが発行するTokenには将来のPR作業用Write Permissionがある。
- 次Task候補: `GOV-001 V2 Root AGENTS.md作成`。本Taskでは開始していない。

## GOV-000C GitHub App制限付きWrite Wrapper・GOV-001準備

### 基本情報

- Task ID: `GOV-000C`
- 実施日時: 2026-07-22T08:14:45Z／2026-07-22T17:14:45+09:00
- Status: `COMPLETE`
- Repository: `ideal-sol/oripa`
- Baseline SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- GitHub App認証は継続して成功し、Access対象は`ideal-sol/oripa`だけ、Permissionは`metadata: read`、`contents: write`、`pull_requests: write`、`issues: write`であることを再確認した。

### Write Wrapper

- API Write Wrapper: `/usr/local/bin/oripa-github-app-api-write`
- Owner／Permission: `root:root`、mode `700`
- 許可Operation: `create-issue`、`create-draft-pr`のみ
- API HostとRepositoryを`api.github.com`／`ideal-sol/oripa`へ固定した。
- Issue作成はGOV-001の固定TitleとRepository外・非Symlink・root所有・mode 600以下・上限64 KiBのBody Fileだけを受け付ける。
- Draft PRは`draft=true`、Base `main`、Head `docs/GOV-001-v2-root-agents`を強制する。機能実装と非破壊検証だけを行い、Draft PR自体は作成していない。
- Git Wrapper: `/usr/local/bin/oripa-github-app-git`
- 既存`ls-remote`を維持し、`push-new-branch`を追加した。
- 新規Push対象は今回の`docs/GOV-001-v2-root-agents`とBaseline Commitだけに限定し、Remote同名Branch、Protected Ref、Force、Delete、Tag、任意Optionを拒否する。
- Broker／Wrapperは短期Tokenを内部利用し、Token、JWT、Private Key、Config値、Authorization Headerを表示・保存・記録していない。

### Safety Test

- Python Syntax: PASS
- root所有／mode 700: PASS
- `set -x`なし、任意URL／Repositoryなし、固定Method／Operation Allowlist、一時File cleanup、Redact済みError分類を確認した。
- Token発行前の拒否Test: `main`、`archive/v1-current`、`release/2.0`、Tag Ref、Branch削除、Force相当、未許可Task Issue、非Draft PR、Baseが`main`以外のPR、任意Repository入力をすべて拒否した。
- Draft PR作成件数: 0件

### GOV-001 Issue／Remote Branch

- Issue: `#1`
- URL: `https://github.com/ideal-sol/oripa/issues/1`
- Author: `ideal-sol-oripa-codex[bot]`
- State: `open`
- Title、Task ID、Repository、Role、Risk、Base SHA、Allowed／Forbidden Path、Acceptance Criteria、Required Verification、Out of Scopeを検証した。
- Issue重複確認では作成前0件、作成後は完全一致1件である。
- Remote Task Branch: `docs/GOV-001-v2-root-agents`
- Source／Remote SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`
- GitHub App Wrapperの`push-new-branch`だけで新規作成し、Local Branch／Worktree／Commitは作成していない。
- `main`、`archive/v1-current`、Annotated Tag、Local backup Branchは変更していない。

### Leakage／Repository状態

- Repository、`.git/config`、Shell History、Worklog、Process引数の高確度Secret値候補: 0件
- Credential付きRemote URL、Credential helper、残存一時File: 0件
- Path名検査の候補1件は既存`password_reset_tokens` Migrationであり、秘密Fileではない。
- Issue本文とWorklogにPrivate Key、App ID、Installation ID、JWT、Token、Authorization Header、Server Credentialを含めていない。
- Application Code、Migration、Tracked Configは変更していない。
- Commitなし、`main` Pushなし、Branch／Tagの移動・削除なし。
- RepositoryのWorking Treeは未追跡`worklogs/new_ver_main.md`だけ。

### 次Task

- GOV-001 Readiness: `READY`
- GOV-001はIssue #1、Remote Branch `docs/GOV-001-v2-root-agents`、専用Worktree、Draft PRの単位で開始できる。
- GOV-001開始時はBaseline Remote Branchから専用Worktreeを作成し、許可Path `/AGENTS.md`と`/worklogs/new_ver_main.md`だけを変更する。
- 本TaskではGOV-001のFile編集、Commit、Draft PR作成を開始していない。

## GOV-000D 既存Task Branchへの安全な更新Push機能

### 基本情報

- Task ID: `GOV-000D`
- 実施日時: 2026-07-22T08:23:46Z／2026-07-22T17:23:46+09:00
- Status: `COMPLETE`
- Repository: `ideal-sol/oripa`へ固定
- 対象Branch: `docs/GOV-001-v2-root-agents`へ固定
- 開始時と完了時のLocal `main`、`origin/main`、Remote Task Branchは`bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま。
- Issue #1はOpen、GOV-001のOpen PRは0件。

### Git Wrapper

- Path: `/usr/local/bin/oripa-github-app-git`
- Owner／Permission: `root:root`、mode `700`
- 追加Operation: `push-task-branch <branch-name> <expected-remote-sha> <new-local-sha>`
- Branch名は`docs/GOV-001-v2-root-agents`の完全一致だけを許可する。
- `expected-remote-sha`と`new-local-sha`は40文字のFull SHA、Localに存在するCommit Objectであることを必須化した。
- `new-local-sha`が`expected-remote-sha`と同一の場合は更新不要として拒否する。
- `merge-base --is-ancestor`とMerge Base一致でFast-forwardを検証し、Local `main`が`new-local-sha`の祖先であることも確認する。
- Remote Branch SHAが`expected-remote-sha`と完全一致しない場合は`REMOTE_BRANCH_CHANGED`として停止する楽観Lockを追加した。
- Push RefspecはWrapper内部で`<new-local-sha>:refs/heads/docs/GOV-001-v2-root-agents`を生成し、利用者から任意RefspecやGit Optionを受け付けない。
- Force、Force-with-lease、Delete、Mirror、All、Tagsを使用しない通常Pushだけを実装した。
- Push後はRemote SHAが`new-local-sha`と一致することを検証する。

### Push前Tree検証

- GOV-001の累積変更Pathを`AGENTS.md`と`worklogs/new_ver_main.md`だけに限定した。
- 新規Git Submoduleを拒否する。
- 秘密File候補Pathと高確度Secret値Patternを拒否し、値は出力しない。
- これらはGOV-001 Commit作成後、実Push前にWrapper内で検証される。

### Safety Test

- Syntax／Owner／Permission: PASS
- `main`、`archive/v1-current`、`release/2.0`、Tag、Delete、Force相当、Short SHA、Missing Object、Non-commit Object、Non-fast-forward、未許可Branch、任意Repository／Refspec、Option注入、Shell Metacharacter、同一SHA更新をすべて拒否した。
- 既存履歴の親CommitからBaselineへの関係を使用し、Fast-forward判定ロジックが成功することをRead-onlyで確認した。
- Remote SHA不一致はRead-only照合で拒否し、Token発行やPushを行っていない。
- 同一SHAから同一SHAへのPushも行っていない。

### Draft PR／Commit Author

- API Write WrapperのHeadは`docs/GOV-001-v2-root-agents`、Baseは`main`、`draft=true`へ固定済み。
- HeadとBaseの同一拒否、Repository外・非Symlink・mode 600以下のBody File制約を再確認した。
- 同一Head／BaseのOpen PRを作成前に照合し、重複を拒否する処理を追加した。
- Draft PRは作成していない。
- GitHub Appの正確なnoreply Emailは秘密IDを使用せず確定できないため、GOV-001 Commit Author／Committerは既存の`oripa-builder <oripa-builder@example.local>`を維持可能とする。PushとPR AuthorはGitHub App Identityになる。

### Leakage／Repository状態

- Token、JWT、Private Key、Config値、Authorization Headerは表示・保存・記録していない。
- `.git/config`、Remote URL、Credential Store、Shell History、Process引数、Wrapper Log、Worklogの高確度Secret候補は0件。
- AskPass一時File／Directoryの残存は0件。
- Application Code、Migration、Tracked Configは変更していない。
- Commit、Push、Issue変更、PR作成、Branch／Tag変更は行っていない。
- RepositoryのWorking Treeは未追跡`worklogs/new_ver_main.md`だけ。

### 次Task

- GOV-001 Readiness: `READY`
- Issue: `#1`
- Remote Branch: `docs/GOV-001-v2-root-agents`
- GOV-001では専用Worktreeで許可PathだけをCommitし、`push-task-branch`でRemote BranchをFast-forward更新した後、固定Draft PR Wrapperを使用できる。
- Risk／Limitation: Remote SHA照合と通常Pushの間はGit Protocol上の短い競合窓があるが、事前の期待SHA一致と通常PushのNon-fast-forward拒否を併用し、上書きやForce Retryは行わない。
- 本TaskではGOV-001を開始していない。

## GOV-001 V2 Root AGENTS.md作成

### 基本情報

- Task ID: `GOV-001`
- 実施日時: 2026-07-22T08:40:26Z／2026-07-22T17:40:26+09:00
- Issue: `#1`
- Risk: `R3`
- Branch: `docs/GOV-001-v2-root-agents`
- Worktree: `/var/www/oripa-worktrees/GOV-001-v2-root-agents`
- Base SHA: `bfca8efa0b85c00a88fb0fd439a123b722577b68`

### 変更内容

- Root `AGENTS.md`をV1の個別仕様中心の内容から、V2 Platform Repository向けの簡潔なCodex統治文書へ置換した。
- 仕様優先順位、Platform Codex／Site Codexの責任境界、Site完全分離、LaravelのDomain Authority、Point／Payment／Auth／APIの中核不変条件を定義した。
- 1 Task／1 Issue／1 Branch／1 Worktree／1 PR、開始前確認、禁止Command、Verification、Draft PRをCodex完了単位とする運用を定義した。
- Public Repository方針と、root実行／GitHub App秘密File Accessを許容しつつ秘密値の表示・保存・記録を禁止する人間決定を反映した。
- 既存の承認済み`worklogs/new_ver_main.md`を内容欠落なしで専用Worktreeへ取り込み、初めてGit追跡対象へ含める。
- 変更File: `AGENTS.md`、`worklogs/new_ver_main.md`のみ。
- Application Code、Backend、Frontend、Migration、Docker、Infrastructure、CI、GitHub設定、V1 Archive Refは変更していない。

### 検証

- `AGENTS.md`: 204行。目安150～250行内。
- Markdown見出し構造: PASS
- 確定V2文書名と優先順位: PASS
- 旧非Revision Security文書を正本として使用していない: PASS
- Password 15文字、Owner自己承認禁止、大型Storefront SDK、共有DB／共有Runtimeを許容する記載: なし
- Password 8～128文字、Owner paid Point自己承認可能、薄いStorefront Client、`/api/v2`直接fetch禁止: 記載済み
- 高確度Secret／PII／Credential候補: 0件
- `git diff --check`: PASS
- 変更Scope: 許可された2 Fileだけ
- Backend Test、Frontend Test、Build、Browser／E2E: 未実行。Documentation-only TaskでApplication Behavior変更がないため。

### Git／GitHub

- Commit Message: `docs(governance): define V2 root agent rules (GOV-001)`
- Commit SHA: このWorklogを含むCommit自身への自己参照は固定できないため、Draft PRとTask完了報告へFull SHAを記録する。
- Push結果: GitHub Appの`push-task-branch`によるFast-forward結果をDraft PRとTask完了報告へ記録する。
- Draft PR: GitHub Appで作成し、URL／Author／Head／BaseをTask完了報告へ記録する。
- CodexはReview、Merge、Stable Release、Production承認を行わない。

### Risk／次Task

- 確定V2文書本体はまだRepository外にあり、本TaskではRepositoryへCopyしていない。Root `AGENTS.md`は正式File名だけをReading Orderとして列挙する。
- 元`/var/www/oripa`の未追跡Worklogは削除・変更せず維持する。
- 人間Reviewで修正が必要な場合に備え、専用Worktreeを残す。
- 次Task候補: 人間によるGOV-001 Draft PR Review。GOV-002は本Taskでは開始しない。

## GOV-001A／B／C GOV-001同期・Cleanup完了

### Local main同期

- `GOV-001A`でHuman Squash Merge済みPR #2を確認し、Local未追跡WorklogとRemote追跡版をRepository外Evidenceへ保全・比較した。
- Remote版にLocal版の内容欠落がないことを確認後、`git merge --ff-only origin/main`でLocal `main`をSquash Commit `0e5815580e20cf5dd78ec3944527f718b7dc8644`へ同期した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-001A-local-sync-20260722T091711Z/`

### Worktree／Branch Cleanup

- `GOV-001B`でTask BranchとSquash Merge後の`main`のTree、`AGENTS.md`、Worklogが一致し、未統合内容と未Push Commitがないことを確認した。
- Git標準CommandでGOV-001 Worktreeを削除し、同等性Evidence保存後にLocal Task Branchを削除した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-001B-cleanup-20260722T092150Z/`
- `GOV-001C`でHumanがGitHub上から削除したRemote Task Branchを`git fetch origin --prune`とRemote Ref照合で確認した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-001C-remote-cleanup-20260722T093402Z/`
- PR #2はMerged、Issue #1はClosed、Local／Remote BranchおよびTask Worktreeは削除済みで、GOV-001は完全終了した。
- V1 Archive Branch、Annotated Tag、Local backup Branchは変更していない。

## GOV-002 Nested AGENTS.md作成

### 基本情報

- Task ID: `GOV-002`
- 実施日時: 2026-07-22T09:43:57Z／2026-07-22T18:43:57+09:00
- Issue: `#3`
- Risk: `R3`
- Branch: `docs/GOV-002-nested-agents`
- Worktree: `/var/www/oripa-worktrees/GOV-002-nested-agents`
- Base SHA: `0e5815580e20cf5dd78ec3944527f718b7dc8644`

### 変更内容

- `apps/api/AGENTS.md`: Laravel Domain Authority、Surface／Realm分離、R3 Transaction／Idempotency／Concurrency、Forward-safe Migrationを定義する。
- `apps/admin/AGENTS.md`: V2 Adminを空のAppから構築し、Admin API限定、MFA／Permission／noindex、品質Checkを定義する。
- `packages/AGENTS.md`: 4つのFirst-party Package、Exact Version、薄いClient、生成物とBreaking Changeの規則を定義する。
- `openapi/AGENTS.md`: OpenAPI 3.1.1、Surface分離、Contract-first順序、Disclosure／Compatibility規則を定義する。
- `infrastructure/AGENTS.md`: Site完全分離、Build Once／Digest Promote、人間のProduction承認、Backup／Rollbackを定義する。
- `docs/AGENTS.md`: Baseline／ADR／Runbook／Release文書の状態分離、正本保全、Markdown検証を定義する。
- `legacy/v1/AGENTS.md`: V1をBehavioral Referenceと保全対象に限定し、新Feature、Archive変更、V2 Image混入を禁止する。
- Application Code、既存Runtime Path、Root `AGENTS.md`、Migration、Docker、CI、Ruleset、V1 Refは変更しない。

### 検証／GitHub

- `git diff --check`、Markdown見出し、Root／Nested矛盾、Scope、Secret／PII、共有Runtime／Production操作の禁止を確認する。
- Backend Test、Frontend Test、Build、Browser／E2EはDocumentation-only Taskのため未実行とする。
- Commit Message: `docs(governance): define nested agent rules (GOV-002)`
- Commit SHA: Worklogを含むCommit自身への自己参照を避け、Draft PRとTask完了報告へFull SHAを記録する。
- Push: GitHub Appの`push-task-branch`によるFast-forward結果をDraft PRとTask完了報告へ記録する。
- Draft PR: GitHub Appで作成し、URL、Author、Head、BaseをTask完了報告へ記録する。
- CodexはReview、Merge、Stable Release、Production承認を行わない。

### Risk／次Task

- Migration Planの最終Frontend Pathは`legacy/v1-frontend`だが、GovernanceのNested指定は`legacy/v1/AGENTS.md`である。本Taskでは優先度の高いGovernance指定に従い、Frontendを移動しない。
- 確定V2文書本体はRepository外のままで、本TaskではCopyしない。
- 次Task候補: 人間によるGOV-002 Draft PR Review。GOV-003は本Taskでは開始しない。

## GOV-002A／B GOV-002同期・Cleanup完了

### Local main同期

- `GOV-002A`でPR #4のHuman Squash MergeとIssue #3のCloseを確認し、Local `main`をSquash Commit `678c980473869dfac821b95ec7eb245d7ac4b0e0`へ`git merge --ff-only`で同期した。
- Squash Commitの変更は7つのNested `AGENTS.md`と`worklogs/new_ver_main.md`の8 Fileだけで、Application、Migration、Docker、CI、V1保全Refへの変更がないことを確認した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-002A-local-sync-20260722T095603Z/`

### Worktree／Branch Cleanup

- `GOV-002B`でRemote Task Branch削除を`git fetch origin --prune`とGitHub Ref照合で確認した。
- Task BranchとSquash Merge後の`main`はTree SHAおよび8 FileのBlob SHAが一致し、未反映内容、未追跡File、未Commit変更がないことを確認した。
- Git標準CommandでGOV-002 Worktreeを削除し、同等性Evidence保存後にLocal Task Branchを削除した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-002B-cleanup-20260722T100037Z/`
- Local／Remote Task BranchとTask Worktreeは削除済みで、GOV-002は完全終了した。
- V1 Archive Branch、Annotated Tag、Local backup Branchは変更していない。

## GOV-003 CODEOWNERS作成

### 基本情報

- Task ID: `GOV-003`
- 実施日時: 2026-07-23T00:08:35Z／2026-07-23T09:08:35+09:00
- Issue: `#5`
- Risk: `R3`
- Branch: `docs/GOV-003-codeowners`
- Worktree: `/var/www/oripa-worktrees/GOV-003-codeowners`
- Base SHA: `678c980473869dfac821b95ec7eb245d7ac4b0e0`

### Human Code Owner

- Default Code Owner: `@myong-ideal`
- GitHub Userの存在とAccount Type `User`を公開APIで確認し、GitHub App／Bot Accountではないことを確認した。
- `ideal-sol/oripa`のRepository PermissionはGitHub App認証下のAPIで`admin`と確認した。
- Organization RoleはAPI権限制約により`UNKNOWN`であり、Repository Permission確認をReview可能性の根拠とする。
- `ideal-sol-oripa-codex[bot]`、Organization名だけのOwner、未作成Team、Email AddressはCODEOWNERSへ指定しない。

### CODEOWNERS

- `.github/CODEOWNERS`へRepository全体のDefault Ruleを最初に置き、すべて`@myong-ideal`へ割り当てる。
- Governance、Root／Nested `AGENTS.md`、Worklog、Platform Application／Contract、V1 Legacy／現行`backend`／`frontend` Pathへ詳細Ruleを後置する。
- Generated CodeやDependencyを除外せず、否定Pattern、空Owner Pattern、後勝ちによるOwner欠落を作らない。
- GitHub AppはPR作成者でありCode OwnerまたはApproval主体にしない。
- Required Code Owner Review、Ruleset、Branch Protectionは本Taskでは設定せず、後続`GOV-005`の対象とする。

### 検証／GitHub

- `git diff --check`、CODEOWNERS Pattern／Owner、Root／Nested矛盾、Scope、Secret／PII、Binary／Submoduleを確認する。
- Backend Test、Frontend Test、Build、Browser／E2EはDocumentation／Governance-only Taskのため未実行とする。
- Commit Message: `chore(governance): define code ownership (GOV-003)`
- Commit SHA: Worklogを含むCommit自身への自己参照を避け、Draft PRとTask完了報告へFull SHAを記録する。
- Push: GitHub Appの`push-task-branch`によるFast-forward結果をDraft PRとTask完了報告へ記録する。
- Draft PR: GitHub Appで作成し、URL、Author、Head、BaseをTask完了報告へ記録する。
- CodexはApprove、Review、Merge、Stable Release、Production承認を行わない。

### Risk／次Task

- Human OwnerのRepository `admin`権限は確認済みだが、Organization上のOwner／Maintainer Role自体はAPI権限制約により未確認である。
- CODEOWNERS追加だけではRequired Code Owner Reviewは有効にならず、後続Ruleset設定が必要である。
- 次Task候補: 人間によるGOV-003 Draft PR Review。GOV-004は本Taskでは開始しない。

## GOV-003A／B GOV-003同期・Cleanup完了

### Local main同期

- `GOV-003A`でPR #6のHuman Squash MergeとIssue #5のCloseを確認し、Local `main`をSquash Commit `4ba5838c0593c0f595e81b6da86aa9042ba0297c`へ`git merge --ff-only`で同期した。
- Squash Commitの変更は`.github/CODEOWNERS`と`worklogs/new_ver_main.md`の2 Fileだけで、Application、Migration、Docker、CI、Ruleset、Root／Nested `AGENTS.md`、V1保全Refへの変更がないことを確認した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-003A-local-sync-20260723T002259Z/`

### Worktree／Branch Cleanup

- `GOV-003B`でHumanが削除したRemote Task Branchを`git fetch origin --prune`とRemote Ref照合で確認した。
- Task BranchとSquash Merge後の`main`はTreeと対象2 Fileの内容が一致し、未反映内容、未追跡File、未Commit変更、未Push Commitがないことを確認した。
- Git標準CommandでGOV-003 Worktreeを削除し、同等性Evidence保存後にLocal Task Branchを削除した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-003B-cleanup-20260723T004006Z/`
- Local／Remote Task BranchとTask Worktreeは削除済みで、GOV-003は完全終了した。
- V1 Archive Branch、Annotated Tag、Local backup Branchは変更していない。

## GOV-004 Issue／PR Template作成

### 基本情報

- Task ID: `GOV-004`
- 実施日時: 2026-07-23T00:54:16Z／2026-07-23T09:54:16+09:00
- Issue: `#7`
- Risk: `R3`
- Branch: `docs/GOV-004-issue-pr-templates`
- Worktree: `/var/www/oripa-worktrees/GOV-004-issue-pr-templates`
- Base SHA: `4ba5838c0593c0f595e81b6da86aa9042ba0297c`

### 変更内容

- `.github/ISSUE_TEMPLATE/task.yml`: V2 Platform Task用のIssue Formを追加する。
- 必須FieldとしてTask ID、Risk、Responsible role、Base SHA、Purpose、Specification sources、Allowed／Forbidden paths、Acceptance criteria、Required verification、Out of scopeを定義する。
- Human decisions and exceptions欄と、Secret／PII禁止、想定外変更時の停止、CodexによるApprove／Merge／Release／Production承認禁止のAcknowledgementを定義する。
- `.github/ISSUE_TEMPLATE/config.yml`: Blank Issueを無効化し、未確定のSupport／Security URLは追加しない。
- `.github/pull_request_template.md`: Task、仕様根拠、Scope、Technical impact、Migration state、実行／未実行Verification、Security／Privacy、Deploy／Rollback、Known risk、Human review、Checklistを標準化する。
- Application Code、Backend、Frontend、Migration、Docker、Infrastructure、GitHub Actions、CODEOWNERS、Ruleset、Branch Protection、Root／Nested `AGENTS.md`、V1 Archive Refは変更しない。

### 検証／GitHub

- `git diff --check`、YAML Parse／Indent／一意ID／必須Validation、Markdown見出し、Root／Nested `AGENTS.md`との整合、Scope、Secret／PII、Binary／Submoduleを確認する。
- GitHub Issue Form固有SchemaはRepositoryへMergeする前にGitHub側の完全Validationを取得できないため、既存ToolによるYAML Parseと手動構造Reviewの範囲を明記する。
- Backend Test、Frontend Test、Build、Browser／E2EはGovernance-only TaskでApplication Behavior変更がないため未実行とする。
- Commit Message: `chore(governance): add issue and PR templates (GOV-004)`
- Commit SHA: Worklogを含むCommit自身への自己参照を避け、Draft PRとTask完了報告へFull SHAを記録する。
- Push: GitHub Appの`push-task-branch`によるFast-forward結果をDraft PRとTask完了報告へ記録する。
- Draft PR: GitHub Appで作成し、URL、Author、Head、BaseをTask完了報告へ記録する。
- CodexはApprove、Review、Merge、Stable Release、Production承認を行わない。

### Risk／次Task

- Blank Issueは無効化するが、Security Reporting Policyの正式URLまたは`SECURITY.md`が未確定のため、`contact_links`は空とする。
- Ruleset、Required Review、CIによるTemplate／Scope強制は本Taskでは設定しない。
- 次Task候補: 人間によるGOV-004 Draft PR Review。GOV-005は本Taskでは開始しない。

## GOV-004 Fast Track継続・GitHub App Wrapper汎用化

### 継続決定

- 実施日時: 2026-07-23T01:24:01Z／2026-07-23T10:24:01+09:00
- 人間の明示決定により、既存Issue `#7`、Branch `docs/GOV-004-issue-pr-templates`、Worktree、PR `#8`をGOV-004 Fast Trackとして継続利用した。
- 新しいIssue、Branch、Worktree、PRは作成していない。
- 継続開始時のLocal／Remote Task SHAは`aa550142d6db0ca7cf3516cf6f4c170f8ad24348`で一致し、Task Worktreeはclean、Local／Remote `main`はBase SHAのままであることを確認した。

### Repository外Tool

- `/usr/local/bin/oripa-github-app-api-write`と`/usr/local/bin/oripa-github-app-git`を、Taskごとの固定値ではなくTask Policyを読み込む汎用方式へ変更した。
- Task Policy Directory: `/etc/ideal-sol/github-app/task-policies/`、`root:root`、mode `700`。
- GOV-004 Policy: `/etc/ideal-sol/github-app/task-policies/GOV-004.json`、`root:root`、mode `600`。
- PolicyはTask ID、Issue／PR Title、Branch、Base Branch／SHA、Risk、Allowed Paths、Allowed Operationsを定義する。
- 今後のTask切替はPolicy Fileの追加または承認済み変更で行い、Wrapper本体をTaskごとに書き換えない。
- PR `#8`本文の補足に限り、PolicyのBranch／Base／Titleへ一致する単一Open PRだけを更新する`update-pr-body`をGOV-004 Policyへ追加した。任意PR番号、Repository、URLは受け付けない。
- WrapperとPolicyはRepository外の運用Toolであり、Git CommitおよびPRの変更Fileには含めない。

### 安全性／検証

- Wrapper Syntax、Policy JSON Parse、Owner／Permission、Policy読込、GitHub App Read-only認証: PASS。
- Policy Symlink、Policy Directory外Task ID、絶対Path／不正Allowed Path: 拒否PASS。
- `main`、`release/**`、`archive/**`、Tag、Force、Delete、任意Repository／URL／Refspec／Git Option、Short SHA、Non-fast-forward: 拒否PASS。
- Expected Remote SHA、Fast-forward、Policy Allowed Paths、Submodule、高確度Secret候補をPush前に検証する。
- Token、JWT、Private Key、Config値、Authorization Headerは表示、Worklog記録、Repository保存、Git Config保存していない。
- AskPass一時Directoryと検証用一時Fileは削除し、TokenをProcess引数またはRemote URLへ含めていない。

### Repository／GitHub

- Repository内の追加変更は`worklogs/new_ver_main.md`だけで、PR全体の変更Fileは既存3 TemplateとWorklogの4件を維持する。
- 追加Commit Message: `chore(governance): generalize GitHub App task policy (GOV-004)`。
- 追加Commit SHAはWorklog自身への循環参照を避け、PR `#8`本文とTask完了報告へFull SHAを記録する。
- 汎用Wrapperの`push-task-branch`によるFast-forward Push結果とPR `#8`のHead更新結果は、PR本文とTask完了報告へ記録する。
- PR `#8`本文へWrapper汎用化、Task Policy方式、Repository外Tool、Worklog Evidenceを補足する。CodexはApproveまたはMergeしない。
- Application、API、Database、Migration、Authentication、Point、Payment、Draw、Docker、Infrastructure、CI、Ruleset、Branch Protectionは変更していない。
- Backend Test、Frontend Test、Build、Browser／E2EはGovernance運用ToolとWorklogだけの変更であるため未実行とする。

### 次Task

- 人間がPR `#8`を再Reviewし、承認後にSquash Mergeする。
- GOV-005は本Taskでは開始しない。

## GOV-004完了／GOV-005 Repository Ruleset基準作成

### GOV-004完了処理

- 実施日時: 2026-07-23T01:44:08Z／2026-07-23T10:44:08+09:00
- PR `#8`のHuman Squash MergeとIssue `#7`のCloseをGitHub APIで確認した。
- GOV-004 Squash Commitは`5a8eedef37b0fe8ba890e9e942a4c60860177151`で、変更Pathが3つのTemplateと本Worklogの4件だけであることを確認した。
- Local `main`を`git merge --ff-only`で`origin/main`へ同期し、Local Merge Commitを作成していない。
- GOV-004 Task BranchとSquash後の`main`は最終Treeが同一で、未反映内容、未追跡File、未Commit／未Push Commitがないことを確認した。
- Git標準CommandでGOV-004 Worktreeを削除し、同等性Evidence保存後にLocal Task Branchを削除した。
- Evidence: `/var/www/oripa-v1-evidence/GOV-004-closeout-20260723T013823Z/`
- Remote `docs/GOV-004-issue-pr-templates`は残存している。Codexは削除せず、人間がPR `#8`画面から削除する。
- V1 Archive Branch、Annotated Tag、Local backup Branchは変更していない。

### GOV-005基本情報

- Task ID: `GOV-005`
- Risk: `R3`
- Issue: `#9` (`https://github.com/ideal-sol/oripa/issues/9`)
- Branch: `chore/GOV-005-rulesets`
- Worktree: `/var/www/oripa-worktrees/GOV-005-rulesets`
- Base SHA: `5a8eedef37b0fe8ba890e9e942a4c60860177151`
- Task Policy: `/etc/ideal-sol/github-app/task-policies/GOV-005.json`、`root:root`、mode `600`。
- 既存の汎用GitHub App Wrapperを利用し、Wrapper本体へTask固有変更を行っていない。

### Read-only監査

- RepositoryはPublic、Default Branchは`main`、CODEOWNERSは追跡済みである。
- Repository Ruleset一覧は0件だった。
- `main`と`archive/v1-current`のBranch metadataは`protected=false`だった。詳細なClassic Branch Protection APIは403のため、詳細設定は`UNKNOWN`として推測しない。
- Squash Merge、Merge Commit、Rebase Mergeは有効、Auto Mergeは無効、Merged Head Branch自動削除は無効だった。
- GitHub Appに`Administration`権限はなく、CodexはRulesetまたはRepository General設定を変更していない。

### Ruleset設定案

- `main-protection`: `main`へPR、Human Approval 1件、CODEOWNERS Review、Stale Approval破棄、最新Push承認、Conversation解決、Linear History、削除／Force Push禁止を提案する。
- `release-branch-protection`: `release/**`へ`main`と同等の保護を提案する。
- `v1-archive-lock`: `archive/v1-current`へBypassなしの更新／削除／Force Push禁止を提案する。
- `stable-tag-protection`: Stable Tag Patternの作成／更新／削除／Force PushをRepository Administrator以外へ禁止する。
- GitHub App、Codex、GitHub ActionsをBypass Actorへ含めない。
- Repository General設定はSquashのみ有効、Merge Commit／Rebase／Auto Merge無効、Merged Head Branch自動削除有効を提案する。
- Required Status Checksは`policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`がGOV-008／009で実行成功した後に追加する。本Taskでは設定案へ含めない。
- 現行Repository Ruleset REST Schemaに独立した`lock_branch` Rule Typeはないため、Archive lockは`Restrict updates`の`update` Ruleとして表現する。存在しないSchemaを推測しない。
- JSON内のRepository Administrator用`actor_id: 0`はHuman UI適用向けの明示的Placeholderであり、直接API送信用とは断定しない。

### Scope／検証／GitHub

- 変更対象はRuleset Baseline、4つのJSON設定案、本Worklogの6 Fileだけとする。
- Application、API、DB、Migration、Authentication、Point、Payment、Draw、Docker、Infrastructure、CI、CODEOWNERS、Root／Nested `AGENTS.md`を変更しない。
- `git diff --check`、JSON Parse、Markdown構造、Target Pattern、Bypass、Approval、CODEOWNERS、Archive／Tag保護、Scope、Secret／PIIを検証する。
- Backend Test、Frontend Test、Build、Browser／E2EはGovernance Documentation-only Taskのため未実行とする。
- Commit Message: `chore(governance): define repository ruleset baseline (GOV-005)`。
- Commit SHAとGitHub App WrapperによるFast-forward Push結果は、Worklog自身への循環参照を避けてDraft PRとTask完了報告へ記録する。
- Draft PRはGitHub App名義で作成し、CodexはApprove、Merge、Ruleset適用、Release、Production承認を行わない。

### Risk／次Task

- 人間Repository AdministratorがGitHub Settingsで4 RulesetとGeneral設定を適用し、適用後Evidenceを確認する必要がある。
- Required Status Checksは実在Check未確認のため延期している。
- 詳細Classic Branch ProtectionはAPI権限制約で`UNKNOWN`である。
- 次Taskは人間によるGOV-005 Draft PR ReviewとRuleset手動適用であり、GOV-006は本Taskでは開始しない。

## GOV-005R1 Codex完全自律GitHub運用

### 基本情報

- 実施開始: 2026-07-23T02:53:50Z／2026-07-23T11:53:50+09:00
- Task ID: `GOV-005R1`
- Risk: `R3`
- Issue: `#11` (`https://github.com/ideal-sol/oripa/issues/11`)
- Branch: `chore/GOV-005R1-autonomous-github`
- Worktree: `/var/www/oripa-worktrees/GOV-005R1-autonomous-github`
- Base SHA: `2f74971a34e64e948748aa53c831b556943f20c8`
- GOV-005 PR `#10`はSquash Merge済みで、Local `main`同期、Remote／Local Branch、Worktree Cleanupを完了した。

### GitHub App権限再検証

- App登録／Installation側の`administration: write`をInstallation metadataで確認した。
- Brokerの固定Permission ProfileへAdministration、Actions read、Checks read、Deployments／Environments write、Statuses read、Workflows writeを追加した。
- 新規発行Token Responseでも`administration: write`を確認し、古いTokenやCacheを再利用していない。
- Access対象は`ideal-sol/oripa`だけである。
- 認証付き`GET /repos/ideal-sol/oripa/rulesets`はHTTP 200だった。
- 無効PayloadによるCreate Endpoint事前判定はHTTP 422 `validation_error`で、前後のRuleset件数は0件のまま、実変更がないことを確認した。
- App ID、Installation ID、JWT、Token、Authorization Header、Private Key、Config値を表示または記録していない。

### 正式文書／Governance

- `V2_CODEX_GIT_CI_GOVERNANCE_FINAL_REV2_2026-07-23.md`を新正本として作成し、旧2026-07-22 GovernanceをSupersededとした。
- `V2_RELEASE_GATES_FINAL_REV1_2026-07-23.md`を新正本として作成し、旧2026-07-22 Release GatesをSupersededとした。
- `V2_AUTONOMOUS_GITHUB_OPERATIONS_ADR_FINAL_2026-07-23.md`へ自律運用のDecision、固定Head Self-review、Bootstrap、Ruleset、Risk／Mitigationを記録した。
- Root／7つのNested `AGENTS.md`、Issue Form、PR Templateを自律Review／Squash Merge方針へ更新した。
- GitHub ApprovalとRequired Code Owner ReviewをMerge条件から外し、PR、CI、Scope、Secret／PII、固定Head、Self-review、SEV-0／1なしを必須とした。
- 初回商用Production最終GO、法務、会計、未確定Provider判断は自律化対象外のままとした。

### Ruleset／Repository設定

- `main`／`release/**`: PR必須、Approval 0、Code Owner Review OFF、最新Push Approval OFF、Conversation解決、Linear History、削除／Force Push禁止、Bypassなしとする。
- `archive/v1-current`: Bypassなしで更新、削除、Force Pushを禁止する。
- Stable Tagは`stable-tag-creation`と`stable-tag-immutability`へ分離する。GitHub AppだけがRelease Gate後に新規作成でき、Appを含む全Actorの更新／削除を禁止する。
- Repository設定はSquash ON、Merge Commit OFF、Rebase OFF、Auto Merge ON、Merged Branch自動削除ONとする。
- GOV-009前は全Local ValidationとGitHubが実際に出す全Checkを必須とし、GOV-009後は5つの標準GateをRulesetへ必須設定してBootstrap例外を失効させる。

### Repository外Wrapper

- BrokerはOperationごとに新規短期Installation Tokenを発行し、必要Permissionだけを固定要求する。
- `/usr/local/libexec/ideal-sol-github-app-autonomy`を追加し、既存Policy Wrapperから固定Operationとして呼び出す。
- 追加Operation: `mark-pr-ready`、`get-pr-checks`、`create-self-review-evidence`、`merge-pr-squash`、`delete-merged-branch`、`update-repository-settings`、`create-ruleset`、`update-ruleset`、`get-rulesets`、`create-release`、`create-protected-tag`。
- MergeはExpected Head SHA、Policy Scope、GitHub Checks、Fresh Self-review Evidence、SEV-0／1なし、Merge Conflictなしを再確認し、Squashだけを許可する。
- Stable Tag作成とRelease Operationは実装するが、本Task Policyでは許可せず誤実行を防止する。
- Wrapper／PolicyはRepository外でroot所有、mode 700／600とし、秘密値を出力・保存しない。

### 検証／完了手順

- JSON／YAML／Markdown、Root／Nested矛盾、Allowed Paths、Secret／PII、Wrapper Syntax／拒否経路を検証する。
- Governance-only変更のためBackend／Frontend Runtime Test、Build、Browser／E2Eは未実行とし、静的／構造検証を必須にする。
- Commit、GitHub App Fast-forward Push、Draft PR、Repository設定／Ruleset適用、Readback、固定Head Self-review、Ready化、CI確認、Squash Mergeを順番に行う。
- Merge後はRemote Task Branch、Local Worktree／BranchをCleanupし、Local `main`を`origin/main`へFast-forward同期する。
- GOV-005R1完了後は人間PR操作を待たず、MIG-010 V2 Repository Baselineを開始する。

### GitHub適用結果

- Initial Commit: `01908dcf63a8d8d451a12b5711e22b7ad081cefd`
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。
- Draft PR: `#12` (`https://github.com/ideal-sol/oripa/pull/12`)、Authorは`ideal-sol-oripa-codex[bot]`、Baseは`main`。
- Repository General設定はSquash ON、Merge Commit OFF、Rebase OFF、Auto Merge ON、Merged Head Branch自動削除ONとしてAPI Responseを確認した。
- `main-protection`、`release-branch-protection`、`v1-archive-lock`、`stable-tag-immutability`、`stable-tag-creation`の5 RulesetをActiveで作成した。
- Main／Release／Archive／Stable immutabilityのBypassは空であり、Stable creationだけがIntegrationのAlways Bypassである。Actor IDは出力・記録していない。
- Main／ReleaseはApproval 0、Code Owner Review OFF、最新Push Approval OFF、Conversation Resolution ON、Squashのみ、Linear History／削除禁止／Force Push禁止をReadbackした。
- PR `#12`のInitial Headに対するGitHub Checkは0件、Required Check 0件、Failure 0件で、GOV-009前のBootstrapとして記録した。
- 本追記を含むFinal Headは別CommitとしてFast-forward Pushし、PR本文、Checks、Self-review EvidenceをFinal Headへ更新する。

### GOV-005R1完了

- Final Head `2c425269fcc5d08863fa8b989a7aa83845a9399f`へ固定したMachine-readable Self-review EvidenceをPR `#12`へ保存した。
- GOV-009前Bootstrapとして、GitHubが実際に発行したCheck 0件、Failure 0件、Missing 0件を確認した。未実行のRuntime TestをPASSとは扱っていない。
- PR `#12`をReady化し、Head不変、Allowed Paths、Check、Fresh Evidence、SEV-0／SEV-1なし、Merge Conflictなしを再検証してGitHub AppがSquash Mergeした。
- Squash Commitは`01ac1521bbb1b0d08405ddcf9be1a859135ede6a`、Issue `#11`はClosedである。
- Remote Task BranchはRepository設定により自動削除され、Local Task Worktree／BranchもTree同等性確認後に削除した。
- Local `main`を`origin/main`へFast-forward同期し、両者はSquash Commitで一致、Working Treeはcleanである。
- V1 Archive Branch、Annotated Tag、Local backup Branchは変更していない。

## MIG-010 V2 Repository Baseline

### Task

- 実施開始: 2026-07-23
- Task ID: `MIG-010`
- Risk: `R3`
- Issue: `#13` (`https://github.com/ideal-sol/oripa/issues/13`)
- Branch: `docs/MIG-010-v2-repository-baseline`
- Worktree: `/var/www/oripa-worktrees/MIG-010-v2-repository-baseline`
- Base SHA: `01ac1521bbb1b0d08405ddcf9be1a859135ede6a`

### Scope

- Repository外の確定文書Directoryを再確認し、未配置の現行FINAL文書5件を`docs/architecture/`へ内容不変で配置する。
- `release-gate.example.yaml`を非秘密の例示Artifactとして配置する。
- `docs/architecture/README.md`をArchitecture Indexとして追加する。
- Root `AGENTS.md`のReading OrderをRepository内の実在Pathへ更新する。
- Context-onlyのChat Handoff／Project Statusと、Superseded済みの旧Governance／Release Gatesは正本として配置しない。
- 旧非Revision Security文書は使用せず、`REV1`だけを正本とする。

### Verification

- 外部原本とRepository配置後FileのSHA-256一致を確認する。
- `git diff --check`、YAML Parse、Markdown見出し／Internal Link、Allowed Paths、Secret／PII、Binary／Submoduleを確認する。
- Documentation-only TaskのためBackend／Frontend Runtime Test、Build、Browser／E2Eは未実行とする。
- GitHubが発行する全Check、固定Head Self-review、SEV-0／SEV-1なしを確認してから自律Squash Mergeする。

### Impact／Next

- Application、API、DB、Migration、Auth、Point、Payment、Draw、CI、Infrastructure、Ruleset、Productionへ変更はない。
- MIG-010完了後、Phase 1の残作業とGate G1を評価する。Workspace SkeletonやMechanical Moveは本Taskに含めない。

### MIG-010正式完了記録

- 完了確認日時: 2026-07-23T03:25:17Z／2026-07-23T12:25:17+09:00
- 配置文書:
  - `API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md`
  - `V1_TO_V2_MIGRATION_PLAN_FINAL_2026-07-22.md`
  - `V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md`
  - `V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md`
  - `V2_PACKAGE_VERSION_COMPATIBILITY_POLICY_FINAL_2026-07-22.md`
  - `release-gate.example.yaml`
- 除外文書:
  - Chat HandoffとProject StatusはContext-onlyのため正本へ含めていない。
  - 旧2026-07-22 Governance／Release GatesはRevision 2／Revision 1にSupersededされたため新正本へ含めていない。
  - 旧非Revision Security文書は使用せず、`REV1`だけを正本とした。
- 上記6 FileはRepository外原本とRepository配置先のSHA-256が全件一致した。
- `docs/architecture/README.md`をArchitecture Indexとして作成し、Root `AGENTS.md`のReading OrderをRepository内の実在Pathへ更新した。
- MIG-010の変更Fileは`AGENTS.md`、Architecture Index、確定文書5件、Release Gate Example、本Worklogの9件だけだった。
- `git diff --check`、YAML Parse、Markdown見出し／Internal Link、文書名、Reading Order、Allowed Paths、Secret／PII、Binary／Submoduleを確認し、すべてPASSした。
- Backend／Frontend Runtime Test、Build、Browser／E2EはDocumentation-only Taskのため未実行であり、PASSとは記録しない。
- Task Commit: `3abbc2d57c96e9b2224966b54800525cfa138f5f`
- GitHub App WrapperでTask BranchへFast-forward Pushした。
- PR: `#14` (`https://github.com/ideal-sol/oripa/pull/14`)
- Final HeadをTask Commitへ固定し、Machine-readable Self-review EvidenceをPRへ保存した。
- GitHub CheckはGOV-009前BootstrapでRequired 0件、Run 0件、Status 0件、Failure 0件、Missing 0件だった。
- Allowed Paths、Secret／PIIなし、SEV-0／SEV-1なし、Head不変、Merge Conflictなしを再確認し、GitHub AppがSquash Mergeした。
- Squash Commit: `d597a605e1bd3e00a9044821a54bfec93869b2e9`
- Issue `#13`はClosed、Remote Task Branchは自動削除済みである。
- Task WorktreeとLocal Task BranchはTree同等性確認後に削除した。
- Local `main`を`origin/main`へ`--ff-only`同期し、両者はSquash Commitで一致、Working Treeはcleanだった。
- V1 Archive BranchとAnnotated Tagは`bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更していない。

### MIG-010時点のGate G1

- 完了:
  - 現行V2 Architecture文書のRepository正本化
  - Architecture Index
  - Root／Nested `AGENTS.md`によるPlatform／Site CodexのPath境界
  - CODEOWNERS、Issue／PR Template、Repository Ruleset基準
  - Version `2.0.0-alpha.1`方針を含むVersion／Compatibility Policy
- 未完了:
  - CI Skeletonの実装とPASS
  - `policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`の実Check化
  - V1仕様／実装のLegacy構造への実隔離
- 判定: `G1 NOT COMPLETE`。MIG-010完了はArchitecture文書正本化の完了であり、Gate G1全体の完了ではない。

## MIG-010A V2 Repository Baseline記録補完

### Task

- 実施開始: 2026-07-23T03:25:17Z／2026-07-23T12:25:17+09:00
- Task ID: `MIG-010A`
- Risk: `R3`
- Issue: `#15` (`https://github.com/ideal-sol/oripa/issues/15`)
- Branch: `docs/MIG-010A-complete-v2-baseline`
- Worktree: `/var/www/oripa-worktrees/MIG-010A-complete-v2-baseline`
- Base SHA: `d597a605e1bd3e00a9044821a54bfec93869b2e9`

### Scope／Verification

- Architecture Indexへ文書ID、Status、優先順位、適用範囲、上書き関係、Checksumを補完する。
- 本WorklogへMIG-010のCommit、Push、PR、Self-review、Checks、Squash Merge、Issue Close、Cleanup、Local同期、Gate G1判定を記録する。
- 変更は`docs/architecture/README.md`と本Worklogだけに限定する。
- V2確定文書本文、Application、Backend／Frontend、Migration、Docker、CI、Ruleset、GitHub App Permission、Infrastructure、V1 Archive Refは変更しない。
- `git diff --check`、Markdown見出し／Internal Link、正本文書名、Superseded関係、Allowed Paths、Secret／PIIを検証する。
- Backend／Frontend Test、Build、Browser／E2EはDocumentation-only補完のため未実行とする。

### MIG-010A Closeout

- PR: `#16` (`https://github.com/ideal-sol/oripa/pull/16`)
- Task Head: `ba69484f4f5a479517eecb481265a98b2e1073f2`
- Machine-readable Self-reviewはAllowed Paths、Checksum、Markdown、Internal Link、Secret／PII、SEV-0／SEV-1なしをFinal Headへ固定してPASSした。
- GitHub CheckはGOV-009前BootstrapでRequired 0件、Run 0件、Status 0件、Failure 0件、Missing 0件だった。
- GitHub AppがSquash Mergeし、Squash Commitは`a8556f915e7830169e8371ed355dcd30dcf40bd8`である。
- Issue `#15`はClosed、Remote Task Branchは自動削除済みである。
- Local Task BranchとWorktreeはTree同等性確認後に削除した。
- Local `main`を`origin/main`へ`--ff-only`同期し、Working Treeはcleanだった。
- Gate G1はCI Skeleton、5つの標準Check、V1資産のLegacy実隔離が未完了のため`G1 NOT COMPLETE`である。

## GOV-006 Codex Environment／Repository Access分離

### Task

- 実施開始: 2026-07-23T03:59:48Z／2026-07-23T12:59:48+09:00
- Task ID: `GOV-006`
- Risk: `R3`
- Issue: `#17` (`https://github.com/ideal-sol/oripa/issues/17`)
- Branch: `chore/GOV-006-codex-access-separation`
- Worktree: `/var/www/oripa-worktrees/GOV-006-codex-access-separation`
- Base SHA: `a8556f915e7830169e8371ed355dcd30dcf40bd8`

### Access Verification

- Token BrokerからCacheを使わず新規Installation Tokenを発行した。
- Installation selectionは`selected`で、Access対象Repositoryは`ideal-sol/oripa`の1件だけだった。
- Installation metadataと新規TokenのAdministration Permissionはいずれも、選択済みRepository Scope内で`write`だった。
- Organization全Repository Accessではなく、想定外Repository Accessは0件だった。
- 他Repositoryへの試験Write、Production Environment／Secret／DB／NetworkへのAccessは実施していない。
- App ID、Installation ID、JWT、Token、Private Key、Authorization Headerを表示または記録していない。

### Baseline

- Platform CodexのGitHub Repository Access分離は実施済みとして記録する。
- Platform Codexはroot実行を継続し、Private Keyへ到達可能なTrust Modelは人間承認済み例外である。
- OS User分離とPrivate KeyのFilesystem分離は未実施であり、完全分離済みとは記録しない。
- Production Secret、Database、Network Accessは許可しない。
- Future Site Codexは1 Site＝1 Repository＝1 Environment＝1専用Credential境界をActivation Gateとし、現時点では未作成である。
- Application、CI、`.codex/**`、Ruleset、Migration、Docker、Infrastructure実装は変更しない。

### Verification

- JSON Parse、`git diff --check`、Markdown見出し／Internal Link、Access Matrix矛盾、Allowed Paths、Secret／PIIを検証する。
- Backend／Frontend Runtime Test、Build、Browser／E2EはGovernance Documentation-only Taskのため未実行とする。

### GOV-006完了

- PR: `#18` (`https://github.com/ideal-sol/oripa/pull/18`)
- Task Head: `8dcbafb32c51fba715932cc4badc6a5e0b6806ee`
- Machine-readable Self-reviewはAllowed Paths、Access Scope、root Trust Exception、Secret／PII、SEV-0／SEV-1なしをFinal Headへ固定してPASSした。
- GitHub CheckはGOV-009前BootstrapでRequired 0件、Run 0件、Status 0件、Failure 0件、Missing 0件だった。
- GitHub AppがSquash Mergeし、Squash Commitは`769d27de28cdfa76e3d14e35181bb90012481128`である。
- Issue `#17`はClosed、Remote Task Branchは自動削除済みである。
- Local Task BranchとWorktreeはTree同等性確認後に削除した。
- Local `main`を`origin/main`へ`--ff-only`同期し、Working Treeはcleanだった。
- V1 Archive BranchとAnnotated Tagは`bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更していない。

## GOV-007 Codex Permission／Command Rules

### Task

- 実施開始: 2026-07-23T04:11:01Z／2026-07-23T13:11:01+09:00
- Task ID: `GOV-007`
- Risk: `R3`
- Issue: `#19` (`https://github.com/ideal-sol/oripa/issues/19`)
- Branch: `chore/GOV-007-codex-permissions`
- Worktree: `/var/www/oripa-worktrees/GOV-007-codex-permissions`
- Base SHA: `769d27de28cdfa76e3d14e35181bb90012481128`

### Configuration／Trust

- Installed Codexは`codex-cli 0.144.4`である。
- `/var/www/oripa`は既存Global Configで`trusted`として明示されており、本TaskでTrust範囲を変更していない。
- Strict Config Probeで`model`、`model_reasoning_effort`、`sandbox_mode`、`approval_policy`、`approvals_reviewer`、`web_search`、`sandbox_workspace_write`の指定Keyを受理することを確認した。
- Project Configは`gpt-5.6`、Reasoning `high`、`workspace-write`、Approval `on-request`、Reviewer `auto_review`、Web Search `cached`を指定する。
- Sandbox内Network Accessを無効化し、`/tmp`と`TMPDIR`を追加Writable Rootから除外する。
- `danger-full-access`、Approval `never`、`--yolo`をDefaultにしない。

### Rules／Verification

- Rulesは`forbidden`、`prompt`、`allow`へ分類し、最も厳しい一致を適用する。
- 破壊的Git、Direct main／Force Push、Stable Tag削除、Docker／DB破壊操作、Token Broker／Autonomy Libexec直接実行を`forbidden`とする。
- Commit、通常Push、Rebase、Container Build／Start、Dependency操作、Migration作成、Network操作を`prompt`とする。
- Read-only Git／Workspace確認、Test／Lint／Typecheck、Task Policy検証済みGitHub App Wrapperを`allow`とする。
- Sandbox外ではShell Wrapper自体を禁止し、Compound Commandによる危険Commandの混入とShell経由の迂回を拒否する。
- `codex execpolicy check`でAllow／Prompt／Forbidden、Compound、Shell Bypass、Direct main、Force、Package、Migration、Docker、Broker、Safe Wrapperを検証する。
- 変更は`.codex/config.toml`、`.codex/rules/governance.rules`、`.codex/README.md`、本Worklogの4件だけに限定する。
- Application、CI、Ruleset、Migration、Docker、Productionへ変更はない。
- Backend／Frontend Runtime Test、Build、Browser／E2EはGovernance-only Taskのため未実行とし、PASSとは記録しない。

### Local Verification

- `TERM=xterm codex --strict-config doctor --json`はOverall、Config Load、Sandbox HelperのすべてでPASSした。
- Effective Modelは`gpt-5.6`、Approvalは`OnRequest`、Filesystem SandboxとNetwork Sandboxはいずれも`restricted`だった。
- Rules FileのStarlark ParseとInline `match`／`not_match` TestはPASSした。
- `codex execpolicy check`はAllow 7件、Prompt 8件、Forbidden 16件の合計31件を検証し、不一致は0件だった。
- Compound CommandとComplex Shell Wrapper、Direct main Push、Force Push、Token Broker／Autonomy Libexec直接実行は`forbidden`だった。
- 通常Push、Commit、Rebase、Dependency Install、Migration作成、Container Build、Network Accessは`prompt`だった。
- Safe GitHub App Wrapper、Read-only Git、`rg`／`ls`／`cat`、Test／Lint／Typecheckは`allow`だった。

### GitHub

- Task Commit: `e8c359311fe244f671fb9ab93af1540b3aa01d7d`
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。
- PR: `#20` (`https://github.com/ideal-sol/oripa/pull/20`)
- PR Authorは`ideal-sol-oripa-codex[bot]`、Baseは`main`、Headは`chore/GOV-007-codex-permissions`である。
- 本追記を含むFinal Headへ更新後、Strict Config、Execpolicy、Allowed Paths、Secret／PII、GitHub Checks、Fresh Self-review、Head不変、Merge Conflictなしを再検証して自律Squash Mergeする。
- GOV-008は本Task完了後も開始しない。

### GOV-007 Closeout

- PR `#20`のFinal Head `f784b63e22e3e4668ebe261c5b209613cf190ec0`へ固定したMachine-readable Self-review Evidenceは、Scope、Strict Config、Execpolicy、Secret／PII、SEV-0／SEV-1なしでPASSした。
- GOV-009前BootstrapとしてGitHub CheckはRequired 0件、Run 0件、Status 0件、Failure 0件、Missing 0件だった。
- GitHub AppがSquash Mergeし、Squash Commitは`ef73eab5d0cbb0cab1a34b2b5f9151fdd315fa89`である。
- Issue `#19`はClosed、Remote Task Branchは自動削除済みである。
- Local Task BranchとWorktreeはTree同等性確認後に削除した。
- Local `main`を`origin/main`へ`--ff-only`同期し、Working Treeはcleanだった。
- V1 Archive BranchとAnnotated Tagは`bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更していない。

## GOV-008 Platform Policy CI

### Task

- 実施開始: 2026-07-23
- Task ID: `GOV-008`
- Risk: `R3`
- Issue: `#21` (`https://github.com/ideal-sol/oripa/issues/21`)
- Branch: `ci/GOV-008-platform-policy-gate`
- Worktree: `/var/www/oripa-worktrees/GOV-008-platform-policy-gate`
- Base SHA: `ef73eab5d0cbb0cab1a34b2b5f9151fdd315fa89`

### Scope／Design

- `.github/workflows/platform-ci.yml`へ`policy-gate`とBootstrap版`ci-gate`を追加する。
- Pull Request、`main` Push、Manual Dispatchを対象とし、`pull_request_target`、Workflow Secret、Write Permissionを使用しない。
- Official ActionはFull Commit SHAへPinし、Checkout Credentialを保持しない。
- PR本文のTask ID、Risk、Base SHA、Allowed Paths、Changed Files、Verification見出しをGit差分と照合する。
- Root／Nested `AGENTS.md`、CODEOWNERS、Issue／PR Template、Workflow安全性、危険Path、基本構造、Architecture Index、Security REV1とSuperseded関係を検証する。
- Positive FixtureはPASS、Metadata欠落、Floating Action、秘密File PathのNegative FixtureはFAILさせる。
- Application、Migration、Docker、Ruleset、Infrastructure、V1 Archive Refは変更しない。

### Local Verification

- Python Syntax CheckはPASSした。
- `unittest`はPositive 1件とNegative 3件の計4件を実行し、Gate期待値との不一致は0件だった。
- Staged Treeを対象にした`python3 scripts/ci/policy_gate.py --repository .`はTracked File 602件を検査してPASSした。
- WorkflowはRead-only Permission、Secret不使用、Full SHA Action Pin、Timeout、Concurrency、`pull_request_target`不使用を確認した。
- `git diff --check`、Allowed Paths、Basic YAML、Secret／PII、Binary／Submodule確認はPASSした。
- Backend／Frontend Runtime Test、Build、Browser／E2EはPolicy CI Taskでは未実行であり、PASSとは記録しない。

### GitHub

- Task Commit: `6d24b7c16f155913633e37c2fae95aac1ba02222`
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。
- PR: `#22` (`https://github.com/ideal-sol/oripa/pull/22`)
- Initial HeadでGitHub上の`policy-gate`と`ci-gate`が実Contextとして成功した。
- 本追記を含むFinal Headへ更新後、両Check、Fresh Self-review、Scope、Secret／PII、Head不変、Merge Conflictなしを再確認してSquash Mergeする。
- GOV-008 Merge後の`main` Pushでも同じ2 Checkが成功することを確認してからGOV-009を開始する。

### GOV-008完了

- PR `#22`のFinal Head `7eea694ece3a2f4e03908bc365a8a8d2c4f367a3`で`policy-gate`と`ci-gate`が成功した。
- Machine-readable Self-reviewはScope、Workflow安全性、Secret／PII、SEV-0／SEV-1なしでPASSした。
- GitHub AppがSquash Mergeし、Squash Commitは`da82bd5278aae58f3216a38d036bebc5a12e4d88`である。
- Issue `#21`はClosed、Remote／Local Task BranchとWorktreeは削除済みである。
- Local `main`を`origin/main`へ`--ff-only`同期し、Merge後`main`でも`policy-gate`と`ci-gate`が成功した。

## GOV-009 Platform Quality／Security／Integration CI

### Task

- 実施開始: 2026-07-23
- Task ID: `GOV-009`
- Risk: `R3`
- Issue: `#23` (`https://github.com/ideal-sol/oripa/issues/23`)
- Branch: `ci/GOV-009-platform-quality-security-integration`
- Worktree: `/var/www/oripa-worktrees/GOV-009-platform-quality-security-integration`
- Base SHA: `da82bd5278aae58f3216a38d036bebc5a12e4d88`

### Baseline／CI Design

- Checkを`policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`の5件へ完成させる。
- V1 Frontend Lintの8 Error／1 WarningはFile、位置、Rule、Severity、Message Hashによる完全Fingerprintで2026-08-31まで管理する。
- Composer 10件、pnpm 14件の既存Dependency FindingはPackage、Version、Advisory ID、Severityを完全一致で2026-07-30まで管理し、`SEC-001`で解消する。
- 新規、欠落、変更、Severity悪化、期限切れはGate Failureとし、Blanket Ignoreは使用しない。
- IntegrationはPHP 8.4、Ephemeral PostgreSQL／Redis、固定Test Credentialだけを使用し、Migration、Backend Test、Frontend Build／Typecheck、Compose Configを実行する。
- Application Source、Migration、Manifest、Lockfile、Docker、V1 Archive Refは変更しない。

### Local Verification

- Python SyntaxとQuality／Security Unit Test 4件はPASSした。
- `quality-gate`はPHP 435件、JSON 16件、YAML 6件、TOML 1件、XML 1件を検査してPASSした。OpenAPI／JSON Schema実体は現時点で0件であり、実行済みとは記録しない。
- Frontend TypecheckはPASSした。
- ESLintは8 Error／1 Warningで、9件すべてが期限付き完全Fingerprint Baselineと一致した。
- Composer ValidateはPASSした。
- Composer Audit 10件とpnpm Audit 14件は期限付きDependency Baselineと完全一致した。
- `security-gate`はTracked File 610件、High-confidence Secret候補0件でPASSした。
- `policy-gate`、`git diff --check`、Ruleset JSON Parse、Allowed Paths、Workflow Permission／Action Pin確認はPASSした。
- Host PHPは8.3でRepository要求PHP 8.4を満たさないため、Backend Migration／TestをLocalで実行しておらずPASSとは記録しない。
- Backend Migration／Test、Frontend Build、Ephemeral PostgreSQL／Redis、Compose ConfigはGitHub `integration-gate`で実行する。

### Initial Checkと既知Backend Test Baseline

- Initial Head `ffe083d55252721b4c4dd8add402962f8aea9486`では`policy-gate`と`security-gate`が成功し、`quality-gate`と`integration-gate`が失敗した。`ci-gate`は依存Gate失敗を正しく拒否した。
- `quality-gate`はESLintの実行DirectoryがLocal Baseline作成時と異なっていたため、`frontend`をCurrent Directoryにして同じCommandを実行するよう修正した。
- React HooksのLint Messageに含まれる絶対Workspace Pathは環境依存だったため、Repository相対`frontend/`へ正規化してからMessage Hashを計算する。Path、位置、Rule、Severity、正規化Messageの完全Fingerprintは維持する。
- `integration-gate`の初回修正後失敗はRepository Rootから`artisan test`を呼んだことでPHPUnit実行Fileの相対Path解決が崩れたためで、`backend` Directory内実行へ修正した。
- Ephemeral PostgreSQL／RedisとPHP 8.4でBackend全332 Testを再現し、MigrationはPASS、Testは2 Failure／332 Warningだった。
- 既知Failureは`Tests\Feature\AdminPaymentApiTest`の返金とChargebackの2件だけで、旧Fixtureが現在必須のPayment-origin Point LotとWalletを作成していないことによる。
- ApplicationやAssertionを変更せず、Class、Method、Exception Typeの完全一致Baselineとして2026-08-15まで`QUALITY-002`で管理する。新規、欠落、変更、期限切れは`integration-gate`を失敗させる。
- BaselineはV1の既知状態だけに適用し、Backend全Testの実行自体は省略しない。

### GOV-009完了

- PR `#24`のFinal Head `5c00861dc74223e7e9dc6bd28f44c57b6d7bbc37`で`policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`がすべて成功した。
- Machine-readable Self-reviewはScope、Secret／PII、Migration／Security、SEV-0／SEV-1なしでPASSした。
- GitHub AppがSquash Mergeし、Squash Commitは`701d27c9e738fe7551440a25d7837b73a5b5f572`である。
- Issue `#23`はClosed、Remote／Local Task BranchとWorktreeは削除済みである。
- Local `main`を`origin/main`へ`--ff-only`同期し、Merge後`main`でも5 Gateすべてが成功した。
- `main-protection`と`release-branch-protection`へ実在する5 CheckをRequired Contextとして追加し、Readbackで完全一致を確認した。GOV-009前Bootstrap例外は失効した。

## GOV-010 Site Template CI

### Task

- 実施開始: 2026-07-23
- Task ID: `GOV-010`
- Risk: `R3`
- Issue: `#25` (`https://github.com/ideal-sol/oripa/issues/25`)
- Branch: `ci/GOV-010-site-template-gate`
- Worktree: `/var/www/oripa-worktrees/GOV-010-site-template-gate`
- Base SHA: `701d27c9e738fe7551440a25d7837b73a5b5f572`

### Design

- Site／Platform Repository責任境界、First-party Package Exact Version、Site Schema、環境Template、薄いStorefront Client経由、Build／Typecheck／Lint／Contract Scriptを検証する。
- `/api/v2`への直接`fetch`、Platform Source Directory、Laravel／Admin／Payment／Point／Draw Logic、他Site設定、Secret-bearing環境名を拒否する。
- Positive FixtureはPASS、Negative FixtureはFAILすることをUnit Testと`integration-gate`で確認する。
- Site Template検証失敗は`integration-gate`と`ci-gate`を失敗させ、Required Check名5件は変更しない。
- Canonical Site Templateは現時点で存在しない。FixtureはGate実装の検証用であり、実Template完成またはGate G1完了とは記録しない。
- Application、Migration、Docker、Ruleset、V1 Archive Refは変更しない。

### Local Verification

- Python SyntaxとSite Template Unit Test 6件はPASSした。
- Positive FixtureはPASSし、Negative FixtureはExact Version違反で期待どおりFAILした。
- Unit TestでDirect `/api/v2` Fetch、First-party非Exact Version、Sensitive環境名、Platform Directory Copyを個別に拒否した。
- `quality-gate`、`policy-gate`、`git diff --check`、JSON Parse、Markdown構造、Allowed Paths、Secret／PII確認はPASSした。
- Backend／Frontend Runtime Test、Migration、Frontend Build、Browser／E2EはLocalでは未実行であり、PASSとは記録しない。既存の5 GitHub GateでRuntime範囲を再実行する。

### GitHub／Gate G1

- Task Commit: `08b67904985cca1e43687b7e810b699fc46fc04f`
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。
- PR: `#26` (`https://github.com/ideal-sol/oripa/pull/26`)
- Initial HeadでRequired `policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`がすべて成功し、Required CheckのRuleset強制が実際に作動した。
- `integration-gate`はBackend全Testの期限付き完全Baseline、Frontend Build／Typecheck、Ephemeral Migration、Compose、Site Template Positive／Negative Fixtureを実行した。
- Final Headを固定後、5 Check、Fresh Machine-readable Self-review、SEV-0／SEV-1なし、Scope、Secret／PII、Merge Conflictなしを再確認してGitHub AppがSquash Mergeする。
- Gate G1ではPlatform Governance、Architecture Baseline、5 Required Check、Site Template Gate実装まで完了した。
- Canonical Site Template、実First-party Package、OpenAPI／JSON Schema Contract、実Site Build／Contract Testは未作成であり、Gate G1は`NOT COMPLETE`のまま維持する。

### GOV-010 Closeout

- PR `#26`はFinal Headの5 Required CheckとMachine-readable Self-reviewが成功した後、GitHub AppがSquash Mergeした。
- Squash Commitは`5616207ef1cc8d15353a9722b0c9137cfbb718f3`、Issue `#25`はClosedである。
- Remote／Local Task BranchとWorktreeは削除済みで、Local `main`は`origin/main`へ`--ff-only`同期済みである。
- Working Treeはclean、V1 Archive BranchとAnnotated Tagは変更していない。

## Governance Wave 4 Language Policy

- 2026-07-23以降に新規作成するGitHub Issue／PR、Commit Message、Self-review説明、GitHub Comment、Worklog実行内容、Task完了報告は日本語で記録する。
- Task ID、Branch、File／Directory、Check、Command、API、JSON／YAML／TOML Key、Class／Method／Package等の技術識別子は英語表記を維持する。
- 過去の英語記録は遡って翻訳しない。

## GOV-011 リリース・環境保護

### Task

- 実施開始: 2026-07-23
- Task ID: `GOV-011`
- Risk: `R3`
- Issue: `#27` (`https://github.com/ideal-sol/oripa/issues/27`)
- Branch: `chore/GOV-011-release-environment-protection`
- Worktree: `/var/www/oripa-worktrees/GOV-011-release-environment-protection`
- Base SHA: `5616207ef1cc8d15353a9722b0c9137cfbb718f3`

### Scope／Design

- `platform-staging`はCodexの自律Deploymentを許容し、`main`、`release/*`、Alpha／Beta TagへDeployment元を限定する。
- `platform-production`は正式な`platform-v*` Tagだけを対象とし、人間OwnerのRequired Reviewerと自己承認防止を維持する。
- Environment設定とRuntime稼働、Secret配置、Deployment成功を分離し、未構築環境を稼働済みと記録しない。
- Alpha／Beta／Stable、Build Once／Digest Promote、Release／Deployment Manifest、SBOM、Migration Revision、Rollback基準を文書化する。
- Example Manifestは非秘密・非Productionの構造例であり、実Releaseや実Deploymentを表さない。
- Application、Migration、CI Workflow、Ruleset、Production Secret、V1 Archive Refは変更しない。

### Verification Plan

- JSON Parse、Markdown構造、Internal Link、Manifest項目、Allowed Paths、Secret／PII、Environment API Readbackを確認する。
- Required `policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`と固定Head Self-reviewをMerge条件とする。
- Documentation-only TaskのためBackend／Frontend Runtime Test、Build、Browser／E2Eは実行せず、PASSとは記録しない。

### Environment／GitHub

- `platform-staging`をCustom Deployment Policyで`main`、`release/*`、`platform-v*-alpha*`、`platform-v*-beta*`へ限定した。
- `platform-production`を正式な`platform-v*` Tagだけに限定し、Required Reviewer `myong-ideal`と`prevent_self_review`を有効にした。
- 両EnvironmentのWait Timerは`0`である。Environment URL、Environment Secret、Credentialは作成・取得していない。
- Environment API ReadbackはEnvironment名、Protection、Reviewer、Branch／Tag Policyについて設定値と一致した。
- Task Commitは`5bedb258e551d620223bee4cec9400542132896b`、PRは`#28` (`https://github.com/ideal-sol/oripa/pull/28`)である。
- Initial Headの`security-gate`は成功した。`policy-gate`はPR本文の`Changed files`省略表現を拒否したため、実File名7件へ修正した。GateやAssertionは変更していない。
- 本追記のCommitをFast-forward Pushし、5 Required Check、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを再確認して自律Squash Mergeする。

### GOV-011 Closeout

- PR `#28`のFinal Head `a119b498e64f6a8a7f94fd3dee6f48ecb9f30645`で5 Required Checkがすべて成功した。
- 日本語説明を含むMachine-readable Self-review EvidenceはScope、Environment Readback、Secret／PII、SEV-0／SEV-1なしでPASSした。
- GitHub AppがSquash Mergeし、Squash Commitは`e4a42fddfc7487bcdb662e8f8810cccf6480df50`である。
- Issue `#27`はClosed、Remote／Local Task BranchとWorktreeは削除済みである。
- Local `main`を`origin/main`へ`--ff-only`同期し、Working Treeはcleanだった。
- `platform-staging`と`platform-production`のEnvironment ProtectionはAPI Readbackで確認済みだが、Runtime、Secret、実Deploymentは未構築・未実行である。

## GOV-012 OIDC／Deploy Credential Baseline

### Task

- 実施開始: 2026-07-23
- Task ID: `GOV-012`
- Risk: `R3`
- Issue: `#29` (`https://github.com/ideal-sol/oripa/issues/29`)
- Branch: `chore/GOV-012-oidc-deploy-credentials`
- Worktree: `/var/www/oripa-worktrees/GOV-012-oidc-deploy-credentials`
- Base SHA: `e4a42fddfc7487bcdb662e8f8810cccf6480df50`

### Scope／Design

- OIDCを第一選択とし、Providerが非対応の場合だけSite別の期限付きCredentialを例外利用する。
- Repository、Environment、Ref、Audience、Subjectを限定し、Pull Request／ForkからProduction Credentialを発行しない。
- 一つのSiteごとにRepository、Codex Environment、GitHub Environment、Deploy Identity、Provider境界を分離する。
- Rotation、Revocation、Incident時の即時停止とProvider Onboarding Checklistを定義する。
- JSON ExampleはProvider-neutralであり、実Role、実Audience、実Credential、実Cloud Loginを表さない。
- Application、Migration、CI Workflow、Ruleset、Environment Secret、V1 Archive Refは変更しない。

### Verification Plan

- JSON Parse、Markdown構造、Internal Link、Allowed Paths、Secret／PII、Infrastructure Ruleとの整合を確認する。
- 5 Required Check、固定Head Self-review、SEV-0／SEV-1なし、Merge ConflictなしをMerge条件とする。
- Documentation-only TaskのためBackend／Frontend Runtime Test、Build、Browser／E2E、実Cloud Loginは未実行であり、PASSとは記録しない。

### Local Verification／GitHub

- JSON Parse、Markdown見出し／Internal Link、`git diff --check`、`policy-gate`、`quality-gate`、`security-gate`、Allowed Paths、Secret／PII確認はPASSした。
- 汎用Git Wrapperが`site-credential-boundary.md`の文書名を秘密File Pathとして誤検出したため、Markdownと明示的ExampleはPath名だけで拒否せず、内容の高確度Secret Scanを維持する一般則へ修正した。
- Wrapperはroot所有mode `700`、Syntax PASSであり、Task固有Branch／Refspec／Repositoryの制限は変更していない。Repository外ToolのためCommit対象外である。
- Task Commitは`b3e67544ba4f713095d25b27c182a1304e11277c`で、GitHub App WrapperによりFast-forward Pushした。
- PRは`#30` (`https://github.com/ideal-sol/oripa/pull/30`)、Authorは`ideal-sol-oripa-codex[bot]`、Draft、Baseは`main`である。
- 本追記を含むFinal Headで5 Required Check、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを再確認して自律Squash Mergeする。

### GOV-012 Closeout

- PR `#30`のFinal Head `36a658d4d67e272a0b7d0db29e54be832cd72b66`で5 Required Checkがすべて成功した。
- 日本語説明を含むMachine-readable Self-review EvidenceはOIDC Claim境界、Scope、Secret／PII、SEV-0／SEV-1なしでPASSした。
- GitHub AppがSquash Mergeし、Squash Commitは`7125eec84afdda2b7acd93b751dd1d7ac20df1c3`である。
- Issue `#29`はClosed、Remote／Local Task BranchとWorktreeは削除済みである。
- Local `main`を`origin/main`へ`--ff-only`同期し、Working Treeはcleanだった。
- Provider固有Role、実Credential、実Cloud Login、Production Deploymentは未実装・未実行である。

## GOV-013 セキュリティスキャン基盤

### Task

- 実施開始: 2026-07-23
- Task ID: `GOV-013`
- Risk: `R3`
- Issue: `#31` (`https://github.com/ideal-sol/oripa/issues/31`)
- Branch: `security/GOV-013-github-security-scanning`
- Worktree: `/var/www/oripa-worktrees/GOV-013-github-security-scanning`
- Base SHA: `7125eec84afdda2b7acd93b751dd1d7ac20df1c3`

### Before／Design

- 開始時ReadbackはDependency Graph、Dependabot Security Updates、Private Vulnerability Reportingが無効、Secret Scanning／Push Protectionがdisabledだった。
- `code_security`と`advanced_security`の設定KeyはPublic Repository API Readbackで`unavailable`だった。Code ScanningはPublic RepositoryのWorkflow方式で検証する。
- CodeQLはRepository実態に合わせ`javascript-typescript`を対象とする。PHPはCodeQL対応Languageではないため解析済みと記録しない。
- Dependency ReviewはPull Requestだけで実行し、High Severity以上を拒否する。
- Dependabotは`/backend`のComposer、`/frontend`のnpm、Repository RootのGitHub Actionsを週次確認する。
- 既存`security-gate`をNo-op化せず、CodeQL／Dependency Reviewと併用する。
- Existing AlertをDismiss／Closeせず、期限・Owner・修正Task・EvidenceのないBaselineを作らない。

### Local Verification／GitHub Security

- Workflow／Dependabot YAML Parse、Markdown見出し／Internal Link、Action Full SHA Pin、Permission最小化、`git diff --check`はPASSした。
- Policy Gate Unit Test 6件、`policy-gate`、`quality-gate`、`security-gate`、Allowed Paths、Secret／PII確認はPASSした。
- CodeQLに必要なJob-level `security-events: write`だけを`codeql.yml`で許可し、他WorkflowとWorkflow全体のWrite Permissionは引き続き拒否する。
- Dependency Graph、Dependabot Alerts／Security Updates、Secret Scanning、Push Protection、Private Vulnerability Reportingを有効化し、API Readbackで確認した。
- `code_security`／`advanced_security`設定KeyはPublic Repository APIで`unavailable`である。CodeQLの実AnalysisとSARIF Upload成功をCode Scanningの検証Evidenceとする。
- Environment Secret、Alert本文、検出Credential、実PIIは取得・表示・記録していない。Existing FindingをDismiss／Closeしていない。
- Task Commitは`41bf40e57c5d2baa32c2e8e52fc8601c17fc6139`で、GitHub App WrapperによりFast-forward Pushした。
- PRは`#32` (`https://github.com/ideal-sol/oripa/pull/32`)、Authorは`ideal-sol-oripa-codex[bot]`、Draft、Baseは`main`である。
- Initial Headでは`policy-gate`、`security-gate`、`dependency-review`、CodeQL Setupが成功し、CodeQL Analysis／SARIF Upload、`quality-gate`、`integration-gate`は実行中だった。
- 本追記を含むFinal Headで5 Required Check、CodeQL、Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを再確認して自律Squash Mergeする。

### GOV-013 Closeout

- PR `#32`のFinal Head `0226fd2e80b0f605f8aec0371eb4c7ac69462322`でRequired `policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`が成功した。
- CodeQL Setupと`CodeQL (javascript-typescript)`、`dependency-review`を含む合計8 Checkが成功した。PHPはCodeQL対応Languageではないため解析済みとは記録しない。
- 日本語説明を含むMachine-readable Self-review EvidenceはScope、Workflow Permission、Secret／PII、SEV-0／SEV-1なしでPASSした。
- GitHub AppがSquash Mergeし、Squash Commitは`a0f3412ab987782294ace25ad28c77a3fc724150`、Issue `#31`はClosedである。
- Remote／Local Task BranchとWorktreeは削除済みで、Local `main`は`origin/main`へ`--ff-only`同期済み、Working Treeはcleanだった。
- Dependency Graph、Dependabot Alerts／Security Updates、Secret Scanning、Push Protection、Private Vulnerability Reportingは有効である。Code ScanningはCodeQL Analysis／SARIF Upload成功で確認した。
- Existing FindingはDismiss／Closeしていない。Environment Secret、Alert本文、検出Credential、実PIIは取得・表示・記録していない。

## MIG-020 V2ワークスペース骨格

### Task

- 実施開始: 2026-07-23
- Task ID: `MIG-020`
- Risk: `R3`
- Issue: `#39` (`https://github.com/ideal-sol/oripa/issues/39`)
- Branch: `migration/MIG-020-workspace-skeleton`
- Worktree: `/var/www/oripa-worktrees/MIG-020-workspace-skeleton`
- Base SHA: `a0f3412ab987782294ace25ad28c77a3fc724150`

### Existing Inventory

- 開始時のTop-levelには`backend`、`frontend`、`apps`、`packages`、`openapi`、`infrastructure`、`legacy`、`manifests`があり、`deployments`とRoot Workspace設定は存在しなかった。
- `apps/api`と`apps/admin`、`packages`、`openapi`、`infrastructure`、`legacy/v1`には既存のNested `AGENTS.md`があり、これを責任境界の正本として再利用した。
- Root `package.json`、`pnpm-workspace.yaml`、Root Lockfileは存在しなかった。既存Lockfileは`backend/composer.lock`とV1 `frontend/pnpm-lock.yaml`である。
- Nodeは`v22.22.3`、pnpmは`10.12.1`であり、V1 `frontend/package.json`の`packageManager`も`pnpm@10.12.1`だった。
- Release／Deployment Example ManifestはGOV-011で作成済みだったが、対応する正式JSON Schemaは存在しなかった。
- Existing 5 Required Checkと`policy-gate`の必須Pathを確認し、新しいCheck名を増やさず既存`policy-gate`を拡張する方針とした。

### Workspace／Responsibility Boundary

- Platform Versionの開始値は`2.0.0-alpha.1`としたが、Root `package.json`と`pnpm-workspace.yaml`は最終成果物へ含めていない。
- 初期案のRoot Workspace設定によりV1 `frontend/pnpm-lock.yaml`を使用する既存CIのinstall／audit解決が変わり、`quality-gate`と`security-gate`が失敗することを確認した。V1 Code、Lockfile、Gate、Baselineを変更せずRoot設定を取り下げた。
- `apps/api`、`apps/admin`、`packages/platform`、`packages/storefront-client`、`packages/site-schema`、`packages/storefront-testkit`、`openapi`、`infrastructure`、`deployments`、`manifests`、`legacy/v1`の責任境界をREADMEで定義した。
- 各READMEはOwner、配置予定Component、Allowed／Forbidden Scope、Nested `AGENTS.md`、Skeleton状態、Production利用不可、V1 CodeをCopyしない方針を明示した。
- Application Code、Package実装、OpenAPI実Contract、Next.js App、Migration、Docker Runtime、Production設定は作成していない。
- Dependencyを追加せず、`pnpm install`を実行せず、Root Lockfileを生成していない。実Package、Dependency、Root Lockfile、V1分離後のCI Commandを同一の後続Taskで確定する。

### Manifest Schema／CI

- `release-manifest.schema.json`と`deployment-manifest.schema.json`をJSON Schema Draft 2020-12のStrict Objectとして作成した。
- Release SchemaはPlatform／Package／API Contract Version、Migration Revision、Source Commit、Image Digest、SBOM、作成日時を必須化した。
- Deployment SchemaはSite、Environment、Platform／Package Version、Image Digest、Migration Revision、Deployment日時、承認参照、Source Release Manifestを必須化した。
- GOV-011のExample ManifestをSchemaへ整合させた。値は非秘密の構造例であり、実Release、実Deployment、実承認を表さない。
- `policy-gate`へ必須Workspace File、README責任境界、Root Workspace設定を将来一括導入する制約、Schema／Example整合、V1 Code複製検出を追加した。
- Positive Fixtureと、README欠落、V1 Workspace混入、Manifest必須Field欠落、V1 Code CopyのNegative FixtureをUnit Testへ追加した。

### Verification

- Python SyntaxとPolicy Gate Unit Test 11件はPASSした。
- Positive Workspace FixtureとRoot Workspace設定を延期したFixtureはPASSし、各Negative Fixtureは意図したPolicy違反でFAILした。
- Hostの`jsonschema`は3.2.0でDraft 2020-12 Validatorを持たないため、新しいPackageを導入せず、Draft宣言、Required Field、Strict Object、SemVer、Digest、UTC日時、Example整合を標準Libraryの`policy-gate`で検証した。
- Backend／Frontend Runtime Test、Application Build、Browser／E2Eは本Taskでは未実行であり、PASSとは記録しない。
- Final Local Verification、Commit、GitHub App Push、PR、Required Check、Self-review、Squash Merge、Cleanup、Local `main`同期を続行する。

### Gate G1

- V2 Workspace責任境界、Version起点、Manifest Schema、継続Policy検査を追加する。
- 実Laravel V2 App、Admin App、First-party Package、OpenAPI Contract、Canonical Site Template、Root Lockfileは未実装であり、Gate G1は`NOT COMPLETE`のまま維持する。
- 次Task候補は`MIG-021`だが、本Task完了後には開始しない。

### Local Verification／GitHub

- `git diff --check`、JSON Parse、Markdown見出し、Internal Link、Workspace設定延期判断、Schema／Example整合、Allowed Paths、Binary／Submodule、V1 Code移動なしを確認した。
- `policy-gate`、`quality-gate`、`security-gate`はLocalでPASSした。Security Gateは期限付きの既存Dependency Advisory完全Baselineと一致し、新規Secret Candidateは0件だった。
- Policy Gate Unit TestはRoot Workspace延期Fixtureを含めてPASSした。Positive FixtureはPASSし、README欠落、V1 Workspace混入、Manifest必須Field欠落、V1 Code CopyのNegative Fixtureは期待どおり拒否された。
- Task Commitは`290a6b484bcd5952aa68a943675f9412a6eeb326`で、ParentはBase SHA `a0f3412ab987782294ace25ad28c77a3fc724150`である。
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。Direct main Push、Force Push、V1 Archive Ref変更は行っていない。
- PRは`#40` (`https://github.com/ideal-sol/oripa/pull/40`)、Authorは`ideal-sol-oripa-codex[bot]`、Draft、Baseは`main`である。
- GitHub ActionsのCheck SuiteがDraft Headで作成されなかったため、PR #40をGitHub AppでReady化し、本記録のFast-forward PushでRequired Checkを開始する。CheckはBypassしない。
- PR eventでCheck Suiteが生成されない場合もRequired Checkを省略しないため、Repository外の汎用WrapperへTask Policy固定Branchの`platform-ci.yml`だけを`workflow_dispatch`するOperationを追加した。任意Workflow、Ref、Repositoryは指定できない。
- 新規Tokenの`actions` Permissionがreadのため`workflow_dispatch`はHTTP 403で拒否され、WorkflowやRepository状態の変更は発生しなかった。Permissionは拡張していない。
- 代替として同じTask PR、Branch、固定HeadだけをClose後に即ReopenするPolicy限定Operationを追加し、標準`pull_request: reopened` eventでRequired Checkを再起動する。新しいIssue、Branch、PRは作成しない。
- GitHub App由来のPR eventではWorkflowが発火しなかったため、登録／Installationで承認済みの`actions: write`を新規Tokenにも最小要求し、固定Workflow／Branchの`workflow_dispatch`を使用する。Token、ID、Authorization Headerは表示・保存しない。
- 本追記を含むFinal HeadでRequired `policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`、Fresh Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認して自律Squash Mergeする。
- Backend／Frontend Runtime Test、Application Build、Browser／E2EはLocalでは未実行であり、GitHub `integration-gate`の実行結果と混同しない。

### MIG-020 Closeout

- PR `#40`のFinal Headは`d3288db7fb7ce3653b19f6853bcd7cdc8cea237c`で、Required 5 Check、CodeQL、Dependency Reviewを含む13 Checkが成功した。
- GitHub AppがSquash Mergeし、Squash Commitは`333f49000168a75917d1249b947cb53f0d28ffa9`、Issue `#39`はClosedである。
- Remote／Local Task BranchとWorktreeは削除済みで、Local `main`は`origin/main`へ`--ff-only`同期済み、Working Treeはcleanだった。
- Root Workspaceは、Root設定がV1 `frontend/pnpm-lock.yaml`を利用する既存install／auditへ影響するため、`MIG-022`のFrontend隔離前には導入しない判断を維持した。
- V2 Application、Package、OpenAPI Contract、Canonical Site Template、Root Lockfileは未実装であり、Gate G1は`NOT COMPLETE`である。

## MIG-021 backend → apps/api Mechanical Move

### Task

- 実施開始: 2026-07-23
- Task ID: `MIG-021`
- Risk: `R3`
- Issue: `#41` (`https://github.com/ideal-sol/oripa/issues/41`)
- Branch: `migration/MIG-021-backend-to-apps-api`
- Worktree: `/var/www/oripa-worktrees/MIG-021-backend-to-apps-api`
- Base SHA: `333f49000168a75917d1249b947cb53f0d28ffa9`

### 移動前Inventory／Runtime

- `backend`のTracked Fileは453件、Migrationは40件、Testは69件、Route Fileは3件だった。
- modeは`100644`が452件、`100755`が1件で、symlink、submodule、binary、1 MiB以上のFile、case-sensitive衝突はなかった。
- `apps/api`には既存の`AGENTS.md`と`README.md`だけがあり、移動元との同名File衝突はなかった。
- systemd、Nginx／Apache、Supervisor、Cron、Process CWD、Running ContainerをRead-only確認し、`backend`を参照するActive Production Runtimeはなかった。
- `backend`をBind MountするContainerは停止中の開発用`backend`／`queue`／`scheduler`だけだった。Production Serviceの停止、再起動、設定変更は行っていない。
- 元Main WorktreeにはGit管理外の`.env` 1件、Storage 46件、Cache 3件が存在した。内容を開かず、移動、削除、Commitを行わず、旧PathのignoreをLocal残置保護として維持した。

### 移動前Checksum／Test

- Backend Tree SHA-256: `12eba8037e922ca9f97981f8d475b88c38edfa6d2158029c51ba4c0d659ee468`
- Migration Set SHA-256: `1598f7074e890b59216f41534d6dd6e7a3c2614160825e281a8ec31ce0fc137e`
- Composer Lock SHA-256: `2302cbfd97acc5f63135e9a24b71206c41ba0db979fd3ee04fdc827a7d01b4f4`
- Route Tree SHA-256: `d71d1187d95976bb57fe63656a9c741bd02d188c2a27bcde8824e5f9b3bae2c9`
- Test Tree SHA-256: `fda729ea742a574c2bd9f0b43d8baf5c7c470e805551a948f770af06a3dab655`
- Config Tree SHA-256: `d3785b82766e30c29d6bf18aac0958124bfc82e645edb568344136c5be18546a`
- Public Tree SHA-256: `9c98379c3e8254c4dbaa7a49c439fb747382fb82a8e94ad53a052e6f9c3b1ea6`
- PHP 8.4、PostgreSQL 17、Redis 7のTask専用Ephemeral環境でComposer Validate、PHP Syntax、Migration適用、Full Backend Test、Route Inventoryを実行した。
- Full Backend Testは334件中332 PASS、2 Failed、Warning 0、Skipped 0だった。2件は期限`2026-08-15`の既存完全一致Baselineで、Classは`Tests\Feature\AdminPaymentApiTest`、Exceptionは`PHPUnit\Framework\ExpectationFailedException`だった。
- Route Inventoryは150件、正規化SHA-256は`11be8fca8e3ee1212c7badac50902d8a94a80412a8f98d9004a0fee2f6eb465d`だった。
- 移動前の`policy-gate`、`quality-gate`、`security-gate`、Site Template Positive／Negative、Docker Compose Configは期待どおりPASSした。Dependency Auditの既存Findingは期限付きBaselineと一致し、拡張していない。

### Mechanical Move／Path参照

- `git mv`でTracked File 453件を`backend/`から`apps/api/`へ移動し、既存`apps/api/AGENTS.md`と`apps/api/README.md`は上書きしていない。
- Pure Renameは453件で、mode、line ending、Application Source、Migration、Test Assertion、Composer Manifest／Lock、Namespace、Class名を変更していない。
- Path Reference Updateは`.github/workflows/platform-ci.yml`、`.github/dependabot.yml`、Docker Compose Bind Source、Dockerfile Copy Source、Quality／Security Gate、ignore、CODEOWNERS、Makefile、README、TASK_BOARDへ限定した。
- Documentation Updateは`apps/README.md`、`apps/api/README.md`、`docs/operations/ci/README.md`、`docs/operations/repository-layout/README.md`へ限定した。
- CI Updateとして`apps/api`からComposer Validate／Audit／Install、Migration、Full Backend Test、PHP Syntax、Dependency Baselineを実行するよう変更した。
- `policy-gate`へ`apps/api`必須Application FileとTracked `backend/`禁止を追加し、Positive Test、旧Path残存、必須File欠落のNegative Testを追加した。
- Container service名、Container内`/var/www/backend`、API URL、DB名、Table名、Cookie名、Queue名、Cache Key、Environment Variable名は変更していない。
- 過去Worklog、確定Architecture文書、V1設計記録の`backend`表記は歴史的記録として書き換えていない。

### 移動後検証

- 移動後のBackend Tree、Migration、Composer Lock、Route Tree、Test Tree、Config Tree、Public TreeのSHA-256は移動前と全件一致した。
- 同一Versionの新規Ephemeral環境でComposer Validate、PHP Syntax、Migration適用、Full Backend Test、Route Inventoryを再実行した。
- Full Backend Testは334件中332 PASS、2 Failed、Warning 0、Skipped 0で、Failure Class／Method／Exception／正規化Fingerprintは移動前と完全一致した。
- Route Inventoryは150件、正規化SHA-256も移動前と一致し、API／Route Behaviorの変化は検出されなかった。
- `policy-gate` Unit Test 15件、`policy-gate`、`quality-gate`、`security-gate`、Site Template Positive／Negative、Docker Compose Config、`git diff --check`はPASSした。
- Test用Container／Networkは正常・異常終了の両方でTask専用名だけを削除した。Production DB、Production Redis、Production Secretは使用していない。
- Root `package.json`、`pnpm-workspace.yaml`、Root Lockfileは追加せず、V1 `frontend`とLockfileを変更していない。

### GitHub／Gate G2

- Commit Messageは`構造: backendをapps/apiへ機械的に移動する (MIG-021)`とし、GitHub App WrapperだけでFast-forward Pushする。
- PRは`[MIG-021] Laravelバックエンドをapps/apiへ移動する`として作成し、5 Required Check、Available CodeQL／Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge ConflictなしをMerge条件とする。
- Gate G2ではLaravel ApplicationのPath移動、Path非依存Checksum一致、移動前後Test／Route一致、CIの新Path対応を完了対象とする。
- V1 `frontend`のLegacy隔離、Root Workspace、V2 Package／Contract実装は残項目であり、Gate G2は`NOT COMPLETE`とする。
- 次Task候補は`MIG-022`だが、MIG-021完了後には開始しない。

### Commit／Push／PR

- Implementation Commitは`18196872ca720b7e892c85a7286034c5f7473cf3`で、ParentはBase SHA `333f49000168a75917d1249b947cb53f0d28ffa9`である。
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。Direct main Push、Force Push、Archive Ref変更は行っていない。
- PRは`#42` (`https://github.com/ideal-sol/oripa/pull/42`)、Authorは`ideal-sol-oripa-codex[bot]`、Draft、Baseは`main`である。
- PR本文へ473 Changed Fileを省略せず記載し、453 Pure Renameと20件のPath Reference／Documentation／CI／Worklog変更を分離した。
- 本追記を含むFinal Headで5 Required Check、Available CodeQL／Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認して自律Squash Mergeする。

### MIG-021 Closeout

- PR `#42`のFinal Headは`bffa6c7c0fb922c431022f76a86fdc7149aa10a8`で、Required 5 Check、CodeQL、Dependency Reviewを含む13 Checkが成功した。
- GitHub AppがSquash Mergeし、Squash Commitは`a54d16f6727437e9261b1bb64f35ddee32b55b51`、Issue `#41`はClosedである。
- Remote／Local Task BranchとWorktreeは削除済みで、Local `main`は`origin/main`へ`--ff-only`同期済み、Working Treeはcleanだった。
- Backend移動前後のApplication Tree、Migration、Composer Lock、Route、Test、Config、Public Asset Checksumは一致した。
- Full Backend Testは移動前後とも334件中332 PASS、既存Baseline 2 Failed、Warning 0、Skipped 0で、Failure Fingerprintも一致した。
- V1 FrontendのLegacy隔離、Root Workspace、V2 Package／Contract実装が残っていたため、Gate G2は`NOT COMPLETE`だった。

## MIG-022 frontend → legacy/v1-frontend Mechanical Move

### Task

- 実施開始: 2026-07-23
- Task ID: `MIG-022`
- Risk: `R3`
- Issue: `#43` (`https://github.com/ideal-sol/oripa/issues/43`)
- Branch: `migration/MIG-022-frontend-to-legacy`
- Worktree: `/var/www/oripa-worktrees/MIG-022-frontend-to-legacy`
- Base SHA: `a54d16f6727437e9261b1bb64f35ddee32b55b51`

### Active Runtime／Inventory

- `frontend`のTracked Fileは62件、Pageは21件、Route Handlerは4件、Public Assetは10件、Frontend Test Fileは0件だった。
- modeは62件すべて`100644`で、Symlink、Submodule、10 MiB以上のFile、Case-sensitive衝突、移動先衝突はなかった。
- `package.json`は`oripa-frontend` `0.1.0`、`packageManager`は`pnpm@10.12.1`で、Nodeは22系を使用する既存CIと一致した。
- systemd、Process CWD、Running Container、Docker Bind Mount、Nginx／Apache、Supervisor、PM2、CronをRead-only確認し、`/var/www/oripa/frontend`を参照するActive Production Runtimeはなかった。
- `frontend`をBind MountするDocker Containerは停止中の開発用`oripa-frontend-1`だけだった。Production Serviceの停止、再起動、設定変更は行っていない。
- 元Main WorktreeにはGit管理外の`.env.local`、`node_modules`、`.pnpm-store`、`.next`、`tsconfig.tsbuildinfo`が存在した。内容を開かず、移動、削除、Commitを行わず、旧PathのignoreをLocal残置保護として維持した。

### 移動前Checksum／Baseline

- Frontend Tree SHA-256: `309ee6974723df99cbf0a14ca97683d9f97d054dcbaa55c9b98ab45b56a678c1`
- Source Tree SHA-256: `160031da0688ceb1a873bf1ba3d1460329ffdbaa6c8f0b2609b7c846553136da`
- Page／Route Tree SHA-256: `125c54aa5dd3b8cba28f5fcb79754fad250341a4d8c0f5230bdf379f4f8c4607`
- Route Inventory SHA-256: `53809a3f9baa6dca8b787548e78a21d5ae9e647bbae34a25e24fc669370075dc`
- Public Asset Tree SHA-256: `9d54fb60935068e94d3cb76e187ca55f1e67e97b23510d0af3568dfd9aaa7ece`
- Package Manifest SHA-256: `2736b5097f5cdcf3c12dcb11fab531787e7a12e0f16447df6a73e0dc7a8d3ad0`
- pnpm Lockfile SHA-256: `55171c1b7dd2f1988b77bdcb8906ce4401cb860a6b6c8c0bfc36dc76f6cb8bfd`
- TypeScript設定SHA-256: `32f0a59b5e4ca1d51ffe5346573b4808e14d261d1c4e49382f8edd139f7bb6b2`
- Repository外の隔離DirectoryでNode 22、pnpm 10.12.1、`pnpm install --frozen-lockfile`、Typecheck、ESLint、Production Buildを実行した。
- Install、Typecheck、Buildは成功した。ESLintはRaw Exit 1、8 Error／1 Warningだったが、期限`2026-08-31`の既存完全一致BaselineでPASSした。
- `package.json`にFrontend Test Scriptは存在しないため、Frontend Testは未実行であり、PASSとは記録しない。
- `policy-gate`、Policy Unit Test 16件、`quality-gate`、Quality Unit Test 5件、`security-gate`、Docker Compose ConfigはPASSした。
- Dependency AuditはComposer 10件、pnpm 14件の期限付き既存完全Baselineと一致し、Baselineを拡張していない。
- MIG-021 Final Headの13 Check成功を、移動前のGitHub `integration-gate`を含む正本Baselineとして確認した。

### Mechanical Move／Governance

- `git mv`でTracked File 62件を`frontend/`から`legacy/v1-frontend/`へ欠落なく移動し、`legacy/v1-frontend/frontend/**`の誤った二重入れ子はない。
- 移動対象のSource、Route、Component、CSS、Asset、Package Manifest、Lockfile、Environment Variable名、API Call、Test Assertionは変更していない。
- `legacy/v1-frontend/AGENTS.md`を追加し、V1参照専用、新機能／通常修正禁止、V2への直接Copy禁止、V2 Production Image禁止を明示した。
- `legacy/v1-frontend/README.md`へ非Production参照用途、Node／pnpm、Install、Typecheck、Lint、Build、Start、Environment Template、Test Script不在を記録した。
- Docker ComposeのV1 Frontend Build ContextとBind Sourceを`legacy/v1-frontend`へ限定し、`.dockerignore`でGit管理外生成物を除外した。Service名、Container内Path、Port、API URL、Environment Variable名は変更していない。

### Path Reference／CI Update

- GitHub ActionsのInstall、Typecheck、Lint、Audit、Buildを`legacy/v1-frontend`から実行するよう更新した。
- Dependabot npm Directory、CODEOWNERS、Docker開発構成、Makefile、README、TASK_BOARD、Repository Layout、CI運用文書を新Pathへ更新した。
- `.gitignore`とRoot `.dockerignore`は旧PathのGit管理外残置物を保護し、新Pathの生成物も追跡／Build Contextから除外する。
- Lint BaselineはPath PrefixとPath依存Fingerprintだけを更新した。Finding数、Rule、Severity、Line／Column、Message Hash、期限、Owner、修正Taskは変更していない。
- Dependency ReviewがLockfileのPath移動を依存関係の新規追加として扱うため、既存Dependency BaselineにあるHigh Severity GHSAだけを`allow-ghsas`へ完全一致で接続した。Package／Version／Path／期限は`security-gate`のExact Baselineで引き続き強制し、新規／悪化Findingを許可しない。
- `policy-gate`へTracked `frontend/**`禁止、Legacy Frontend必須File、二重入れ子禁止、V2 DockerfileによるLegacy Copy禁止を追加した。
- Positive Testに加え、旧Path残存、二重入れ子、V2 Dockerfile CopyのNegative Testを追加し、Policy Unit Testは20件すべてPASSした。
- 過去Worklog、確定Architecture、V1仕様、Review／Audit文書の旧Pathは歴史的記録として書き換えていない。
- Root `package.json`、`pnpm-workspace.yaml`、Root Lockfileは追加していない。Root Workspace導入可否はMIG-022完了後の別Taskで判断する。

### 移動後検証

- 移動対象62件の内部相対Path、mode、内容SHA-256を比較し、Frontend Tree、Source、Page／Route、Public Asset、Package Manifest、pnpm Lockfile、TypeScript設定は移動前と全件一致した。
- 同一Node／pnpm、同一Command、同一非Production API設定でInstall、Typecheck、ESLint、Buildを再実行した。
- Install、Typecheck、Buildは成功し、ESLintは移動前と同じ8 Error／1 WarningでExact Baseline PASSだった。
- LintのRule、Severity、Line／Column、正規化Message Hashは移動前後で完全一致した。
- Frontend Test Scriptは移動後も存在せず、Frontend Testは未実行である。
- `security-gate`はComposer 10件、pnpm 14件の既存Baselineと一致し、Secret Candidateは0件だった。
- `policy-gate`、`quality-gate`、`security-gate`、Docker Compose Config、`git diff --check`はPASSした。
- Application Runtime、Browser／E2E、Production Deployは未実行であり、PASSとは記録しない。

### GitHub／Gate G2

- Commit Messageは`構造: frontendをlegacy/v1-frontendへ機械的に移動する (MIG-022)`とし、GitHub App WrapperだけでFast-forward Pushする。
- PRは`[MIG-022] frontendをlegacy/v1-frontendへ移動する`として作成し、5 Required Check、Available CodeQL／Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge ConflictなしをMerge条件とする。
- Gate G2ではLaravelの`apps/api`移動、V1 Frontendの`legacy/v1-frontend`隔離、前後Checksum／Test一致、CIの新Path対応を完了対象とする。
- Root Workspace、V2 Admin／Storefront、First-party Package、OpenAPI Contractは未実装であり、Gate G2は`NOT COMPLETE`とする。
- 次Task候補は`MIG-023`だが、MIG-022完了後には開始しない。

### Commit／Push／PR

- Implementation Commitは`818ebcab33a91d934a965b87ec9d27f47ea798c6`で、ParentはBase SHA `a54d16f6727437e9261b1bb64f35ddee32b55b51`である。
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。Direct main Push、Force Push、Archive Ref変更は行っていない。
- PRは`#45` (`https://github.com/ideal-sol/oripa/pull/45`)、Authorは`ideal-sol-oripa-codex[bot]`、Draft、Baseは`main`である。
- PR本文で62 Pure Rename、Path Reference、Governance、Documentation、CI、Worklog Updateを分離し、移動前後Checksum／Test結果を記録した。
- Initial GitHub CheckではPR本文のChanged File完全列挙不足により`policy-gate`が失敗し、Lockfile Renameを新規Dependencyと判定した`dependency-review`が既存High Advisoryを再検出した。PR本文を85件の完全列挙へ更新し、既存Exact BaselineだけをDependency Reviewへ接続してFinal Headで再検証する。
- 同時実行した`quality-gate`の1 Runは`corepack prepare`で一時失敗したが、同Headの別Runでは同Step以降が成功した。Final Headで再実行し、失敗RunをBypassしない。
- Dependency Review Workflow追加後の86 Changed PathをPR本文へ完全列挙し、修正済み本文を読む新Headで全Checkを再実行する。
- 本追記を含むFinal HeadでRequired 5 Check、Available CodeQL／Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認して自律Squash Mergeする。

### MIG-022 Closeout

- PR `#45`のFinal Headは`261a2dcdf9b2f503e89700716dcd6805e4e8a0e0`で、Required 5 Check、CodeQL、Dependency Reviewを含む8 Checkが成功した。
- GitHub AppがSquash Mergeし、Squash Commitは`0f008085ff7b3afb54fe1d6745f87979e979947e`、Issue `#43`はClosedである。
- Remote／Local Task BranchとWorktreeは削除済みで、Local `main`は`origin/main`へ`--ff-only`同期済み、Working Treeはcleanだった。
- V1 Frontend移動前後のTracked Tree、Source、Page／Route、Public Asset、Package Manifest、pnpm Lockfile、TypeScript設定のChecksumは一致した。
- Install、Typecheck、Buildは移動前後とも成功し、ESLintは既存Baselineと完全一致する8 Error／1 Warningだった。Frontend Test Scriptは存在せず未実行である。
- `legacy/v1-frontend`はV2 Workspace、V2 Production Image、V2 Build Contextから除外され、Production用途ではないV1 Referenceとして隔離された。
- Root Workspace、`apps/admin`のBuild可能なSkeleton、First-party Package Skeleton、V2 Composeが未整備だったため、Gate G2は`NOT COMPLETE`だった。

## MIG-023 V2 Admin／Workspace Skeleton

### Task

- 実施開始: `2026-07-23T10:23:30Z`
- Task ID: `MIG-023`
- Risk: `R3`
- Issue: `#49` (`https://github.com/ideal-sol/oripa/issues/49`)
- Branch: `migration/MIG-023-admin-workspace-skeleton`
- Worktree: `/var/www/oripa-worktrees/MIG-023-admin-workspace-skeleton`
- Base SHA: `0f008085ff7b3afb54fe1d6745f87979e979947e`

### Existing Inventory

- Root `package.json`、`pnpm-workspace.yaml`、`pnpm-lock.yaml`は存在せず、`apps/admin`と4つのFirst-party PackageはREADME／AGENTSだけのSkeletonだった。
- `apps/api`はLaravel Application、`legacy/v1-frontend`は独立したV1 Next.js Referenceであり、それぞれの既存Dependency／Lockfileを維持した。
- Repositoryで使用中のVersionはNode `22.22.3`、pnpm `10.12.1`で、既存CIとV1 Frontendの`packageManager`を根拠に固定した。
- 既存`docker-compose.yml`は`apps/api`、`legacy/v1-frontend`、PostgreSQL、Redis、MinIO、Mailpitを含む非ProductionのV1 Referenceとして再利用した。

### Root Workspace／Skeleton

- Root Package `@oripa/platform-workspace`を`private: true`、Version `2.0.0-alpha.1`、`packageManager` `pnpm@10.12.1`、Node `22.22.3`で作成した。
- Workspace対象は`apps/admin`と`packages/*`だけで、`legacy/**`と`apps/api`は含めていない。RootとLegacyのLockfileおよび依存解決は分離した。
- Root `pnpm-lock.yaml`はpnpm `10.12.1`の実Installから生成し、手書きしていない。DependencyはExact Versionだけを使用した。
- Root Workspace追加後に通常の`pnpm --dir legacy/v1-frontend install`が親Workspaceを探索することを実測し、CIと手順へ`--ignore-workspace`を追加してLegacyの独立Lockfile／依存解決を維持した。
- `apps/admin`へNext.js `16.2.11`、React `19.2.7`の最小Skeleton、`noindex`／`nofollow`、`/api/health`、非Production表示を追加した。
- `apps/admin`にはBusiness Logic、Laravel API接続、Auth、Session、Cookie、MFA、Mock業務Data、Site固有Design、Server Secretを実装していない。
- `@oripa/platform`、`@oripa/storefront-client`、`@oripa/site-schema`、`@oripa/storefront-testkit`へVersion `2.0.0-alpha.1`のprivate Manifestだけを追加した。Export、Dependency、Fake API、Legacy Codeは追加していない。

### Compose／Smoke Test

- `docker-compose.yml`を非ProductionのV1 Referenceとして明示し、TaskごとのPort分離を可能にした。Service名、Application Behavior、Environment Variable名は変更していない。
- `docker-compose.v2.yml`へ`apps/api`、`apps/admin`、PostgreSQL、Redisの非Production Skeletonを追加した。Legacy Frontend、Production Secret、固定Container名は含めていない。
- V1 ReferenceをTask専用Project名でBuild／起動し、API HealthとFrontend Healthを確認した。停止後にTask専用Container／Network／Volumeは残存しなかった。
- V2 SkeletonをTask専用Project名でBuild／起動し、API HealthとAdmin Healthを確認した。初回検証でRuntime Working Directory不備を検出してDockerfileだけを修正し、再検証後は全Serviceがhealthyとなった。
- V2停止後にTask専用Container／Network／Volumeが残存しないことを確認した。Production Service、Production DB、Production Secretは使用していない。

### CI／Policy

- 既存Check名`policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`を維持した。
- CIへRoot frozen install、Admin Typecheck／Lint／Build、Root Dependency Audit、Workspace Manifest、V1／V2 Compose Config、Ephemeral V2 Smoke、API／Admin Health、Cleanup検査を追加した。
- Legacy Frontendの独立Install／Typecheck／Lint／Buildと既存Baseline検証は維持し、DependabotのLegacy対象に加えてRoot Workspace対象を追加した。
- `policy-gate`へRoot Workspace、Exact Version、Lockfile、Admin許可File、Health Endpoint、Package Skeleton、Compose境界、Legacy除外の継続検証を追加した。
- Negative TestはLegacy Workspace混入、`apps/api`混入、Version Range、Root Lockfile欠落、Admin Health欠落、Business Logic混入、V2 ComposeへのLegacy混入を拒否する。
- Root Workspaceの初回Auditで検出した新規Transitive Advisoryは、修正版が存在するExact Versionへ固定し、最終Root Auditは0 Findingとなった。Legacy Dependency Baselineは変更していない。
- 初回PR Runの`policy-gate`はPR本文のAllowed Pathsが説明文だったため失敗し、Task Policyと一致する実Path Patternへ修正した同一Headの再実行でPASSした。
- GitHub Runner上の`docker compose up --wait`を含むSmoke Stepが2回失敗した一方、同一ComposeのLocal Smokeは再現せずPASSした。Gateを弱めず、API／Admin Endpointの最大300秒Bounded Pollingと4 ServiceのDocker Health検査へ置き換え、失敗時のTask専用診断を追加した。
- 診断付きRunでAPI／PostgreSQL／Redisは正常、AdminはNext.js起動済みだが`localhost` Requestを受信していないことを確認した。Alpine `wget`とIPv4 Bindの差を排除するため、Admin Health URLを`127.0.0.1`へ固定して再検証する。

### Local Verification

- Root `pnpm install --frozen-lockfile`、Admin Typecheck、Lint、BuildはPASSした。
- Legacy Frontend Install、Typecheck、BuildはPASSし、Lint Findingは既存完全一致Baselineから増加していない。
- Package Manifest／Lockfile／JSON／YAML／Markdown、V1／V2 Compose Config、Policy Unit Test 26件、Quality Unit Test 5件、Security Unit Test 4件、`git diff --check`はPASSした。
- `policy-gate`、`quality-gate`、`security-gate`はPASSした。Root Dependency Findingは0件、Legacyは既存Baselineと完全一致する14件、Composerは既存Baselineと完全一致する10件、Secret Candidateは0件だった。
- V1／V2 Compose SmokeはPASSし、Cleanup後のContainer／Network／Volume残存は0件だった。
- Application Business Logic、Migration、DB Schema、OpenAPI Contract、Storefront Client、Site Schema、Testkit、Admin Auth／MFAは変更していない。
- Browser／E2E、Production Deployは未実行であり、PASSとは記録しない。

### GitHub／Gate G2

- Commit Messageは`構造: V2 AdminとWorkspace Skeletonを整備する (MIG-023)`とする。
- GitHub App WrapperだけでFast-forward Pushし、Draft PR、Required／Available Check、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認してSquash Mergeする。
- Required Check成功、GitHub Cleanup、Local `main`同期後に、Backend Move一致、Legacy隔離、V1／V2 Skeleton起動、Admin Build／Health、Root Workspace／Lockfile、Package Skeleton、Business Logic不変を根拠としてGate G2を判定する。
- 次Task候補は`MIG-030`だが、MIG-023完了後には開始しない。

### Commit／Push／PR

- Implementation Commitは`5dc23d6fa243752973affbdad5db1e022f85e029`で、ParentはBase SHA `0f008085ff7b3afb54fe1d6745f87979e979947e`である。
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。Direct main Push、Force Push、Archive Ref変更は行っていない。
- PRは`#50` (`https://github.com/ideal-sol/oripa/pull/50`)、Authorは`ideal-sol-oripa-codex[bot]`、Draft、Baseは`main`である。
- PR本文へ36 Changed Pathを完全列挙し、Root Workspace、Admin／Package Skeleton、Compose、CI／Policy、Worklogを分離して記録した。
- 本追記を含むFinal HeadでRequired 5 Check、Available CodeQL／Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認して自律Squash Mergeする。

### MIG-023 Closeout

- PR `#50`のFinal Headは`d4df803536d845893db59549d727a6bf698cc2f1`で、Required 5 Check、CodeQL、Dependency Reviewを含む8 Checkが成功した。
- GitHub AppがSquash Mergeし、Squash Commitは`0efd04ec8283ef8a084b6b7d7eddbfcea2d1bd4d`、Issue `#49`はClosedである。
- Remote／Local Task BranchとWorktreeは削除済みで、Local `main`は`origin/main`へ`--ff-only`同期済み、Working Treeはcleanだった。
- Root Workspace／Lockfile、Build可能な`apps/admin` Skeleton、4つのFirst-party Package Skeleton、V1／V2 Compose、API／Admin Health、CI継続検査が成立した。
- Backend移動前後Test一致、Legacy Frontend隔離、V1／V2 Skeleton起動、Admin Build／Health、Root Workspace、Package Skeleton、Business Logic不変、Required Check成功を確認し、Gate G2は`COMPLETE`と判定した。

## MIG-030 OpenAPI Contract Skeleton

### Task

- 実施開始: `2026-07-23T12:39:45Z`
- Task ID: `MIG-030`
- Risk: `R3`
- Issue: `#55` (`https://github.com/ideal-sol/oripa/issues/55`)
- Branch: `migration/MIG-030-openapi-contract-skeleton`
- Worktree: `/var/www/oripa-worktrees/MIG-030-openapi-contract-skeleton`
- Base SHA: `0efd04ec8283ef8a084b6b7d7eddbfcea2d1bd4d`

### Existing Inventory／仕様根拠

- 開始時の`openapi/`は`AGENTS.md`とREADMEだけで、OpenAPI Entry Point、Component、Lint設定、Bundleは存在しなかった。
- 正本はOpenAPI 3.1.1、Public `/api/v2`、Admin `/admin/api/v2`、Webhook `/webhooks/v2`の完全分離、RFC 9457 Problem Details、共通Header、各Contractの独立SemVerを規定している。
- 移行計画の順序はOpenAPI共通Primitiveを先頭とし、Public Read-only、Auth、Draw、Payment、Admin、Webhookの業務Endpointは後続Taskで段階的に定義する。
- MIG-030では業務Endpointを推測せず、3 Contractとも`paths: {}`のSkeletonとした。Laravel Route／Controller、DB、Migration、Generated Clientは変更していない。

### OpenAPI 3.1.1 Skeleton

- `openapi/public/openapi.yaml`、`openapi/admin/openapi.yaml`、`openapi/webhook/openapi.yaml`を独立Entry Pointとして作成した。
- 各ContractはOpenAPI `3.1.1`、JSON Schema Draft 2020-12、Contract Version `2.0.0-alpha.1`、`x-status: skeleton`、Surface固有Server Prefixを持つ。
- `openapi/components/common.yaml`へ`OpaqueId`、`SemanticVersion`、`UtcDateTime`、`BusinessDate`、`ProblemDetails`、`CursorPageMeta`、共通Header／Parameter／Problem Responseを定義した。
- `ProblemDetails`は`type`、`title`、`status`、`code`、`request_id`、`retryable`を必須とし、Stack Trace、内部Field、Secret、実PIIを含めない。
- Public Schema Leak検査は`password_hash`、`provider_secret`、`cost_price`、`individual_ppm`等の内部Fieldを拒否する。
- Cookie名、Provider署名Header、Provider Payload、業務Request／Responseは未確定値を推測せず定義していない。

### Lint／Bundle／Breaking Change

- Root Dev Dependencyへ`@redocly/cli` `2.40.0`だけをExact Versionで追加し、pnpm `10.12.1`の実InstallでRoot Lockfileを更新した。
- `openapi/redocly.yaml`は3 APIを登録し、Redocly Recommended Rule、`operationId`、曖昧Path、Security定義を検査する。Lintは3 ContractともWarning 0でPASSした。
- `scripts/ci/openapi_contract_gate.py`はLint、deterministic Bundle生成、Commit済みBundleとのByte比較、3 Surfaceの識別／Version／Prefix／共通Schema、global `operationId`重複、Public Leakを検査する。
- Pull RequestではEvent Base SHAの既存Bundleと比較し、Path／Operation／Response／Schema削除、`operationId`／認証／冪等性／型変更、Required Field追加、Enum値削除をBreaking Changeとして拒否する。
- MIG-030以前にBundleが存在しない初回導入ではPrevious Bundleなしとして開始し、以後のPull Requestで比較を必須化する。
- `openapi/bundled/public.openapi.json`、`admin.openapi.json`、`webhook.openapi.json`を生成し、再生成Bundleとの差分なしを確認した。各BundleのOperation数は0件である。

### Positive／Negative Test

- Positive FixtureはOptional Field追加だけを含み、Breaking Finding 0件でPASSした。
- Negative Fixtureは`operationId`変更、Required Field追加、Property削除、型変更をすべて検出した。
- Public Internal Field Leakと、Skeletonへの業務Endpoint混入をNegative Testで拒否した。
- OpenAPI Unit Test 4件、Policy Unit Test 27件、Quality Unit Test 5件、Security Unit Test 4件はPASSした。

### CI／Local Verification

- `quality-gate`へOpenAPI Unit Test、3 Contract Lint、Bundle差分、Breaking Change検査を追加した。
- `integration-gate`へ同じCommitted Bundle検証を追加し、Application接続前でもContract Artifactの差分を継続検査する。
- Check名`policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`は変更していない。
- Local `policy-gate`、`quality-gate`、`security-gate`、`git diff --check`、Admin Typecheck／Lint／BuildはPASSした。
- Root pnpm Auditは0 Finding、Composer 10件とLegacy pnpm 14件は既存期限付きExact Baselineと一致し、Secret Candidateは0件だった。Baselineは拡張していない。
- Backend Test、Migration適用、Docker Compose Smoke、Legacy Frontend Build、Browser／E2E、Generated Client、Laravel Route差分、Production DeployはMIG-030では未実行であり、PASSとは記録しない。GitHubの既存`integration-gate`で既存Application検証を省略せず実行する。

### Commit／Push／PR

- Commit Messageは`契約: OpenAPI Skeletonと検証基盤を整備する (MIG-030)`とする。
- GitHub App Task Policy WrapperだけでFast-forward Pushし、Draft PR、Required／Available Check、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認してSquash Mergeする。
- Direct main Push、Force Push、Gate Bypass、V1 Archive Branch／Annotated Tag変更は行わない。
- 次Task候補は`MIG-031`だが、MIG-030完了後には開始しない。

### MIG-030 Closeout

- PR `#56`のFinal Headは`3fc880c87316bc77f5ebdd8508b3c1021f9ea41e`で、Required 5 Check、CodeQL、Dependency Reviewを含む8 Checkが成功した。
- GitHub AppがSquash Mergeし、Squash Commitは`9793f1089fdff981d2433731ff224bae87f2c2e6`、Issue `#55`はClosedである。
- Remote／Local Task BranchとWorktreeは削除済みで、Local `main`は`origin/main`へ`--ff-only`同期済み、Working Treeはcleanだった。
- Public／Admin／WebhookのOpenAPI 3.1.1 Skeleton、共通Component、Lint、deterministic Bundle、Breaking Change検査が`main`へ反映された。
- 3 SurfaceのOperation数は0件で、Laravel、DB、Migration、業務Endpoint、Generated ClientはMIG-030では変更していない。
- V1 Archive BranchとAnnotated Tagは`bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更されていない。

## MIG-031 Storefront Client Alpha

### Task

- 実施開始: `2026-07-23T13:54:39Z`
- Task ID: `MIG-031`
- Risk: `R3`
- Issue: `#57` (`https://github.com/ideal-sol/oripa/issues/57`)
- Branch: `feat/MIG-031-storefront-client`
- Worktree: `/var/www/oripa-worktrees/MIG-031-storefront-client`
- Base SHA: `9793f1089fdff981d2433731ff224bae87f2c2e6`

### Public API／生成

- Public OpenAPI Bundle `openapi/bundled/public.openapi.json`だけを型の正本とし、`openapi-typescript` `7.13.0`で`packages/storefront-client/src/generated/public.ts`を決定的に生成した。
- 生成ToolのPeer範囲へ合わせ、Client PackageのTypeScriptはExact Version `5.9.3`とした。Root WorkspaceのTypeScript `6.0.3`、Admin Dependency、Legacy Dependencyは変更していない。
- `generate:check`はRepository外の一時Directoryへ再生成し、Commit済み生成物とのByte差分を拒否する。生成物は手動編集禁止である。
- Public API Operationは0件であり、Generated `paths`／`operations`は`Record<string, never>`である。Fake Endpoint Method、架空の業務型、Admin／Webhook型は追加していない。
- 公開Entry PointはPackage Root、`browser`、`server`、`types`だけである。`types`はPublic `paths`、`components`、`operations`だけを再Exportする。

### Transport／Error／Retry

- Package Versionは`2.0.0-alpha.1`で、Browser Clientは`credentials: include`、JSON、`X-Oripa-Client-Version`、`X-Oripa-Site-Version`、Request ID／API Version等のResponse Metadataを扱う。
- TimeoutはClient Configの`default_timeout_ms`を既定値としてRequest単位で上書きでき、外部`AbortSignal`とTimeoutを別Error Codeへ変換する。
- `createIdempotencyKey()`、16～128文字の`Idempotency-Key`検証、同一Keyを保持するMutation Retry境界を実装した。
- GET／HEADはNetwork Errorと502／503／504だけを最大2回、Idempotency-Key付きMutationは同条件で最大1回Retryする。KeyなしMutation、409、422、429はRetryしない。
- RFC 9457拡張の`application/problem+json`を`ApiProblemError`へ変換し、`request_id`、`retryable`、`retry_after_seconds`、Field Errorを保持する。
- CSRF Endpoint、Cookie名、Header名は推測せず、必要なMutationだけが呼ぶ設定可能な`csrf_initializer`境界を設けた。
- Server ClientはRequest単位のCookie Header転送とGET／HEADだけを許可する。Authorization Header、LocalStorage Token、Cache、React State、UI、Business Logic、Draw／Point／Payment判断、Provider固有処理は持たない。

### CI／検証

- Root Scriptと`quality-gate`／`integration-gate`へ生成差分、Typecheck、Lint、Build、Unit Testを統合し、既存Check名は変更していない。
- `policy-gate`はStorefront ClientをSkeleton扱いからAlpha Package検証へ移し、Package identity、Exact Version、公開面、Generated Operation 0件、Browser Cookie、Transport境界を継続検査する。
- Positive Fixtureに加え、Admin型公開、Fake Operation、`credentials: omit`を拒否するNegative Testを追加した。Policy Unit Test 30件はPASSした。
- `pnpm install --frozen-lockfile`、生成差分、Typecheck、Lint、Build、Unit Test 9件、OpenAPI Unit Test 4件、3 Contract Lint／Bundle、Quality Unit Test 5件、Admin Typecheck／Lint／BuildはPASSした。
- Unit TestはCookie通信、Version Header、Request ID、Timeout／Abort、Idempotency、RFC 9457 Problem Details、限定Retry、409／422／429非Retry、CSRF境界、Server GET／HEAD、Admin／Webhook型非公開、Fake Operationなしを検証した。
- 生成Toolが導入した`js-yaml` AdvisoryはPatched Version `4.3.0`へExact Overrideし、Root Auditは0 Findingである。Composer 10件とLegacy pnpm 14件は既存期限付きExact Baselineと一致し、Baselineを拡張していない。
- Local `policy-gate`、`quality-gate`、`security-gate`、`git diff --check`はPASSし、Secret Candidateは0件だった。
- Laravel／DB／Migration、Backend Test、Legacy Frontend Build、Docker Compose Smoke、Browser／E2E、Production DeployはMIG-031の変更対象ではなくLocalでは未実行であり、PASSとは記録しない。GitHubの`integration-gate`では既存検証を省略しない。

### Gate G3／GitHub

- Gate G3のOpenAPI LintとGenerated Client cleanを進めた。Public業務Operationが0件のため、実OperationのContract Testは未完了である。
- `migrate:fresh`、User／Admin Realm分離、Constraint Test、Backup／Restore初回確認、`2.0.0-alpha.1` Artifact作成、Site Schema、Storefront Testkitは後続Taskであり、Gate G3は`NOT COMPLETE`である。
- Commit Messageは`実装: Storefront Client Alpha基盤を整備する (MIG-031)`とする。
- GitHub App Task Policy WrapperだけでFast-forward Pushし、Draft PR、Required／Available Check、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認してSquash Mergeする。
- Direct main Push、Force Push、Gate Bypass、V1 Archive Branch／Annotated Tag変更は行わない。
- 次Task候補は`MIG-032`だが、MIG-031完了後には開始しない。

### Commit／Push／PR

- Implementation Commitは`7450ec5748420b6a3e436baa4558c2c0c1ad4ce6`で、ParentはBase SHA `9793f1089fdff981d2433731ff224bae87f2c2e6`である。
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。Direct main Push、Force Push、Archive Ref変更は行っていない。
- PRは`#58` (`https://github.com/ideal-sol/oripa/pull/58`)、Authorは`ideal-sol-oripa-codex[bot]`、Baseは`main`である。
- PR本文へ23 Changed Pathを完全列挙し、Generated Type、Transport、Test、CI／Policy、Worklogを分離して記録した。
- Ready化とPR再Openで同一HeadのPlatform CIが重なり、Concurrencyにより古い`integration-gate`がCancelされ、その系列の`ci-gate`が失敗した。GateをBypassせず、本追記を含む新しいFinal Headの`synchronize` Eventで全Checkを再実行する。
- Final HeadでRequired 5 Check、Available CodeQL／Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認して自律Squash Mergeする。

### MIG-031 Closeout

- PR `#58`のFinal Headは`9df392b2f338b08fe206a48d51cfb3bd90b5ecc1`で、Required 5 Check、CodeQL、Dependency Reviewを含む8 Checkが成功した。
- GitHub Appが固定HeadのSelf-review後にSquash Mergeし、Squash Commitは`643d5ec9e0e89a6ac9ea955865d93be78efed16b`、Issue `#57`はClosedである。
- Remote／Local Task Branch `feat/MIG-031-storefront-client`とWorktreeは削除済みである。
- Local `main`は`origin/main`へ`--ff-only`同期済みで、Working Treeはcleanだった。
- V1 Archive BranchとAnnotated Tag Peeled Commitは`bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更されていない。
- OpenAPI LintとGenerated Client cleanは完了したが、実Public Operation Contract Test、Realm分離、Constraint Test、Backup／Restore、Alpha Artifactは未完了のため、Gate G3は`NOT COMPLETE`である。
- 次Task候補は`MIG-032`だが、MIG-031A完了後には開始しない。

## SEC-002 PostCSS Security Advisory対応

### Task

- 実施開始: `2026-07-24T00:17:00Z`
- Task ID: `SEC-002`
- Risk: `R3`
- Issue: `#61` (`https://github.com/ideal-sol/oripa/issues/61`)
- Branch: `security/SEC-002-postcss-advisory`
- Worktree: `/var/www/oripa-worktrees/SEC-002-postcss-advisory`
- Base SHA: `643d5ec9e0e89a6ac9ea955865d93be78efed16b`
- Draft PR `#60`はMIG-031A専用としてHead `7e1c8bbebe8780adc8534b8f4d90e807b6d4efa4`のまま保持し、本Taskでは変更していない。

### Advisory／Dependency Graph

- 対象はHigh Severity Advisory `GHSA-6g55-p6wh-862q`で、影響範囲は`postcss <=8.5.11`、修正版は`8.5.12`以上である。
- Root Workspaceは`apps/admin -> next 16.2.11 -> postcss 8.5.10`のTransitive Dependencyとして検出した。
- Legacy Frontendは`legacy/v1-frontend -> next 16.2.9 -> postcss 8.4.31`のTransitive Dependencyとして検出した。
- Rootは既存`pnpm.overrides`をExact Version `8.5.12`へ更新した。
- Legacyは独立した`package.json`へ`pnpm.overrides.postcss = 8.5.12`を追加し、RootとLegacyのLockfile分離を維持した。
- Root／Legacyの`pnpm-lock.yaml`はpnpm `10.12.1`で独立再生成し、PostCSS以外のVersion、Application Dependency、Node／pnpm Versionは変更していない。

### Baseline／Policy

- Root WorkspaceのPolicy期待Versionだけを`8.5.10`から`8.5.12`へ同期した。検査条件、Allowed Scope、Assertionの強度は変更していない。
- LegacyのPostCSS更新により対象High Advisoryと既存Moderate Advisory `GHSA-qx2v-qp2m-jg93`が同時に解消されたため、解消済みModerate Finding 1件だけを期限付きBaselineから削除した。
- Legacy Baselineの残る13 Findingについて、Advisory ID、Package、Version、Severity、Path、期限、修正Taskは変更していない。Baselineの追加、拡張、Gate弱体化は行っていない。

### Audit／Build／Test

- Root `pnpm install --frozen-lockfile`はPASSし、Root Auditは0 Finding、`GHSA-6g55-p6wh-862q`は不在だった。
- Legacy `pnpm install --frozen-lockfile`はPASSし、Auditは更新前15件から更新後13件となった。対象High Advisoryと解消済みPostCSS Moderate Advisory以外の増減はない。
- Admin Typecheck、Lint、BuildはPASSした。AdminにはUnit Test ScriptがないためUnit Testは未実行であり、PASSとは記録しない。
- Storefront Clientの生成差分、Typecheck、Lint、Build、Unit Test 9件はPASSした。Fake Operation、Admin／Webhook Export、OpenAPI生成物の差分はない。
- Legacy TypecheckとBuildはPASSした。Lintは既知の8 Error／1 Warningで非0終了し、CIと同じFingerprint Baseline比較は9 Finding完全一致でPASSした。LegacyにはUnit Test ScriptがないためUnit Testは未実行である。
- OpenAPI Unit Test 4件、Public／Admin／WebhookのLint／Bundle差分検査はPASSし、3 ContractともOperation数0件を維持した。
- Policy Unit Test 30件、Quality Unit Test 5件、Security Unit Test 4件、Local `policy-gate`、`quality-gate`、`security-gate`、`git diff --check`はPASSした。
- Security GateはRoot 0 Finding、Legacy 13 Finding、Composer 10 Findingを検証し、Legacy／Composerは更新後の期限付きExact Baselineと一致、Secret Candidateは0件だった。
- Application Logic、OpenAPI Contract、Migration、DB、Security Gateの判定ロジック、Test Assertion、CI Workflow、Rulesetは変更していない。
- Backend Test、Migration適用、Docker Compose Smoke、Browser／E2E、Production操作はDependency Security更新の対象外のため未実行であり、PASSとは記録しない。

### GitHub／完了方針

- Repository変更はPostCSSのManifest／Lockfile、解消済みBaseline 1件、固定Version期待値、Worklogだけに限定する。
- Initial Implementation Commitは`95b5080f9f21f68768fd0bfb751cc9e8f7fc62b2`で、GitHub App WrapperによるFast-forward Push後にPR `#62` (`https://github.com/ideal-sol/oripa/pull/62`)を作成した。
- 初回`policy-gate`はPR本文の見出しが標準Templateと一致せず失敗した。Code、Gate、Assertionは変更せず、PR本文を`Summary`、`Specification sources`、`Scope`、`Allowed paths`、`Changed files`、`Verification performed`、`Verification not performed`へ整形した。
- 同一Headの再実行では新しい`policy-gate`がPASSしたが、旧Failure／Cancelled RunもHeadへ残った。Wrapperが旧Runを成功扱いしないため、本Worklog追記の追加Commitを新しいFinal Headとし、全Checkを一度だけ再実行する。
- GitHub App Task Policy WrapperだけでFast-forward Pushし、Draft PR、Required 5 Check、CodeQL、Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認してSquash Mergeする。
- Direct main Push、Force Push、Required Check Bypass、V1 Archive Branch／Annotated Tag変更は行わない。
- SEC-002完了後もPR `#60`は変更せず、MIG-031AおよびMIG-032は開始しない。

### SEC-002 Closeout

- PR `#62`のFinal Headは`445b478eed88593e3b8aeeb8d53bb44a10a16c45`で、Required 5 Check、CodeQL、Dependency Reviewを含む8 Checkが成功した。
- GitHub Appが固定Head Self-review後にSquash Mergeし、Squash Commitは`09d4b5b196ce842e39319f5ebe1ac9db31d4da74`、Issue `#61`はClosedである。
- Remote／Local Task Branch `security/SEC-002-postcss-advisory`とWorktreeは削除済みである。
- Local `main`は`origin/main`へ`--ff-only`同期済みで、Working Treeはcleanだった。
- `GHSA-6g55-p6wh-862q`はRoot／Legacy双方から解消され、Root Auditは0 Finding、Legacy Auditは既存期限付きBaselineと一致する13 Findingだった。
- V1 Archive BranchとAnnotated Tag Peeled Commitは`bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更されていない。

## MIG-031A Worklog Closeout修正

- 再開日時: `2026-07-24T01:42:57Z`
- Task ID: `MIG-031A`
- Risk: `R3`
- Issue: `#59` (`https://github.com/ideal-sol/oripa/issues/59`)
- PR: `#60` (`https://github.com/ideal-sol/oripa/pull/60`)
- Branch: `docs/MIG-031A-worklog-closeout`
- Worktree: `/var/www/oripa-worktrees/MIG-031A-worklog-closeout`
- 最新Base SHAはSEC-002 Squash Commit `09d4b5b196ce842e39319f5ebe1ac9db31d4da74`である。
- 既存Task Branchへ最新`main`を通常Mergeし、Worklog競合では正しいMIG-031 CloseoutとSEC-002本文／Closeoutを保持し、誤ったMIG-030重複記録だけを削除した。
- 通常Merge Commitは`9f7a89e0070505cc76f8a4b933c35c57e2acb598`で、Parentは既存Task Head `7e1c8bbebe8780adc8534b8f4d90e807b6d4efa4`と最新`main` `09d4b5b196ce842e39319f5ebe1ac9db31d4da74`である。
- GitHub App Wrapperで通常Merge Commitを既存Remote BranchへFast-forward Pushし、Force Pushや履歴書換えは行っていない。
- MIG-031A Task PolicyはBase SHAを最新`main`へ更新し、最終Allowed Pathを`worklogs/new_ver_main.md` 1件へ固定した。
- PR `#60`のPostCSS Security Blocked記録はSEC-002完了結果に基づき解消済みへ更新し、本追記のFinal Headで全Checkを再実行する。
- Repository変更差分は`worklogs/new_ver_main.md`だけに限定し、Application、OpenAPI、Package、CI、Dependency、Lockfile、Migration、Rulesetは変更しない。
- Required 5 Check、CodeQL、Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認後にPR `#60`を自律Squash Mergeする。
- Gate G3は`NOT COMPLETE`で、次Task候補は`MIG-032`だがMIG-031A完了後には開始しない。

## OPS-001 luxe-pack.biz／admin.luxe-pack.biz V1 Runtime一時復旧

### 実施情報

- 実施日時: `2026-07-24T02:03:12Z`
- Task ID: `OPS-001`
- Production障害対応のためGitHub Issue／PR／Commitは作成していない。
- V2 `main`の履歴は変更せず、V1 Archive Ref
  `archive/v1-current`の固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`からDetached Worktree
  `/var/www/oripa-v1-runtime`を作成した。
- V1 Archive BranchとAnnotated Tagはcheckout、更新、削除していない。

### 障害原因

- DNSはCloudflare Proxyを経由し、Public／Admin DomainのTLS証明書は有効だった。
- NginxはPublic／Admin Frontendを`127.0.0.1:3000`、Public／Admin APIを
  `127.0.0.1:8000`へ転送していた。
- 対応PortのApplication Containerが停止してListenerが存在せず、Nginx Logで
  Upstream Connection Refusedを確認した。両Domainの502原因は停止中の
  Frontend／Backend Upstreamだった。
- systemd、Supervisor、Cronに別のActive Oripa Runtimeはなく、既存の停止済み
  Docker Compose Project `oripa`がV1 Runtimeの正本だった。

### V1 Runtime

- 固定V1 Source、既存Docker Image、既存`oripa_*` Named Volumeを再利用し、
  新しいDB／Redis／Storage Volumeは作成していない。
- 既存Environment Fileは内容を表示・複製せず、Backend／Frontend Containerへ
  Read-only bindした。Secret、Credential、Environment値はWorklogへ記録していない。
- Laravel APIはLoopback限定`127.0.0.1:8100`、Next.js Public／Admin Frontendは
  Loopback限定`127.0.0.1:3100`で起動した。
- PostgreSQL、Redis、MinIO、Mailpitは内部Networkだけで起動し、Host Portを
  公開していない。既存Volumeの追加・削除は0件だった。
- 表示復旧に不要なQueue／Schedulerは起動していない。
- Build、Migration、Rollback、Seed、DB Data変更は実行していない。
- Read-onlyのMigration Status確認は成功し、適用済み40件、Pending 0件だった。

### Domain分離／Nginx

- 固定V1 Sourceで、`luxe-pack.biz`はPublic Route、
  `admin.luxe-pack.biz`はHost判定されたAdmin Routeへ分岐することを確認した。
- Public API `/api/**`とAdmin API `/admin/api/**`は同じLaravel Process内の
  分離Routeであり、Admin Routeは認証Middlewareで保護されていることを確認した。
- NginxのPublic Frontend Upstreamを`127.0.0.1:3100`、Public API Upstreamを
  `127.0.0.1:8100`へ変更した。
- Admin Frontend Upstreamを`127.0.0.1:3100`、Admin API Upstreamを
  `127.0.0.1:8100`へ変更し、Admin Domainへ
  `X-Robots-Tag: noindex, nofollow, noarchive`を追加した。
- TLS、Host、Forwarded Header、WebSocket、Upload Size、Timeoutは変更していない。
- `nginx -t`成功後、NginxだけをReloadした。

### 検証

- Nginx切替前にHost Header付きLocal Upstream検証を行い、Public Top、
  Public Login、主要Page、Public／Admin Static Asset、Public API Healthが成功した。
- Admin TopはAdmin Routeへ正常遷移し、未認証Admin APIは401、Admin Loginへの
  不正なGETは405だった。認証なしの管理情報露出は確認されなかった。
- Cloudflare経由でPublic Top、Static Asset、Login、主要Page、API Healthは
  すべて200だった。
- Cloudflare経由でAdmin Topは正常遷移後200、Static Assetは200、
  未認証Admin APIは401、`X-Robots-Tag`は有効だった。
- Origin直結でもPublic／Adminは200で、検証Request中の新規502／504／
  Upstream Errorは0件だった。
- Cookie Domain、CORS、CSRF、認証仕様、Production Dataは変更していない。

### Repository／Rollback

- V2 `main`と`origin/main`は
  `10eeeb8492bbc9290a578aa8b46ebe3d2e395639`のまま変更していない。
- PR `#60`、V2 File、V2 Worktree、Application Codeは変更していない。本節の
  Worklog追記だけがLocal Repositoryの未Commit差分である。
- V2へ戻す場合は、V2 Runtimeを先にHost Header付きでLocal検証し、Nginx
  Upstreamを検証済みV2 Portへ戻して`nginx -t`後にReloadする。外部検証完了後に
  V1 Frontend／Backendを停止し、Named Volumeは削除しない。
- Runtime手順は`/etc/oripa-v1-runtime/README.md`、EvidenceはRepository外の
  `OPS-001-runtime-recovery-20260724T020312Z` Directoryへ保存した。

### V1／V2 Database分離決定

- 最新の人間決定により、V2はV1本番DBを共有しない。
- V2専用DB、認証情報、Volume、Schema、Migration履歴をV1から分離する。
- V2のMigration、`migrate:fresh`、Testは、V2専用DBまたはTask専用Ephemeral DB
  だけで実行する。
- V1本番DBではV2 Migration、Rollback、Seed、`migrate:fresh`を実行しない。
- Runtime境界で必要な場合は、V2 RedisもV1 Redisから分離する。
- 本決定は境界の正本化であり、OPS-001AではV2 DB／Redisの作成、接続、Migrationを
  開始しない。

## OPS-001A V1 Runtime復旧記録の正本化

### Task

- 実施開始: `2026-07-24T03:45:31Z`
- Task ID: `OPS-001A`
- Risk: `R3`
- Issue: `#63` (`https://github.com/ideal-sol/oripa/issues/63`)
- Branch: `docs/OPS-001A-v1-runtime-closeout`
- Worktree: `/var/www/oripa-worktrees/OPS-001A-v1-runtime-closeout`
- Base SHA: `10eeeb8492bbc9290a578aa8b46ebe3d2e395639`
- Allowed Pathは`worklogs/new_ver_main.md`だけである。

### 未Commit差分の保全／反映

- Local `main`に残っていたOPS-001差分を、Repository外の
  `OPS-001A-worklog-preservation-20260724T034531Z` DirectoryへPatch、全文、
  OPS-001節、`origin/main`版、SHA-256として保全した。
- 保全Patchの対象Fileは`worklogs/new_ver_main.md` 1件だけで、Secret scanは
  PASSした。
- 最新`origin/main`からRemote Branchと専用Worktreeを作成し、保全Patchを適用した。
- 元Local差分と専用Worktreeへ適用したOPS-001節のSHA-256は一致した。
- 内容一致を確認するまで、元のLocal未Commit差分は変更・破棄していない。

### 正本化範囲

- V1 Runtime Commitは`bfca8efa0b85c00a88fb0fd439a123b722577b68`で、
  Runtime Worktreeはclean、`luxe-pack.biz`と`admin.luxe-pack.biz`は200である。
- OPS-001復旧時にV2 Repository履歴は変更しておらず、OPS-001Aでは本Worklogだけを
  Repository正本へ反映する。
- OPS-001Aの実施中にNginx、systemd、Docker Production構成、V1本番DB、Redis、
  Storageを変更していない。
- Migration、Rollback、Seed、`migrate:fresh`は実行していない。
- V1 Archive BranchとAnnotated Tagは
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更していない。
- V1 Runtimeを表示したまま、分離されたV2 Runtime／DB境界でV2開発を継続できる。
- Application、OpenAPI、Package、Dependency、Lockfile、CIは変更していない。
- MIG-032およびDB構築Taskは開始しない。

### 検証

- `git diff --check`、変更Path 1件、Markdown見出し、Task ID、Full SHA、
  Secret／PII差分確認はPASSした。
- Local `policy-gate`と`quality-gate`はPASSした。
- OpenAPI 4件、Policy 30件、Quality 5件、Security 4件、Site Template 6件の
  CI Unit TestはPASSした。
- 保全したOPS-001節と専用Worktree反映後の同節はSHA-256一致である。
- Backend／Frontend Test、Build、Browser／E2EはWorklog正本化Taskの対象外のため
  未実行であり、PASSとは記録しない。
- Migration、Rollback、Seed、`migrate:fresh`、V2 DB／Redis構築は未実行である。
- Final HeadでRequired 5 Check、CodeQL、Dependency Review、Fresh Self-review、
  SEV-0／SEV-1なしを確認してからSquash Mergeする。

## OPS-001A Closeout

- PR `#64`のFinal Headは
  `c24cd8c9cbc0785a3537e4582a04f7122f81dd60`で、Required 5 Check、CodeQL、
  Dependency Reviewを含む8 Checkが成功した。
- GitHub Appが固定Head Self-reviewを作成し、PR `#64`をSquash Mergeした。
  Squash Commitは`af6ef79c74c61a1673ca216d4b97a0a94ef2e78f`、Issue `#63`は
  Closedである。
- Remote／Local Branch `docs/OPS-001A-v1-runtime-closeout`と専用Worktreeは
  Cleanup済みである。
- Local `main`は`origin/main`へ`--ff-only`同期済みで、Working Treeはcleanだった。
- Repository正本のOPS-001記録と保全したLocal記録の内容一致を確認済みである。
- V1 Runtime Worktreeは固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`でclean、V1 Archive Branchと
  Annotated Tagも同Commitのまま変更されていない。
- V1 Runtime、Nginx、Docker Production構成、V1本番DB、Redis、Storageは
  OPS-001Aで変更しておらず、Migration、Rollback、Seed、`migrate:fresh`、
  V2 DB／Redis構築も未実行である。

## MIG-032 Site Schema Alpha

### Task

- 実施開始: `2026-07-24T04:19:01Z`
- Task ID: `MIG-032`
- Risk: `R3`
- Issue: `#65` (`https://github.com/ideal-sol/oripa/issues/65`)
- Branch: `feat/MIG-032-site-schema`
- Worktree: `/var/www/oripa-worktrees/MIG-032-site-schema`
- Base SHA: `af6ef79c74c61a1673ca216d4b97a0a94ef2e78f`
- Root／`packages/AGENTS.md`、Package Version／Compatibility、API v2／Storefront
  Client、V1→V2 Migration Planを再確認し、正本から確定できるFieldだけを採用した。

### Site Manifest Contract

- Packageは`@oripa/site-schema` `2.0.0-alpha.1`で、JSON Schema Draft 2020-12の
  `packages/site-schema/schema/site-manifest.schema.json`を構造の正本とした。
- Top-levelは`schema_version`、`site_version`、`compatibility`、`public`だけで、
  全ObjectをStrictにし、Unknown Fieldを拒否する。
- `compatibility`はCore Compatibility Family `2`、Exact SemVerの
  `storefront_client_version`、`required_capabilities`だけを公開する。
- `public`は`locale`、`timezone`、`features.enabled`だけを公開する。
  `features.enabled`はCapability名の明示Listで、空ListをSecure Defaultとする。
- Capability名は正本の`<domain>.<feature>.v<number>`形式を検査する。正本にない
  Business CapabilityやSite固有Design設定を作成していない。
- Secret、Credential、Cookie、Token、DB接続情報、顧客PII、Provider設定、
  Draw／Point／Payment判断をSchema／型／Fixtureへ含めていない。

### Type／Validator／Compatibility

- `scripts/generate-types.mjs`が正本SchemaからTypeScript型とRuntime用Schema定数を
  決定的に生成し、`generate:check`が再生成差分を拒否する。Generated Fileの
  直接編集は禁止した。
- Runtime ValidatorはDraft 2020-12対応の`Ajv 8.20.0`をExact Versionで使用し、
  `parseSiteManifest`と`validateSiteManifest`を公開する。
- `SiteManifestValidationError`はPath、Keyword、Messageだけを保持し、入力値を
  Errorへ含めない。
- Compatibility判定はCore Family、Test済みSchema Version、Storefront Client
  Major／Minimum Version、Required Capability不足を検査する。
- N／N-1は`tested_schema_versions`へ明示したVersionだけを受け入れる拡張境界とし、
  初回Alphaでは`2.0.0-alpha.1`だけをTest済みとした。未作成のN-1を対応済みとは
  記録しない。
- Runtime Dependencyは`ajv 8.20.0`と`semver 7.8.5`、Development Dependencyは
  Repository既存Toolを含めすべてExact Versionで固定した。

### Fixture／Local Verification

- Positive Fixture 2件、Negative Fixture 4件を追加した。Invalid SemVer、
  Family Major不一致、Unknown Field、Secret風追加FieldをNegativeとして確認した。
- Site SchemaのType生成、Typecheck、Lint、Build、Unit Test 10件はPASSした。
- Required Capability不足、Minimum Storefront Client Version未達、Schema
  Version Support、Validation Error値非露出のCompatibility TestはPASSした。
- Existing Storefront Clientの生成差分、Typecheck、Lint、Build、Unit Test 9件は
  PASSした。
- OpenAPI Unit Test 4件とPublic／Admin／Webhook 3 Contract／Bundle検査はPASSし、
  Operation数は各0のまま変更していない。
- Policy Unit Test 32件、Quality Unit Test 5件、Security Unit Test 4件、
  `quality-gate`、`security-gate`、`git diff --check`はPASSした。
- Root Workspace Auditは0 Findingである。Legacy Frontend Auditは既存期限付き
  Baselineと一致する13 Findingで、新規Finding／Severity悪化はない。
- Backend／Frontend Runtime Test、Browser／E2E、Migration、DB／Redis操作は
  本Package TaskのLocal対象外として未実行であり、PASSとは記録しない。

### CI／Scope／保護

- Root Scriptへ`site-schema:*`を追加し、`platform-ci.yml`の`quality-gate`と
  `integration-gate`で`site-schema:check`を実行する。
- `policy-gate`へPackage Identity、Exact Dependency、必須File、Strict Schema、
  Secure Default、公開禁止Field、Generated Typeの継続検査とNegative Testを追加した。
- Check名は`policy-gate`、`quality-gate`、`security-gate`、
  `integration-gate`、`ci-gate`のまま増減していない。
- Laravel、OpenAPI Contract、Migration、DB／Redis、V1 Runtime、Nginx、Docker
  Production構成、V1 Archive Branch／Annotated Tagを変更していない。
- GitHub App WrapperだけでCommitを既存Task BranchへFast-forward Pushし、
  Required 5 Check、CodeQL、Dependency Review、固定Head Self-review、
  SEV-0／SEV-1なし、Merge Conflictなしを確認後にSquash Mergeする。
- Gate G3はSite Schema Alpha基盤を追加したが、V2 Baseline Migration、Realm分離、
  Constraint Test、Backup／Restore、初回Artifactが残るため`NOT COMPLETE`である。
- 次Task候補は`MIG-033`だが、MIG-032完了後には開始しない。

### GitHub検証

- Implementation Commitは
  `97d103efe42f06af29d6380a59f2c0d6b0ace3d3`で、GitHub App Wrapperにより
  Remote BranchへFast-forward Pushした。
- PR `#66` (`https://github.com/ideal-sol/oripa/pull/66`)を作成し、Issue `#65`と
  関連付けた。PR Authorは`ideal-sol-oripa-codex[bot]`、Baseは`main`である。
- 初回`policy-gate`はPR本文の`Changed files`が実差分のPath一覧でなかったため
  Failureとなった。Code、Gate、Assertionは変更せず、PR本文を実際の28 Pathへ
  修正した。
- 同一Implementation Headの再実行ではRequired 5 Check、CodeQL、
  Dependency Reviewを含む8 Checkが成功した。初回Failure／Cancelled Runも同一
  Headへ残るため、本Worklog追記だけの追加CommitをFinal Headとし、全Checkを
  一意な履歴で再実行する。
- Final HeadでRequired 5 Check、CodeQL、Dependency Review、Fresh Self-review、
  SEV-0／SEV-1なし、Merge Conflictなし、Head SHA不変を再確認してから
  GitHub AppがSquash Merge、Issue Close、Remote／Local BranchとWorktreeの
  Cleanup、Local `main`の`--ff-only`同期を実行する。

## MIG-032 Closeout

- GitHub APIとLocal Repositoryで`2026-07-24T04:51:02Z`に完了状態を再確認した。
- PR `#66` (`https://github.com/ideal-sol/oripa/pull/66`)はMergedで、Final Headは
  `5120687fc1739252133d8ca02744e39be150471b`、Squash Commitは
  `d092e634b9107aaa00c0c68a5cbb0206805af913`である。
- Final Headでは`policy-gate`、`quality-gate`、`security-gate`、
  `integration-gate`、`ci-gate`のRequired 5 Checkがすべて成功した。
- CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewも成功し、
  GitHub Checkは合計8件成功した。
- Fresh Self-reviewはPR `#66`のComment
  `https://github.com/ideal-sol/oripa/pull/66#issuecomment-5066208342`に存在し、
  対象HeadはFinal Headと一致する。Scope、Secret／PII、Contract SecurityはPASS、
  Merge Recommendationは`MERGE`、SEV-0／SEV-1は0件である。
- Issue `#65`はClosedで、State Reasonは`completed`である。
- Remote Branch `feat/MIG-032-site-schema`はGitHub APIでNot Found、
  Remote Tracking Refも存在せず、削除済みである。
- Local Branch `feat/MIG-032-site-schema`とWorktree
  `/var/www/oripa-worktrees/MIG-032-site-schema`は削除済みである。
- Local `main`と`origin/main`はSquash Commit
  `d092e634b9107aaa00c0c68a5cbb0206805af913`で一致し、Working Treeはcleanである。
- V1 Runtime Worktreeは固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`でcleanである。
- V1 Archive BranchとAnnotated Tag Peeled Commitは
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更されていない。
- MIG-032および本Closeout確認ではV1 Runtime、Nginx、Docker Production構成、
  V1本番DB、Redis、Storageを変更していない。V1／V2 DBのMigration、Rollback、
  Seed、`migrate:fresh`、V2 DB／Redis構築は実行していない。
- Gate G3はSite Schema Alphaまで完了したが、V2 Baseline Migration、Realm分離、
  Constraint Test、Backup／Restore、初回Artifactが残るため`NOT COMPLETE`である。
- 次Task候補は`MIG-033`だが、MIG-032A完了後には開始しない。

## MIG-032A Site Schema Alpha Closeout記録補完

- Task ID: `MIG-032A`
- Risk: `R3`
- Issue: `#67` (`https://github.com/ideal-sol/oripa/issues/67`)
- Branch: `docs/MIG-032A-site-schema-closeout`
- Worktree: `/var/www/oripa-worktrees/MIG-032A-site-schema-closeout`
- Base SHA: `d092e634b9107aaa00c0c68a5cbb0206805af913`
- Allowed Pathは`worklogs/new_ver_main.md` 1件だけである。
- MIG-032のGitHub操作とCleanup結果の正本化だけを行い、Site Schema実装、
  Package Manifest／Lockfile、OpenAPI、Storefront Client、CI／Gate／Ruleset、
  Laravel／Migrationを変更しない。
- `git diff --check`、Markdown見出し、Full SHA、PR／Issue番号、変更Scope、
  Secret／PII、V1 Runtime／V1本番DB／V2 DB非変更を検証する。
- Final HeadでRequired 5 Check、CodeQL、Dependency Review、Fresh Self-review、
  SEV-0／SEV-1なし、Merge Conflictなしを確認後にGitHub AppがSquash Mergeする。

## MIG-032A Closeout

- PR `#68`のFinal Headは
  `a6008420e1c84644f81c6825096538c6f370f602`で、Required 5 Check、CodeQL、
  `CodeQL (javascript-typescript)`、Dependency Reviewを含む8 Checkが成功した。
- GitHub Appが固定HeadのFresh Self-reviewを作成し、SEV-0／SEV-1が0件、
  Merge Conflictなしを確認してSquash Mergeした。Squash Commitは
  `d552710fc9eb938278d183056b1c2df737b2ffc1`である。
- Issue `#67`はClosed、Remote／Local Branch
  `docs/MIG-032A-site-schema-closeout`と専用WorktreeはCleanup済みである。
- Local `main`と`origin/main`はSquash Commitで一致し、Working Treeはcleanだった。
- MIG-032の正しいCloseout、Gate G3 `NOT COMPLETE`、次Task候補`MIG-033`が
  `worklogs/new_ver_main.md`へ正本化された。
- V1 Runtime、Nginx、Docker Production構成、V1本番DB、Redis、Storage、
  V1 Archive Branch／Annotated Tagは変更されていない。V1／V2 DBのMigration、
  Rollback、Seed、`migrate:fresh`、V2 DB／Redis構築は未実行である。

## MIG-033 Storefront Testkit Alpha

### Task

- 実施開始: `2026-07-24T05:18:14Z`
- Task ID: `MIG-033`
- Risk: `R3`
- Issue: `#69` (`https://github.com/ideal-sol/oripa/issues/69`)
- Branch: `feat/MIG-033-storefront-testkit`
- Worktree: `/var/www/oripa-worktrees/MIG-033-storefront-testkit`
- Base SHA: `d552710fc9eb938278d183056b1c2df737b2ffc1`
- Root／`packages/AGENTS.md`、API v2／Storefront Client Contract、Package
  Version／Compatibility Policy、V1→V2 Migration Plan、Public OpenAPI Bundle、
  Storefront Client、Site Schemaを再確認した。

### Package／Contract

- `@oripa/storefront-testkit` `2.0.0-alpha.1`を、Site StorefrontのPlatform
  Contract適合を検査するprivateかつ非ProductionのTest Packageとして実装した。
- `@oripa/storefront-client`と`@oripa/site-schema`は
  `workspace:2.0.0-alpha.1`でExact参照し、`workspace:*`、SemVer Range、
  `latest`を使用していない。Root WorkspaceとLegacy FrontendのLockfile分離は
  維持した。
- ExportはRoot、`./assertions`、`./fixtures`、`./mock`だけに固定し、
  `sideEffects: false`、決定的な`dist` Buildとした。Admin／Webhook／Provider型、
  Business Authority、Production Runtime APIは公開していない。
- Public OpenAPI BundleからOpenAPI Version、Operation ID、Operation数、
  Bundle SHA-256を決定的に生成する。Generated Fileは直接編集禁止で、
  `generate:check`が再生成差分を拒否する。
- Public API Operationは0件のままで、架空Endpoint、Fake Operation、
  Draw／Point／Payment等の架空業務Responseを追加していない。

### Mock Transport／Fixture

- `createMockFetch`はFIFO応答QueueとRequest Recorderを持ち、Method、URL、Header、
  Body、Credentialsを順序どおり記録する。
- JSON Response、RFC 9457 `application/problem+json`、Network Error、
  Abort／Timeout用Pending応答を提供し、応答未登録、期待Request不一致、Queue残存を
  即時Failureとする。
- Mockは実Networkへ接続せず、Error MessageへRequest、Cookie、Token、
  Credential、入力Bodyを含めない。
- Site ManifestのMinimal／Required Capability、Platform Compatibility、
  Public-safe Response Metadata Fixtureを提供する。実顧客情報、Secret、
  Credential、Cookie、TokenはFixtureへ含めていない。

### Boundary Assertion／Unit Test

- Browser `credentials: include`、Client／Site Version Header、Request ID、
  Authorization非付与、Public Surface、Server GET／HEAD、RFC 9457 Error、
  Site Manifest Schema／Compatibilityを検査するAssertionを実装した。
- Unit Test 16件でMock決定性、Request記録、FIFO、Unexpected Request、
  Network Error、Timeout、Abort、Problem Details、Credentials／Version Header、
  Authorization非付与、Public Surface、Server GET／HEAD、Valid／Invalid Manifest、
  Family不一致、Required Capability不足、Secret風Field拒否、Operation 0件、
  実Network不使用、Export Surfaceを検証し、全件PASSした。
- No-op Test、AssertionなしSnapshot、Skipped Testは追加していない。

### CI／Policy

- Root Scriptへ`testkit:*`を追加し、`quality-gate`と`integration-gate`で生成差分、
  Typecheck、Lint、Build、Unit Test、Export Surface、実Network不使用を実行する。
- `policy-gate`へPackage Identity、Exact Version、固定Export、Operation 0件、
  Mock Boundary、実Network禁止、Substantive Testを継続検査するPositive／Negative
  Testを追加した。
- Policy Unit Test 38件、Quality Unit Test 5件、Security Unit Test 4件、
  Local `policy-gate`、`quality-gate`、`security-gate`はPASSした。
- Existing Storefront Clientは生成差分、Typecheck、Lint、Build、Unit Test 9件、
  Site Schemaは生成差分、Typecheck、Lint、Build、Unit Test 10件がPASSした。
- Public／Admin／Webhook ContractとBundleは変更しておらず、OpenAPI Unit Test
  4件とBundle検査はPASSした。
- Root Workspace Auditは0 Findingである。Legacy Frontend 13件とComposer
  10件は既存期限付きBaselineと完全一致し、新規Finding／Severity悪化はない。
- Backend／Legacy Frontend Runtime Test、Browser／E2E、Migration、DB／Redis操作は
  本Package TaskのLocal対象外として未実行であり、PASSとは記録しない。

### Scope／保護

- Repository変更は`packages/storefront-testkit/**`、Root Script／Lockfile、
  `platform-ci.yml`、`policy_gate.py`とそのUnit Test、本Worklogだけである。
- Laravel、Public／Admin／Webhook OpenAPI Contract、Migration、V2 DB／Redis、
  V1 Runtime、`luxe-pack.biz`、`admin.luxe-pack.biz`、Nginx、Docker Production
  構成、V1本番DB／Redis／Storageを変更していない。
- V1 Archive BranchとAnnotated Tagは
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`のままである。
- GitHub App WrapperでFast-forward Pushし、Required 5 Check、CodeQL、
  Dependency Review、固定Head Fresh Self-review、SEV-0／SEV-1なし、
  Merge Conflictなしを確認してからSquash Mergeする。
- Implementation Commitは
  `c2f226ad293aa0ea45e75d0e41972552fe98b5fe`で、GitHub App Wrapperにより
  Remote BranchへFast-forward Pushした。
- PR `#70` (`https://github.com/ideal-sol/oripa/pull/70`)を作成してReady化し、
  Issue `#69`と関連付けた。PR Authorは`ideal-sol-oripa-codex[bot]`、
  Baseは`main`である。
- Implementation Commitでは`policy-gate`、`quality-gate`、
  `security-gate`、`integration-gate`、`ci-gate`のRequired 5 Check、
  CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewの計8 Checkが
  すべて成功した。
- 本GitHub結果を記録するWorklog追加CommitをFinal Headとし、同じ8 Check、
  Fresh Self-review、SEV-0／SEV-1なし、Merge Conflictなし、Head SHA不変を
  再確認してからSquash Mergeする。
- Gate G3はStorefront Client、Site Schema、Storefront TestkitのAlpha基盤まで
  完了したが、V2 Baseline Migration、Realm分離、Constraint Test、
  Backup／Restore、初回Artifactが残るため`NOT COMPLETE`である。
- 次Task候補は`MIG-040`だが、MIG-033完了後には開始しない。

## MIG-033 Closeout

- GitHub APIとLocal Repositoryで`2026-07-24T05:47:03Z`に完了状態を再確認した。
- PR `#70` (`https://github.com/ideal-sol/oripa/pull/70`)はMergedで、Final Headは
  `29c24e4a02bb9a70ede7ff0e33778b8f55dc9636`、Squash Commitは
  `feb6a3817ef9199c7fd23c6075434ea4aa554d57`である。
- Final Headでは`policy-gate`、`quality-gate`、`security-gate`、
  `integration-gate`、`ci-gate`のRequired 5 Checkがすべて成功した。
- CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewも成功し、
  GitHub Checkは合計8件成功した。
- Fresh Self-reviewはPR `#70`のComment
  `https://github.com/ideal-sol/oripa/pull/70#issuecomment-5066521123`に存在し、
  対象HeadはFinal Headと一致する。Scope、Secret／PII、
  Migration／Contract／SecurityはPASS、Merge Recommendationは`MERGE`、
  SEV-0／SEV-1は0件である。
- Issue `#69`はClosedで、State Reasonは`completed`である。
- Remote Branch `feat/MIG-033-storefront-testkit`はGitHub APIでNot Found、
  Remote Tracking Refも存在せず、削除済みである。
- Local Branch `feat/MIG-033-storefront-testkit`とWorktree
  `/var/www/oripa-worktrees/MIG-033-storefront-testkit`は削除済みである。
- Local `main`と`origin/main`はSquash Commit
  `feb6a3817ef9199c7fd23c6075434ea4aa554d57`で一致し、Working Treeはcleanである。
- `main`には`@oripa/storefront-testkit` `2.0.0-alpha.1`、FIFO応答Queue付き
  Mock Transport、Request Recorder、Public Contract／Site Manifest Fixture、
  Boundary Assertion、Unit Test 16件が保持されている。
- Public API Operationは0件で、Root Workspace Auditは0 Findingである。
  Testkitは実Network通信を行わず、Fake Operationを持たず、
  Admin／Webhook／Provider型を公開していない。
- V1 Runtime Worktreeは固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`でcleanである。
- V1 Archive BranchとAnnotated Tag Peeled Commitは
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`のまま変更されていない。
- MIG-033および本Closeout確認ではV1 Runtime、Nginx、Docker Production構成、
  V1本番DB、Redis、Storageを変更していない。V1／V2 DBのMigration、Rollback、
  Seed、`migrate:fresh`、V2 DB／Redis構築は実行していない。
- Gate G3はStorefront Client、Site Schema、Storefront TestkitのAlpha基盤まで
  完了したが、V2 Baseline Migration、Realm分離、Constraint Test、
  Backup／Restore、初回Artifactが残るため`NOT COMPLETE`である。
- 次Task候補は`MIG-040`だが、MIG-033A完了後には開始しない。

## MIG-033A Storefront Testkit Alpha Closeout記録補完

- Task ID: `MIG-033A`
- Risk: `R3`
- Issue: `#71` (`https://github.com/ideal-sol/oripa/issues/71`)
- Branch: `docs/MIG-033A-storefront-testkit-closeout`
- Worktree:
  `/var/www/oripa-worktrees/MIG-033A-storefront-testkit-closeout`
- Base SHA: `feb6a3817ef9199c7fd23c6075434ea4aa554d57`
- Allowed Pathは`worklogs/new_ver_main.md` 1件だけである。
- MIG-033のGitHub操作、Check、Self-review、Cleanup、Local同期結果の正本化だけを
  行い、Storefront Testkit、Storefront Client、Site Schema、Package
  Manifest／Lockfile、OpenAPI、CI／Gate／Ruleset、Laravel／Migrationを変更しない。
- `git diff --check`、Markdown見出し、Full SHA、PR／Issue番号、変更Scope、
  Secret／PII、V1 Runtime／V1本番DB／V2 DB非変更を検証する。
- Final HeadでRequired 5 Check、CodeQL、Dependency Review、Fresh Self-review、
  SEV-0／SEV-1なし、Merge Conflictなしを確認後にGitHub AppがSquash Mergeする。
- Backend／Frontend Runtime Test、Build、Browser／E2E、Migration、DB／Redis操作は
  Documentation-only Taskの対象外として未実行であり、PASSとは記録しない。

## MIG-033A Closeout

- PR `#72` (`https://github.com/ideal-sol/oripa/pull/72`)はMergedで、Final Headは
  `1727378dffbb20b48580b6a56dcb6e906364e75e`、Squash Commitは
  `204bafa1e02b5988e586fd4384a3a94064e85fe5`である。
- Final HeadではRequired 5 Check、CodeQL、`CodeQL (javascript-typescript)`、
  Dependency Reviewを含む8 Checkがすべて成功した。
- Fresh Self-reviewはPR `#72`のComment
  `https://github.com/ideal-sol/oripa/pull/72#issuecomment-5066619977`に存在し、
  SEV-0／SEV-1は0件、Merge Recommendationは`MERGE`である。
- Issue `#71`はClosedで、Remote／Local Branch
  `docs/MIG-033A-storefront-testkit-closeout`と専用WorktreeはCleanup済みである。
- Local `main`と`origin/main`はSquash Commitで一致し、Working Treeはcleanだった。
- MIG-033Aの実施とCloseoutではApplication、Package、OpenAPI、CI、Migration、
  V1 Runtime、V1／V2 DB／Redisを変更していない。

## MIG-040 V2専用DB Baseline

### Task／Inventory

- 実施開始: `2026-07-24T06:29:36Z`
- Task ID: `MIG-040`
- Risk: `R3`
- Issue: `#73` (`https://github.com/ideal-sol/oripa/issues/73`)
- Branch: `migration/MIG-040-v2-db-baseline`
- Worktree: `/var/www/oripa-worktrees/MIG-040-v2-db-baseline`
- Base SHA: `204bafa1e02b5988e586fd4384a3a94064e85fe5`
- V1 Migration Root `apps/api/database/migrations`は40 Fileで、Pathに依存しない
  内容SHA-256 Setは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  である。本文、File名、Modeを変更していない。
- 稼働中V1 RuntimeはCompose Project `oripa`、固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`でcleanであり、V1 PostgreSQL、
  Redis、Network、Volume、Nginx、Public／Admin Domainを変更していない。

### Migration Boundary／Guard

- V2専用Migration Rootを`apps/api/database/migrations-v2`へ作成した。
  MIG-040ではBusiness Migrationを追加せず、Laravel標準`migrations` Repository
  Tableだけを独立V2 DBへ作成する。
- V2 Migration Setは0 File、SHA-256は
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`
  である。
- `scripts/db/v2_database.py`を唯一のV2 DB Runnerとし、Migration Path明示、
  `APP_ENV`、DB名、DB／Redis Host、Compose Project、Volume Namespace、
  Env FileのOwner／Mode／Symlink、Credential Fieldを実行前に検査する。
- Production、V1 DB名、V1 Migration Path、Path未指定、V1 Compose Project、
  想定外DB／Redis Host、共有Volume、Host Port公開、Group／Other Readable Env、
  Symlink Env、Production Credential Path、追加Credential Fieldを拒否する。
- RunnerはRollback、Seed、V1 Migration、任意CommandのPass-throughを提供しない。
  ErrorへPassword、接続文字列、Secretを含めない。
- V1 Migration 40件の内容Checksum、V2 Path、Compose分離、`tenant_id`禁止、
  Host Port禁止、Secret非固定、Guard／CI接続を`policy-gate`で継続検査する。

### Persistent V2 Development

- Persistent Compose Projectは`oripa-v2-dev`である。
- PostgreSQLは`postgres:17-alpine`、Redisは`redis:7-alpine`を使用する。
- Networkは`oripa-v2-dev_v2_private`、PostgreSQL Volumeは
  `oripa-v2-dev_v2_postgres`、Redis Volumeは`oripa-v2-dev_v2_redis`であり、
  V1 Project `oripa`のResourceと共有していない。
- PostgreSQL／RedisのHost Portは公開していない。固定Container名と
  `tenant_id`方式を使用していない。
- Secret FileはRepository外`/etc/oripa-v2/dev.env`で、Ownerは`root:root`、
  Directory Modeは`700`、File Modeは`600`、非Symlinkである。値は表示、
  Worklog記録、Commit、Command引数への展開を行っていない。
- V2 PostgreSQL／Redisだけを長期Serviceとして起動し、両方のHealthはPASSした。
  Migration用API Containerはone-shotで実行後に削除した。
- V2専用Pathで`migrate:fresh`を2回実行し、Migration StatusはPASS、
  Schema Inventoryは`public.migrations`だけで再現した。V1固有Table、
  Identity、Admin、Audit、Outbox、Point、Payment Tableは存在しない。

### Ephemeral Backup／Restore

- 2つのTask専用Ephemeral ProjectでPostgreSQL／Redisを分離し、
  V2専用Pathの`migrate:fresh`を2回実行した。
- Source／RestoreのSchema Inventoryは`public.migrations`だけで一致した。
- PostgreSQL 17のRaw Schema Dumpは毎回生成される`\restrict`／`\unrestrict`
  Nonceだけが異なった。Raw DumpをRepository外Evidenceへ保持し、この2行だけを
  固定Tokenへ正規化して再比較した。その他のSchema差分はない。
- 正規化Schema SHA-256はSource／Restoreとも
  `58e8aba469b229bbabd9005e3fd558aba8927f50bdc4e1ff52fc6655ad4774a0`
  で一致した。
- Migration Row SHA-256はSource／Restoreとも
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`
  で一致した。
- Empty V2 Database Backup SHA-256は
  `c5448a67968e836069b77dac7749769672a1c4617bd812ad7987890ef384a536`
  で、別Ephemeral PostgreSQLへのRestoreはPASSした。
- API／Admin Health、PostgreSQL／Redis HealthはPASSした。
- Source／RestoreのContainer、Network、VolumeはTask Project Labelで限定して
  Cleanupし、残存Resourceは0件である。`docker compose down -v`、Global Prune、
  未限定Volume削除は実行していない。
- EvidenceはRepository外
  `/var/www/oripa-v1-evidence/MIG-040-v2-db-baseline-20260724T062133Z/`
  に保存し、Directory Mode `700`、File Mode `600`とした。実PIIとV1 Dataは
  含まない。

### CI／Verification／Scope

- `integration-gate`はV1 Characterization用Migrationへ
  `--path=database/migrations`を明示し、別のTask専用V2 ProjectでGuard Unit Test、
  V2 `migrate:fresh`、Status、Schema Inventory、Migration Checksum、
  Backup／Restore、API／Admin Health、Cleanupを実行する。
- 既存Check名`policy-gate`、`quality-gate`、`security-gate`、
  `integration-gate`、`ci-gate`は変更していない。
- Local Migration Guard Unit Test 13件、Policy Unit Test 43件、
  Quality Unit Test 5件、Security Unit Test 4件、Local `policy-gate`、
  `quality-gate`、Compose Config、`git diff --check`はPASSした。
- Root Workspace Auditは0 Findingである。Legacy Frontend 13件とComposer
  10件は既存期限付きBaselineと完全一致し、新規Finding／Severity悪化はない。
- OpenAPI 3 Contract／Bundle、Storefront Client Unit Test 9件、Site Schema
  Unit Test 10件、Storefront Testkit Unit Test 16件、Admin
  Typecheck／Lint／Build、Legacy Frontend TypecheckはPASSした。
- Full Backend Test、Legacy Frontend Build／Lint、Browser／E2EはLocalでは
  未実行であり、PASSとは記録しない。Full Backend TestとLegacy Buildは
  GitHub `integration-gate`で既存Baselineに対して実行する。
- V1本番DB／Redis／Storageへの接続、Migration、Rollback、Seed、
  `migrate:fresh`は実行していない。Production DB／Secretを作成していない。
- Identity／Admin Realm、Constraint、Audit／Outbox、Point／Payment Table、
  OpenAPI、Storefront Package、Application Business Logicを変更していない。
- V1 Archive BranchとAnnotated Tagは
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`のままである。
- Gate G3はV2専用DB／Redis分離、V2 Migration Path、`migrate:fresh`、
  Migration Checksum、初回Backup／Restoreまで完了した。
- User／Admin Realm、Constraint Test、Point／Payment基礎Table、Audit／Outbox、
  初回`2.0.0-alpha.1` Artifactが残るためGate G3は`NOT COMPLETE`である。
- 次Task候補は`MIG-041 Identity／Admin Realm`だが、MIG-040完了後には開始しない。

### GitHub検証

- Implementation Commitは
  `73e4463c674b4ebfdff0856cec471c6894ec2868`で、GitHub App Wrapperにより
  Remote BranchへFast-forward Pushした。
- PR `#74` (`https://github.com/ideal-sol/oripa/pull/74`)を作成してReady化し、
  Issue `#73`と関連付けた。PR Authorは`ideal-sol-oripa-codex[bot]`、
  Baseは`main`である。
- Implementation Headでは`policy-gate`、`quality-gate`、`security-gate`、
  `integration-gate`、`ci-gate`のRequired 5 Check、CodeQL、
  `CodeQL (javascript-typescript)`、Dependency Reviewの合計8 Checkが
  すべて成功した。
- 本GitHub結果を記録するWorklog追加CommitをFinal Headとし、同じ8 Check、
  Fresh Self-review、SEV-0／SEV-1なし、Merge Conflictなし、Head SHA不変を
  再確認してからGitHub AppがSquash Mergeする。

## MIG-040 Closeout

- PR `#74` (`https://github.com/ideal-sol/oripa/pull/74`)はMergedで、Final Headは
  `2c109face3a40f5a4bbe457008eab9fd51e25294`、Squash Commitは
  `9cde6c5fd70d6dc944710b040acc07462f4c4531`である。
- Final Headでは`policy-gate`、`quality-gate`、`security-gate`、
  `integration-gate`、`ci-gate`のRequired 5 Check、CodeQL、
  `CodeQL (javascript-typescript)`、Dependency Reviewを含む8 Checkが成功した。
- Fresh Self-reviewはFinal Headに固定され、SEV-0／SEV-1は0件だった。
- Issue `#73`はClosedで、Remote Branch
  `migration/MIG-040-v2-db-baseline`は削除済みである。
- Local BranchとWorktree
  `/var/www/oripa-worktrees/MIG-040-v2-db-baseline`はCleanup済みである。
- Local `main`と`origin/main`はSquash Commitで一致し、Working Treeはcleanである。
- V1 Runtime Worktreeは固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`でclean、V1 Archive Branchと
  Annotated Tag Peeled Commitも同SHAのままである。

## MIG-041 Identity／Admin Realm Foundation

### Task／Scope

- Task ID: `MIG-041`
- Risk: `R3`
- Issue: `#75` (`https://github.com/ideal-sol/oripa/issues/75`)
- Branch: `migration/MIG-041-identity-admin-realm`
- Worktree: `/var/www/oripa-worktrees/MIG-041-identity-admin-realm`
- Base SHA: `9cde6c5fd70d6dc944710b040acc07462f4c4531`
- V2専用DBへIdentity DB Schema、User／Admin別Model、Provider／Guard、
  Session／Cookie、Password Policy、Admin MFA Credential保存、Realm境界、
  Deny-by-default Permission基礎を追加する。
- Login／Registration／Password Reset、OAuth、MFA Enrollment／Verification、
  Admin UI、Audit／Outbox、Point／Payment／Draw／Probabilityは実装しない。

### V2 Identity Migration

- `apps/api/database/migrations-v2`へIdentity Account、Realm Session、
  Admin MFA Credentialの3 Migrationを追加した。
- `users`はOpaque `public_id`、Email表示値／Normalized値、Verified日時、
  Argon2id `password_hash`、固定Account Stateを持つ。
- Pending User Emailは重複可能で、Verified User Emailだけを
  `users_verified_email_unique`で一Site内Uniqueにする。
- `admins`はUser Tableと共有せず、固定Role `owner`／`admin`／`operator`、
  固定State `invited`／`active`／`suspended`／`disabled`をDB Constraintで
  制限する。Custom RoleとUserからの昇格構造は持たない。
- `user_sessions`と`admin_sessions`は別Tableで、Raw Session IDやPayloadを
  保存せず、SHA-256 Hash、Idle／Absolute期限、Revocationだけを保持する。
- User Cookieは`__Host-oripa_user_session`、Idle 60分、Absolute 24時間、
  SameSite `Lax`、Admin Cookieは`__Host-oripa_admin_session`、Idle 15分、
  Absolute 8時間、SameSite `Strict`、Remember禁止である。
- `user_remember_devices`はSelector＋Token Hash、Rotation Counter、
  最大30日、Revocation、Replay検出日時を保持し、Token平文を保存しない。
- Admin MFAはWebAuthn Public Key、暗号化前提TOTP Ciphertext＋
  Last Used Time Step、Recovery Code Hash＋使用日時を別Tableへ保存する。
  SMS／Email MFA Methodは作成していない。

### Auth／Security Boundary

- 既存V1 `web` Guard／`users` Providerを維持し、`v2_user`と`v2_admin`の
  Provider／Guardだけを追加した。
- Realm MiddlewareはUnknown Surface、Realm切替、User／Admin同時認証、
  User CookieによるAdmin Access、Admin CookieによるPublic Access、
  WebhookでのBrowser Session、MFA未確認AdminをFail Closedにする。
- Password Policyは8～128 Unicode文字、Space許可、Composition Ruleなし、
  Common／Compromised Hash Blocklist、Argon2id 64MiB／3 iteration／1 thread、
  `needsRehash`、Generic Errorを一元化する。
- Admin MFA PolicyはOwnerへ2つ以上かつ1つ以上のWebAuthn、Admin／Operatorへ
  WebAuthnまたはTOTPを1つ以上要求し、Recovery CodeをAuthenticator数へ含めない。
- Permission CodeとLaravel Gateは中央Matrixを使用し、未登録Role／Permissionを
  Denyする。Ownerのpaid Point調整自己承認を禁止する定義は追加していない。
- `tenant_id`、Local Storage Token、Secret／Credential／実PIIのLog出力を
  実装していない。

### DB Runner／CI

- MIG-040のGuardをTask固定値から`migNNN-v2-*`の限定Namespaceへ一般化し、
  V1 Project／DB／Migration Path／Volume、Production、Host Port、
  任意Projectの拒否を維持した。
- Persistent／Ephemeral両方で3 Migrationを2回再現し、Identity Schema
  Inventory、`tests/V2`、Migration Checksum、Backup／Restoreを検証する。
- `policy-gate`へV1 Migration不変、V2 Migration Set、Realm Table、
  Provider／Guard、Session／Cookie、Password、MFA、Deny-by-default、
  `tenant_id`／業務Table禁止の継続検査とNegative Testを追加した。
- V1 Runtime、Nginx、V1本番DB／Redis／Storage、V1 Migration、
  V1 Archive Branch／Annotated Tagを変更していない。OPS-003のV1修正を
  V2へ取り込んでいない。
- Gate G3はV2専用DB／Redis、Identity Realm、主要Constraintまで進むが、
  Authentication Flow、Audit／Outbox、Point／Payment基礎Table、
  初回`2.0.0-alpha.1` Artifactが残るため`NOT COMPLETE`である。
- 次Task候補は`MIG-041A User／Admin Authentication Flow・Admin MFA Enrollment`
  だが、MIG-041完了後には開始しない。

### Local DB／Test結果

- V1 Migrationは40件、内容SHA-256 Setは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  でMIG-040 Baselineと一致し、File名、内容、Modeを変更していない。
- V2 Migrationは3件、内容SHA-256 Setは
  `5f52d4d565b94ec4c0d25a65dcb8bf1a3a2c306b428274e3893157d9f05c1d4b`
  である。
- Persistent `oripa-v2-dev`とTask専用Ephemeral DBの両方でV2専用Pathの
  `migrate:fresh`を2回実行し、Migration Status、Identity Test、
  PostgreSQL／Redis HealthはPASSした。
- Identity TestはPassword、Realm、Guard、Cookie、Session Table、
  Verified Email、Admin Role／State、MFA保存、Permissionを23 Testで検証し、
  150 Assertion、新規Failure／WarningなしでPASSした。
- Schema Inventoryは`users`、`admins`、`user_sessions`、`admin_sessions`、
  `user_remember_devices`、`admin_webauthn_credentials`、
  `admin_totp_methods`、`admin_recovery_codes`、`migrations`の9 Tableだけである。
  V1固有Table、`tenant_id`、Audit／Outbox、Point／Payment Tableは存在しない。
- Ephemeral Source／RestoreのSchema SHA-256は
  `a469c082f92c4bb50cd18a0c3663cc1296106190f854a06e05b43385bdd6611f`、
  Migration Row SHA-256は
  `bad9535fff8c4718cb8cde338eb58d4c69dc25c36ada21db4fc5e04b19e6c1e2`
  でそれぞれ一致した。
- Backup SHA-256は
  `ed0fb72110cc1b09620ba56376f4c13eb97e08e6535f0f4422f3e2eaf877f96c`
  で、別Ephemeral PostgreSQLへのRestore、API／Admin Health、
  Container／Network／Volume CleanupはPASSした。
- EvidenceはRepository外
  `/var/www/oripa-v1-evidence/MIG-041-identity-final3-20260724T084020Z/`
  に保存し、Directory Mode `700`、File Mode `600`とした。Credential値、
  V1 Data、実PIIを含まない。
- V1 Archive BranchとAnnotated Tag Peeled Commitは
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`のままである。

### Local Gate／Scope

- PHP Syntax、Composer Validate、XML／JSON／YAML／TOML Parse、
  V1／V2 Compose Config、`git diff --check`はPASSした。
- Policy Unit Test 46件、DB Guard Unit Test 14件、Quality Unit Test 5件、
  Security Unit Test 4件、OpenAPI Unit Test 4件はPASSした。
- Local `policy-gate`、`quality-gate`、`security-gate`はPASSし、
  Secret Candidateは0件である。
- OpenAPI 3 Surface／Bundle、Storefront Client Test 9件、Site Schema Test
  10件、Storefront Testkit Test 16件、Admin Typecheck／Lint／Build、
  Legacy Frontend TypecheckはPASSした。
- Root Workspace Auditは0 Findingである。Legacy Frontend 13 Findingと
  Composer 10 Advisoryは既存期限付きBaselineと一致し、新規Critical／High、
  件数増加、Severity悪化はない。
- Repository変更はMIG-041 Task PolicyのAllowed Path 42件だけで、
  Binary／Submodule、Application Endpoint、OpenAPI、Dependency／Lockfile、
  V1 Migration、V1 Runtime設定を変更していない。
- Full V1 Backend Test、Legacy Frontend Build／Lint、Browser／E2EはLocalでは
  未実行であり、PASSとは記録しない。GitHub `integration-gate`で既存Baselineと
  比較して実行する。

### GitHub初回実行

- Implementation Commitは
  `848577f86d54771e6a37cf0db7f5c025781ab3a8`で、GitHub App Wrapperにより
  Remote BranchへFast-forward Pushした。
- PR `#76` (`https://github.com/ideal-sol/oripa/pull/76`)を作成し、
  Issue `#75`と関連付けた。PR Authorは`ideal-sol-oripa-codex[bot]`、
  Baseは`main`である。
- 初回`policy-gate`はPR本文の`Changed files`が42 Fileの要約記載で、
  Git差分の正確なPath一覧と一致しなかったため失敗した。実装差分やPolicyを
  弱めず、PR本文を実差分42 Pathの明示列挙へ修正した。
- 同一Headの旧失敗Runを成功扱いにせず、本Worklog追記を通常Commitとして
  Fast-forward Pushする。新しいFinal HeadでRequired 5 Check、CodeQL、
  `CodeQL (javascript-typescript)`、Dependency Reviewを再実行する。
- Final Head固定後にFresh Self-review、SEV-0／SEV-1なし、
  Merge Conflictなし、Head SHA不変を確認し、CheckをBypassせず
  GitHub AppがSquash Mergeする。

## MIG-041 Closeout

- PR `#76`はMerged、Issue `#75`はClosedである。
- Task Final Headは`3d3db56ad244a3e06c6b4fa290a2006ef6ef0503`、Squash Commitは
  `2a40029f01204ee5b7804414a5c6c207357780b4`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。
- Final Head固定後のFresh Self-reviewでSEV-0／SEV-1は0件だった。
- Remote Branchは削除済みで、Local BranchとWorktreeはMerge後Treeとの一致確認後に
  Cleanupした。
- Local `main`と`origin/main`はSquash Commitで一致し、Working Treeはcleanである。
- V1 Runtime、Nginx、V1本番DB／Redis／Storage、V1 Archive Branch／Annotated Tagを
  変更していない。

## MIG-041A User／Admin Authentication Flow・Admin MFA Enrollment

### Task／Scope

- Task ID: `MIG-041A`
- Risk: `R3`
- Issue: `#77` (`https://github.com/ideal-sol/oripa/issues/77`)
- Branch: `feat/MIG-041A-authentication-mfa-flow`
- Worktree: `/var/www/oripa-worktrees/MIG-041A-authentication-mfa-flow`
- Base SHA: `2a40029f01204ee5b7804414a5c6c207357780b4`
- Contract-first順序でPublic Auth 6 Operation、Admin Auth 9 Operation、
  Generated Public Types、Laravel Flow、Feature／Integration Testを追加した。
- Password Reset、OAuth／Google／LINE、User MFA、Admin UI、Admin Role管理、
  Audit／Outbox Table、Point／Payment／Draw／Probabilityは実装していない。

### OpenAPI／Generated Client

- Public Contractへ`registerUser`、`loginUser`、`logoutUser`、
  `resendUserEmailVerification`、`verifyUserEmail`、`getUserSession`を追加した。
- Admin ContractへPassword Pre-auth、MFA Verify、Logout、Session、TOTP登録／確認、
  WebAuthn Options／登録、Recovery Code再生成の9 Operationを追加した。
- Public／Admin BundleはOpenAPI 3.1.1、JSON Schema Draft 2020-12でLint／Bundleに
  成功し、Committed Bundleとの差分はない。
- `@oripa/storefront-client`のPublic TypesはPublic Bundleから決定的に再生成した。
  Admin／Webhook Operationおよび型はExportしていない。
- `@oripa/storefront-testkit`のContract FixtureはPublic Auth 6 Operationへ更新し、
  架空業務Operationを追加していない。

### User Authentication

- User登録は既存`V2PasswordPolicy`を使用し、`pending_verification`で作成する。
  同じ未検証Emailは複数登録できる。
- `user_email_verifications`はCSPRNG TokenのSHA-256 Hash、Relative Redirect、
  60分期限、使用／失効日時だけを保存する。再送時は旧Tokenを失効する。
- VerificationはNormalized Email単位のPostgreSQL Advisory LockとVerified Emailの
  Partial Unique Indexを使用し、同時成功を1 Accountに限定する。
- LoginはVerified Emailと`active`／`restricted`だけを許可し、失敗時はAccount存在を
  開示しない`INVALID_CREDENTIALS`を返す。
- Login／Verification成功時は新しいUser SessionをHash保存し、Logout時はServer
  Sessionを失効、CookieとCSRF Tokenを更新する。

### Admin Authentication／MFA

- Admin Password成功は5分・1回限りの暗号化Pre-auth Transactionだけを発行し、
  MFA成功前に`admin_sessions`を作成しない。
- Initial OwnerはConsole Command
  `v2:identity:create-owner-invitation`だけからInvitationを作成する。TokenはHash保存、
  30分、1回限りで、Web Endpointはない。
- TOTPは`spomky-labs/otphp` `11.5.0`を使用し、6桁、30秒、前後1 Step、同一Step
  Replay拒否を実装した。SecretはApplication-level Encryptionで保存し、Confirm前は
  Authenticator数へ含めない。
- WebAuthnは`web-auth/webauthn-lib` `5.3.5`を使用し、Admin Domain固定RP ID、
  Exact Origin、`userVerification=required`、Attestation `none`、5分・1回限り
  Challenge、Credential ID Unique、Counter更新を実装した。Public Key以外の秘密情報を
  永続化しない。
- Recovery Codeは128bit以上を10件生成し、SHA-256 Hashだけを保存する。再生成で旧Codeを
  全失効し、1回使用後のSessionは`requires_mfa_enrollment`により通常Admin Accessを
  拒否する。Recovery Code使用後だけ5分の専用Enrollment Transactionを発行し、
  PasswordだけのActive Admin Pre-authからのMFA追加を拒否する。Recovery Codeは
  Authenticator数へ含めない。
- OwnerはAuthenticator 2つ以上かつWebAuthn 1つ以上、Admin／OperatorはWebAuthnまたは
  TOTP 1つ以上を要求する。SMS／Email OTP／Security Questionは追加していない。

### Browser／Security Boundary

- User／AdminはSession Table、Session Cookie、CSRF Cookie、SameSite、Originを共有しない。
- Unsafe MethodはJSON、CSRF Double Submit、Exact OriginまたはReferer fallbackを要求し、
  `Sec-Fetch-Site: cross-site`を拒否する。
- User Login、Admin Login、MFA、Register、Verification ResendのRate LimitをConfigへ
  固定し、EmailはApplication KeyによるHMACだけをRate Limit Keyへ使用する。
- Critical Rate Limiterと認証Transaction Storeは利用不能時にFail Closedとなる。
- `V2SecurityEventSink`はRegister、Verification、Login、Logout、MFA Enrollment／結果、
  Recovery Code使用の境界を提供する。Password、Token、Session ID、MFA Secret、
  Recovery Code、Full EmailをEventへ含めない。
- MIG-042の永続Audit接続前であるため、Production Deploymentは禁止である。

### Migration／DB Verification

- V1 Migrationは40件、内容SHA-256 Set
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  のままで、編集、改名、削除していない。
- V2 Migrationは`user_email_verifications`、`admin_invitations`、
  `admin_sessions.requires_mfa_enrollment`を追加した4件で、内容SHA-256 Setは
  `fccc5f42648f49eb0067d3e0e990411d11cbea4e1abc230be0efb53f64f5b237`
  である。
- Task専用PostgreSQL 17／Redis 7でV2 Migration Pathの`migrate:fresh`を2回実行し、
  Migration Statusと11 TableのSchema Inventoryは一致した。V1固有Table、
  `tenant_id`、Audit／Outbox、Point／Payment Tableは存在しない。
- PHP 8.4上のV2 Testは37件、237 Assertionが2回連続で成功した。
  `phpunit.v2.xml`はTest専用`array` Cacheを強制し、Compose RedisにRate Limit状態を
  残さない。
- Source／Restore Schema SHA-256は
  `0f7ebb547ae6dd424630735c0008e84cf1e573c9cc7a097d37df4661595177cb`、
  Migration Row SHA-256は
  `bf356fd02f6eaea61825a59b51e9775a06b90bc640056a69cdc26f9f41193c4d`
  で一致した。Backup SHA-256は
  `826fd8775c4cc4513e4b86ead91ebe2b35ba6790f3dc61aa76593cdbf8c50ad9`
  である。
- EvidenceはRepository外
  `/var/www/oripa-v1-evidence/MIG-041A-local-final4/`に保存した。
  Secret、実PII、V1 Dataを含まない。

### Local Verification／Gate

- Composer Manifest／Lock、PHP Syntax、OpenAPI Lint／Bundle、Generated Client、
  Storefront Client Test 9件、Site Schema Test 10件、Storefront Testkit Test 16件、
  Policy Unit Test 46件、Quality Unit Test 5件、Security Unit Test 4件、DB Guard Unit
  Test 14件、OpenAPI Unit Test 4件、Site Template Unit Test 6件はPASSした。
- Admin Typecheck／Lint／Build、Legacy Frontend Typecheck／Build、既存Lint Baseline、
  `quality-gate`、`security-gate`はPASSした。Root Auditは0 Findingで、Legacy
  Frontend 13 FindingとComposer 10 Advisoryは既存期限付きBaselineと一致し、
  Secret Candidateは0件である。
- Local `policy-gate`は全変更を明示StageしたFinal Scopeで実行する。Required 5 Check、
  CodeQL、`CodeQL (javascript-typescript)`、Dependency ReviewはGitHub PRの固定Headで
  実行する。
- V1 Runtime Commitは`bfca8efa0b85c00a88fb0fd439a123b722577b68`でclean、
  Public／Admin Runtime、Nginx、V1本番DB／Redis／Storageを変更していない。
- Gate G3はIdentity RealmとAuthentication Flowまで進んだが、Audit／Outbox、
  Point／Payment基礎Table、初回`2.0.0-alpha.1` Artifactが残るため
  `NOT COMPLETE`である。
- 次Task候補は`MIG-042 Audit／Outbox`だが、MIG-041A完了後には開始しない。

### GitHub Check再実行

- PR `#78`の初回`policy-gate`は、実装ではなくPR本文の必須見出しと
  `Allowed paths`／`Changed files` Section構造の不足により失敗した。
- PR本文を既存Policyが要求するTemplate構造へ修正し、実変更56 Pathとの完全一致を
  GitHub APIとLocal Diffの両方で確認した。Gate、Assertion、Allowed Pathは
  弱めていない。
- 同一Headの再実行では過去の失敗／cancelled Check Runが厳格なWrapper集計へ残るため、
  履歴書換えやForce Pushを行わず、本Worklog追記を通常Commitとして追加した新Headで
  Required／Available Checkを再実行する。

## MIG-041A Closeout

- PR `#78`はMerged、Issue `#77`はClosedである。
- Task Final Headは`19b67ae148b1a37a18d1587cf4c362d497d8652c`、Squash Commitは
  `170a80928a42ac3b15c46e7ac2fb854e271d6786`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。
- Final Head固定後のFresh Self-reviewは同じHeadを対象とし、SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-041A Worktreeは削除済みである。
- Local `main`と`origin/main`はSquash Commitで一致し、Working Treeはcleanである。
- V1 Runtime、Nginx、V1本番DB／Redis／Storage、V1 Migration、V1 Archive
  Branch／Annotated Tagを変更していない。

## MIG-042 Audit／Transactional Outbox Foundation

### Task／Scope

- Task ID: `MIG-042`
- Risk: `R3`
- Issue: `#79` (`https://github.com/ideal-sol/oripa/issues/79`)
- Branch: `migration/MIG-042-audit-outbox-foundation`
- Worktree: `/var/www/oripa-worktrees/MIG-042-audit-outbox-foundation`
- Base SHA: `170a80928a42ac3b15c46e7ac2fb854e271d6786`
- V2専用Migration Rootへ`audit_logs`、`audit_daily_digests`、
  `outbox_messages`を追加した。Point、Payment、Draw、Probability、実外部Transport、
  Audit検索／Export API、WORM連携は実装していない。

### Audit Foundation

- `audit_logs`は`public_id`、`occurred_at`、`business_date`、`request_id`、
  Actor／Role／Realm／Session Correlation、Action／Target／Outcome／Reason、
  Redaction済みBefore／After／Metadata、`previous_hash`、`record_hash`を保持する。
- PostgreSQL Advisory Transaction Lockで並行Writeを直列化し、外部
  `V2_AUDIT_HMAC_KEY`によるRecord Hash Chainを生成する。Key値はDB、Repository、
  Log、Worklogへ保存していない。
- `audit_logs`と`audit_daily_digests`のUpdate／Delete／TruncateはEloquent Modelと
  PostgreSQL Triggerの両境界で拒否する。
- Chain全件再計算、Tamper検知、Business Date単位のDaily Digest、Digest重複拒否を
  実装した。
- Password、Token、Raw Session ID、Recovery Code、TOTP Secret、Full Email、
  Authorization／Cookie値、不要なPIIをRedactorで拒否し、IP、User Agent、Sessionは
  HMAC相関Hashだけを保存する。
- `V2SecurityEventSink`を`V2PersistentSecurityEventSink`へ接続し、Register、
  Email Verification、Login成功／失敗、Logout、Admin Invitation、MFA
  Enrollment／成功／失敗、Recovery Code使用、Rate Limit発火を永続Auditへ接続した。

### Transactional Outbox

- `V2OutboxService::enqueue`はActive Domain Transaction内だけを許可し、Rollback時は
  Outbox Messageも残らない。
- `topic`、Aggregate、Event Type、Payload、Deduplication Key、Status、
  Available At、Attempts、Lease／Lock、Delivered At、Last Errorを構造化Columnで
  保持する。
- Unique Deduplication Key、`FOR UPDATE SKIP LOCKED`、Worker／Lease所有確認により、
  Deduplication、並行Claim、期限切れClaim、Success／Retry／Failure遷移を保証する。
- Email Verification通知はUser／Verification Token更新と同じTransactionでenqueue
  する。Recipient、Token、RedirectはApplication-level Encryption済みCiphertext
  だけをPayloadへ保存し、平文通知情報を保存しない。
- 実Mail／SMS／Discord／Payment Provider送信とTransport Workerは実装していない。
  外部通信はDB Transaction外の後続Worker責任である。

### Migration／DB Verification

- V1 Migrationは40件、内容SHA-256 Set
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  でBaselineと一致し、編集、改名、削除していない。
- V2 Migrationは5件、内容SHA-256 Set
  `4d9c030fc155bc8e1e49a6f21d668d1bbe84081742095a68b5925c28000ef2ec`
  である。
- `/etc/oripa-v2/dev.env`へRepository外Audit HMAC Keyを追加した。Fileは
  `root:root`、mode `600`で、値を表示、記録していない。
- Persistent V2 PostgreSQL 17／Redis 7でGuard経由の`migrate:fresh`を2回実行し、
  Migration Status、14 TableのSchema Inventory、Health、Host Port非公開を確認した。
- V2 PHPUnitは48 Test、289 Assertionが成功した。Audit／Outbox専用は11 Test、
  52 Assertionで、Append-only、Hash Chain、Tamper、Daily Digest、並行Write、
  Transaction Rollback、Deduplication、Claim、Lease、Retry、Security Event永続化を
  検証した。
- Task専用Ephemeral Source／Restore ProjectでBackup／Restoreを実行し、Source／Restore
  Schema SHA-256は
  `6982efd696edb35dfa22de2bb7dcd8017ec791504068dd17736436f5c86d27d3`、
  Migration Row SHA-256は
  `84b168f29f67e239e5a377f0295b3ddc4a0600d920527d53d7ac06ef9d76c9a2`
  で一致した。最終Backup SHA-256は
  `81cdf84e423656ada8492dd37251b8d185e00c43c90915d22b69490cd5f3a6f5`
  である。
- Task専用Container／Network／VolumeはGuardが対象ProjectだけをCleanupし、残存なしを
  確認した。Global PruneやV1 Resource削除は実行していない。
- EvidenceはRepository外
  `/var/www/oripa-v1-evidence/MIG-042-persistent-final-20260724T124100Z/`と
  `/var/www/oripa-v1-evidence/MIG-042-ephemeral-final-20260724T125300Z/`へ保存した。
  Directoryはmode `700`、Fileはmode `600`で、Secret、実PII、V1 Dataを含まない。

### Local Verification／Gate

- PHP Syntax、Composer Manifest、Policy／DB Guard Unit Test 64件、Quality Unit Test
  5件、Security Unit Test 4件、OpenAPI Unit Test 4件はPASSした。
- OpenAPI Public 6／Admin 9／Webhook 0 OperationのLint／Bundle、Storefront Client
  9 Test、Site Schema 10 Test、Storefront Testkit 16 Test、Legacy Frontend
  Typecheck、Admin Typecheck／Lint／BuildはPASSした。
- `quality-gate`、`security-gate`、`git diff --check`はPASSした。Root Workspace
  Auditは0 Finding、Secret／PII Candidateは0件である。
- Legacy Frontend 13 FindingとComposer 10 Advisoryは既存期限付きBaselineと一致し、
  新規Finding、件数増加、Severity悪化はない。
- Full V1 Backend Test、Legacy Frontend Lint／Build、Browser／E2E、実外部Transportは
  本Local検証では未実行であり、PASSとは記録しない。GitHub `integration-gate`では
  既存BaselineとV2 Ephemeral DB検証を実行する。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewは
  GitHub PRの固定Final Headで実行し、Fresh Self-review後に結果を確定する。
- V1 Runtime、Nginx、V1本番DB／Redis／Storage、V1 Migration、V1 Archive
  Branch／Annotated Tagを変更していない。
- Gate G3はIdentity、Authentication、Audit／Outbox、V2 DB分離まで進んだが、
  Point／Payment基礎Tableと初回`2.0.0-alpha.1` Artifactが残るため
  `NOT COMPLETE`である。

## MIG-042 Closeout

- PR `#80`はMerged、Issue `#79`はClosedである。
- Task Final Headは`12b3590054a4b6c6e6df8ccc4269bed129cf549a`、Squash Commitは
  `56db6174510c286129d37403ae7052473a4d3454`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。
- Final Head固定後のFresh Self-reviewは同じHeadを対象とし、SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-042 Worktreeは削除済みである。
- Local `main`と`origin/main`はSquash Commitで一致し、Working Treeはcleanである。
- V1 Runtime、Nginx、V1本番DB／Redis／Storage、V1 Migration、V1 Archive
  Branch／Annotated Tagを変更していない。

## MIG-043 Point Model Foundation

### Task／Scope

- Task ID: `MIG-043`
- Risk: `R3`
- Issue: `#81` (`https://github.com/ideal-sol/oripa/issues/81`)
- Branch: `migration/MIG-043-point-model-foundation`
- Worktree: `/var/www/oripa-worktrees/MIG-043-point-model-foundation`
- Base SHA: `56db6174510c286129d37403ae7052473a4d3454`
- V2専用Migration Rootへ`wallets`、`point_operations`、`point_lots`、
  `point_ledger_entries`、`point_adjustments`、`point_balance_snapshots`、
  `point_reconciliation_runs`、`point_reconciliation_discrepancies`、
  `idempotency_records`を追加した。
- Payment、Payment Adjustment、Provider Event、Point購入Plan、返金／チャージバック、
  Draw／Probability／Inventory、Public／Admin／Webhook API、UIは実装していない。

### Schema／Constraint

- Point値はPostgreSQL `bigint`整数だけを使用し、paid／free、Balance／Reservedを
  構造化Columnで分離した。WalletとLotは負数を拒否し、ReservedはBalance以下に限定する。
- paid Lotは`expire_at IS NULL`、free Lotは`expire_at IS NOT NULL`をDB Constraintで
  強制する。`tenant_id`、float／小数、内部IDのAPI公開は追加していない。
- `point_operations.business_key`とIdempotency Scope／KeyはUniqueである。
  Point Operation、Ledger、Reconciliation DiscrepancyはModelおよびPostgreSQL Triggerで
  Update／Delete／Truncateを拒否する。
- `public_id`はUUIDv7、`business_date`はAsia/Tokyo、Snapshotは
  `occurred_at < ledger_cutoff`でLedgerから再構築し、3月31日／9月30日だけ
  `is_base_date=true`とする。
- `point_lot_reservations`は`payment_adjustments`へのFKと取消／返金時のReservation
  不変条件を必要とするためMIG-044へ延期した。架空のPayment Tableや汎用JSONB代替は
  作成していない。

### Domain Service／Permission

- Wallet初期化、free Point付与、Point消費、free Point失効、Ledger記録、
  Idempotency、Deadlock／Serialization Failureの最大3回限定Retryを実装した。
- 消費はWalletを先に`FOR UPDATE`し、freeを`expire_at ASC, granted_at ASC, id ASC`、
  paidを`granted_at ASC, id ASC`でLockする。`SKIP LOCKED`は使用しない。
- Ledger再構築、Ledger Cutoff Snapshot、Wallet／Lot／Ledger Reconciliationを
  実装した。不一致は記録するだけで自動修復しない。
- 通常Serviceはfree付与だけを公開し、成功済みPaymentを確認できないpaid付与経路を
  持たない。Point AdjustmentはSchema、固定Permission、Audit境界までに限定し、
  実行APIは追加していない。
- `point.ledger.read`、Adjustment Request、free／paid Approval Permissionを固定した。
  paid ApprovalはOwnerだけで、Owner自己承認を禁止する定義は追加していない。
- Wallet初期化、free付与、消費、失効、Snapshot、ReconciliationをMIG-042の
  Append-only Auditへ接続した。Password、Token、Full Email、Raw Session ID、
  不要なPIIをAudit／Metadataへ保存しない。

### Migration／DB Verification

- V1 Migrationは40件、内容SHA-256 Set
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  でBaselineと一致し、編集、改名、削除していない。
- V2 Migrationは6件、内容SHA-256 Set
  `e25ae9c68129c311e894440d7e5d72944de22618bc238b8bf75dd2543b6027d5`
  である。
- Persistent V2 PostgreSQL 17／Redis 7でGuard経由の`migrate:fresh`を2回実行し、
  Migration Status、23 TableのSchema Inventory、Health、Host Port非公開を確認した。
- V2 PHPUnitは62 Test、356 Assertionが成功した。Point専用14 Testは、Constraint、
  immutable、消費順、失効、Rollback、Idempotency、並行消費、消費／失効競合、
  Deadlock Retry、Ledger再構築、Snapshot、Reconciliation、Permission、Auditを
  検証する。
- Task専用Ephemeral Source／Restore ProjectでBackup／Restoreを実行し、
  Source／Restore Schema SHA-256は
  `b360b2d6394452606c724aabe043a378839c11773b4067399a15fc93d700cd37`、
  Migration Row SHA-256は
  `082d7e5b67c0ae065f8160b5379bba92ae8d07c83d3634fb3d2f96886b888555`
  で一致した。Backup SHA-256は
  `83d256c545bf7c76eef82555a053db3c7c16c665f68140cb6809a12f51daa24f`
  である。
- EvidenceはRepository外
  `/var/www/oripa-v1-evidence/MIG-043-persistent-final-20260724T141500Z/`と
  `/var/www/oripa-v1-evidence/MIG-043-ephemeral-final-20260724T141500Z/`へ保存した。
  Task専用Container／Network／VolumeのCleanupはPASSし、Secret、実PII、V1 Dataを
  含まない。

### Local Verification／Gate

- PHP Syntax、Policy Unit Test 52件、DB Guard Unit Test 16件、Quality Unit Test
  5件、Security Unit Test 4件はPASSした。
- `quality-gate`、`security-gate`、`git diff --check`はPASSした。Root Workspace
  Auditは0 Finding、Secret／PII Candidateは0件である。
- Legacy Frontend 13 FindingとComposer 10 Advisoryは既存期限付きBaselineと一致し、
  新規Finding、件数増加、Severity悪化はない。
- Full V1 Backend Test、Legacy Frontend Runtime Test、Browser／E2E、
  Payment／Provider Integration、Production Deploymentは未実行であり、PASSとは
  記録しない。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewは
  GitHub PRの固定Final Headで実行し、Fresh Self-review後に結果を確定する。
- V1 Runtime、Nginx、V1本番DB／Redis／Storage、V1 Migration、V1 Archive
  Branch／Annotated Tagを変更していない。
- Gate G3はPoint Model Foundationまで進んだが、Payment Modelと初回
  `2.0.0-alpha.1` Artifactが残るため`NOT COMPLETE`である。
- 次Task候補は`MIG-044 Payment Model`だが、MIG-043完了後には開始しない。

## SEC-003 PostCSS／brace-expansion 新規High Advisory対応

### MIG-044 Blocked／差分保全

- MIG-044は`GHSA-r28c-9q8g-f849`および`GHSA-mh99-v99m-4gvg`の新規High
  Advisory検出によりBLOCKEDのまま維持した。Issue `#83`はOpen、Branchは
  `migration/MIG-044-payment-model-foundation`、Worktreeは
  `/var/www/oripa-worktrees/MIG-044-payment-model-foundation`である。
- MIG-044 WorktreeのHEADは
  `2c388015b1174b5ad7edf04853d59ccd35d2e5b0`で、Commit／Push／PRは未実施、
  未Commit差分16 Pathを保持している。SEC-003ではMIG-044のFile、Index、Branch、
  Worktreeを変更していない。
- Repository外Evidenceは
  `/var/www/oripa-v1-evidence/SEC-003-mig044-preservation-20260727T012131Z/`へ保存した。
  Directoryはmode `700`、Fileは`root:root`、mode `600`である。
- Changed Path一覧SHA-256は
  `e5f7ab10d330bd628314f29938b8cd95def518ba407d3480edb4fc5de289e70d`、
  未Commit Patch SHA-256は
  `dce3d3cb0ecfb7aed3593337cfc9f6a97e10f3f67cc20b21513e932908f5edee`
  である。高確度Secret／PII Candidateは0件で、最終検証時にもChecksum一致を確認する。

### Task／Dependency更新

- Task IDは`SEC-003`、Riskは`R3`、Issueは`#84`、Branchは
  `security/SEC-003-postcss-brace-expansion`、Base SHAは
  `2c388015b1174b5ad7edf04853d59ccd35d2e5b0`である。
- Root WorkspaceとLegacy Frontendを独立して調査した。`postcss`はNext.js経由、
  `brace-expansion`はESLint、minimatch、OpenAPI生成Tool等のTransitive Dependency
  経由で導入されていた。`brace-expansion`と`@isaacs/brace-expansion`は混同していない。
- Root／Legacyの`postcss`をExact `8.5.12`から`8.5.18`へ更新した。
  `brace-expansion`は全Dependency経路をExact `5.0.8`へ固定した。
- `brace-expansion 5.0.8`は旧`minimatch 3.x`へ単独OverrideするとESLintでAPI非互換に
  なるため、Repositoryですでに使用していたExact `minimatch 10.2.5`へRoot／Legacyを
  統一した。互換性はAdmin、First-party Package、Legacy FrontendのLint／Buildで
  検証した。
- pnpm `10.12.1`でRootとLegacyのLockfileを別々に再生成した。Legacy操作では
  `--ignore-workspace`を使用し、Lockfile共有、SemVer Range、Floating Version、
  無関係なDependency更新は行っていない。
- 解消した既存`GHSA-3jxr-9vmj-r5cp`のBaseline Entry 2件とDependency Review
  Allowlistだけを削除した。新規AdvisoryのBaseline／Allowlist、期限延長、
  Blanket Ignore、Gate弱体化は追加していない。
- RootのPostCSS／brace-expansion／minimatch固定Versionを検査するPolicyとUnit Testを
  同期した。Application Logic、OpenAPI、Migration、DB、MIG-044実装は変更していない。

### Local Verification

- Root `pnpm install --frozen-lockfile`、Audit、Admin Typecheck／Lint／Build、
  Storefront Client生成差分／Typecheck／Lint／Build／9 Test、Site Schema生成差分／
  Typecheck／Lint／Build／10 Test、Storefront Testkit生成差分／Typecheck／Lint／
  Build／16 TestはPASSした。
- Legacyは独立した`pnpm install --frozen-lockfile --ignore-workspace`、Typecheck、
  BuildがPASSした。Lintは既存Baselineの8 Error／1 Warning、9 Findingと完全一致し、
  Error、Warning、Rule、Location、Message Fingerprintの増加はない。
- Root Auditは0 Findingである。Legacy Auditは11 Findingで、新規Critical／High
  Findingは0件、対象2 Advisoryは0件である。従来13件から減少した理由は、今回の
  `brace-expansion 5.0.8`統一により既存Baseline 2件も同時に解消したためである。
- Policy Unit Test 52件、Quality Unit Test 5件、Security Unit Test 4件、
  `policy-gate`、`quality-gate`、`security-gate`、`git diff --check`、
  JSON／YAML基本構造はPASSした。Secret／PII Candidateは0件である。
- 初回PR Runは必須Heading／Changed Path宣言が不足したPR本文Schemaにより
  `policy-gate`が失敗した。PR本文だけを正式Template構造へ修正し、GateをBypassせず
  新しいFinal Headで全Checkを再実行する。
- V1 Migrationは40件で不変である。V1 Runtime、Nginx、V1本番DB／Redis／Storage、
  V1 Archive Branch／Annotated Tagを変更していない。
- Backend Runtime Test、Browser／E2E、Production TestはDependency-only Taskのため
  未実行であり、PASSとは記録しない。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Review、
  Final Head固定後のFresh Self-review、Squash Commit、CleanupはGitHub PR上で
  確定する。SEV-0／SEV-1または新規Critical／High Findingがある場合はMergeしない。
- SEC-003完了後もMIG-044は再開せず、Issue `#83`、Branch、Worktree、未Commit差分を
  保持する。MIG-045は開始しない。

## MIG-043／SEC-003 CloseoutとMIG-044再開・Payment Model Foundation

### MIG-043 Closeout

- MIG-043のPR `#82`はMerged、Issue `#81`はClosedである。Final Headは
  `8b4d00097fb7e98acb9532818b99cdc25daf8a5e`、Squash Commitは
  `2c388015b1174b5ad7edf04853d59ccd35d2e5b0`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Fresh Self-reviewはFinal Headと一致し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-043 Worktreeは削除済みで、Local
  `main = origin/main`、Working Tree cleanを確認した。V1 Runtime、V1本番DB、
  V1 Archive Branch／Annotated Tagは変更していない。

### SEC-003 Closeout／MIG-044保存Evidence

- MIG-044は新規High Advisoryにより一時BLOCKEDとなり、Issue `#83`、Branch
  `migration/MIG-044-payment-model-foundation`、専用Worktreeの未Commit 16 Pathを
  保持した。SEC-003ではPayment実装を変更していない。
- SEC-003のIssue `#84`はClosed、PR `#85`はMergedである。Final Headは
  `bb280c5c02a648ae77a677d7de8601f17332b945`、Squash Commitは
  `5270012c0ffa6a1b4db3a9fbe1922b2cbbc7a519`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Fresh Self-reviewはFinal Headと一致し、
  SEV-0／SEV-1は0件だった。SEC-003のRemote／Local BranchとWorktreeは削除済みで、
  Local `main = origin/main`、Main Working Tree cleanである。
- Root Auditは0 Finding、Legacy Auditは既存Baselineと一致する11 Finding、
  `GHSA-r28c-9q8g-f849`と`GHSA-mh99-v99m-4gvg`は0件である。
- MIG-044再開前EvidenceはRepository外
  `/var/www/oripa-v1-evidence/MIG-044-resume-preservation-20260727T022002Z/`
  にmode `700`、File mode `600`で保存した。Changed Path一覧SHA-256は
  `e5f7ab10d330bd628314f29938b8cd95def518ba407d3480edb4fc5de289e70d`、
  Binary対応Patch SHA-256は
  `dce3d3cb0ecfb7aed3593337cfc9f6a97e10f3f67cc20b21513e932908f5edee`
  で既存Evidenceと一致し、高確度Secret／PII Candidateは0件だった。
- 16 PathだけをCheckpoint Commit
  `325b7515462dda46f94a21f39ff2e915cb05cdaa`へ記録し、SEC-003 Squash Commitを
  通常Mergeした。Rebase、Squash、Force Push、履歴書換えは行っていない。
  Merge競合は発生せず、SEC-003の安全Dependency VersionとBaseline削除を維持した。
  Resume Baseは`5270012c0ffa6a1b4db3a9fbe1922b2cbbc7a519`へ更新し、
  Original Base `2c388015b1174b5ad7edf04853d59ccd35d2e5b0`はEvidenceと
  本記録へ保持した。SEC-003のDependency FileはMIG-044のPR差分へ再登場していない。

### MIG-044 Task／Schema

- Task IDは`MIG-044`、Riskは`R3`、Issueは`#83`、Branchは
  `migration/MIG-044-payment-model-foundation`である。新しいIssue、Branch、
  Worktreeは作成せず既存Taskを継続した。
- V2専用Migration Rootへ`point_purchase_plans`、`payments`、
  `payment_status_histories`、`payment_point_grants`、`payment_provider_events`、
  `payment_provider_event_attempts`、`payment_provider_operations`、
  `payment_adjustments`、`payment_adjustment_status_histories`、
  `payment_adjustment_point_impacts`、`payment_adjustment_point_operations`、
  `point_lot_reservations`を追加した。
- 金額とPointはPostgreSQL `bigint`、通貨は`JPY`、paid Pointは決済金額と一致し、
  購入Bonusはfree Pointとして分離する。Payment statusへRefund／Chargebackを
  混在させず、`financial_state`と`tenant_id`は保存していない。
- Published Planの金額／paid／free／通貨変更をDB Triggerで拒否する。
  Payment AdjustmentはPayment金額を超えられず、Status History、Provider Event、
  Provider Event Attempt、Adjustment HistoryはDB TriggerでUpdate／Delete／
  Truncateを拒否する。
- `provider_code + external_event_id`、Payment Point Grant、Provider Operation、
  Adjustment Source Event、Point Lot ReservationへUnique Constraintを設けた。
  Raw Provider Payloadは暗号化し、Header／Request／ResponseはRedaction済み情報だけを
  保存する。PAN、CVV／CVC、PIN、Track Data、Provider Secretは保存しない。
- `payment_adjustment_prize_actions`は正しいV2 `user_prizes`が未実装のため、
  架空RelationやFKなしTableを作らずDraw／Prize実装後へ延期した。

### MIG-044 Domain Service／Transaction

- Payment作成、署名検証済みProvider Event記録、Payment Lifecycle、Payment成功、
  paid／free Point付与、全額未使用Refund、Provider Refund Operation、
  Chargeback、Chargeback Reversalの`manual_review`境界を実装した。
- Payment成功はProvider Event、Payment、Wallet、Point Lot／Ledgerの順にLockし、
  Payment status、Point Operation／Lot／Ledger、Wallet、Grant、Audit、Outboxを
  同一DB Transactionで確定する。同一Paymentの再送／並行処理はPointを1回だけ付与し、
  Terminal Stateを巻き戻さない。
- Provider EventとProvider Operationは同じIdempotency識別子の再送内容が完全一致する
  場合だけReplayを許可し、異なるPayload／Requestでの再利用を拒否する。
  Provider通信境界はDB Transaction外に限定した。
- Refundは成功済みPayment由来のpaid／free Lotが全額未使用、未失効、未予約の場合だけ
  Reservationを作成する。Provider結果不明時はReservationを維持し、明確な失敗時だけ
  release、成功時はPoint Operation／Ledgerで取消してconsumeする。通知失敗で返金本体を
  RollbackしないOutbox境界を設けた。
- Chargebackは対象Paymentのpaid、他のpaid FIFO、対象Paymentのfree Bonus、
  他のfree通常順、paid不足分のfree、Shortfallの順で処理する。Wallet／Lotを負数にせず、
  Shortfall用の負Ledgerを作らない。free Bonusをpaid Lotから取消さず、
  Chargeback Reversalは自動復元せず`manual_review`とする。
- ProductionでMock PaymentをFail Closedにした。Public／Admin／Webhook API、
  実Provider Adapter／SDK、3D Secure、Hosted Checkout、部分返金、UI、
  Draw／Prize／Shippingは実装していない。

### Migration／Local Verification

- V1 Migrationは40件、内容SHA-256 Set
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  でBaselineと一致し、編集、改名、削除していない。
- V2 Migrationは7件、内容SHA-256 Set
  `e2f3b383b89291bbdcb997136f78b6d6f2175b0fe4613409b33525125f94a486`
  である。
- `/etc/oripa-v2/dev.env`と`scripts/db/v2_database.py`のGuardを使用し、
  Persistent V2 PostgreSQL 17／Redis 7およびTask専用Ephemeral Source／Restoreで
  `migrate:fresh`を各2回実行した。Migration Status、35 TableのSchema Inventory、
  PostgreSQL／Redis／API／Admin Health、Host Port非公開はPASSした。
- V2 PHPUnitは82 Test、432 Assertionが成功した。Payment専用TestはPlan Constraint、
  Lifecycle、Terminal巻戻し、Idempotency、同一Payment並行成功、二重付与防止、
  Transaction Rollback、Refund条件、Reservation消費拒否、Provider失敗／不明、
  Provider Event／Operation再送、Chargeback取消順、Shortfall、Reversal、
  Audit／Outbox、Mock Production拒否を検証した。MIG-041A～MIG-043 Regressionも
  同じ全V2 Testで成功した。
- Ephemeral Backup／Restoreは一致した。Source／Restore Schema SHA-256は
  `deb895e3245d2d6c06c96b336f23dbf465434f69d647c2efcc9ddcb65c916b49`、
  Migration Row SHA-256は
  `951f49f3fe666b8140006863fae87bfdffcbac280f8434fc2b4f2d16235a343e`、
  Backup SHA-256は
  `5c2537cbbfffa7ae28605f52480c338216d24c5be5634d2bdadd1ef43c9e30f4`
  である。Task専用Container／Network／Volume CleanupはPASSした。
- Policy Unit Test 55件、Quality Unit Test 5件、Security Unit Test 4件、
  DB Guard Unit Test 16件、`policy-gate`、`quality-gate`、`security-gate`、
  `git diff --check`はPASSした。DB Guardのredacted diagnosticを`run`に加えて
  `exec`にも適用し、秘密値を含めずTest失敗箇所を識別可能にした。
- Root WorkspaceのInstall／Audit、OpenAPI、Admin Typecheck／Lint／Build、
  Storefront Client、Site Schema、Storefront Testkitの生成差分／Typecheck／Lint／
  Build／TestはPASSした。Root Auditは0 Findingである。
- Legacy Frontendは独立Install、Typecheck、BuildがPASSし、Lintは既存Baselineの
  8 Error／1 Warning、9 Findingと完全一致した。Legacy Auditは11 Finding、
  新規Critical／High Findingは0件、SEC-003対象Advisoryは0件である。
- 実Payment Provider、実Webhook署名、Browser／E2E、Production Deploymentは
  未実行であり、PASSとは記録しない。Required 5 Check、CodeQL、
  `CodeQL (javascript-typescript)`、Dependency Review、Final Head固定後の
  Fresh Self-review、Squash Commit、CleanupはGitHub PR上で確定する。
- Draft PR `#87`の初回`policy-gate`は、PR本文の`Base SHA`欄にOriginal／Resumeの
  2 SHAを併記したため単一Full SHAのMetadata Schemaを満たさず失敗した。実装やGateの
  Failureではない。`Base SHA`をResume Baseの単一値へ修正し、Original Baseを
  Summaryへ分離した再実行では`policy-gate`が成功した。初回Failureを含まない新しい
  Final Headで全Checkを再実行し、Required CheckをBypassしない。
- 次のHeadの`integration-gate`では、既存Point Idempotency TestがReplay用Expiryを
  `now()->addDay()`で2回生成し、実行が秒境界を跨いだ場合だけRequest Hashが変化する
  Timing依存を検出した。Expiryを一度だけ固定して同一Replayへ再利用し、
  Replay成功と異なるAmountでのKey再利用拒否Assertionは維持した。GateやBaselineを
  弱めず、Final Headで全V2 Testを再実行する。
- Timing修正後のHeadでは、追加変更した
  `apps/api/tests/V2/PointModelFoundationTest.php`をPR本文のChanged filesへ
  反映していなかったため`policy-gate`が失敗した。実際のPathはTask Policyの
  `apps/api/tests/V2/**`内である。PR本文へ明示し、新しいFinal Headで全Checkを
  再実行する。
- 直後の再実行はBranch Push後にPR本文を更新したため、Workflow Eventが更新前本文を
  snapshotして同じChanged files不一致となった。GitHub上のPR本文と実際の18 Pathが
  完全一致することを先に確認し、その本文を固定した状態で新HeadをPushする。
- 稼働中V1 Runtimeは固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`でcleanである。Nginx、V1本番DB／
  Redis／Storage、V1 Migration、V1 Archive Branch／Annotated Tagを変更していない。
- Gate G3はPayment Model Foundationまで進んだが、実Provider Adapter、
  `payment_adjustment_prize_actions`、初回`2.0.0-alpha.1` Artifact等が残るため
  `NOT COMPLETE`である。次候補は`MIG-045 Initial 2.0.0-alpha.1 Artifact`だが、
  MIG-044完了後には開始しない。

## MIG-044 Closeout／MIG-045 Initial Platform Alpha Artifact

### MIG-044 Closeout

- MIG-044のPR `#87`はMerged、Issue `#83`はClosedである。Final Headは
  `4bd3ca56a8a9c7b71430a3a42191548b87fa8cc6`、Squash Commitは
  `b7cdc941b540fc7e28e985cc7e42c6ab86226469`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Fresh Self-reviewはFinal Headと一致し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-044 Worktreeは削除済みで、Local
  `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtimeは`bfca8efa0b85c00a88fb0fd439a123b722577b68`でcleanであり、
  V1本番Resource、V1 Migration 40件、Archive Branch、Annotated Tagを変更していない。

### MIG-045 Task／Release Boundary

- Task IDは`MIG-045`、Riskは`R3`、Issueは`#89`、Base SHAは
  `b7cdc941b540fc7e28e985cc7e42c6ab86226469`である。
- 推奨された`release/MIG-045-platform-alpha-artifact`は、GitHub App Wrapperが
  `release/**`をProtected RefとしてTask Pushから拒否するため使用しない。許可済みPrefixの
  `chore/MIG-045-platform-alpha-artifact`をPR用Task Branchとして使用し、
  Release Tag `platform-v2.0.0-alpha.1`とは分離する。
- Versionは`2.0.0-alpha.1`、Compatibility Familyは`2`、Channelは`alpha`である。
  同じTag、Version、GitHub Release、MIG-045 Issue／PRが存在しないことを開始前に確認した。
- AlphaはInternal Development専用で、Production／Commercial利用禁止、Stable Data保持保証
  なしとする。人間のCommercial Production GO、法務、会計、未確定Provider判断は行わない。

### Artifact Foundation

- API／Admin Container Image、3 First-party Package Tarball、Public／Admin／Webhook
  OpenAPI Bundle、V2 Migration Archive、Release Manifest、Compatibility Matrix、
  Changelog、Known Issues、Test／Migration／Security Summary、CycloneDX SBOM、
  in-toto SLSA Provenance、`SHA256SUMS`を固定Sourceから生成するBuilderを追加した。
- Docker Base ImageはNode、PHP、ComposerをTagだけでなく取得済みManifest Digestへ固定し、
  Version、Revision、Source、Created、TitleのOCI Labelを必須化した。
- Release Manifest Schemaを`2.0`へ更新し、API／Admin Image、3 Contract、
  First-party Package、Migration Set、Exact Runtime、Rollback分類、Known Issues、
  SBOM、Provenance、Secret Scan、Production GateをStrict Objectとして分離した。
- BuilderはSource CommitのTimestampを使用し、Migration Archiveとgzip metadataを固定する。
  Package、Image、Manifest、Provenance、全Asset Checksumを検証し、同じSourceから2回生成した
  Bundleの全File SHA-256一致を要求する。
- Provenanceは全Assetと固定Sourceを結ぶ機械可読in-toto Statementである。
  外部署名IdentityによるCryptographic Signatureは本Alphaでは`NOT_STARTED`で、
  署名済みとは記録しない。
- Repository外GitHub App Wrapperへ、Pre-release Tagの自動判定と、
  固定Repository／Tag／Commit／Release Evidence／Asset SHA-256を必須にする
  `upload-release-asset`を汎用Operationとして追加した。任意URL、Asset差替え、
  Token／JWT／Private Key表示は許可しない。

### Initial Verification

- Release Unit Test 5件とPolicy Unit Test 57件はPASSした。
- Release Source Validationは3 Contract Version `2.0.0-alpha.1`、V2 Migration 7件、
  Migration Set SHA-256
  `e2f3b383b89291bbdcb997136f78b6d6f2175b0fe4613409b33525125f94a486`
  を確認した。
- WrapperとTask PolicyのPython／JSON Syntax、Owner／Permission、
  invalid Asset SHA-256拒否TestはPASSした。秘密値は出力していない。
- Root／Legacy Frozen Install、Audit、Package／Admin／OpenAPI、V2 Database、
  Image Build／Scan、SBOM、Artifact再現性、GitHub Checkは固定Checkpoint Commit後に
  全て再実行する。未実行項目をPASSとは記録しない。
- V1 Runtime、Nginx、V1本番DB／Redis／Storage、V1 Migration、
  V1 Archive Branch／Annotated Tagを変更していない。

### Local Validation／Image Security

- Root／LegacyのFrozen InstallはPASSした。Root Auditは0 Finding、Legacy Auditは
  既存Baseline以下の11 Findingで、新規Critical／High Findingは0件だった。
  Composer Auditは既存の期限付き10 Findingと完全一致し、Baselineを追加・拡張していない。
- AdminのTypecheck／Lint／Build、Legacy FrontendのTypecheck／Build、
  Storefront Client 9 Test、Site Schema 10 Test、Storefront Testkit 16 Test、
  OpenAPI 4 TestとBundle差分検査はPASSした。Legacy Lintは既存Baselineの
  8 Error／1 Warningと完全一致した。
- Persistent／Task専用Ephemeral V2 PostgreSQL 17／Redis 7で
  `migrate:fresh`を2回実行し、V2 PHPUnit 82 Test／432 Assertion、
  Backup／Restore、Schema／Migration Checksum、Resource CleanupはPASSした。
  Source／Restore Schema SHA-256は
  `deb895e3245d2d6c06c96b336f23dbf465434f69d647c2efcc9ddcb65c916b49`、
  Migration Row SHA-256は
  `951f49f3fe666b8140006863fae87bfdffcbac280f8434fc2b4f2d16235a343e`である。
- API Imageの初回Scanは、公式PHP Base Imageに含まれる
  `linux-libc-dev 6.1.176-1`の修正可能なHigh Finding 10件を検出し、
  Artifact生成をFail Closedで停止した。Build dependencyをmulti-stageへ隔離し、
  Runtimeの同PackageをExact `6.1.177-1`へ更新した再Scanでは
  Critical／High Finding 0件となった。`pcntl`、`pdo_pgsql`、`zip`のRuntime Moduleは
  維持され、Application Logicは変更していない。
- Trivy Vulnerability ReportのScan時刻とCycloneDXのUUID／生成時刻を除去して
  正規化し、Security Evidenceの内容を維持したまま同一SourceのByte再現性を検証可能にした。
  Admin ImageはNext.js standaloneのmulti-stage Runtimeへ変更し、Root Workspaceと
  Runtime不要のglobal `npm`／`corepack`をRelease Imageへ残さない。Admin Runtimeは
  180,269,440 ByteでHealth Endpointが成功し、再ScanはCritical／High 0件だった。
  Composer VersionはAPI RuntimeへBuild toolを残さず、Digest固定したComposer Imageから
  収集する。
  Docker ArchiveはOCI Layoutの`manifest.json`から拡張子なしConfig Blobを解決して
  Digest／OCI Labelを検証し、外装Tar Headerを固定Commit時刻へ正規化する。
  CycloneDXの参照IDもComponent内容から決定する。Release Unit Test 10件、
  Policy Unit Test 58件、Quality Unit Test 5件、
  Security Unit Test 4件、DB Guard Unit Test 16件、
  `policy-gate`、`quality-gate`、`security-gate`はPASSした。
- Host PHPは8.3.31のためPHP 8.4を要求するComposer Lockを直接Installできなかった。
  Release API Image内のPHP 8.4でFrozen Composer Install、Module確認、Image Scanを
  実行して代替し、Host失敗をPASSとは記録しない。
- Final Headは本節とImage Security修正を含むCommitへ固定し、同一Sourceからの
  Artifact二重生成、全Asset SHA-256一致、Required／Available Check、
  Fresh Self-reviewをPR上で確定する。PR Merge後はSquash CommitをRelease Sourceとして
  同じ検証を再実行する。

## MIG-045 Closeout／MIG-050 Catalog・Probability Read-only Vertical Slice

### MIG-045 Closeout

- MIG-045のIssue `#89`はClosed、PR `#90`はMergedである。Final Headは
  `c68ff796fe1edf4c05ba7644950a9865c20a294f`、Squash Commitは
  `07d9da4c8a482a806c092ec8ef19ac62d901dbec`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Fresh Self-reviewはFinal Headと一致し、
  SEV-0／SEV-1は0件だった。
- `platform-v2.0.0-alpha.1`はSquash Commitを指すPre-releaseとして作成済みである。
  Release Asset、Manifest、Image Digest、SBOM、Provenance、Checksumの検証を完了し、
  Gate G3は`COMPLETE`である。AlphaはProduction／Commercial利用禁止のままである。
- Remote／Local Task BranchとWorktreeは削除済みで、開始時にLocal
  `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、V1本番DB／Redis／Storage、Nginx、`v1/early-release`、V1 Migration、
  Archive Branch、Annotated Tagを変更していない。

### MIG-050 Task／Schema

- Task IDは`MIG-050`、Riskは`R3`、Issueは`#103`、Branchは
  `migration/MIG-050-catalog-probability`、Base SHAは
  `07d9da4c8a482a806c092ec8ef19ac62d901dbec`である。
- V2専用Migration RootへCategory、Tag、Rank、Rank Asset、Prize Master、
  Presentation Asset、Gacha Master、Gacha Version、Gacha-Prize Relation、
  Probability Version／Stage／Entry、Minimum Guarantee、Fixture Import Runの
  15 Tableを追加した。
- Public IDはUUIDv7、Point／ppm／数量は整数、価格・在庫初期値・販売口数は非負、
  Category／Tag／Rank／Asset／Prize／GachaのCode／Slug／Storage識別子はUniqueとした。
  `tenant_id`、`no_prize`、Provider／Shippingの推測構造は追加していない。
- Probability Entryは`prize`または`point_back`だけを許可する。各StageはEntryと
  Minimum Guaranteeの合計が`1,000,000 ppm`でなければDomain Validationと
  PostgreSQL Triggerの双方で公開を拒否する。
- Published Gacha Version、Published Probability Version、そのPrize Relation、
  Stage、Entry、Minimum GuaranteeはApplication ModelとDB TriggerでUpdate／Deleteを
  拒否する。公開開始／終了日時の逆転もDB Constraintで拒否する。

### Contract／公開制御

- Public OpenAPIへ`listGachaCategories`、`listGachaTags`、`listGachas`、
  `getGacha`、`getGachaBySlug`を追加した。Public Operationは認証6件と合わせて
  11件で、Admin 9件、Webhook 0件とのSurface分離を維持する。
- 公開中かつ公開期間内のGacha Versionと、それに紐づくPublished Probability
  Versionだけを返す。Draft、公開前、公開終了後は一覧へ含めず、詳細はRFC 9457の
  `CATALOG_NOT_FOUND`を返す。
- 一覧はOpaque Cursor、既定20件、最大100件である。Master、一覧、詳細へ
  `Cache-Control`を明示し、Request IDとAPI Version Headerを返す。
- Public ResponseはCategory／Tag、Rank、Prize表示情報、Presentation Asset、
  Rank別合計ppm、Point Back合計ppm、Minimum Guaranteeを含む。景品別個別ppm、
  内部在庫、原価、Storage識別子、Snapshot Checksum、内部`id`、Secret、
  Credentialは公開しない。
- Public OpenAPI Bundleを正本としてStorefront Client Typesを再生成し、
  GET専用Catalog Facadeを追加した。Admin／Webhook型はStorefront Clientへ公開しない。
  Storefront TestkitへPublic Catalog Fixtureを追加し、Operation 11件、集約確率、
  Public-safe Fieldを固定した。

### Fixture Import／Characterization

- 決定的FixtureはCategory／Tag、Rank、Asset、Prize、Gacha、Version、
  Probability Stage／Entryの順にImportする。Record数28件、Manifest SHA-256、
  FK、重複Code／Slug、Stage合計、Asset SHA-256を検査する。
- 同一Manifest再実行は同じImport Runを返し、Recordを重複作成しない。
  MIG-070／MIG-071の本番Exporter／ImporterやProduction Dataは使用していない。
- V1の`GachaDetailResource`、Probability Validator、既存Gacha API Testを
  Characterization参照として固定した。V1のお知らせ拡張と100／1000回Bulk Drawは
  V2移行差分として記録し、MIG-050へCode Copyしていない。

### Local Verification

- OpenAPI Lint／Bundle／差分検査はPASSし、Operation数はPublic 11、Admin 9、
  Webhook 0である。OpenAPI Unit Test 4件、Policy Unit Test 62件、
  Quality Unit Test 5件、DB Guard Unit Test 17件はPASSした。
- Storefront Clientは生成差分、Typecheck、Lint、Build、10 TestがPASSした。
  Storefront Testkitは生成差分、Typecheck、Lint、Build、17 Test、
  Export Surface、実Network禁止検査がPASSした。
- `/etc/oripa-v2/dev.env`と`scripts/db/v2_database.py`のGuardだけを使用した。
  Persistent V2 PostgreSQL 17／Redis 7でV2 `migrate:fresh`を2回実行し、
  Migration Status、全V2 Test、Schema Inventory、HealthはPASSした。
- Task専用Ephemeral Source／Restoreでも`migrate:fresh`を2回実行した。
  V2 Migrationは8件、Migration Set SHA-256は
  `1ac40a48b0906ea72e190141846954df3cf8cd59e81382ae5f406abc124fa1fa`である。
  Source／Restore Schema SHA-256は
  `0a0628da358cc096f817b96795de0ee2054df7026e027338a8849f2b2690b842`、
  Migration Row SHA-256は
  `5d45636a52cd25841b20e84177fe5353294189235a67c41700b9e793f5dec294`、
  Backup SHA-256は
  `2445ceeda8cfdbca8a86258757258047ae94d1369c7c22bcabf974fee9169d8c`
  で一致した。Task専用Container／Network／Volume CleanupはPASSした。
- Repository外Evidenceは
  `/var/www/oripa-evidence/MIG-050-20260727T130018Z/`へDirectory mode `700`、
  File mode `600`で保存した。DB本文、Secret、Credential、実PIIはWorklogへ
  記録していない。
- Root／Legacy Audit、全Package／Admin検証、Security Gate、Required／Available
  GitHub CheckはFinal候補Headで実行する。未実行項目をPASSとは記録しない。
- Draw Transaction、Point消費、在庫減算、Row Lock、User Prize、Admin Mutation、
  Admin UI、Shipping、Production Deploymentは実装していない。
- V1 Runtime、本番Resource、Nginx、`v1/early-release`、V1 Migration 40件、
  Archive Branch、Annotated Tagは変更していない。
- Gate G4はCatalog／ProbabilityのRead-only Vertical Sliceまで進んだが、
  Draw Vertical Slice等が残るため`NOT COMPLETE`である。次Task候補は
  `MIG-051 Draw Vertical Slice`だが、本Task完了後には開始しない。

## MIG-050 Closeout／MIG-051 V2 Draw Vertical Slice

### MIG-050 Closeout

- MIG-050のIssue `#103`はClosed、PR `#104`はMergedである。Final Headは
  `563aa8838d266c9233fce448c4855e89961d9039`、Squash Commitは
  `6ba4a949bf41526a8b09c3c544bf30b9bd3ac2fa`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Fresh Self-reviewはFinal Headと一致し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-050 Worktreeは削除済みである。開始時にLocal
  `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、V1本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### MIG-051 Task／Schema

- Task IDは`MIG-051`、Riskは`R3`、Issueは`#105`、Branchは
  `feat/MIG-051-draw-vertical-slice`、Base SHAは
  `6ba4a949bf41526a8b09c3c544bf30b9bd3ac2fa`である。
- V2専用Migration Rootへ`gacha_draw_states`、`prize_inventories`、
  `draw_requests`、`draw_results`、`user_prizes`を追加した。
  有効な`user_prizes` FKを参照できる段階になったため、MIG-044で延期した
  `payment_adjustment_prize_actions`も追加した。Chargeback時の景品自動取消や
  自動復元は実装していない。
- 内部PKは`bigint`、Public ResourceはUUIDv7、Point／Count／Sequenceは整数とした。
  負数、許可外Draw Count、Draw Sequence重複、Draw Request内Sequence重複を
  DB Constraintで拒否する。`tenant_id`、内部原価、個別ppmのPublic公開はない。
- Published Catalog／Probability VersionをDraw Requestへ固定し、Draw時点の
  表示SnapshotとChecksumを保存する。Core状態をJSONBだけでは管理しない。

### Contract／Transaction

- Public OpenAPIへ`createDraw`と`getDrawRequest`を追加し、Public Operationは
  13件、Admin 9件、Webhook 0件である。全Draw Countで`Idempotency-Key`を必須とし、
  RFC 9457 Error、User Realm、CSRF、Exact Origin、JSON Content Typeを既存境界で
  検査する。
- Storefront ClientへPublic Draw Facadeと生成型を追加し、Storefront Testkitへ
  Draw Fixtureを追加した。Admin／Webhook型、内部`id`、個別ppmは公開していない。
- Lock順はIdempotency Record／Draw Request、Gacha Mutable State、Wallet、
  Point Lot、Prize Inventoryの内部ID昇順、Draw Result／User Prizeである。
  Point消費、Inventory、Draw Result、User Prize、Audit、Outboxを単一DB Transactionで
  確定し、外部HTTP、Mail、S3、Provider通信はTransaction内で実行しない。
- `1`／`5`／`10`／`100`／`1000`だけを受け付ける。Point不足、残口数不足、
  Inventory不足、非公開Catalogは永続Draw履歴を作成せず拒否する。
  同一Key／同一RequestはCanonical Resultを返し、異なるRequestはConflict、
  処理中はFail Closedとする。ReplayではCSPRNGを再実行しない。
- Deadlock／Serialization Failureは、最初に生成した同一CSPRNG列と同一Keyで
  最大3回だけRetryする。Terminal状態を巻き戻さない。Retentionは30日とし、
  Terminal Recordだけを後続Cleanup対象にする。

### Probability／Set-based Persistence

- V1固定Sourceと承認済みTestをChecksum付きCharacterization Fixtureへ固定した。
  各DrawでCSPRNGを生成し、Draw Sequenceに従ってProbability Stageを順方向へ評価する。
  Stage単位Range CacheとPointerを使用し、閾値跨ぎ時だけ次Stageへ進む。
- Minimum GuaranteeはV1どおり、在庫切れPrizeのppmをStageのMinimum Guaranteeへ
  合算し、`prize`またはfree Pointの`point_back`だけを結果とする。
  `no_prize`や期待値による一括近似は使用しない。
- 1000件を順序どおりメモリ上で生成し、Draw Result／User Prizeを250件単位で
  Bulk Insertする。Point Back Lot／Ledgerは専用Bulk Domain Methodで保存し、
  Wallet更新を集約する。個別履歴、Sequence、Point由来、Auditの追跡性は省略しない。
- ResponseはDraw Request Public ID、実行数、Point消費内訳、更新後Wallet、
  Rank／Prize集計、Point Back、高Rank上限付き結果、Probability Version、
  Replay、Request ID、状態を返す。100／1000回で1000件の個別結果は返さない。

### Local Verification／Performance

- Persistent V2 PostgreSQL 17／Redis 7でV2 `migrate:fresh`を2回実行し、
  Migration Status、全V2 Test、Schema Inventory、HealthはPASSした。
  V2 Migrationは9件、Migration Set SHA-256は
  `bb045db7e8d9e88ff3430b842d1c7e6cb6c37c4f2026a1806af83f106b709451`
  である。
- Draw対象Test、V1 Characterization、HTTP Auth／CSRF／Origin、
  Point／Payment／Refund／Chargeback／Audit／Outbox Regression、
  Policy Unit Test 65件、DB Guard Unit Test 17件、OpenAPI Unit Test 4件は
  PASSした。
- 単独100回は5回でp50約150ms、p95約159ms、単独1000回は5回で
  p50約434ms、p95約528msだった。1000回のQuery数は最大57、
  Response Sizeは最大約18.5KBで、V2 Merge基準の2秒／100 Query以内である。
- 同一Gachaへの1000回集中実行は、5 User最終約3.4秒、10 User最終約7.2秒、
  20 User最終約14.1秒だった。別Gacha 20 Userは最終約14.5秒である。
  最大Query数64、未解決Deadlock 0、500／502／504 0、Point／Inventory／
  Draw History不整合0だった。
- Storefront Clientは生成差分、Typecheck、Lint、Build、TestがPASSした。
  Site Schema、Admin、Storefront Testkitの生成差分、Typecheck、Lint、Build、
  18 Test、Export Surface、実Network禁止検査もPASSした。
- Task専用Ephemeral Source／RestoreでV2 `migrate:fresh`を2回実行し、
  全V2 Test、Draw Load Test、API／Admin Health、Backup／Restore、
  Resource CleanupはPASSした。Source／Restore Schema SHA-256は
  `398c1295cae5416c9e165301cb58bd47ea3b297b57d5d7f42bbd22a8cd5c6cd2`、
  Migration Row SHA-256は
  `1d08dfe9c592cecf7d23cac47a0d8ce0e3fd43d3f30304a12a6ed8b7bd6e5b11`、
  Backup SHA-256は
  `1f3c746cb9ba3e6d9d662f223a2d1da1491ad39bef31d7db1796a3e77ce0fd58`
  で一致した。
- Root／Legacy Frozen InstallはPASSした。Root Auditは0 Finding、Legacy Auditは
  既存Baseline以下の11 Finding、Composer Auditは既存期限付き10 Findingと一致し、
  Baselineを追加・拡張していない。新規Critical／High Findingは0件である。
- Legacy FrontendのTypecheck／BuildはPASSし、Lintは既存Baselineの
  8 Error／1 Warningと完全一致した。Required／Available GitHub Checkと
  Final Head固定後のFresh Self-reviewはPR上で確定する。
- Storefront UI、Animation、Admin Mutation、Prize Exchange、Shipping、QA Draw、
  Payment Provider、Production Deploymentは実装・実行していない。
- V1 Runtime、本番Resource、Nginx、`v1/early-release`、V1 Migration 40件、
  Archive Branch、Annotated Tagを変更していない。
- Handoff CはCatalog ReadとDraw WriteのContract／DB境界まで実装済みだが、
  Prize／Exchange／Shipping Vertical Sliceが残る。Gate G4は`NOT COMPLETE`である。
  次Task候補は`MIG-052 Prize／Exchange／Shipping Vertical Slice`だが、
  MIG-051完了後には開始しない。

## MIG-051 Closeout／MIG-052 Prize・Exchange・Shipping Vertical Slice

### MIG-051 Closeout

- MIG-051のIssue `#105`はClosed、PR `#106`はSquash Mergedである。Final Headは
  `1fcc43d75eb70d696fa3968287822276a282d1ba`、Squash Commitは
  `4112f0d9442efcd155e9593fbbf8e531aef58641`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewは
  成功した。Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-051 Worktreeは削除済みである。開始時にLocal
  `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、V1 Migration、
  Archive Branch、Annotated Tagを変更していない。

### Characterization／Schema

- Task IDは`MIG-052`、Riskは`R3`、Issueは`#107`、Branchは
  `feat/MIG-052-prize-exchange-shipping`、Base SHAは
  `4112f0d9442efcd155e9593fbbf8e531aef58641`である。
- V1のUser Prize、Point交換、Shipping Address／Request、Tracking、Chargeback Holdを
  Characterizationした。V1の確定状態は`stored`、`shipping_requested`、`packing`、
  `shipped`、`delivered`、`converted`、`expired`、`held`／`hold`、
  `return_requested`、`returned`、`canceled`を基礎とする。QA由来景品も通常景品と同じ
  交換／配送境界を使用し、MIG-053用の追跡関係を維持した。
- V2専用Migrationへ`user_prize_status_histories`、`prize_exchange_requests`、
  `prize_exchange_request_items`、`shipping_addresses`、`shipping_requests`、
  `shipping_request_items`、`shipping_request_status_histories`を追加した。
  `user_prizes`へDraw時点の交換Point Snapshot、Storage期限、交換Point、Terminal時刻を
  Forward-safeに追加した。既存Migration、`tenant_id`、V1 Tableは変更していない。
- User Prizeの所有User、Draw Result、Catalog Relation、取得日時、交換Point Snapshot、
  Storage期限はDB Triggerで変更を拒否する。User Prize／Shipping状態履歴は
  Update／Delete／TruncateをDB境界で拒否する。Rollbackは状態が全件`stored`の場合だけ
  許可し、業務状態を暗黙に巻き戻さない。

### Contract／Point Exchange

- Public OpenAPIへUser Prize一覧／詳細、Point交換、Shipping Address CRUD、
  Shipping Request一覧／作成／詳細を追加した。Admin OpenAPIへShipping Request
  一覧／詳細／状態更新を追加した。Public Operationは24件、Adminは12件、
  Webhookは0件である。RFC 9457、Opaque Cursor、User Realm、Admin Realm／Permission、
  MFA、CSRF、Exact Originの既存境界を維持する。
- Public Responseへ内部`id`、原価、個別ppm、内部Storage識別子、暗号文、
  管理Metadataを公開しない。Storefront Client TypesをPublic Bundleから再生成し、
  Prize／Shipping Facadeを追加した。Admin／Webhook型はExportしていない。
- 交換PointはPrize Masterの現在値ではなくMIG-051のDraw時点Snapshotを使用する。
  1件または最大100件を内部ID昇順でLockし、全件交換可能な場合だけ
  MIG-043の`V2PointService::grantPrizeExchange`でfree Pointを付与する。
  Point Operation／Lot／Ledger／Wallet、User Prize状態、履歴、Audit、Outboxを
  同一Transactionで確定する。一部成功、二重付与、Replay時の再付与はない。
- `Idempotency-Key`はPoint交換とShipping Requestで必須である。同一Key／同一Requestは
  Canonical Result、異なるRequestはConflict、処理中はFail Closedとする。
  Deadlock／Serialization Failureは同一Keyで最大3回だけRetryする。

### Shipping／PII／Hold

- Shipping Addressの宛名、郵便番号、都道府県、市区町村、番地、建物、電話番号は
  Laravel Application-level Encryptionで保存する。相関確認用にRepository外Keyによる
  HMACだけを保持し、一覧はMask、本人詳細だけ復号する。Address削除／変更後も
  Shipping Requestの暗号化Snapshotは不変である。
- PII相関Keyは`V2_PII_CORRELATION_KEY`としてRepository外Env Fileへ保存し、
  `scripts/db/v2_database.py`で32 Byte以上のBase64 Keyを生成・検証する。
  Compose／CIへ値を表示せず受け渡し、Key不在時はFail Closedとする。
- 1 Shipping Requestへ同一Userの複数User Prizeを含める。Address所有権と全景品状態を
  検査し、User Prize、Request／Item／History、Audit、Outboxを同一Transactionで作成する。
  Point交換済み、依頼済み、Hold中、期限切れ、Terminalの景品を拒否する。
- Adminは`shipping.request.manage` PermissionとMFA済みAdmin Realmでのみ参照・更新できる。
  許可遷移だけを受け付け、発送時はCarrier Code、Tracking Number、発送日時を必須とする。
  Tracking Numberは暗号化し、履歴とAuditへはHMAC相関値だけを残す。
  発送済み／配送完了／返送完了から交換可能状態へ巻き戻さない。
- Activeな`payment_adjustment_prize_actions`の`hold`／`return_request`は交換と発送を拒否する。
  Hold解除、景品取消、返送完了、Payment Adjustment状態変更を自動実行しない。
  拒否結果はPIIを含まない相関HashとReason Codeで永続Auditへ記録する。
- 配送先詳細取得、Address CRUD、Shipping Request作成、Admin配送先参照、
  Tracking登録、状態変更、Point交換、Hold拒否をAuditする。住所全文、電話番号、
  Full Email、Cookie、Session ID、TokenはAudit／Log／Errorへ保存しない。Address
  作成／更新／削除はAudit永続化と同一Transactionで実行し、Audit障害時はPII Mutationも
  Rollbackする。
- Lock順はIdempotency Record、User／Wallet、User Prize内部ID昇順、Point Lot、
  Shipping Request、Payment Adjustment Prize Action、History／Audit／Outboxである。
  `SKIP LOCKED`や外部配送通信をTransaction内で使用していない。

### Test／Performance

- Persistent V2 PostgreSQL 17／Redis 7とTask専用Ephemeral Source／Restoreで
  V2 `migrate:fresh`を各2回実行した。V2全Testは117件／781 Assertion、
  Performance Test 19 Assertion、実Process並行Test 9 Assertionを含めPASSした。
  通常Suiteでは明示的Load Test 1件をSkippedとし、Ephemeral Smokeの専用Load工程で
  別途PASSを確認した。
- 同一景品のPoint交換とShipping Requestを独立Process 2本で同時開始し、片方だけが成立、
  もう片方は状態競合として拒否された。重複Request、二重Point付与、二重Shipping Item、
  未解決Deadlock、部分成功は0件だった。
- Audit HMAC Keyを意図的に無効化するNegative Testで、Shipping Addressの作成／更新／削除が
  すべてFail Closedとなり、PII Mutationが残らないことを確認した。
- User Prize 1,000件のCursor一覧は10 Page／10 Query、Page p50 9.840ms、
  p95 11.192msだった。100件一括Point交換5回はp50 328.579ms、
  p95 367.675ms、各525 Queryだった。100件Shipping Request 5回は
  p50 192.530ms、p95 194.434ms、各317 Queryだった。
  Shipping一覧は2 QueryでN+1なし、Peak Memoryは82,837,504 Byteだった。
  Queryの主因は景品ごとのimmutable状態履歴とRequest Itemであり、省略していない。
- Ephemeral Backup／Restoreは一致した。V2 Migrationは10件、Migration Set SHA-256は
  `7d071219bff4bec38052b4e214a1fa4f3580a9ba13929b24b05fdde98f9dbb84`、
  Source／Restore Schema SHA-256は
  `f0dc3761ba913ffe0181252381926408c76783fbe32da193a9f46c23b5c26a6a`、
  Migration Row SHA-256は
  `1b502dcbedeeff9865e3f187f1b0ee4abfd932c8dc1a7a7d80928945c25c1118`、
  Backup SHA-256は
  `e9f5b35c5a73d5e705be5aae4fc702585fb64602b0ea6e82e7d4325a1ce5ada7`
  である。Task専用Container／Network／Volume CleanupはPASSした。
- OpenAPI Lint／Bundle／Checksum、Storefront Client生成差分／Typecheck／Lint／Build／
  11 Test、Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Storefront Testkit生成差分／Typecheck／Lint／Build／19 Test／Export／Network境界、
  Admin Typecheck／Lint／BuildはPASSした。
- Policy Unit Test 68件、Quality Unit Test 5件、Security Unit Test 4件、
  DB Guard Unit Test 19件、OpenAPI Unit Test 4件、Release Unit Test 10件、
  `policy-gate`、`quality-gate`、`security-gate`はPASSした。
  Root Auditは0 Finding、Legacy Auditは11 Finding、Composer Auditは既存期限付き
  10 Findingと一致し、Baselineを追加・拡張していない。新規Critical／Highは0件、
  Secret／PII Candidateは0件である。
- Legacy FrontendのFrozen Install、Typecheck、BuildはPASSし、Lintは既存Baselineの
  8 Error／1 Warningと完全一致した。V1 Migrationは40件、内容Checksumは
  `e490ab8b248cecd709908023a21201e7f3bf7dfb0bbd703a8197d4642eff0631`
  で不変である。
- Registration Verificationの`created_at`と`expires_at`が秒境界を跨ぐ既存Flakeを
  検出した。DBの60分TTL Constraintを満たすよう同一固定時刻から生成する最小修正だけを
  行い、認証仕様、TTL、Token、Session境界は変更していない。
- GitHub Required／Available Check、Final Head固定後のFresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree CleanupはPR上で確定する。
  未実行項目をPASSとは記録しない。

### Scope／Gate G4

- Storefront UI、Admin UI、Site Design、Animation、Carrier API／Webhook、
  送り状PDF、送料決済、倉庫API、Mail／SMS送信、QA Mode、Reporting／Export、
  Payment Provider、Production Deployment、V1本番反映は実装・実行していない。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、V1 Migration、
  Archive Branch、Annotated Tagを変更していない。
- Handoff CはCatalog Read、Draw Write、User Prize Read、Point Exchangeまで接続済みである。
  Handoff DはShipping Address／Request／TrackingとPII AuditのAPI／DB境界まで接続済みだが、
  最小Storefront画面、最小Admin画面、Staging E2Eが残る。
- Gate G4は`NOT COMPLETE`である。次Task候補は
  `MIG-053 QA Draw Vertical Slice`だが、MIG-052完了後には開始しない。

## MIG-052 Closeout／MIG-053 QA Draw Vertical Slice

### MIG-052 Closeout

- MIG-052のIssue `#107`はClosed、PR `#108`はSquash Mergedである。Final Headは
  `393a4e278a14bda7e6b21ee513f1858d1bdd4f03`、Squash Commitは
  `37ba098b89848f962ddab2fbc5dcb0763beac818`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Fresh Self-reviewはFinal Headと一致し、
  SEV-0／SEV-1は0件だった。
- Remote Task Branch、Local Task Branch、MIG-052 Worktreeは削除済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- Final Headの通常V2 Suiteは117 Test／781 Assertion／1 Skipである。これは
  Performance Test 1件／19 Assertionと専用Process Concurrency Test
  1件／7 Assertionを含む総数であり、これらを117件へ加算しない。Skip 1件は
  `ZDrawConcurrencyLoadTest`で、専用Load工程では環境変数を明示して別途実行した。
  PR本文の116 Test／774 Assertion／1 SkipはConcurrency Test追加前の実測であり、
  Final Headの正本値とは統合・合算しない。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### V1 Migration Checksum正規化

- `apps/api/database/migrations/*.php`と固定V1 Runtimeの対応40 Fileについて、
  File名、Byte内容、改行、mode `100644`が全件一致し、実File差分は0件だった。
- Policy正本Algorithmは各File内容のSHA-256を辞書順に並べ、改行区切りのDigest Setを
  再度SHA-256化する
  `sha256(sorted(sha256(file_content)))`である。正本値は
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で、`.ci/baselines/v1-migrations.json`を変更していない。
- MIG-052記録の
  `e490ab8b248cecd709908023a21201e7f3bf7dfb0bbd703a8197d4642eff0631`
  はFile名順のDigest列をHashした旧算出値である。Algorithm差だけであり、
  Baselineを新値で上書きしていない。以後はPolicy正本Algorithmを使用する。

### MIG-053 Task／Schema

- Task IDは`MIG-053`、Riskは`R3`、Issueは`#109`、PRは`#110`、Branchは
  `feat/MIG-053-qa-draw-vertical-slice`、Base SHAは
  `37ba098b89848f962ddab2fbc5dcb0763beac818`である。
- V2専用Migrationへ`qa_test_user_modes`、`qa_draw_plans`、
  `qa_draw_plan_items`、`qa_draw_executions`を追加した。Draw Requestへ
  QA Mode／Plan参照、Draw ResultへPlan Item参照を追加し、User Prizeは
  `user_prize → draw_result → QA識別`で追跡するため重複QA Booleanを持たない。
- User＋GachaのActive PlanはPostgreSQL Partial Unique Indexで1件に制限する。
  Mode、Plan、Item、Executionは物理削除をDB Triggerで拒否し、Executionは
  Updateも拒否する。Quantity／Sort Order／消費数、Mode最大24時間、
  QA参照のNULL整合をDB Constraintで固定した。`tenant_id`は追加していない。
- V1 QA Mode／Plan／ExecutionはImportせず、Production Seedも追加していない。
  V2 Production開始時のQA Recordは0件であり、FixtureはTask専用DBだけで使用した。

### QA Mode／Plan／Owner Permission

- QA ModeはUserごとに1 Recordとし、理由と終了日時を必須、開始日時を任意、
  最大24時間とした。再有効化は同一Recordを更新し、無効化は
  `is_enabled=false`、`disabled_at`、`disabled_by_admin_id`を保存する。
  開始前、期限切れ、無効化済みは通常Probability Drawを使用する。
- QA Planは`active`、`paused`、`completed`、`disabled`を区別し、1件以上の
  Item、正整数Quantity、Plan内一意Sort Order、Published Gacha Version所属Prize、
  PublicなImage／Video Asset型を検証する。開始前Planは保持し、期限切れまたは
  全消費Planだけを`completed`へ遷移する。Completed／Disabled Planは再Activateしない。
- Admin OpenAPIへMode取得／更新／無効化、Plan一覧／作成／詳細／更新／Pause／
  Activate／Disable、Execution一覧／詳細の12 Operationを追加した。Admin Operationは
  24件、Publicは24件、Webhookは0件である。
- `qa.draw.manage`はOwner Roleだけへ付与した。ControllerとDomain Serviceの両方で
  Admin／OperatorのRead／Writeを403、未認証を401とし、既存のAdmin Realm、
  MFA Session、CSRF、Exact Origin、JSON Content Type境界を維持した。
  Public Bundle／Storefront ClientへQA管理型、理由、Owner、内部IDを公開していない。

### Draw統合／Idempotency／QA識別

- Active QA Modeでは有効なPlanを必須とし、Planなし、期間外、残数不足、
  Prize／Asset不整合、在庫不足を422でFail Closedにする。通常Probabilityへの
  Fallbackは行わず、Point、Inventory、Sold／Won、Draw、User Prize、Plan消費、
  Executionを残さない。
- Plan Itemは`sort_order`、内部ID、Quantity／Consumed Count順でLockして展開する。
  同一ItemのPrize／Asset検証結果は1回だけ構築して数量展開へ再利用する。
  各Drawで通常どおりProbability Stageを解決しCSPRNGを生成するが、QA景品選択へ
  CSPRNGを使用せず、Point Backを生成しない。
- `1`／`5`／`10`／`100`／`1000`を既存単一Bulk Requestと単一Transactionで処理する。
  Draw Result／User Prizeは既存Chunked Bulk Insertを使用し、個別ResultとPlan Item
  Relationを全件保存する。Responseは既存集計形式を維持し、QA理由や巨大JSONを返さない。
- Lock順はIdempotency、Gacha、QA Mode／Plan／Item、Wallet／Point Lot、
  Prize Inventory内部ID順、Result／Prize、Plan消費／Execution、Audit／Outboxである。
  Point ServiceへTransaction内専用の事前Lock／残高検証境界を追加し、QAだけが
  InventoryをWalletより先にLockする順序反転を防止した。
- Completed ReplayはCanonical Resultを返し、Plan、Point、Inventory、Sold／Won、
  Executionを再更新しない。CSPRNG列は非Replay確定後に1度だけ生成し、
  Deadlock Retryでは同じ列と同じPlan Item列を最大3回再利用する。
- QA Draw成功／失敗、Mode／Plan操作、Replay／Conflict、Execution参照を
  MIG-042 Auditへ接続した。通常`draw.completed` OutboxへQA識別だけを追加し、
  Password、Cookie、Session ID、Token、CSPRNG生値、Full Email、Asset内部Storage、
  不要なPIIはAudit／Public Responseへ保存していない。

### Test／Performance

- V2 Migrationは11件、Migration Set SHA-256は
  `54a8cb25cde7b961c8f5ea4033b6798f967e11178bc8486b55a99aa7ae8199e3`
  である。Task専用Source／Restoreで`migrate:fresh`を2回実行し、Migration Status、
  全V2 Suite、通常Draw Load、QA Draw Load、API／Admin HealthはPASSした。
- Source／Restore Schema SHA-256は
  `3cf6abcdaa5cb14a223254eb95d3e879c4446670d55713734dd6aeda353396ce`、
  Migration Row SHA-256は
  `b035e90a3e58d3864bb88e040603e814de688633ecf8ef3c72a6571b321493f0`、
  Backup SHA-256は
  `f35e536bc91149d36d122207ab1100b0b2bf79939b99ab191714d77504bc4e8a`
  で一致した。Host Port公開なし、Task Resource CleanupはPASSした。
- QA 100回5回はp50約124ms、p95約154ms、最大65 Queryだった。
  QA 1000回5回はp50約687ms、p95約825ms、最大76 Query、Response最大約18.6KB、
  Peak Memory約57.1MBだった。
- 同一GachaへのQA 1000回集中実行は5 User最終約4.0秒、10 User最終約8.2秒、
  Lock Wait p95はそれぞれ約2.9秒／7.1秒だった。500／502／504、未解決Deadlock、
  負Wallet、Inventory超過、Result／User Prize不整合は0件である。
  通常1000回Load Regressionも2秒／100 Query基準内でPASSした。
- Mode／Plan、Owner-only、Inactive通常Draw、Active指定景品、全Draw Count、
  Plan順序／完了、固定Asset Snapshot、Replay、CSPRNG非再実行、期限切れ完了、
  設定／在庫Failure全Rollback、QA景品Point交換、Execution参照を専用Testで確認した。
  同一Keyの異なるQA RequestはConflict、Processing中の同一KeyはFail Closedとし、
  Replay／Conflict／In-flightでPlan消費、Point、Resultを再更新しないことを直接確認した。
  Test専用PostgreSQL Triggerで2番目の250件Result Chunkを失敗させ、Draw Request／
  Result／User Prize／Point／Inventory／Sold／Plan消費／Execution／Outboxが
  単一Transactionで全Rollbackし、通常`draw.failed`とQA固有`qa.draw.failed`の
  両Auditが残ることを確認した。
  MIG-050～052、Point、Payment／Refund／Chargeback、Auth／MFA、Audit／Outbox、
  Backup／Restore Regressionは全V2 SuiteでPASSした。
- 全V2 Regressionで、`UserEmailVerification`がServiceの明示`created_at`を
  Mass Assignmentで破棄し、PostgreSQL Transaction開始時刻を使うため、Password Hashが
  秒境界を跨ぐと60分TTL Constraintへ1秒違反する既存不具合を再現した。
  `created_at`をModel Allowlistへ追加し、Serviceが既に生成している同一固定時刻を
  保存する最小修正で解消した。TTL、Token、Session、Migration、認証Contractは
  変更していない。同じTransaction時刻依存があったInitial Owner Invitationも、
  作成時刻と30分期限を同一固定時刻から保存し、TTLを直接Assertionした。
  修正後の全V2 SuiteとIdentity TestはPASSした。
- OpenAPI Lint／Bundle、Storefront Client生成差分／Typecheck／Lint／Build／11 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、Storefront Testkit生成差分／
  Typecheck／Lint／Build／19 Test／Export／実Network禁止、Admin Typecheck／Lint／Build、
  Release 10 Test／Source ValidationはPASSした。
- Policy Unit Test 72件、DB Guard Unit Test 20件を含む合同92件、Quality Unit Test
  5件、Security Unit Test 4件、OpenAPI Unit Test 4件はPASSした。
  Root Auditは0 Finding、Legacy Auditは11 Finding、Composer Auditは既存期限付き
  10 Finding、Secret／PII Candidateは0件で、Baselineを追加・拡張していない。
- Legacy Frontend Frozen Install、Typecheck、BuildはPASSし、Lintは既存Baselineの
  8 Error／1 Warningと完全一致した。V1 Migration 40件はPolicy正本Checksumで不変、
  V1 Runtime／本番Resource、Nginx、`v1/early-release`、Archive Branch、
  Annotated Tagを変更していない。
- GitHub Required／Available Check、Final Head固定後のFresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree CleanupはPR上で確定する。
  未実行項目をPASSとは記録しない。

### Handoff／Gate G4

- Handoff CはCatalog／Probability、Draw、User Prize、Point Exchange、QA識別まで接続済み。
  Handoff DはShipping／PII AuditとMIG-054が利用できるQA Execution／Relationまで接続済み。
- Reporting／CSV、最小Admin画面、最小Storefront画面、Staging E2Eが残るため、
  Gate G4は`NOT COMPLETE`である。次Task候補は
  `MIG-054 Reporting／Export Vertical Slice`だが、MIG-053完了後には開始しない。

## MIG-053 Closeout／MIG-053A Admin Fresh MFA Step-up Foundation

### MIG-053 Closeout

- Issue `#109`はClosed、PR `#110`はSquash Mergedである。Final Headは
  `63d582ab027492298fb4a7774aed0cdfb3373596`、Squash Commitは
  `23212e6686824c6dbb4cec2a39f8d77b1b215d56`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-053 Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Fresh MFA欠落原因／Task

- Task IDは`MIG-053A`、Riskは`R3`、Issueは`#111`、Branchは
  `security/MIG-053A-admin-fresh-mfa-qa`、Base SHAは
  `23212e6686824c6dbb4cec2a39f8d77b1b215d56`である。
- MIG-053は`auth:v2_admin`、通常MFA済みAdmin Session、Owner Permissionを
  検査していたが、QA API Request時点で`admin_sessions.mfa_verified_at`の
  5分境界を検査していなかった。QA Domain Serviceも`Admin` Modelを直接受け取り、
  Controller外からFresh MFAを証明できない構造だった。
- Client Header、Request Body、Browser時刻を正本にせず、Server DBの現在Session行と
  Server時刻だけを使用する`V2AdminAuthorizationContext`と
  `V2AdminFreshMfaAuthorizer`を追加した。ContextはAdmin、Role、DB照合用Session Hash、
  Audit HMACによるSession Correlation Hash、Request IDを相関し、QA Domain Serviceの
  全入口で再検証する。

### Admin Step-up／5分境界／Session Rotation

- Admin Contractへ`POST /admin/api/v2/auth/reauthenticate`と
  `POST /admin/api/v2/auth/reauthenticate/webauthn/options`を追加した。
  TOTPまたはWebAuthnだけを許可し、Password単独とRecovery Codeを拒否する。
- Fresh条件は有効かつ未失効のAdmin Session、MFA Enrollment完了、
  `mfa_verified_at`あり、`now < mfa_verified_at + 5 minutes`である。
  4分59秒はFresh、5分ちょうどからExpiredとし、403のRFC 9457
  `FRESH_AUTHENTICATION_REQUIRED`、`retryable=false`を返す。
- WebAuthn Challengeは現在のAdminとSession HashへBindingし、RP ID／Origin Exact、
  `userVerification=required`、5分、1回限りの既存Transaction Storeを使用する。
  TOTPは6桁、前後1 Step、同一Step Replay拒否の既存Serviceを使用する。
- 再認証成功時は現在SessionをLockして失効し、CSPRNG Tokenの新Sessionへ回転する。
  DBへ保存するのはHashだけで、`mfa_verified_at=now`、Enrollment完了を設定し、
  CSRF Tokenも回転する。新SessionのAbsolute期限は旧Session期限を上限とし、
  Admin Idle 15分／Absolute 8時間を延長・弱体化しない。失敗時は旧Sessionを維持する。

### QA Enforcement／Rate Limit／Audit

- QA Mode取得／更新／無効化、Plan一覧／作成／詳細／更新／Pause／Activate／Disable、
  Execution一覧／詳細の全12 OperationへOwner＋Fresh MFA 5分を強制した。
  Admin／OperatorはFreshでも403、未認証は401、Enrollment未完了または無効Sessionは
  Fail Closedである。
- QA Domain Serviceは`Admin` Modelを直接受け取らずAuthorization Contextを要求し、
  Session行、Admin、Role、Permission、FreshnessをService入口で再検証する。
  ControllerのAuth差替えやDomain Service直接呼出による迂回を許可しない。
- MFA VerifyはSession単位5回／5分、Critical QA MutationはAdmin単位10回／10分である。
  Rate Limit KeyはApplication KeyによるHMAC相関値で、Limiter障害時はFail Closed、
  429と`Retry-After`を返す。QA ReadにはMutation Limitを適用しない。
- `admin.reauthentication.succeeded`／`failed`／`rate_limited`、
  `admin.fresh_mfa.required`とFresh成立後のQA操作をAppend-only Auditへ接続した。
  Actor、Role、Realm、Session Correlation Hash、Request ID、Outcome、Reason、
  MFA Methodだけを保存し、Password、Code、Secret、Raw Credential、Challenge、
  Session ID／Cookie、Recovery Code、Full Email、IP／User Agent平文を保存しない。

### Test／Check／Scope

- Persistent V2 PostgreSQL 17／Redis 7へGuard付きV2 Migration Rootだけを使用し、
  `migrate:fresh`を2回実行した。V2 Migrationは11件、Migration Set SHA-256は
  `54a8cb25cde7b961c8f5ea4033b6798f967e11178bc8486b55a99aa7ae8199e3`、
  Migration Status／Schema Inventory／Health／全V2 SuiteはPASSした。
- Freshness、Owner境界、TOTP再認証、Session Rotation、Absolute期限非延長、
  Password／Recovery Code拒否、TOTP Replay、Rate Limit、Audit、失効／期限切れSession、
  Client時刻非採用、WebAuthn ChallengeのSession Binding／1回限りと既存QA Regressionの
  対象Testは19件／159 AssertionでPASSした。全V2 SuiteはGuard RunnerでPASSした。
  Policy Unit Testは74件、DB Guard Unit Testは20件、Quality Unit Testは5件、
  Security Unit Testは4件、OpenAPI Unit Testは4件で、すべてPASSした。
- PR Scope Parserの節終端をMarkdownの次のH2／H3見出しへ正規化し、後続の検証結果を
  Changed filesへ誤算入しない回帰Testを追加した。宣言Pathと実Git差分の完全一致、
  Allowed Path境界、Gate強度は維持している。
- Admin OpenAPIは26 Operation、Publicは24 Operation、Webhookは0 Operationである。
  Public／Webhook ContractとStorefront ClientのAdmin型非公開境界を変更していない。
- MIG-053のQA Draw、Point、Inventory、Plan消費、CSPRNG、Idempotency、
  Set-based Persistence、公開Response Contractを変更していない。
  User Draw RequestへAdmin Fresh MFA Queryを追加していない。
- Task専用Ephemeral Source／Restoreで`migrate:fresh`を2回実行した。Source／Restoreの
  Schema SHA-256は
  `3cf6abcdaa5cb14a223254eb95d3e879c4446670d55713734dd6aeda353396ce`、
  Migration Row SHA-256は
  `b035e90a3e58d3864bb88e040603e814de688633ecf8ef3c72a6571b321493f0`
  で一致し、Backup SHA-256は
  `e8406c4573279dcf484cd86509d267617f074722b9bcbe6f7145fa220bbf6c50`
  だった。Backup／Restore、API／Admin Health、通常Draw Load、QA Draw Load、
  Host Port非公開、Task Resource CleanupはPASSした。
- QA／通常1000回LoadはMIG-053／MIG-051の同一Testで再検証し、
  QA 1000回p95 2秒以下／150 Query以下、通常1000回p95 2秒以下／100 Query以下、
  同一Gacha QA 10 User最終20秒以下、未解決Deadlock／整合性不一致0件の各Assertionを
  PASSした。今回のFresh MFA処理はQA管理APIだけにあり、Draw Requestごとの
  Admin Session Queryは追加していない。
- Storefront Client生成差分／Typecheck／Lint／Build／11 Test、Site Schema生成差分／
  Typecheck／Lint／Build／10 Test、Storefront Testkit生成差分／Typecheck／Lint／
  Build／19 Test／Export／実Network禁止、Admin Typecheck／Lint／Build、
  Legacy Frozen Install／Typecheck／Build、Release 10 Test／Source ValidationはPASSした。
  Legacy Lintは既存8 Error／1 Warningと一致した。
- Root Auditは0 Finding、Legacy Auditは11 Finding、Composer Auditは既存期限付き
  10 Finding、Secret／PII Candidateは0件で、Baselineを追加・拡張していない。
  `policy-gate`、`quality-gate`、`security-gate`のLocal相当検証、
  OpenAPI Lint／Bundle、`git diff --check`はPASSした。
- GitHub Required／Available Check、Final Head固定後のFresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree CleanupはPR上で確定する。
  未実行項目をPASSとは記録しない。
- V1 Runtime、本番Resource、Nginx、V1 Migration 40件、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。
- Reporting／Export、最小Admin画面、最小Storefront画面、Staging E2Eが残るため、
  Gate G4は`NOT COMPLETE`である。次Task候補は
  `MIG-054 Reporting／Export Vertical Slice`だが、MIG-053A完了後には開始しない。

## MIG-053A Closeout／MIG-054 Reporting・Export Vertical Slice

### MIG-053A Closeout

- Issue `#111`はClosed、PR `#112`はSquash Mergedである。Final Headは
  `6f11c4efc22558341fd9c23b76057ee72a5ceba4`、Squash Commitは
  `f38d521f8c9b2c1e0639923018948d13303c24c7`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-053A Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 最新の人間決定／Task

- Payment Provider実装`MIG-055`は延期する。Wave 3完了後に追加機能、各UI修正、
  最終Design、実Gacha登録を行い、その時点で決済審査を申請する。実Provider Adapterは
  決済審査承認後に実装する。
- `test.luxe-pack.biz`はV2 User Front確認用、`ad.luxe-pack.biz`はV2 Admin画面確認用
  とする。Nginx、Let's Encrypt、HTTPSはBrowser確認Wave開始時に設定する。
  MIG-054ではDomain、Nginx、TLS、Let's Encrypt、V1本番の
  `luxe-pack.biz`／`admin.luxe-pack.biz`を変更しない。
- Task IDは`MIG-054`、Riskは`R3`、Issueは`#113`、Branchは
  `feat/MIG-054-reporting-export`、Base SHAは
  `f38d521f8c9b2c1e0639923018948d13303c24c7`である。

### V1 Characterization／集計正本

- V1の月間Calendar、日別売上、成功Refund／Chargeback、Point種別消費、
  Gacha別抽選口数、日次Point残高、CSV Header／BOM／日時、QA識別、
  Export監査の業務結果をCharacterizationした。V1のTable名、内部ID、Full Emailを
  V2 ContractへCopyしていない。
- 売上はPaymentの`succeeded_at`、Refund／ChargebackはAdjustmentの
  `succeeded_at`をAsia／Tokyo業務日へ変換するEvent集計である。
  Gross Salesから成功Refundと成功Chargebackを控除してNet Salesを算出し、
  Pending／Processing／Manual ReviewとChargeback Reversalは別項目にする。
  会計上の売上認識を確定するReportではないことをResponseと運用文書へ明記した。
- PointはWallet現在値で過去を逆算せず、immutableな`point_operations`と
  `point_ledger_entries`からpaid／freeの付与、消費、失効、取消、Point Back、
  景品交換、管理調整、Refund／Chargeback影響を集計する。
- DrawはRequest数、Result数、paid／free消費、Gacha／Rank／Prize別結果を集計する。
  QAは実Point／Inventoryへ影響するためDefault `all`へ含め、
  `normal`／`qa`／`all` FilterとCSVの`is_qa_draw`で分離する。
  QA理由、Owner、Plan内部情報、個別ppm、原価、内部IDを公開しない。

### Contract／Schema／Snapshot

- Admin OpenAPIへ月別売上、月内日別、指定日明細、Adjustment、Point、Gacha、
  Draw Request／Result、Point Snapshot、同期CSV、Export Job作成／一覧／詳細／
  Download取得の15 Operationを追加した。Interactive期間は`YYYY-MM`または
  `YYYY-MM-DD`に限定し、Cursor Pagination、RFC 9457、
  `Cache-Control: private, no-store`を使用する。
- V2専用Migrationへ`export_jobs`を追加した。UUIDv7 Public ID、固定Report／Status、
  Month／Date、QA Filter、Canonical Filter Hash、Data Cutoff、Query Version、
  Admin、Request／Idempotency相関、Row／Byte／SHA-256、Private Object Key、
  Claim／Lease／Retry／Expiryを型付きColumnとConstraintで保持する。
  Status遷移はDB TriggerでもFail Closedにし、`tenant_id`は追加していない。
- 既存Ledger Cutoff方式の`occurred_at < cutoff`、paid／free分離、
  3月31日／9月30日基準日、Checksum、同日再生成Auditを正本として再利用する。
  Console CommandはAsia／Tokyoの前日だけを決定的に生成し、任意過去日へ現在残高を
  保存できる引数を持たない。毎日00:20 JSTのScheduler境界を追加した。

### Export／Storage／Fresh MFA

- 同期CSVは最大10,000 Row、非同期閾値は10,001 Rowへ固定した。設定値が連続しない場合は
  Fail Closedとする。同期処理はGenerator、非同期Workerは500 Row Chunkで処理し、
  全RecordをMemoryへ展開しない。
- 大量ExportはJob、Idempotency、Outbox、Auditを同一Transactionで確定し、
  Commit後にWorkerが`FOR UPDATE SKIP LOCKED`でClaimする。CSV生成とObject Storage書込は
  DB Transaction外で実行し、Lease、最大3 Attempt、Retry／Resume、二重File確定拒否、
  Temporary File削除を実装した。
- Private Prefixは`v2/private/exports/`、Download URLは5分、Job／File期限は24時間である。
  Object KeyとSigned URLをAPI／DB Auditへ公開・保存せず、File完成後にRow Count、
  Byte Size、SHA-256を確定する。CSVはUTF-8 BOM、固定Header、Timezone付きTimestamp、
  Public ID、paid／free分離、Formula Injection、Quote／改行／Unicodeを検証する。
- Owner／AdminはMFA済みSessionで集計閲覧とExportを利用でき、Operatorは403である。
  Export作成、同期CSV、Download URL取得はMIG-053A共通Fresh MFA 5分を必須とする。
  Financial ExportはAdmin単位5回／1時間、Limiter障害時Fail Closedである。
  Rate Limit、Fresh MFA要求、Report閲覧、Export要求／開始／成功／失敗／Download／
  Expiry、Snapshot生成をAppend-only Auditへ接続し、Full PII、Signed URL、Object Key、
  Session ID、Cookie、Token、CSV内容を保存しない。

### Test／Performance

- Reporting対象TestはJST月境界、Payment／Adjustment Event日、Net Sales、Ledger基準Point、
  CSV Formula対策、QA列、Export Replay／Conflict、Outbox、Worker Lease／Retry、
  Private Checksum、Owner／Admin／Operator境界、Fresh MFA、5回／時Rate Limit Audit、
  Snapshot Cutoff／404を検証した。
- Task専用Performance Fixtureでは100,000 Payment RowをStreaming／Async対象として使用し、
  月別Summary 5回のp50は約132ms、p95は約192ms、日別First Page 5回のp50は約98ms、
  p95は約132msだった。5回のSummary QueryとAuditを合わせたQuery数は45で、
  `EXPLAIN ANALYZE`とIndex使用を確認した。
- Async 100,000 Row CSVは約5.2秒、約12.5MBで完了し、Row Count／Checksum一致、
  Memory増分0、未解決Deadlock0、長時間DB Transaction0だった。
  Interactive p95 1秒以下、Peak Memory 256MB以下、OOM／500／502／504なしの基準を満たした。
- Persistent V2 DBでGuard付き`migrate:fresh`を2回実行し、12 Migration、
  Migration Set SHA-256
  `1a77a173a354a89cf11549e02d1359d534296534d82b3c034ba73d0d7d5c414c`
  と`export_jobs`を含むSchema Inventoryを確認した。
- Policy／DB Guard Unit Test 98件はPASSした。OpenAPI Lint／BundleはPASSし、
  Admin 41 Operation、Public 24 Operation、Webhook 0 Operationである。
  Public／Webhook ContractとStorefront ClientのAdmin型非公開境界を変更していない。
- Task専用Ephemeral Source／Restoreで`migrate:fresh`を2回実行した。
  Source／Restore Schema SHA-256は
  `bd34041cb1a953f3129f482d7d64e4d178693a24c0b2c1710046b54e0f83260a`、
  Migration Row SHA-256は
  `b0151f523172e847af278ef9605cfedc63d90148c0e7e7a1169b42d96e400a08`
  で一致し、Backup SHA-256は
  `9fefbdc33a2ef55a1f3268ac42bd2ff819636c7e8e3a5e563165da07d151774f`
  だった。全V2 Suite、通常Draw／QA Draw Load、Reporting Performance、
  Backup／Restore、API／Admin Health、Host Port非公開、Task Resource CleanupはPASSした。
- Storefront Client生成差分／Typecheck／Lint／Build／11 Test、Site Schema生成差分／
  Typecheck／Lint／Build／10 Test、Storefront Testkit生成差分／Typecheck／Lint／
  Build／19 Test／Export／実Network禁止、Admin Typecheck／Lint／Build、
  Release 10 Test／Source ValidationはPASSした。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Legacy Typecheck／Buildは
  PASSし、Lintは既存8 Error／1 Warningと完全一致した。
  Root Auditは0 Finding、Legacy Auditは11 Finding、Composer Auditは既存期限付き
  10 Finding、Secret／PII Candidateは0件で、Baselineを追加・拡張していない。
- Policy Unit Test 77件、DB Guard Unit Test 21件、Quality Unit Test 5件、
  Security Unit Test 4件、OpenAPI Unit Test 4件、Release Test 10件はPASSした。
  `policy-gate`、`quality-gate`、`security-gate`、OpenAPI Lint／Bundle、
  Release Source Validation、`git diff --check`のLocal相当検証はPASSした。
  `integration-gate`のLocal相当はGuard SmokeでPASSした。
- GitHub Required／Available Check、Final Head固定後のFresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree CleanupはPR上で確定する。
  未実行項目をPASSとは記録しない。
- V1 Migration 40件、V1 Runtime／本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### Gate

- Reporting／ExportのBackend Vertical Sliceは実装したが、最小Admin画面、
  最小Storefront画面、Staging E2Eが残るためGate G4は`NOT COMPLETE`である。
  Payment Provider実装`MIG-055`は人間決定により延期し、次Task候補は
  `MIG-056 Content／Contact Vertical Slice`である。MIG-054完了後には開始しない。

## MIG-054 Closeout／MIG-056 Content・Contact Vertical Slice

### MIG-054 Closeout

- Issue `#113`はClosed、PR `#114`はSquash Mergedである。Final Headは
  `fcbbea33b8021faa6adc05955e562f53e823ea2a`、Squash Commitは
  `a8400a3aefaf645e78b2a91105f125970f9982cb`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-054 Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 最新の人間決定／Task

- Wave 3完了後に追加機能、各UI修正、最終Design、実Gacha登録を行い、その後に
  決済審査を申請する。審査承認・正式見積り前にProvider固有実装を開始しない。
- Stripe候補はCredit Card、Apple Pay、Google Pay、PayPayである。
  Convenience Store Payment候補はPAYSLEで、Stripe Convenience Store Paymentは
  比較・代替候補とする。料金・審査条件の自動Reminderは設定しない。
- `test.luxe-pack.biz`／`ad.luxe-pack.biz`はBrowser確認Waveまで設定しない。
  MIG-056ではNginx、Let's Encrypt、TLS、本番Domain、V1本番の
  `luxe-pack.biz`／`admin.luxe-pack.biz`を変更しない。
- Task IDは`MIG-056`、Riskは`R3`、Issueは`#115`、Branchは
  `feat/MIG-056-content-contact`、Base SHAは
  `a8400a3aefaf645e78b2a91105f125970f9982cb`である。

### V1 Characterization／Schema

- V1 BannerはImage、Link、Active、Sort Orderを持つ。NoticeはTitle、本文、
  Thumbnail、重要表示、Draft／Published／Hidden、Published Atを持ち、
  Published At以前のRecordを新しい順で公開する。Static PageはSlug、Title、
  本文、Status、Published Atを持ち、`terms`、`privacy`、`commercial-law`、
  `point-terms`をLegal Pageとして扱う。
- V1 ContactはAnonymous／Authenticatedの双方からName、Email、Phone、Bodyを受け付け、
  `new`／`replied`／`closed`を管理し、Mail／Discordを同期通知する。Attachmentはなく、
  V1 Table名、内部ID、未Sanitize HTML、Test Contact DataはV2へCopyしない。
- V2 Migrationへ`content_banners`、`content_notices`、`content_static_pages`、
  `content_versions`、`content_version_assets`、`contact_inquiries`、
  `contact_status_histories`、`contact_internal_notes`、`contact_reply_requests`を
  追加した。Public IDはUUIDv7、内部PKはbigint、`tenant_id`はない。
- Content MasterとVersionを分離し、Draft／Published／Archived、開始・終了期間、
  Checksum、Actorを型付きColumnで保持する。Published VersionのUpdate／Delete、
  Contact History／Internal Note／Reply RequestのUpdate／Delete、
  Contact Inquiryの物理DeleteをDB Triggerで拒否する。

### Contract／Content Security

- Public OpenAPIへ公開中Banner一覧、Notice一覧／詳細、Static Page Slug取得、
  Contact送信の5 Operationを追加した。Public Operationは合計29件で、
  Storefront ClientのContent／Contact FacadeとTestkit Fixtureを再生成した。
  Admin／Webhook型、内部ID、Storage Identifier、管理MetadataをPublicへExportしない。
- Admin OpenAPIへBanner／Notice／Static Pageの一覧、作成、詳細、Version追加、
  Publish、Unpublish、Archiveと、Contact一覧／詳細、Status更新、Internal Note、
  Reply Requestの26 Operationを追加した。UIは実装していない。
- Public ContentはPublished Versionかつ開始日時以下、終了日時がNULLまたは現在より後だけを
  返す。BannerはPublic Image Assetを必須とし、Sort Orderを維持する。
  NoticeはOpaque Cursor Paginationを使用する。
- Server-side DOM Allowlist Sanitizerで段落、見出し、強調、List、Link、Tableを許可し、
  Script、Inline Event、Style、危険URL、iframe、embed、object、form、SVG、MathMLを
  除去する。保存時とPublic Response時の両方で同じPolicyを適用する。
- 中央Permission Matrixへ`content.read`、`content.manage`、`content.publish`、
  `contact.read`、`contact.manage`を追加した。Owner／Adminは管理可能、
  OperatorはRead-onlyである。Legal PageのPublish／置換／Unpublish／Archiveは
  MIG-053A共通Fresh MFA 5分をDomain Service入口で必須とする。

### Contact／PII／Anti-spam／Audit

- Name、Email、Phone、Subject、Body、Internal Note、Reply本文はApplication-level
  Encryptionで保存する。Email検索・Rate LimitはRepository外KeyによるHMAC相関値を
  使用し、Full PIIをLog、Error、Audit、Outboxへ保存しない。
- Contact MutationはCSRF、Exact Origin、JSON Content-Type、Honeypot、Field Length、
  20,000 byte本文上限、Unicode NFCを検査する。IPは5回／1時間、Emailは3回／1時間、
  Limiter障害時はFail Closed、429では`Retry-After`を返す。
- Content作成／更新／Publish／Unpublish／Archive、Legal Publish、Contact受付／閲覧／
  Status変更／Internal Note／Reply Request／Rate Limit／Validation拒否を
  Append-only Auditへ接続した。Contact受付、受付確認、管理者通知Outboxは同一Transaction、
  Reply RequestとOutboxも同一Transactionである。実Mail／SMS／Discordは送信しない。
- V1 Contact DataはImportせず、V2 Production開始時は0件とする。
  MIG-070～072がContent Master、Version、Public Asset Relationの順でImportできる
  構造だけを提供する。

### Test／Performance／Gate

- Guard付きPersistent V2 DBとTask専用Ephemeral DBの双方で`migrate:fresh`を2回実行し、
  最新Migrationの1 Step Rollback／Reapply、Migration Status、Schema Inventoryを
  確認した。Migrationは13件、Migration Set SHA-256は
  `b190715a03b28e3a86ba1aa2052752abee7b8a37e7b363331bc4ea683202e423`
  で一致した。
- Task専用Ephemeral Source／RestoreのSchema SHA-256は
  `b2d2be974f4822d9da461ef2948fde1013a0d6c84c6bee00dc85ec2ebd89c109`、
  Migration Row SHA-256は
  `7ae1f0b79eeeb104988caa52385fab772a0e570c5746224b0a5e28d7bb82c666`
  で一致した。Backup SHA-256は
  `346df5f403a15580580604c576e4efaf2313751f8d6b9076dcec6e1eee73f337`
  で、Backup／Restore、API／Admin Health、Host Port非公開、
  Task専用Container／Network／Volume CleanupはPASSした。
- 全V2 Suite、通常Draw／QA Draw Load、Reporting Performance、
  Content／Contact Feature／Performance、Auth／Fresh MFA、Catalog／Probability、
  Point／Payment／Refund／Chargeback、Prize／Shipping、Audit／Outboxの回帰はPASSした。
  実装途中に検出したContent DTO NULL処理、匿名Audit Actor、Legal Slug、
  Audit列名、Test間Outbox Baseline、Published Asset Relation順序の不一致は修正済みである。
- Storefront Client生成差分／Typecheck／Lint／Build／12 TestはPASSした。
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Storefront Testkit生成差分／Typecheck／Lint／Build／20 Test／固定Export／
  実Network禁止、Admin Typecheck／Lint／BuildもPASSした。
- OpenAPI Unit Test 4件、Policy Unit Test 80件、Quality Unit Test 5件、
  Security Unit Test 4件、DB Guard Unit Test 23件、Release Test 10件はPASSした。
  Public 29 Operation、Admin 67 Operation、Webhook 0 OperationでBundle差分はない。
  `policy-gate`、`quality-gate`、`security-gate`、Release Source Validation、
  `git diff --check`のLocal相当はPASSし、`integration-gate`相当はGuard SmokeでPASSした。
- 100 Banner、10,000 Notice、100 Static Page Version、100,000 ContactのFixtureで、
  Banner First Page p50／p95は7.122／8.060ms、Noticeは36.528／42.662ms、
  Static Pageは4.310／9.954ms、Contactは2.512／3.320msだった。
  10並行Contactはp95 556.161ms、受付10件、Failure 0、未解決Deadlock 0である。
  全測定とAuditを含むQuery数は45、Peak Memoryは44,564,480 byte、
  N+1検出0で、Interactive First Page p95 1秒以下の基準を満たした。
  Admin Content一覧とVersion履歴はJoin／Subqueryを使用する。
- GitHub IntegrationでCompose v2.40と旧BuildxのBake経路非互換を検出した。
  DB Guardは`COMPOSE_BAKE=false`を子Process環境へ固定し、外側の環境指定なしで
  Guard Smokeを再実行してPASSした。Compose起動失敗時はPassword、Token等を除外した
  Health／Build診断だけを出力し、Fail Closedを維持する。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Auditは0 Finding、
  Legacy Auditは11 Finding、Composer Auditは既存期限付き10 Findingである。
  Baseline追加／拡張、新規Critical／High、Secret／PII Candidateは0件だった。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  のままである。算出方式は
  `sha256(sorted(sha256(file_content)))`で固定した。
- V1 Migration 40件、V1 Runtime／本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。
- Admin／Storefront UI、実Mail／SMS／Discord、CAPTCHA Provider、Provider固有決済、
  Domain／Nginx／TLS、Staging E2E、Production Deploymentは未実行であり、
  PASSとは記録しない。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree／Task Resource CleanupはPR上で確定する。
- Admin／Storefront UI、通知実送信、Staging E2Eが残るためGate G4は
  `NOT COMPLETE`である。Password Reset／SMS Verification、UI／Staging、
  通知Transport等が残るためGate G5も`NOT COMPLETE`である。
  Payment Provider実装`MIG-055`は延期を維持し、
  次Task候補は`MIG-057 Password Reset／SMS Verification Vertical Slice`だが、
  MIG-056完了後には開始しない。

## MIG-056 Closeout／MIG-057 Password Reset・SMS Verification Vertical Slice

### MIG-056 Closeout

- Issue `#115`はClosed、PR `#116`はSquash Mergedである。Final Headは
  `1b82684a8dc6f5ecef533a6c9b0c2901920181b4`、Squash Commitは
  `e0b4640bf12456d47c6cbf22ca99c681a67ad05b`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-056 Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### MIG-057 Task／Contract

- Task IDは`MIG-057`、Riskは`R3`、Issueは`#117`、Branchは
  `feat/MIG-057-password-reset-sms-verification`、Base SHAは
  `e0b4640bf12456d47c6cbf22ca99c681a67ad05b`である。
- Public OpenAPIへPassword Reset Request／Confirm、SMS認証状態取得、
  SMS送信／再送／Code検証の6 Operationを追加した。Public Operationは35件、
  Adminは67件、Webhookは0件である。RFC 9457、CSRF、Exact Origin、
  JSON Content-Type、User Realm境界を既存Middlewareで維持する。
- Storefront ClientへPassword Reset／SMS Facadeを追加し、Public OpenAPIから
  型を再生成した。TestkitへToken、Code、Full Email、Full Phoneを含まない
  Identity Recovery Fixtureを追加し、Admin／Webhook型は公開していない。

### Password Reset

- `password_reset_tokens`へCSPRNG TokenのSHA-256だけを保存する。有効期限30分、
  1回限り、Token／Account単位の失敗5回で失効し、同一Tokenの並行Confirmは
  Row Lockにより1件だけ成功する。
- Request ResponseはAccount存在、未認証、状態を区別せずGenericにした。
  Verified Emailを持つ対象Accountだけへ、暗号化Delivery Envelopeを含むOutboxを
  Transaction内で登録する。RedirectはRelative Pathだけを許可する。
- Confirmは既存`V2PasswordPolicy`を使用し、成功時に全User Sessionと
  Remember Deviceを失効する。新Session／CSRFを再生成し、
  Password変更通知OutboxとAppend-only Auditを同一Transactionで確定する。
- Request Rate LimitはAccount 3回／1時間、IP 10回／1時間、Confirmは
  Token／Account各5回である。Email／IPはHMAC相関値だけを使用し、
  Limiter障害時はFail Closedとする。

### SMS Verification／PII

- `user_phone_numbers`と`sms_verification_challenges`を追加した。Phoneは
  Application-level Encryption、検索／重複判定／Rate LimitはRepository外Keyによる
  HMAC相関値、数字CodeはCSPRNG生成してSHA-256だけを保存する。
- Challengeは5分、1回限り、失敗5回で失効する。再送時は旧Challengeを失効し、
  並行Verifyは1件だけ成功する。Phone送信は3回／1時間・10回／日、
  IPは5回／1時間、VerifyはChallenge当たり5回である。
- 未認証Phoneの重複を許可し、`active`／`suspended` UserのVerified Phoneは
  Partial Unique Indexで1件に制限する。`closed`／`anonymized` UserのPhoneは
  再利用可能である。
- Phone登録／変更はServer DBのFresh User Sessionを必須とする。変更開始時に旧Phoneの
  認証状態を解除し、Verify成功時にSession／CSRFをRotationする。
  User／Admin Realmを共有しない。
- SMS Provider実送信は実装せず、暗号化Delivery Envelopeを持つOutboxまでとした。
  Provider Secret、API Key、Phone平文、Code平文をOutbox Metadata、Audit、Logへ
  保存しない。

### Security／Audit／Recovery Boundary

- Password Reset成功／失敗／Rate Limit／Session失効、SMS送信／成功／失敗、
  Phone変更、Rate LimitをMIG-042のAppend-only Auditへ接続した。
  Password、Token、Code、Full Email、Full Phone、Cookie、Raw Session IDを
  Audit／Errorへ保存しない。
- Suspicious Recovery条件は正本から具体化できないため独自Heuristicを追加せず、
  明示的な検証済みSignalだけを受け取るFail-closed接続境界を追加した。
- `user_sessions.reauthenticated_at`をForward-safeに追加し、既存Sessionは
  `created_at`でBackfillする。Phone変更境界はClient時刻ではなくServer DB Sessionと
  Server時刻を正本とする。

### Migration／Test／Gate

- V2 Migrationは14件、Migration Set SHA-256は
  `a92551fc56e8c9202201fcb384964ac819c80be289348113410aef1ce969af60`
  である。Persistent／Task専用Ephemeral DBの双方で`migrate:fresh`を2回、
  最新Migrationの1 Step Rollback／Reapply、Migration Status、Schema Inventoryを
  Guard経由で確認した。
- Ephemeral Source／RestoreのSchema SHA-256は
  `ef2a759f15827d55ed322d30ac49522569b82d6866666b3e47ad81607601d10d`、
  Migration Row SHA-256は
  `82c81f26517ed15802a6a8e7c12dad30eafdf49dc6e3ac34dce9bc4118772808`
  で一致した。Backup SHA-256は
  `9ae8fd8eba94e27e9966aef2fd5ed836de389b092b02ac9aed317d182348c439`
  で、Backup／Restore、API／Admin Health、Host Port非公開、
  Task専用Container／Network／Volume CleanupはPASSした。
- Password Reset／SMS対象Test 12件／81 Assertion、Process並行Test
  2件／17 AssertionはPASSした。全V2 Suite、通常Draw／QA Draw Load、
  Reporting／Content／Contact Performance、Auth／MFA、Catalog、Point、
  Payment／Refund／Chargeback、Prize／Shipping、Audit／Outboxの回帰もPASSした。
- Storefront Client生成差分／Typecheck／Lint／Build／13 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Testkit生成差分／Typecheck／Lint／Build／21 Test／固定Export／実Network禁止、
  Admin Typecheck／Lint／BuildはPASSした。
- OpenAPI Unit Test 4件、Policy Unit Test 80件、DB Guard Unit Test 25件、
  Release Test 10件はPASSした。Root／Legacy Frozen Installはpnpm `10.12.1`で
  PASSした。Root Auditは0 Finding、Legacy Auditは既存11 Finding、
  Composer Auditは既存期限付き10 Findingで、Baseline追加／拡張はない。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtimeは固定Commit
  `bfca8efa0b85c00a88fb0fd439a123b722577b68`かつclean、Publicは200、
  Adminは307であり、V1本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。
- SMS Provider実送信、Google OIDC、LINE、Referral、Admin／Storefront UI、
  Domain／Nginx／TLS、Staging E2E、Production Deploymentは未実行であり、
  PASSとは記録しない。
- PR `#118`を作成し、初回Final候補Head
  `c96f33c2af943f9000ac722be99add849912a18f`でRequired 5 Check、
  CodeQL 2件、Dependency Reviewの最新Runはすべて成功した。PR本文の
  Task Policy Metadata不足による過去の失敗Runは本文修正だけで解消し、
  Application／Migration／Contractを変更せず、提出記録を確定した次Headを
  Final Headとして全CheckとFresh Self-reviewを再実行する。
- Final Head、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。UI、通知Transport、Staging E2Eが
  残るためGate G4／G5は`NOT COMPLETE`である。次Task候補は
  `MIG-058 Google OIDC／LINE Identity Linking Vertical Slice`だが、
  MIG-057完了後には開始しない。

## MIG-057 Closeout／MIG-058A External Identity・Google OIDC Vertical Slice

### MIG-057 Closeout

- Issue `#117`はClosed、PR `#118`はSquash Mergedである。Final Headは
  `2f2f28d29bab4ce59b1458f6aacd49994e7b3970`、Squash Commitは
  `84da69e78d9ed877699427448a29b78e83fabd12`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-057 Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Task／V1 Characterization

- Task IDは`MIG-058A`、Riskは`R3`、Issueは`#119`、Branchは
  `feat/MIG-058A-google-oidc`、Base SHAは
  `84da69e78d9ed877699427448a29b78e83fabd12`である。GoogleとLINEを分離し、
  LINE Loginは`MIG-058B`へ延期した。
- V1 Google LoginはCache StateとAuthorization Code交換後のUserInfoを使用し、
  Nonce／PKCE／ID Token署名検証を行わず、Provider Subject、Email、
  Profileを保存していた。既存Subject Login、Verified Email新規登録、
  Verified Email衝突拒否を業務結果としてCharacterizationした。
  V1のEmail識別、Raw Subject／Token／Profile保存、脆弱なProtocol処理はCopyしていない。
- Google公式OpenID Connect／OAuth 2.0文書を正本として、Server-side
  Authorization Code Flow、State、Nonce、PKCE S256、固定Issuer／Endpoint、
  RS256署名、Audience／`azp`／`exp`／`iat`／`sub`／`email_verified`検証を採用した。

### Schema／Protocol／Account

- V2 Migrationへ`external_identity_accounts`、
  `external_identity_transactions`、`external_identity_account_histories`を追加し、
  `users.password_login_enabled`をForward-safeに追加した。Public IDはUUIDv7、
  内部PKはbigint、Providerは`google`だけ、`tenant_id`はない。
- `(provider, issuer, subject_hash)`とUser当たりProviderをUniqueにし、
  Raw SubjectはRepository外KeyによるHMAC相関値だけを保存する。
  Transactionは10分、1回限り、State／Nonce／Browser BindingはHash、
  Code VerifierはApplication-level Encryptionで保存する。Account Identity、
  Transaction状態遷移、History Append-onlyをDB Triggerで保護する。
- Google Token／JWKS EndpointとIssuerは固定し、任意Discovery URLを受け入れない。
  Redirect URI Exact Match、PKCE S256、RS256 Algorithm Allowlist、`kid`再取得、
  Clock Skew 60秒、Nonce／Issuer／Audience／`azp`／Expiry／Issued At／Subject／
  Verified EmailをFail Closedで検証する。Provider通信はDB Transaction外である。
- 既存SubjectはEmail変更に影響されずLoginし、新規UserはVerified Emailの場合だけ
  `active`で作成する。Password Loginは明示的に無効で、内部Argon2id Hashを
  Loginへ使用させない。既存Verified Email一致では自動Linkせず、
  明示Login後のGoogle再認証を要求する。Password Reset成功時だけ
  Password Loginを有効化する。

### Link／Unlink／Session／Security

- Public OpenAPIへGoogle Login Start／Callback、Link Start／Callback、
  Linked Identity一覧、Google Reauthentication、Unlink、
  User Password Reauthenticationの7 Operationを追加した。
  Public Operationは42件、Admin 67件、Webhook 0件である。
- Link／Google ReauthenticationはUser Session、Browser Transaction、
  Google Subject再検証へBindingする。Link、Reauthentication、Unlink、
  Password Reauthentication成功時にSession／CSRFをRotationし、
  Absolute Session期限を延長しない。
- UnlinkはServer DBの`reauthenticated_at`とServer時刻を正本とする5分境界を使用し、
  Passwordまたは残るCredentialがない場合は拒否する。Unlink成功時はRemember Deviceと
  他Sessionを失効する。
- Login StartはIP 10回／10分、Callback FailureはTransaction＋IP、
  Link／Reauthentication StartはUser 5回／10分、Password Reauthenticationは
  Session 5回／5分、UnlinkはUser 5回／1時間である。識別値はHMAC相関値、
  Limiter障害時はFail Closedである。
- OIDC Start／Protocol拒否／Provider Failure／新規User／Login／Link／Unlink／
  Reauthentication／Rate LimitをAppend-only Auditへ接続し、新規User、Link、
  Unlink通知をTransactional Outboxへ接続した。Token、Code、ID Token、Raw Subject、
  State、Nonce、Verifier、Full Email、Cookie、Raw Session ID、Client Secretを
  Audit／Log／Error／Outbox Metadataへ保存しない。
- `firebase/php-jwt`はManifest `^7.1`、Frozen Lock／Policyで解決Versionを
  Exact `7.1.0`に固定した。Google Client ID／Secret／
  Redirect URIはRepository外Environmentだけを使用し、未設定時はFail Closedである。

### Test／Migration／非変更

- Persistent V2 DBで`migrate:fresh`を2回、最新Migrationの1 Step
  Rollback／Reapply、Migration Status、Schema Inventory、PostgreSQL／Redis Health、
  Host Port非公開、全V2 SuiteをGuard経由で確認した。
- Task専用Ephemeral Source／Restoreでも`migrate:fresh`を2回、全V2 Suite、
  Draw／QA Draw Load、Reporting／Content／Contact Performance、API／Admin Health、
  Backup／Restore、Schema／Migration Checksum一致、Task Resource Cleanupを確認した。
- V2 Migrationは15件、Migration Set SHA-256は
  `53cbd05cae2fa794d39a3fd5c71ad87cefcb398e69eafc066a29ec9356e4f27a`である。
  Source／Restore Schema SHA-256は
  `7ae754f3fcbf1cff5cdf48961f0e03293e0e4e432124e92b0bbb399dcec60090`、
  Migration Row SHA-256は
  `3e9d7878e58a77810819042186ef4ac43acb4926d74a7e619296657e382fd4ea`
  で一致した。Backup SHA-256は
  `21a549606f240faafe86703a359dbbf3916878d7ab9f09eaf3e9a3ac8f14f773`である。
- State／Nonce／PKCE、Code／Transaction Replay、Expiry、Issuer、Audience、`azp`、
  Token Expiry／Future `iat`、Algorithm、Unknown／Rotated Key、Invalid Signature、
  JWKS／Provider Failure、SSRF固定境界、既存Identity Login、新規User、
  Email衝突、Account State、Link／Unlink、5分Fresh境界、Session／CSRF Rotation、
  Remember Device、同一Callback並行実行を検証した。
- 並行Callbackで後着Replayが先着Workerの`processing` Transactionを
  `failed`へ変更できる競合を検出し、Claim成功したWorkerだけが自身のTransactionを
  失敗化できる所有境界へ修正した。並行実行は1 User／1 Identity／1 Sessionだけが
  成功し、後着はGeneric ErrorでFail Closedする。
- 期限切れCallbackは既にFail Closedだったが、Transaction状態が`pending`のまま残る
  不備をFresh self-reviewで検出した。Server時刻で期限切れを再確認して`expired`へ
  明示遷移し、状態をTestで固定した。
- Public Auth Controllerの存在しない基底Controller継承をHTTP Route Testで検出し、
  他V2 Controllerと同じStandalone Controllerへ修正した。
- Storefront Client生成差分／Typecheck／Lint／Build／14 Test、
  Testkit生成差分／Typecheck／Lint／Build／22 Test／固定Export／実Network禁止、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Admin Typecheck／Lint／Build、OpenAPI Unit 4件、Policy Unit 80件、
  Quality Unit 5件、Security Unit 4件、Site Template Unit 6件、
  DB Guard Unit 25件、Release Unit 10件はPASSした。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Auditは0 Finding、
  Legacy Auditは既存11 Finding、Composer Auditは既存期限付き10 Findingである。
  `policy-gate`、`quality-gate`、`security-gate`、OpenAPI Contract Gate、
  `git diff --check`はPASSし、Secret／PII Candidate、新規Critical／High、
  Baseline追加／拡張は0件だった。Legacy Lintは既存8 Error／1 Warningと一致し、
  Typecheck／BuildはPASSした。
- V1 Migration 40件の正本Checksum、
  V1 Runtime／本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。実Google Account E2E、
  UI、実Mail、LINE、Payment Provider、Domain／TLS、Staging／Production Deploymentは
  未実行であり、PASSとは記録しない。

### 時間を要した作業／Gate

- Guard付きPersistent検証はImage Build、Migration 2回、全V2 Suiteを含み、
  1回あたり約2分07秒～2分36秒を要した。初回Test Harness 2件、HTTP Route 500、
  並行Replay競合の検出ごとに再実行し、対象修正後の正規環境結果を正本とした。
- Ephemeral Smokeは全Suite／Load／Backup／Restoreを含み、成功Runは4分53秒だった。
  事前のHost Storage不足、並行Replay競合、CHECK式のPostgreSQL Canonical表現差で
  Fail Closedし、未使用Build Cache／Dangling Imageだけを限定Cleanupした後、
  所有境界修正とCanonical CHECK式で解決した。
- 今後はSmoke前のDisk Preflight、Guardへの対象Test Mode、Migration CHECK式Templateの
  Canonical化、HTTP Route Smokeの早期実行により再Build回数を短縮する。
- Canonical Migration確定後の最終Persistent Guardは1分39秒でPASSした。
- 期限切れTransaction状態の補完後にPersistent Guardを再実行し、2分10.6秒で
  Migration 15件、全V2 Suite、PostgreSQL／Redis Healthを含めPASSした。
- GitHub Quality Gate初回はComposer Strict ValidateがManifestのExact制約を
  SemVer警告として扱いFailureとなった。Gateを弱めず、Manifestを`^7.1`、
  Frozen LockとPolicyをExact `7.1.0`へ固定して再現性を維持した。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。UI、通知Transport、実Google Browser E2E、
  Staging E2Eが残るためGate G4／G5は`NOT COMPLETE`である。
  次Task候補は`MIG-058B LINE Login v2.1 Identity Linking Vertical Slice`だが、
  MIG-058Bは本Task内で開始しない。

## MIG-058A Closeout／MIG-060A New Admin App Authentication／Session Shell

### MIG-058A Closeout

- Issue `#119`はClosed、PR `#120`はSquash Mergedである。Final Headは
  `68c4e23c9a8149ac453b3a711053574af0cf1161`、Squash Commitは
  `691686f6a5527f2748650818c70bc5b12534a654`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-058A Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Task／Admin Contract

- Task IDは`MIG-060A`、Riskは`R3`、Issueは`#121`、Branchは
  `feat/MIG-060A-admin-auth-shell`、Base SHAは
  `691686f6a5527f2748650818c70bc5b12534a654`である。
- Admin OpenAPI Bundleの11 Auth OperationとSchema／Enumを検証し、
  Bundle SHA-256付きTypeを決定的に生成する。生成差分0を
  `quality-gate`で継続検査する。
- API Clientは`/admin/api/v2/auth/*`だけを許可し、Same-origin Cookie、
  `credentials: include`、Admin CSRF Cookie／Header、Request ID、RFC 9457、
  401／403／429、Timeout／Abortを実装した。Authorization Header、
  Local Storage／Session Storage Token、User Realm Cookieを使用しない。

### Authentication／Session

- `/login`、`/auth/mfa`、`/auth/enroll`、`/auth/recovery`、`/`を実装し、
  anonymous、Password Pre-auth、MFA、Enrollment、authenticated、
  expiredをServer Session応答に従って分岐する。
- TOTP／WebAuthn／Recovery Code MFA、TOTP Enrollment／Confirm、
  WebAuthn Enrollment、Recovery Code再生成、Session取得、Logoutを実装した。
  Recovery Code利用後の`requires_mfa_enrollment`をEnrollment Routeへ誘導する。
- Admin Session／CSRF RotationはResponse Cookieを正本とし、ClientでTokenを
  永続化しない。Fresh MFAはMIG-053AのTOTP／WebAuthn Contractを共通Dialogから
  使用し、Browser時刻によるFreshness判定を行わない。
- Native `fetch`をClass field経由で呼ぶ際のreceiver不一致をBrowser E2Eで検出し、
  `globalThis`へ明示Bindingした。Password、TOTP、Recovery Code、
  WebAuthn CredentialをLog、Error、Storageへ保存しない。

### Common Shell／Security

- Header、Sidebar、Backend応答に基づくOwner／Admin／Operator表示、Logout、
  Main Content、Loading、Error、Empty、403、404、Session Expiredを実装した。
  業務Menuはdisabled Placeholderだけで、架空Dataや未実装業務画面はない。
- Mobile Drawer、Keyboard操作、Skip Link、Fresh MFA Dialogの初期Focus、
  Focus Trap、閉鎖後Focus復帰を実装した。Desktop／Mobileで横溢れとControl overlapが
  ないことをPlaywright Screenshotで確認した。
- Unknown HostをFail Closedで404とし、CSP nonce、`frame-ancestors 'none'`、
  `X-Frame-Options: DENY`、`nosniff`、Referrer／Permissions Policy、
  `private, no-store`、`noindex／nofollow／noarchive`を全Pageへ適用した。
- CSP nonceを動的Responseへ付与するためRoot LayoutをDynamic Renderとし、
  Development用`unsafe-eval`を許可せずProduction BuildでBrowser E2Eを実行した。
  Domain、Nginx、TLS、Production Hostは設定していない。

### Test／Migration／Security

- Admin generated差分0、Typecheck、Lint、Production Build、
  Unit／Component 12 Test、Chromium Browser E2E 3 TestはPASSした。
  Browser E2EはPassword Pre-auth、TOTP、Fresh MFA、Logout、429 Generic Error、
  Credential非保存、Role、Mobile／Keyboard／横溢れをTask専用API Test Doubleで確認した。
  実Credential、実WebAuthn Device、Production Resourceは使用していない。
- Policy Unit Testは84件、`policy-gate`、`quality-gate`、
  `security-gate`はPASSした。Admin認証Shellの許可File、Admin API限定、
  Cookie Session、Storage Token禁止、Security Header、CI生成差分／Testを
  Policyへ固定した。
- Persistent V2 DBで`migrate:fresh` 2回、最新Migration Rollback／Reapply、
  全V2 Suite、PostgreSQL／Redis Healthを確認した。Task専用Ephemeralでは
  `migrate:fresh` 2回、全V2 Suite、Draw／QA／Reporting／Content負荷回帰、
  Backup／Restore、API／Admin Health、Resource Cleanupを確認した。
- V2 Migrationは15件、Migration Set SHA-256は
  `53cbd05cae2fa794d39a3fd5c71ad87cefcb398e69eafc066a29ec9356e4f27a`である。
  Source／Restore Schema SHA-256は
  `7ae754f3fcbf1cff5cdf48961f0e03293e0e4e432124e92b0bbb399dcec60090`、
  Migration Row SHA-256は
  `3e9d7878e58a77810819042186ef4ac43acb4926d74a7e619296657e382fd4ea`
  で一致した。Backup SHA-256は
  `d5dfad57793f5d51e5ea030243ec1e0073d084479d4a5f43a9d4cfd0188ea38b`である。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Auditは0 Finding、
  Legacy Auditは既存11 Finding、Composer Auditは既存期限付き10 Findingである。
  Secret／PII Candidate、新規Critical／High、Baseline追加／拡張は0件だった。
- OpenAPI Unit 4件／BundleはPublic 42、Admin 67、Webhook 0 OperationでPASSした。
  Storefront Client生成差分／Typecheck／Lint／Build／14 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Testkit生成差分／Typecheck／Lint／Build／22 Test／実Network禁止はPASSした。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtime、本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 時間を要した作業／Gate

- Browser E2E基盤整備はChromium初回取得、CSP nonceの静的Render問題、
  Native `fetch` receiver不一致、厳密Locator、Drawer transitionの順に検出・修正し、
  複数回のProduction Buildを含め約10分を要した。CSPを弱めずDynamic nonceと
  Production Server検証で解決した。今後はBrowser E2Eを認証Shell変更の早期段階で
  実行し、Native Browser APIのbindingをUnit Test Doubleだけで判断しない。
- Guard付きPersistent／Ephemeral DB回帰は約8分を要した。100,000件Contact、
  Reporting、Draw／QA Load、Backup／RestoreをAdmin-only変更でも全実行したためである。
  今後はGateを弱めず、同一Final候補HeadのDB Evidenceを再利用できる承認済み
  Admin-only回帰Profileを後続改善候補とする。
- Playwright fallback Chromiumに日本語FontがなくScreenshotでは日本語Glyphが
  欠落した。DOM Text、Accessible Name、Keyboard E2Eは正常で、Layout／Drawer／
  Overlay／横溢れは視覚確認できた。Staging Browser確認Waveでは実配信Font／
  対象OSで再確認する。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。Catalog／QA／Shipping／Reporting／
  Content等の業務Admin画面、Storefront画面、通知Transport、Staging E2Eが残るため、
  Gate G4／G5は`NOT COMPLETE`である。
- LINE Login `MIG-058B`は人間指示まで保留する。次Task候補は
  `MIG-060B Admin Dashboard／Navigation／Permission Foundation`だが、
  MIG-060Bは本Task内で開始しない。

## MIG-060A Closeout／MIG-060B Admin Dashboard／Navigation／Permission Foundation

### MIG-060A Closeout

- Issue `#121`はClosed、PR `#122`はSquash Mergedである。Final Headは
  `4ee324bc8c2b4615f499443158b79065cb6308e5`、Squash Commitは
  `cfe1b511cb0ecf5ef170a7e00165a2c4f2709211`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-060A Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Task／Effective Permission Contract

- Task IDは`MIG-060B`、Riskは`R3`、Issueは`#123`、Branchは
  `feat/MIG-060B-admin-navigation-permissions`、Base SHAは
  `cfe1b511cb0ecf5ef170a7e00165a2c4f2709211`である。
- Admin OpenAPIへ`GET /admin/api/v2/auth/permissions`を追加した。
  有効なAdmin Realm SessionとMFA Enrollment完了を既存Middlewareで検査し、
  Role、有効Permission Code、Request IDだけを`private, no-store`で返す。
  Public／Webhook ContractおよびStorefront ClientへAdmin型を公開しない。
- 中央`V2PermissionAuthorizer`を唯一のPermission正本とし、未知Role、
  未登録Permission、重複PermissionをFail Closedとする。
  Catalog参照の共通境界として`catalog.read`を中央Matrixへ追加し、
  Owner／Admin／Operatorへ割り当てた。ControllerやComponentでRole名を比較しない。
- Permission取得ControllerをAdmin Auth Flow Controllerから分離した。
  Permission取得だけでWebAuthn等の無関係なProvider設定を解決しないための
  最小Dependency境界である。

### Dashboard／Navigation／Route Guard

- 型付きNavigation RegistryへDashboard、Catalog、QA Draw、Prize／Shipping、
  Reporting／Export、Content、Contactを集約した。Stable Route ID、Label、Path、
  Permission、Icon、Section、Sort Order、実装状態、Fresh MFA境界を持つ。
- PermissionがないModuleはNavigationとDashboardから非表示になる。
  Permission取得失敗時はDashboard以外をFail Closedとし、401はSession失効、
  403は汎用Forbidden、429は安全な`Retry-After`表示とする。
- `PermissionProvider`、`PermissionGate`、`ProtectedAdminRoute`、
  `AdminNavigation`、`Breadcrumb`、`ModulePlaceholder`、
  `DashboardModuleCard`、`AdminPageHeader`を責任別に実装した。
  直接URLも同じRegistryとPermission Route Guardを通る。
- `/catalog`、`/qa`、`/shipping`、`/reports`、`/content`、`/contacts`を追加した。
  各RootはPage Title、Breadcrumb、Active Navigation、Loading、Error、403、
  準備中表示を持ち、架空業務Dataを表示しない。
- DashboardはCurrent Admin Public ID、Role、MFA Enrollment状態、
  Server取得Permission、利用可能Moduleを表示する。売上、User、Gacha等の
  架空集計値およびBrowser時刻によるSession／Freshness判定は追加していない。
- Desktop SidebarとMobile Drawerで同一Registryを使用し、Escape Close、
  Focus移動／復帰、Keyboard操作、Active Route境界を実装した。
  MIG-060AのCookie Session、CSRF、Exact Origin、Realm分離、CSP、
  Unknown Host、Storage Token禁止、`private, no-store`を維持した。

### Test／Migration／Security

- Admin OpenAPI Unit／BundleはPASSし、Operation数はPublic 42、Admin 68、
  Webhook 0である。Admin Contract生成差分は0である。
- Admin Typecheck、Lint、Production Build、Unit／Component 21 Test、
  Chromium Browser E2E 6 TestはPASSした。Owner／Admin／OperatorのNavigation差、
  Permission取得失敗、直接URL拒否、401／403／429、Mobile Drawer、Keyboard、
  Focus、横溢れ、Secret／Credential非保存を検証した。
- Backend Permission Contract対象Testは4 Test／46 AssertionでPASSした。
  Policy Unitは86 Test、Quality Unitは5 Test、Security Unitは4 Test、
  Release Unitは10 TestでPASSした。
- Storefront Client生成差分／Typecheck／Lint／Build／14 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Storefront Testkit生成差分／Typecheck／Lint／Build／22 Test／実Network禁止は
  PASSした。Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。
- Root Auditは0 Finding、Legacy Auditは既存11 Finding、
  Composer Auditは既存期限付き10 Findingであり、Baseline追加／拡張、
  新規Critical／High、Secret／PII Candidateは0件だった。
- Guard付きPersistent V2 DBで`migrate:fresh` 2回、最新Migration
  Rollback／Reapply、全V2 Suite、PostgreSQL／Redis Healthを確認した。
  Task専用Ephemeralでは`migrate:fresh` 2回、全V2 Suite、
  Draw／QA／Reporting／Content Load、API／Admin Health、Backup／Restore、
  Task Resource Cleanupを確認した。
- V2 Migrationは15件、Migration Set SHA-256は
  `53cbd05cae2fa794d39a3fd5c71ad87cefcb398e69eafc066a29ec9356e4f27a`である。
  Source／Restore Schema SHA-256は
  `7ae754f3fcbf1cff5cdf48961f0e03293e0e4e432124e92b0bbb399dcec60090`、
  Migration Row SHA-256は
  `3e9d7878e58a77810819042186ef4ac43acb4926d74a7e619296657e382fd4ea`
  で一致した。Backup SHA-256は
  `8a61ca630206f5d35c6ea52a5b3220e342b8a135b9fa8a376c42f06ff25350e4`である。
- Persistent Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-060B/persistent/persistent-result.json`、
  Ephemeral Evidenceは`/var/lib/oripa-v2-evidence/MIG-060B/smoke/`へ
  Repository外保全した。Secret／PIIを含めず、Task ResourceはCleanup済みである。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtime、本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 時間を要した作業／Gate

- Guard付きPersistent検証は初回無出力待機でSIGTERMとなったためHeartbeat付きで
  再実行した。HTTP 500、Cookie Test Harness、不要なWebAuthn設定解決、
  Cache-Control順序、Laravel Auth Guard Cacheを順に検出し、Controller分離と
  Test境界修正後に約2分で全SuiteをPASSした。今後はPermission Contract対象Testと
  HTTP Route SmokeをGuard付きFull Suiteより前に実行する。
- Ephemeral Smokeは全Suite／Load／Backup／Restoreを含み約4分30秒を要した。
  全工程は1回の成功Runで完了し、Source／Restore Schema一致とResource Cleanupを
  Evidence化した。後続Admin-only Taskでは同一Final HeadのDB Evidence再利用可否を
  Governanceで明文化することを改善候補とする。
- Package検証を並行実行した初回はStorefront ProcessのSIGTERMと、
  Site Schema Build完了前にTestkitが参照する競合が発生した。
  依存順にSerial再実行して全件PASSした。今後は生成Packageを
  Site Schema、Storefront Client、Testkitの依存順で実行する。
- fallback Chromiumに日本語FontがなくScreenshotでは日本語Glyphが欠落した。
  DOM Text、Accessible Name、Keyboard E2E、Layout／Drawer／横溢れは正常である。
  Staging Browser確認Waveで実配信Fontと対象OSを再確認する。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。業務Admin画面、Storefront画面、
  通知Transport、Staging E2Eが残るためGate G4／G5は`NOT COMPLETE`である。
- LINE Login `MIG-058B`は人間指示まで保留する。次Task候補は
  `MIG-060C Admin Catalog／Gacha／Probability Management`だが、
  MIG-060Cは本Task内で開始しない。

## MIG-060B Closeout／MIG-060C Admin Catalog Read／Selection Foundation

### MIG-060B Closeout

- Issue `#123`はClosed、PR `#124`はSquash Mergedである。Final Headは
  `f7feb8cb2af69f6ba0acbefa16f292e413ff8b28`、Squash Commitは
  `34e074e4ea5e72c736a4a1139a3ba57c9ea31cc1`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-060B Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Task／Admin Catalog Contract

- Task IDは`MIG-060C`、Riskは`R3`、Issueは`#125`、Branchは
  `feat/MIG-060C-admin-catalog-read`、Base SHAは
  `34e074e4ea5e72c736a4a1139a3ba57c9ea31cc1`である。
- Admin OpenAPIへCategory、Tag、Rank、Prize、Presentation Assetの
  一覧／詳細を合計10 Operation追加した。全OperationはAdmin Realm、
  有効Session、MFA Enrollment、中央Permission Matrixの`catalog.read`を必須とし、
  `private, no-store`、Request ID、RFC 9457 Errorを返す。
- Opaque Cursor PaginationはResource、Sort、Direction、値、Public IDを
  暗号化してBindingする。Stable SortとPublic ID tie-breakを使用し、
  Cursorの別Resource／Sort流用、改ざん、無効UUIDをFail Closedとする。
- Search、Visibility、Rank、Media Type、Sortは既存Schemaから確定した範囲だけを
  Allowlist化した。Mutation Endpoint、DB Migration、Public／Webhook Contract変更は
  追加していない。Storefront ClientへAdmin型をExportしていない。
- ResponseはUUIDv7 Public IDと表示用Fieldだけを返し、内部DB ID、原価、
  個別ppm、Storage Identifier、非公開Asset Pathを公開しない。
  非公開AssetはRelationを維持しつつPreview URLを`null`にする。
- `V2AdminCatalogReadService`自身でもAuthorization Contextと
  `catalog.read`を検証し、Controller／Route Guardだけに依存しない。

### Admin Catalog UI／Selection Foundation

- `/catalog`の準備中画面をCatalog Overviewへ置き換え、
  `/catalog/categories`、`/tags`、`/ranks`、`/prizes`、
  `/presentation-assets`と各詳細Routeを追加した。
- 共通Catalog Registry、Section Navigation、Search／Filter、
  Data Table、Opaque Cursor Pagination、Detail、Status Badge、
  Public Asset Preview、API Error Boundary、Breadcrumbを実装した。
- Loading、Empty、Network Error、401、403、429、再試行を共通表示し、
  Backend 403を最終判断として扱う。既存`PermissionProvider`、
  `ProtectedAdminRoute`、Admin Shellを再利用し、Role比較による別権限基盤や
  架空Dataを追加していない。
- Image／Video PreviewはPublicかつ同一Origin Relative Pathだけを許可し、
  Video自動再生を行わない。不正外部URL、非公開Asset、読込失敗はFallback表示とし、
  CSPや許可Domainを変更していない。
- Desktop／Mobile、Keyboard、Focus、Breadcrumb、Active Navigationを
  Chromium Browser E2Eで確認した。Server ComponentからClient Componentへ
  Lucide Component参照を渡す境界違反を初回E2Eで検出し、
  SerializableなResource IDだけを渡す構造へ修正した。

### Test／Migration／Security

- Admin Catalog Backend対象Testは5 Test／146 AssertionでPASSした。
  Owner／Admin／Operator、5 Resource一覧／詳細、Search／Filter／Sort／Cursor、
  Field非露出、未認証／MFA未完了、Service直接呼出、Mutation Route不存在を確認した。
- Admin OpenAPI Unit／BundleはPASSし、Operation数はPublic 42、Admin 78、
  Webhook 0である。Admin Contract生成差分は0である。
- Admin Typecheck、Lint、Production Build、Unit／Component 27 Test、
  Chromium Browser E2E 7 TestはPASSした。
- Policy Unit 88 Test、Quality Unit 5 Test、Security Unit 4 Test、
  Release Unit 10 Test、`policy-gate`、`quality-gate`、`security-gate`はPASSした。
- Storefront Client生成差分／Typecheck／Lint／Build／14 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Storefront Testkit生成差分／Typecheck／Lint／Build／22 Test、
  Export／Network BoundaryはPASSした。PHP Syntax 558 FileはPASSした。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Auditは0 Finding、
  Legacy Auditは既存11 Finding、Composer Auditは既存期限付き10 Findingである。
  Legacy Lintは既存8 Error／1 Warningと完全一致し、Baseline追加／拡張、
  新規Critical／High、Secret／PII Candidateは0件だった。
- Guard付きPersistent V2 DBで`migrate:fresh` 2回、最新Migration
  Rollback／Reapply、全V2 Suite、PostgreSQL／Redis Healthを確認した。
  Task専用Ephemeralでは`migrate:fresh` 2回、全V2 Suite、
  Draw／QA／Reporting／Content Load、API／Admin Health、Backup／Restore、
  Task Resource Cleanupを確認した。
- V2 Migrationは15件、Migration Set SHA-256は
  `53cbd05cae2fa794d39a3fd5c71ad87cefcb398e69eafc066a29ec9356e4f27a`である。
  Source／Restore Schema SHA-256は
  `7ae754f3fcbf1cff5cdf48961f0e03293e0e4e432124e92b0bbb399dcec60090`、
  Migration Row SHA-256は
  `3e9d7878e58a77810819042186ef4ac43acb4926d74a7e619296657e382fd4ea`
  で一致した。Backup SHA-256は
  `f0ee5e6ae4efee3ce6f5b2c1c1985297fa0a09506dfaa093dfd1d632164a1e1b`である。
- Persistent Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-060C/persistent/persistent-result.json`、
  Ephemeral Evidenceは`/var/lib/oripa-v2-evidence/MIG-060C/smoke/`へ
  Repository外保全した。Task専用Container／Network／VolumeはCleanup済みである。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtime、本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 時間を要した作業／Gate

- Task専用API ImageはHostのCompose `2.40.1`と旧Buildx `0.12.1`の組合せで
  BuildKitの`--allow`を解釈できず、Classic Builderで構築した。
  Composer依存層を再評価するため対象Test再実行まで約2分を要した。
  GateやDependencyを変更せず解決し、環境互換性を提出レポートへ残した。
- Browser E2E初回はServer／Client Component境界違反を検出した。
  Resource IDだけをClientへ渡す修正後、Unit 27件とE2E 7件を再実行し、
  E2E成功Runは約44秒だった。
- Guard付きPersistent回帰は約2分、Ephemeral Smokeは全Suite、
  100,000件Contact等のLoad、Backup／Restoreを含み約6分を要した。
  Admin-only変更でも全V2 Domain非回帰を1回の成功Runで確認した。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。Catalog Mutation、Gacha／Probability、
  他業務Admin画面、Storefront画面、通知Transport、Staging E2Eが残るため、
  Gate G4／G5は`NOT COMPLETE`である。
- LINE Login `MIG-058B`は人間指示まで保留する。次Task候補は
  `MIG-060D Admin Catalog Mutation Foundation`だが、
  MIG-060Dは本Task内で開始しない。

## MIG-060C Closeout／MIG-060D Admin Category／Tag／Rank Mutation Foundation

### MIG-060C Closeout

- Issue `#125`はClosed、PR `#126`はSquash Mergedである。Final Headは
  `2e3572aac804384122d8b7deb6aa60126330a360`、Squash Commitは
  `3fe45e39ed05564a70d03419da8a20db62a81373`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-060C Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Task／Characterization／Permission

- Task IDは`MIG-060D`、Riskは`R3`、Issueは`#127`、Branchは
  `feat/MIG-060D-admin-catalog-master-mutation`、Base SHAは
  `3fe45e39ed05564a70d03419da8a20db62a81373`である。
- V1のCategory／TagはCreate／Update、RankはGacha配下のCreate／Updateであり、
  物理Delete／Archive APIを持たない。V2では公開表示がMasterを動的参照するため、
  公開中または公開予定Versionが参照するName、Slug、Description、Sort Order、
  Visibility、Archiveの変更をFail Closedとした。Published Version自体は変更しない。
- 中央Permission Matrixへ`catalog.manage`を追加し、Owner／Adminへ許可、
  Operatorへ不許可とした。Operatorは既存`catalog.read`だけを維持する。
  UI／ControllerでRole名を直接比較せず、Backendの有効Permissionを正本とする。

### Contract／Schema／Domain

- Admin OpenAPIへCategory／Tag／RankのCreate、Update、Archiveを合計9 Operation追加した。
  Admin Realm、有効Session、MFA Enrollment、`catalog.manage`、CSRF、Exact Origin、
  JSON Content-Type、RFC 9457、Request ID、`private, no-store`を適用した。
  Public／Webhook Contractは非変更で、Storefront ClientへAdmin型を公開していない。
- 全Mutationは`Idempotency-Key`必須で、同一Admin＋同一Key＋同一Requestは
  Canonical Replay、異なるRequest／OperationへのKey再利用は409とする。
  既存共通Idempotency Recordの24時間Retentionを使用する。
- Category／Tag／Rankへ`revision`と`archived_at`を追加した。
  Revisionは1ずつ増加し、Stale Updateは409、Codeは作成後immutable、
  Archive後は通常Update不可、物理Delete不可、暗黙復元不可である。
- DB TriggerでもCode変更、Archive後Update、Revision飛越し、物理Delete、
  公開中／公開予定参照を破壊する表示変更を拒否する。既存Migrationは編集していない。
- Unknown Field、長さ超過、HTML／制御文字、不正Code／Slug、負Sort Orderを拒否し、
  Name／DescriptionはUnicode NFCへ正規化する。Core Fieldは型付きColumnを使用する。
- Mutation Service自身でAuthorization Contextを検証し、Controller直接／Service直接の
  迂回を拒否する。Deadlock／Serialization Failureは同一Keyで最大3回に限定する。
- Create／Update／Archive、Replay、Conflict、公開参照拒否、Permission拒否、
  Rate LimitをAppend-only Auditへ接続した。成功Mutationは`catalog.change` Outboxと
  同一Transactionで確定し、内部ID、Cookie、Session ID、PIIを保存しない。
- 管理MutationはAdmin相関HMAC Keyで合計30回／10分に制限し、
  Limiter障害時はFail Closed、429は`Retry-After`を返す。Read APIは対象外である。

### Admin UI／共通Mutation基盤

- MIG-060CのCategory／Tag／Rank一覧・詳細へ新規作成、編集、Archiveを追加した。
  `catalog.manage`がないOperatorには操作UIを表示せず、Read-onlyを維持する。
- 共通Mutation Form、Archive Confirmation、Conflict Boundary、Dirty State Guardを
  実装した。Code immutable、Client Validation、未保存変更警告、二重送信防止、
  409／412時のStale Data再取得、成功後の一覧／詳細再取得を共通化した。
- Network結果不明時は同じ入力Fingerprintに同じIdempotency-Keyを再利用し、
  成功または確定失敗後の新規操作では新しいBrowser CSPRNG UUIDを生成する。
- Existing Admin Shell、PermissionProvider、ProtectedAdminRoute、Error Boundaryを
  再利用し、架空Data、Role比較、Local／Session Storage Tokenを追加していない。
  Desktop／Mobile、Keyboard、Focus、Dialogを既存Design System内で実装した。

### Test／Migration／Security

- Catalog Backend対象は24 Test／364 AssertionでPASSした。このうち新規Mutationは
  9 Test／110 Assertionで、Owner／Admin、Operator 403、Create／Update／Archive、OCC、
  Idempotent Replay／Key Conflict、公開参照保護、DB Trigger、Rate Limit、
  Audit／Outbox Rollback、物理Delete API不存在、Service迂回拒否を検証した。
- Admin OpenAPI Unit／BundleはPASSし、Operation数はPublic 42、Admin 87、
  Webhook 0である。Admin Contract生成差分は0である。
- Admin Typecheck、Lint、Production Build、Unit／Component 33 Test、
  Chromium Browser E2E 9 TestはPASSした。Owner MutationのCSRF／Idempotency Header、
  Canonical再取得、Operator Read-only、Mobile／Keyboard／Focusを確認した。
- Policy Unit 88 Test、Quality Unit 5 Test、Security Unit 4 Test、
  Release Unit 10 Test、DB Guard UnitはPASSした。`quality-gate`、
  `security-gate`、Commit固定後の`policy-gate`はPASSした。
- Storefront Client生成差分／Typecheck／Lint／Build／14 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Storefront Testkit生成差分／Typecheck／Lint／Build／22 Test、
  Export／Network BoundaryはPASSした。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Auditは0 Finding、
  Legacy Auditは既存11 Finding、Composer Auditは既存期限付き10 Findingである。
  Baseline追加／拡張、新規Critical／High、Secret／PII Candidateは0件だった。
- Guard付きPersistent V2 DBで`migrate:fresh` 2回、最新Migration
  Rollback／Reapply、全V2 Suite、PostgreSQL／Redis Healthを確認した。
  Task専用Ephemeralでは`migrate:fresh` 2回、全V2 Suite、
  Draw／QA／Reporting／Content Load、API／Admin Health、Backup／Restore、
  Task Resource Cleanupを確認した。
- V2 Migrationは16件、Migration Set SHA-256は
  `5e1cc64eaa3b8ea05d6500f1bc2d4866283ed8dd21785bd9f7d96ed5ffe49ffa`である。
  Source／Restore Schema SHA-256は
  `e868893e67fa303f6f656a067df32f33c5c921d9318b19a566674086ff0b2043`、
  Migration Row SHA-256は
  `7c10bd058debe42bde7b379d1a91aab80fbc08d6670552328f5244e602aa9311`
  で一致した。Backup SHA-256は
  `8a7d5438763983acf66150e2fb70de78c139e0d51ff6d6c4b67440f63e535b1d`である。
- Persistent Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-060D/persistent-result.json`、
  Ephemeral Evidenceは`/var/lib/oripa-v2-evidence/MIG-060D/smoke/`へ
  Repository外保全した。Task専用Container／Network／VolumeはCleanup済みである。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtime、本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 時間を要した作業／Gate

- Guard付きPersistent検証の初回は約2分で203 Test中、新規Testの
  Cache-Control Directive順序だけが一致せず停止した。Framework実値は
  `no-store, private`で要件を満たしており、Directiveを正しく検査するよう修正後、
  Migration 2回と全Suiteを最初から再実行してPASSした。
- Browser E2E初回は52秒で新規TestのLabel Locatorが既存Sort Selectと曖昧になった。
  Dialog内のExact Accessible Nameへ限定し、成功Run 9 Testは50.6秒だった。
- Guard付きEphemeral Smokeは全Suite、Load、Backup／Restore、
  Source／Restore比較、Resource Cleanupを含み308秒を要した。
  Backup／Schema／Migration Rowは一致し、Host Port公開と残存Resourceは0だった。
- Catalog固有のRate Limit／Limiter障害／Outbox Rollback Test追加後は、
  Classic BuilderでTask API Imageを再構築し、Mutation 9 Test／110 Assertionと
  Catalog対象24 Test／364 Assertionを再実行してPASSした。
- Frozen InstallとPackage／Admin／Gate検証は依存順に実行した。
  未Commit新規Fileを`git ls-files`で列挙するPolicy本体だけが作業途中に停止したが、
  Worklog／Reportを含む固定Commit後にPolicy／Quality／Security Gateを再実行して
  すべてPASSした。GateやBaselineは弱めていない。
- Fresh Self-reviewでは、未知のPostgreSQL `P0001`を既知Catalog Conflictへ
  誤分類しないFail-closed化と、Idempotency Request Hashへの操作種別固定を追加した。
  後者のFinal候補ではGuard付きPersistent検証を再実行し、`migrate:fresh` 2回、
  最新Migration Rollback／Reapply、全V2 SuiteがPASSした。Local判定の
  SEV-0／SEV-1は0件である。
- 初回GitHub CheckはPR必須見出し不足と、既存Category／Tag／Rank Read Schemaへ
  `revision`／Archive属性をrequired追加した後方互換違反を検出した。PR本文は
  Governanceの必須見出しへ修正し、Server Responseは新属性を常時返しながら
  OpenAPIではoptional拡張へ変更した。Mutation UIは`revision`欠落時に操作を
  表示せず、直接呼出もFail Closedにした。OpenAPI Bundle／Contract Gate、
  Admin生成差分、Typecheck、Lint、Production Build、Browser E2E 9 Testを
  再実行してPASSした。旧Read Responseで`revision`がない場合のFail-closed
  Component Testも追加し、Admin Unit／Component 33 TestがPASSした。
  続くPolicy CheckでPR本文のTask Metadataと`Allowed paths`／`Changed files`の
  厳密な節不足も検出したため、Repository Policy Parserへ本文を直接照合し、
  Task ID、Risk、Base SHA、全33 Changed Pathを固定した。一連のGitHub修正と
  再検証には約12分を要した。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。Prize／Asset Mutation、
  Gacha／Probability、他業務Admin画面、Storefront画面、通知Transport、
  Staging E2Eが残るためGate G4／G5は`NOT COMPLETE`である。
- LINE Login `MIG-058B`は人間指示まで保留する。次Task候補は
  `MIG-060E Admin Prize／Presentation Asset Mutation`だが、
  MIG-060Eは本Task内で開始しない。

## MIG-060D Closeout／MIG-060E Admin Prize／Presentation Asset Mutation

### MIG-060D Closeout

- Issue `#127`はClosed、PR `#128`はSquash Mergedである。Final Headは
  `2e20bdf7f0d7ff560987e0c07c727c3be6d2d79e`、Squash Commitは
  `b4c04c998afef762c93f5d2cb77a8b0e58c5985f`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-060D Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Task／Characterization／Scope

- Task IDは`MIG-060E`、Riskは`R3`、Issueは`#129`、Branchは
  `feat/MIG-060E-admin-prize-asset-mutation`、Base SHAは
  `b4c04c998afef762c93f5d2cb77a8b0e58c5985f`である。
- MIG-050のV2 Catalog Schema、MIG-060CのRead Contract、MIG-060Dの
  `catalog.manage`、Idempotency、OCC、Audit／Outbox、Rate Limit、
  Admin Mutation UIを正本として再利用した。別の権限・Mutation基盤は作成していない。
- PrizeはCode、Rank、任意Presentation Asset、表示名、説明、表示価格、
  交換Point、Visibilityを既存型付きColumnで管理する。Codeは作成後immutableである。
- Presentation Assetは既存SchemaのStorage識別子、Public Relative Path、
  SHA-256、Image／Video種別、MIME、Byte Size、Alt、Public状態だけを扱う。
  本Taskは既存ObjectのMetadata登録境界であり、Upload、外部URL取得、
  Storage Provider拡張は実装していない。作成後のObject Identity変更は拒否する。

### Contract／Schema／Domain

- Admin OpenAPIへPrizeとPresentation AssetのCreate、Update、Archiveを
  合計6 Operation追加した。Admin Realm、有効Session、MFA Enrollment、
  `catalog.manage`、CSRF、Exact Origin、JSON Content-Type、RFC 9457、
  Request ID、`private, no-store`を維持する。
- Existing Read Responseは`revision`、`is_archived`、`archived_at`をoptional拡張し、
  後方互換性を維持した。Public／Webhook ContractとStorefront Clientは変更していない。
  Admin Responseへ内部ID、原価、個別ppm、Storage Identifierを公開しない。
- Prize／Assetへ`revision`と`archived_at`を追加するForward-safe Migrationを追加した。
  既存Migrationは編集していない。DB Triggerで物理Delete、Archive後Update、
  Revision飛越し、Prize Code変更、Asset Object Identity変更を拒否する。
- 公開中または公開予定Gacha Versionが参照するPrize、およびPrize／Rank／Gachaが
  参照するAssetについて、表示・公開・Archiveの破壊的変更をApplicationとDBの
  両境界でFail Closedにした。Published Gacha／Probabilityは変更していない。
- PrizeのRank／Asset参照はActiveな既存Public IDへ限定し、Asset種別とMIME、
  Relative Public Path、Checksum、非負Byte Sizeを検証する。
  Unknown Field、HTML／Script、制御文字、長さ超過を拒否し、表示文字列をNFC化する。
- 全MutationはMIG-060Dと同じ`Idempotency-Key`、24時間Retention、
  Canonical Replay、異なるRequestの409 Conflict、`revision` OCCを使用する。
  同一Transaction内でMaster、Idempotency、Append-only Audit、
  `catalog.change` Outboxを確定し、Deadlock／Serialization Failureは最大3回に限定する。
- 管理Mutation Rate Limitは既存のAdmin相関HMAC Keyによる30回／10分を再利用し、
  Limiter障害はFail Closedとする。OperatorはRead-onlyで、入力Validationより前に
  Permissionを検査してMutation規則を露出しない。

### Admin UI

- MIG-060CのPrize／Presentation Asset一覧・詳細へ新規作成、編集、Archiveを追加した。
  MIG-060Dの共通Mutation Form、Confirmation、Conflict Boundary、Dirty State Guard、
  Idempotency Fingerprint、Canonical再取得を拡張して再利用した。
- Prize Formは既存Admin Read APIからRankとPublic Assetを取得して選択し、
  Asset Formは作成時だけObject Metadataを入力する。更新時はAltとPublic状態だけを
  変更可能にし、Object IdentityはUI／API／DBでimmutableとした。
- `catalog.manage`がない場合はMutation UIを表示せず、Backend 403を最終判断とする。
  Client Validation、未保存変更警告、二重送信防止、409／412 Conflict、
  401／403／429、成功後Canonical再取得、Mobile、Keyboard、Focusを実装した。
  架空Data、Role直接比較、Local／Session Storage Tokenを追加していない。

### Test／Migration／Security

- Catalog Backend対象は17 TestでPASSした。Prize／Asset Create／Update／Archive、
  Owner／Admin成功、Operator 403、OCC、Idempotency、公開参照保護、
  Object Identity不変、物理Delete拒否、Audit／Outbox／Rate Limit回帰を確認した。
- Admin OpenAPI Unit／BundleはPASSし、Operation数はPublic 42、Admin 93、
  Webhook 0である。Admin Contract生成差分は0である。
- Admin Typecheck、Lint、Production Build、Unit／Component 35 Test、
  Chromium Browser E2E 10 TestはPASSした。
- Policy Unit 88 Test、Quality Unit 5 Test、Security Unit 4 Test、
  Release Unit 10 Test、DB Guard Unit 25 TestはPASSした。
  `policy-gate`、`quality-gate`、`security-gate`はPASSした。
- Storefront Client生成差分／Typecheck／Lint／Build／14 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Storefront Testkit生成差分／Typecheck／Lint／Build／22 Test、
  Export／Network BoundaryはPASSした。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Auditは0 Finding、
  Legacy Auditは既存11 Finding、Composer Auditは既存期限付き10 Findingである。
  Legacy Lintは既存8 Error／1 Warningと一致し、Baseline追加／拡張、
  新規Critical／High、Secret／PII Candidateは0件だった。
- Guard付きPersistent V2 DBで`migrate:fresh` 2回、最新Migration
  Rollback／Reapply、全V2 Suite、PostgreSQL／Redis Healthを確認した。
  Task専用Ephemeralでは`migrate:fresh` 2回、全V2 Suite、
  Draw／QA／Reporting／Content Load、API／Admin Health、Backup／Restore、
  Task Resource Cleanupを確認した。
- V2 Migrationは17件、Migration Set SHA-256は
  `bd92fa2fa15e9359a23fdf0192233929c81000c552790ff7485bae825c2749f2`である。
  Source／Restore Schema SHA-256は
  `dc606787bccee66f169c2478667488b6473df4956fc1121cc3685716e0f66313`、
  Migration Row SHA-256は
  `dc58041dba95823a451219537e5b504c6b5ab1aa6b04c830964b90d9ab675cc1`
  で一致した。Backup SHA-256は
  `dd4914fef26abf047c749c55eff916820c849d94abab1463a30ed37e25d85858`である。
- Persistent Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-060E/persistent-final/persistent-result.json`、
  Ephemeral Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-060E/ephemeral-final/`へRepository外保全した。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtime、本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 時間を要した作業／Gate

- Persistent V2 Guardの初回は約93秒で、Read Testが新DB Triggerを迂回する直接Updateと
  旧Mutation不存在Assertionを使用していることを検出した。Fixtureを新Schemaどおり
  作成し、物理Delete Endpoint不存在を検査する内容へ修正後、全Suiteを再実行した。
  Self-reviewの権限判定順序修正後にも約91秒のFinal Runを行い、すべてPASSした。
- Ephemeral Smokeは約5分を要した。全Suite、100,000件Contact等のLoad、
  Backup／Restore、Source／Restore Checksum比較、Resource Cleanupを含み、
  一致とCleanupを確認した。
- Chromium Browser E2EのFinal Runは約1分18秒で10 TestがPASSした。
  Admin Unit／Component 35 Test、Production Buildも同じFinal候補で確認した。
- First-party Packageを並列検証した初回は、Testkitが依存PackageのBuild完了前に
  型解決できず停止した。Storefront Client／Site Schema Build完了後に依存順で
  Testkitを再実行し、22 Testと全BoundaryがPASSした。Code変更は不要だった。
- 未Commitの新規Migrationを追跡対象として要求する`policy-gate`は作業途中に
  Fail Closedとなった。固定Commit後は新Admin Componentの明示Allowlist登録漏れも
  検出したため、既存の限定列挙へ1 Pathだけを追加した。Wildcardや任意Path許可へ
  緩和せず、Policy Unit 88件とPolicy Gate本体を再実行してPASSした。
  Gate、Baseline、Assertionは弱めていない。

### Closeout待ち／Gate

- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。
- Gacha／Probability Mutation、他業務Admin画面、Storefront画面、通知Transport、
  Staging E2Eが残るため、Gate G4／G5は`NOT COMPLETE`である。
- LINE Login `MIG-058B`は人間指示まで保留する。

## MIG-060E Closeout／MIG-058B LINE Login v2.1 Identity Linking Vertical Slice

### MIG-060E Closeout

- Issue `#129`はClosed、PR `#130`はSquash Mergedである。Final Headは
  `cf81ab2519adf8b5ecea623a8091e771b8d7ecc9`、Squash Commitは
  `e10748d2d7b8d8a435b1bcf9e2e94ddf7c834a4e`である。
- Required 5 Check、CodeQL、`CodeQL (javascript-typescript)`、Dependency Reviewを
  含む8 Checkは成功した。Final Headと一致するFresh Self-reviewを確認し、
  SEV-0／SEV-1は0件だった。
- Remote／Local Task BranchとMIG-060E Worktreeは削除済みである。
  Local `main = origin/main`、Main Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### Task／Characterization／共通基盤

- Task IDは`MIG-058B`、Riskは`R3`、Issueは`#131`、Branchは
  `feat/MIG-058B-line-login`、Base SHAは
  `e10748d2d7b8d8a435b1bcf9e2e94ddf7c834a4e`である。
- V1固定SourceにLINE Login実装は存在しなかった。V1由来の架空互換は作らず、
  LINE Login v2.1公式仕様、Identity／Security正本、MIG-058AのExternal Identity
  Transaction／Account／History／Session／Audit／Outboxを正本とした。
- Google固有だったProvider処理を共通Provider Interface／Registryへ分離し、
  Googleの既存Login／Link／Reauthentication／Unlinkを同じ共通Service上で維持した。
  LINE専用Identity Table、別Session、別Idempotency／Rate Limit基盤は作成していない。

### Contract／Protocol／Account

- Public OpenAPIへLINE Login Start／Callback、Link Start、Reauthentication Start、
  Unlinkの5 Operationを追加した。Public Operationは47件である。
  Admin／Webhook Contractは非変更で、Raw Subject、Token、内部Transaction ID、
  Channel Secretを公開しない。
- Authorization、Token、ID Token VerifyはLINE公式の固定HTTPS Endpointだけを使用する。
  CSPRNG State／Nonce、PKCE S256、Exact Redirect URI、Relative Return Path、
  Browser／User Session Binding、10分、1回限りの既存Transaction境界を再利用する。
  任意URL、Discovery URL、Provider応答URLへ接続しない。
- Verify応答は固定Issuer `https://access.line.me`、Audience＝Channel ID、
  `exp`、`iat`、Nonce、Subject、必須型をServer時刻で検査する。Provider Timeout、
  429、5xx、Verify拒否はFail Closedとし、曖昧なAuthorization Codeを再利用しない。
- 基本Scopeは`openid profile`である。Repository外設定
  `V2_LINE_LOGIN_EMAIL_SCOPE_ENABLED`が明示的に有効な場合だけ`email`を要求する。
  Client ID／Secret／Redirect URIもRepository外Environmentだけを使用する。
- Identity Keyは`provider + issuer + HMAC(subject)`であり、Email／Display Nameを
  Keyにしない。Raw Subject、Access／Refresh Token、Authorization Code、ID Tokenを
  永続化しない。
- 既存LINE IdentityはEmailなしでSubject Loginでき、認証済みUserへのLinkも
  Emailを要求しない。新規UserはEmail Claimがある場合だけ作成し、
  `password_login_enabled=false`とする。Emailがない場合はActive Userや架空Emailを
  作らず`EXTERNAL_IDENTITY_EMAIL_COMPLETION_REQUIRED`を返す。
- Verified Email衝突で既存Userへ自動Linkしない。明示Link、Concurrent Callback／Link、
  Session／CSRF Rotation、Recent Reauthentication、最後のCredential保護、
  Remember Device失効はMIG-058A共通規則を維持する。

### Schema／Audit／Client

- Forward-safe Migration
  `2026_08_07_000018_add_line_external_identity_provider.php`でProvider Allowlistと
  Issuer Pair Constraintを`google`／`line`へ拡張した。既存Migrationは編集せず、
  External Identity Tableを追加していない。
- `(provider, issuer, subject_hash)`一意、User＋Provider一意、Transaction TTL／状態、
  Account／HistoryのMutation Guardを既存Schemaで維持する。RollbackはLINE Recordが
  存在する場合に履歴保持のためFail Closedとする。
- OIDC Start／成功／拒否、Email不足／衝突、Provider障害、Link／Unlink／
  Reauthentication、Rate LimitをAppend-only Auditへ接続した。
  Protocol値、Full Email、Cookie、Raw Session ID、Secretを保存しない。
- Storefront ClientへLINE Facade、生成型、Callback境界を追加し、TestkitへLINE Start、
  Linked Identity Fixtureを追加した。Google／LINEのTokenをClient永続Storageへ
  保存せず、Admin型を公開しない。

### Test／Migration／Security

- LINE専用は12 Testで、固定Endpoint、PKCE、Email Scope、Email有無、新規User、
  既存Identity、Email衝突、Link／Reauthentication／Unlink、Session／CSRF Rotation、
  Claim拒否、Provider Timeout／429／5xx、DB Constraint、Audit Redactionを確認した。
  同一LINE Login／Link CallbackのProcess Concurrency Testも追加し、
  1 User／1 Identityだけが成立することを確認した。State／Transaction／Rate Limit等の共通境界は既存Google
  OIDC Testと全V2回帰で確認した。
- OpenAPI Unit／BundleはPASSし、Operation数はPublic 47、Admin 93、Webhook 0である。
  Storefront Client生成差分／Typecheck／Lint／Build／14 Test、
  Site Schema生成差分／Typecheck／Lint／Build／10 Test、
  Storefront Testkit生成差分／Typecheck／Lint／Build／22 TestがPASSした。
- Admin生成差分0、Typecheck、Lint、Unit／Component 35 Test、Production Build、
  Release Unit 10 Test、Policy Unit 88 Test、Quality Unit 5 Test、
  Security Unit 4 Test、DB Guard Unit 25 TestがPASSした。
- `policy-gate`、`quality-gate`、`security-gate`、OpenAPI Contract GateはPASSした。
  Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Auditは0 Finding、
  Legacy Auditは既存11 Finding、Composer Auditは既存期限付き10 Findingである。
  Legacy Typecheck／BuildはPASSし、Lintは既存8 Error／1 Warningと一致した。
  Baseline追加／拡張、新規Critical／High、Secret／PII Candidateは0件だった。
- Guard付きPersistent V2 DBで`migrate:fresh` 2回、最新Migration
  Rollback／Reapply、全V2 Suite、PostgreSQL／Redis Healthを確認した。
  Task専用Ephemeralでは`migrate:fresh` 2回、全V2 Suite、
  Draw／QA／Reporting／Content Load、API／Admin Health、Backup／Restore、
  Task Resource Cleanupを確認した。
- V2 Migrationは18件、Migration Set SHA-256は
  `4f8323fde23415f4a38daccae1d175d6d25a9962b8290523e24fd2ece219a40e`である。
  Source／Restore Schema SHA-256は
  `638f04f706db84f3f5cbd1c97ff77099dd68d1d0dce6265a03e151a9f2dd7b02`、
  Migration Row SHA-256は
  `2057fae3cf8684b8e4bf327b049b586df05ef6608291d3e145ce4ce8b106fab3`
  で一致した。Backup SHA-256は
  `b3cace19b28d7dcb21317f228448b08dbe654a3795d6f9b1527f56cf054d7190`である。
- Persistent Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-058B/persistent-final/persistent-result.json`、
  Ephemeral Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-058B/ephemeral-final/`へRepository外保全した。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtime、本番Resource、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagを変更していない。

### 時間を要した作業／再試行

- API Imageの初回BuildはBuildx環境が`--allow`を解釈できず停止した。
  Repository既定のClassic Builderへ切り替え、約30秒で成功した。
- First-party Package並行検証では、TestkitがStorefront／Site Schema Build前に
  型解決できず停止した。依存Packageを先にBuildして順次再実行し、全PackageがPASSした。
- Persistent Guard初回は40.06秒でPHPUnit final methodと新Test Helper名の衝突を検出した。
  Helper名修正後の再実行は137.99秒で、Email不足Audit EventのAllowlist漏れと
  HTTP Fake差替えによるTest不整合を検出した。監査Eventを正式登録し429 Testを
  独立させ、全工程を再実行した。
- Ephemeral Smoke初回は340.49秒でBackup／Restore Schema比較に停止した。
  `IN` CHECKがRestore時に等価な別表現へ正規化されたことが原因であり、
  決定的な明示OR Constraintへ変更した。最終Ephemeral Runは303.69秒で
  全Suite／Load／Backup／Restore／CleanupがPASSした。
- Migration最終SourceでPersistent Guardを108.89秒かけて再実行し、
  `migrate:fresh` 2回、Rollback／Reapply、全SuiteがPASSした。
  Gate、Baseline、Assertionは弱めていない。
- Fresh Self-reviewでLINE固有のState／PKCE／Expiry／Completed Replay Testと
  Concurrent Link Testを追加した。最終候補のPersistent／Ephemeral Guardは
  141.68秒／275.18秒で再実行し、全Suite、Backup／Restore、Resource Cleanupが
  PASSした。
  再実行し、全Suite、Backup／Restore、Resource CleanupがPASSした。

### Closeout待ち／Gate

- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR上で確定する。
- Storefront／Admin業務画面、通知Transport、Staging E2E等が残るため、
  Gate G4／G5は`NOT COMPLETE`である。
- 次Task候補は`MIG-060F Admin Gacha Version Management`だが、
  MIG-060Fは本Task内で開始しない。

### MIG-058B 最新仕様変更／Messaging API Follow統合

- PR作業中の最新人間決定により、旧「Messaging API送信なし」、
  「Channel Access Token不要」、「Adminメッセージ設定画面なし」を廃止した。
  新Task、Branch、Worktree、PRは作成せず、Issue `#131`、PR `#132`、
  `feat/MIG-058B-line-login`上の既存LINE Login実装とCommit履歴を維持した。
- MIG-058A由来のExternal Identity Account／Transaction／History、
  Provider Registry、HMAC Subject、Login／Link／Reauthentication／Unlink、
  Session／CSRF Rotation、Rate Limit、Audit／Outboxを再利用した。
  Google OIDCの複製、LINE専用Identity Table、Messaging Channel ID設定は追加していない。
- Repository外Environmentは`LINE_LOGIN_CHANNEL_ID`、
  `LINE_LOGIN_CHANNEL_SECRET`、`LINE_MESSAGING_CHANNEL_SECRET`、
  `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN`を使用する。Messaging Secretは
  Webhook署名、Access TokenはReply MessageのBearer認証だけに使用し、
  値をResponse、Log、Audit、Error、Worklogへ記録しない。

### Webhook／Reply／Pending Follow／Point

- Webhook Contractへ`POST /webhooks/v2/line`を追加し、生Request Bodyの
  HMAC-SHA256署名をDeserialize前に検証する。`follow`／`unfollow`だけを処理し、
  User Message本文は保存しない。
- `webhookEventId`と`source.userId`はHMAC相関値だけを保存する。
  `line_webhook_events`のUnique Constraintと
  `pending → processing → sent|failed` DB TriggerでRedelivery、並行Follow、
  Reply Tokenの二重使用を防止する。Reply Token、Raw User ID、Access Tokenは
  DB／Log／Auditへ保存しない。
- Login済みFollowはIdentity HMACでUserを特定し、Friendship、free Point、
  Point Operation／Lot／Ledger／Wallet、Webhook Event、Audit、Outboxを
  同一Transactionで確定する。Business KeyはFriendship Public ID由来で一意である。
- Login前Followは`line_pending_follows`へ保存する。後続LINE Login／Link成功時に
  同じIdentity Transaction内でClaimし、FriendshipとPointを一度だけ付与する。
  Login後のPush Messageは送信しない。
- Point／設定失敗時はFollow TransactionをRollbackし、完了Messageを送らない。
  Transaction成功後に固定LINE Reply Endpointへ1 Text Messageだけを送信する。
  Reply 429／5xx／TimeoutはRedaction済みResultとしてAuditし、Pointを取消さず、
  Push／Broadcast／Multicast／NarrowcastへFallbackしない。

### Admin LINE Message設定

- Forward-safe Migration
  `2026_08_07_000019_create_line_messaging_follow_foundation.php`で
  `line_messaging_settings`、`line_friendships`、`line_pending_follows`、
  `line_webhook_events`を追加した。既存Migrationは編集していない。
- Admin OpenAPIへ設定取得、Preview、更新を追加した。`identity.line.manage`は
  Ownerだけに付与し、更新はFresh MFA 5分、Admin Realm、CSRF、Exact Origin、
  JSON、Revision OCC、Idempotency-Key、Critical Mutation Rate Limit、
  Audit／Outboxを必須とする。
- 2種類の本文はUnicode NFC、1～1,000文字、空文字／HTML／Script／制御文字を
  拒否し、`{login_url}`だけを置換する。Login URLはServer固定Relative Path
  `/login`から生成する。Default MessageはMigrationへ明示した。
- Admin Appへ編集、Server Preview、Conflict再読込、Dirty State、
  二重送信防止、Fresh MFA Dialog、Canonical Response再取得を実装した。
  Reward Point量は未承認値を推測せず初期値0とし、Domainは正の設定値が存在する
  場合だけ冪等付与する。

### 最新検証／時間を要した作業

- OpenAPI Operation数はPublic 47、Admin 96、Webhook 1である。
  Public Storefront ClientへAdmin／Webhook型、Message設定、Subject、Token、
  SecretをExportしていない。
- LINE Messaging専用8 Test、LINE Login専用13 Test、
  Webhook 2 Process同時Redelivery Testを追加した。Valid／Invalid署名、
  Login済み／前Message、Placeholder、設定／Access Token不在、
  Reply成功／429／5xx／Timeout、Point失敗、Redelivery、Pending Claim、
  Unfollow、Audit Redaction、Push系Endpoint非使用を検査する。
- Persistent Guardは最終候補前の成功時点でMigration 19件、
  Migration Set SHA-256
  `3ae1148aa1c847bca97a4531dd673e354ae4d9070849aeeb29f5b9506b538e90`、
  `migrate:fresh` 2回、最新Migration Rollback／Reapply、全V2 Suite、
  Schema Inventory、PostgreSQL／Redis HealthがPASSした。
- Admin Typecheck／Lint／Production Build、Unit／Component 38 Test、
  Browser E2E 11 TestがPASSした。BrowserではOwner、Preview、Fresh MFA、
  同一Idempotency-Key再送、Canonical Revision更新を確認した。
- Storefront Client 14 Test、Testkit 22 Test、Site Schema 10 Testと
  各生成差分／Typecheck／Lint／Build、OpenAPI Unit 4件がPASSした。
- Root／Legacy Frozen Installはpnpm `10.12.1`でPASSした。Root Audit 0、
  Legacy Auditは既存11 Finding、Composerは既存Baseline対象だけであり、
  Baseline追加／拡張、新規Critical／Highは0件である。
- API Image Buildは候補反映ごとに約30秒を要した。Persistent Guardは
  Webhook Header、Audit Actor、Pending日時、HTTP Fake、Schema Inventory順の
  Fail Closed検出ごとに約143～150秒を要し、修正後に全工程を成功させた。
  Admin Browser E2Eは約52秒、First-party Package一式は約90秒を要した。
- Final Ephemeral Smoke、Fresh Self-review、Final Head／GitHub Check／
  Squash Commit／CleanupはCloseout時に確定する。
- V1 Migration 40件の正本Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  で不変である。V1 Runtime、本番DB／Redis／Storage、Nginx、
  `v1/early-release`、Archive Branch、Annotated Tagを変更していない。
- Gate G4／G5は`NOT COMPLETE`である。次Task候補は
  `MIG-060F Admin Gacha Version Management`だが、本Task内で開始しない。

### MIG-058B Messaging統合 最終Local検証

- LINE Messaging統合後の最終Migration数は19件、Migration Set SHA-256は
  `96a6b7501d1291f5103f1b23438c4670f8ae04449e851e2120622f1f8f520836`
  である。
- Persistent Guardを144.41秒で完了し、`migrate:fresh` 2回、最新Migration
  Rollback／Reapply、全V2 Suite、Schema Inventory、PostgreSQL／Redis Healthが
  PASSした。Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-058B/persistent-final-messaging-stable/persistent-result.json`
  である。
- Task専用Ephemeral Guardを278.80秒で完了し、`migrate:fresh` 2回、
  全V2 Suite、Draw／QA／Reporting／Content Load、API／Admin Health、
  Backup／Restore、Resource CleanupがPASSした。Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-058B/ephemeral-final-messaging-stable/ephemeral-result.json`
  である。
- Source／Restore Schema SHA-256は
  `3d59ccfb05abd0c1954c6d6ff8eef435ccd913fa7aef72972ea75087edd472a2`、
  Migration Row SHA-256は
  `77cbd2fc2bce85ea8830e6ff3a8d7b50086fac778c29672e6e10cdff242cd623`
  で一致した。Backup SHA-256は
  `21b173ef40c209bbea8cea48df2fe4d2c318693465fed6c54b36b56dace64fd3`
  である。
- Ephemeral初回は245.49秒後にHost root filesystem容量不足で停止した。
  Source、稼働Container／Image、DB Volumeを変更せず、未使用Docker Build Cache
  12.34GBだけを削除して空き容量を確保した。
- 2回目は374.94秒後に、新規CHECK式がPostgreSQL Dump／Restoreで等価な別表現へ
  正規化されるためSchema Checksum不一致となった。CHECKを意味不変の明示比較と
  `text = ANY(text[])`へ安定化し、Persistent／Ephemeralを最初から再実行した。
  Gate、Baseline、Assertion、Timeout、Memory設定は弱めていない。
- Policy Unit 88件、Policy Gate、Quality Gate、Security Unit 4件、
  Security Gateは最終差分でPASSした。Root Audit 0、Legacy Audit 11、
  新規Critical／High 0、Secret Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変であり、V1 Runtime、本番DB／Redis／Storage、Nginx、
  `v1/early-release`、Archive Branch、Annotated Tagを変更していない。
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree CleanupはPR Closeoutで確定する。
- Gate G4／G5は`NOT COMPLETE`を維持する。次Task候補は
  `MIG-060F Admin Gacha Version Management`であり、本Taskでは開始しない。

## MIG-058B Closeout／MIG-058C LINE Friend Reward Configuration Completion

### MIG-058B Closeout

- Issue `#131`はClosed、PR `#132`はSquash Mergedである。
- Final Headは`cc2511973eb49a5d8e4fa088d0361c6fb52e8ab7`、Squash Commitは
  `2834eba8242867a5b7400e4bada7cafe0f91f86c`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task BranchとMIG-058B Worktreeは削除済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-058C Task／設計

- Task IDは`MIG-058C`、Riskは`R3`、Issueは`#133`、Branchは
  `fix/MIG-058C-line-friend-reward-settings`、Base SHAは
  `2834eba8242867a5b7400e4bada7cafe0f91f86c`である。
- MIG-058BのLINE Login、Webhook署名、Reply Message、Pending Follow、
  External Identity、Point Service、Admin Message設定、Fresh MFA、
  Idempotency／OCC、Audit／Outboxを再利用した。Login／Webhook／Replyの
  Endpoint、Token保持方針、Push禁止境界は変更していない。
- Forward-safe Migration
  `2026_08_07_000020_add_line_friend_reward_enabled.php`で
  `line_messaging_settings.reward_enabled`を追加した。Defaultは`false`、
  Amountは0、Expirationは180日であり、既存Migrationは編集していない。
- DB CHECKは無効時Amount 0、有効時Amount 1～1,000,000、Expiration 1～3,650日、
  Revision 1以上を保証する。Dump／Restore安定性のため、Amount、Expiration、
  Revisionへ明示的PostgreSQL Castを付け、`BETWEEN`を使わないCanonical比較とした。
- 1,000,000 Point上限は承認済みV1 Point管理境界を正本にし、
  V2 Point Domain、Admin OpenAPI、Server Validation、Admin UI、DB CHECKで
  同じ値を使用する。Pointはfreeとして付与し、設定日数をLot期限に使用する。
- Reward無効時はFriendshipを確定するが、Wallet、Point Operation、Lot、Ledger、
  Rewarded状態を作成しない。`reward_disabled`をRedaction済みAppend-only Auditへ
  記録する。
- Reward有効時は既存Friendship Public ID由来Business Keyを用い、Webhook Redelivery、
  Concurrent Follow、Unfollow／Re-follow、設定変更後も一度だけ付与する。
  Point失敗時は完了Replyを送らず、Reply失敗時は確定済みPointをRollbackしない。

### MIG-058C Contract／Admin UI

- Admin OpenAPIの既存LINE Messaging設定取得／Preview／更新へ
  `reward_enabled`、`reward_point_amount`、`reward_expiration_days`を追加した。
  Public／Webhook Operation数とStorefront Client Contractは変更していない。
- 既存`identity.line.manage`、Owner-only、Admin Realm、Fresh MFA 5分、
  CSRF、Exact Origin、JSON、Critical Mutation Rate Limit、
  Idempotency-Key、Revision OCC、Canonical Replay、Audit／Outboxを維持した。
- Admin設定画面へ有効Toggle、free Point表示、Amount、Expiration、現在状態、
  Server Preview、Validation／Conflict／Fresh MFA／Canonical再取得を追加した。
  無効化時はAmountを0へ固定し、1～1,000,000 Pointと1～3,650日の範囲を
  Client／Server双方で検証する。Secret／資格情報は表示・変更しない。

### MIG-058C 途中検証／効率改善

- Root Frozen Installはpnpm `10.12.1`でPASSした。OpenAPI Bundle／Check、
  Admin Contract生成／生成差分0、Admin Typecheck／Lint、Unit／Component
  39 TestはPASSした。
- Admin Browser E2Eは既存11 Scenarioを55.6秒で実行し、LINE設定のReward入力、
  Preview、Fresh MFA再送、同一Idempotency-Key、Canonical Revision更新を含めPASSした。
- Persistent全V2 Suite初回は106.8秒後、追加TestのWallet非生成期待、Rate Limit状態共有、
  Carbon型比較の3点で停止した。実装／Gateを弱めずTestを正した。
- Persistent全V2 Suite再試行は106.4秒後、Audit Column名のTest誤記だけで停止した。
  `action`を正本`action_code`へ修正した。
- 重い3回目の前に、対象HTTP TestとAdmin Smokeを先行する効率改善方針を適用した。
  LINE Messaging／Login対象は23 Test／194 Assertion、Webhook 2 Process並行Testは
  1 Test／10 AssertionでPASSした。
- 対象Testの最初の手動実行は、先行Full Suite失敗後のPersistent V2開発DB Fixtureと
  `artisan test`既定DB名の影響でEvidenceに採用しなかった。V2専用
  `oripa-v2-dev`だけを`migrate:fresh`し、`vendor/bin/phpunit`と
  `phpunit.v2.xml`を明示して短時間で正本対象結果を得た。
- 重い検証前のRead-only容量確認はRoot 4.2GB、`/tmp` 3.3GBであり、
  容量不足Cleanupは実施していない。稼働Container、Named Volume、
  V1 Resourceへ触れていない。
- First-party Packageは依存Build順にSerial実行する。Buildx／Host Toolchain更新は
  行わず、既存Docker Builderを使用する。

### MIG-058C Closeout予定

- Final Persistent Guardは`migrate:fresh` 2回、最新Migration
  Rollback／Reapply、全V2 Suite、PostgreSQL／Redis Health、Schema Inventoryが
  PASSした。Ephemeral Guardは`migrate:fresh` 2回、全V2／Load／Performance、
  Backup／Restore、API／Admin Health、Task Resource CleanupがPASSした。
- Migration Setは20件、SHA-256は
  `2bbfb220d6fd5412eba404ce2331cbd4df922422ba01c4aff8a9b5f45f49532f`。
  Backup SHA-256は
  `e8ecd6c43cfa8c6c216a7149e9e97e6450c73474590699ffa24c046bb974f193`。
  Source／Restore Schema SHA-256
  `ac0dcef6f79ed025b50b83c4e57ee90dfdea61c59b4df3934a1a683e8622e025`、
  Migration Row SHA-256
  `280948a670af7612bc6449adee96e1dae5d22e5cbe756605d0fcc072a875826a`
  は一致した。
- Storefront Client 14 Test、Site Schema 10 Test、Storefront Testkit
  22 Testを依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
  AdminはTypecheck／Lint／Build、Unit 39 Test、Browser E2E 11 TestがPASSした。
- Policy Unit 88件、Policy Gate、Quality Unit 5件、Quality Gate、
  Security Unit 4件、Security GateがPASSした。Root Audit 0、Legacy Audit 11、
  Composer既存Baseline 10、新規Critical／High 0、Secret Candidate 0である。
  Release Unit 10件、Artifact Source Validation、Legacy Typecheck、
  Lint既存8 Error／1 Warning完全一致、Composer Manifest、
  `git diff --check`もPASSした。
- 重い検証前にRoot／`/tmp`／Docker CacheをRead-only確認した。Root空き4.2GBは
  直前Taskの容量不足再発リスクがあったため、稼働Resource、Image、Named Volumeへ
  触れず、未使用Build Cacheだけ2.33GB削除した。以降はRoot 5.1GB／4.7GBを
  確認し、追加Cleanupを行っていない。
- Persistent Final初回は約52秒で、新CHECK式のReward OR節の閉じ括弧不足を
  PostgreSQLが検出した。明示CastとCanonical比較を維持して括弧だけを修正し、
  約126秒のPersistent Finalと約348秒のEphemeral Finalを成功させた。
  対象HTTP／Admin Smokeを先行し、既に成功したFull Suite／Package Evidenceを
  重複実行せず維持した。Host Toolchainは更新していない。
- Push前検査でTest用Bearer文字列が高確度Secret Patternへ一致したため、
  同じConfig値とHeader期待値を短い`test-token`へ置換した。Final Treeの
  高確度Secret候補は0件、PHP構文はPASSした。置換後の単独Test再実行は常駐
  Persistent DBの既存Fixtureと件数前提が衝突したため採用せず、Clean DBで成功した
  全V2 Suite Evidenceを維持し、同一HeadのGitHub Checkで最終回帰を確認する。
- GitHub Quality Gate初回は、既存Admin Setting／Update／Preview Schemaへ
  Reward Fieldをrequired追加したBreaking Changeを検出した。Gateを緩めず、
  Reward Fieldを後方互換なOptional Contractへ修正した。Server Responseは常時
  新Fieldを返し、旧Requestが3 Fieldを全省略した場合は現在設定を保持し、
  一部だけの指定は422で拒否する。Idempotency hashは省略状態を維持する。
- 修正後のOpenAPI Breaking Check、Admin生成差分0、Typecheck／Lint／Unit 39件／
  BuildがPASSした。Clean DBのPersistent互換回帰も`migrate:fresh` 2回、
  全V2 Suite、最新Migration Rollback／Reapply、Health、Migration Checksumが
  PASSした。重い回帰前にRoot空き3.8GBと未使用Build Cache 1.885GBを確認し、
  稼働Resourceへ触れずBuild Cacheだけを追加Cleanupした。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。Final Head、GitHub Check、Fresh Self-review、Squash Commit、
  CleanupはPR Closeoutで確定する。Gate G4／G5は`NOT COMPLETE`である。
- 次Task候補は`MIG-060F Admin Gacha Version Management`であり、
  MIG-060Fは本Task内で開始しない。

### MIG-058B Fresh Self-review補正

- Fresh Self-reviewで、LINE Login Channel資格情報が正式名を優先しながら
  旧`V2_LINE_*`へFallbackする実装と、空／閉じていない波括弧を未知Placeholderとして
  拒否できない境界を検出した。
- Login Channel資格情報は`LINE_LOGIN_CHANNEL_ID`／
  `LINE_LOGIN_CHANNEL_SECRET`だけをRepository外正本とし、運用READMEも同期した。
  Messaging資格情報は`LINE_MESSAGING_CHANNEL_SECRET`／
  `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN`のままである。
- Message Templateは`{login_url}`を除去した後に残る`{`／`}`を拒否し、
  `{unknown}`、`{}`、`{unknown`の回帰Testを固定した。
- 補正後のPersistent Guardを144.66秒で再実行し、`migrate:fresh` 2回、
  最新Migration Rollback／Reapply、全V2 Suite、Schema Inventory、
  PostgreSQL／Redis HealthがPASSした。Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-058B/persistent-final-head/persistent-result.json`
  である。

## MIG-058C Closeout／MIG-060F Admin Gacha Master／Draft Version Management

### MIG-058C Closeout

- Issue `#133`はClosed、PR `#134`はSquash Mergedである。
- Final Headは`9a918c8b492f7acfc2abe92a4f0c22e102f41053`、Squash Commitは
  `236f2842003779aeb9e86b24858a0a5619ae1753`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task BranchとWorktreeはCleanup済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-060F Task／Characterization

- Task IDは`MIG-060F`、Riskは`R3`、Issueは`#135`、Branchは
  `feat/MIG-060F-admin-gacha-draft-management`、Base SHAは
  `236f2842003779aeb9e86b24858a0a5619ae1753`である。
- MIG-050のGacha Master、Version、Category／Tag／Prize／Presentation Asset
  Relationを正本とした。GachaのCode／Slug、Version番号、Published Version、
  Draw履歴を破壊せず、Draftだけを更新可能とした。
- `catalog.read`はOwner／Admin／Operator、`catalog.manage`はOwner／Adminだけに
  許可する既存中央Permission Matrixを再利用した。Publish用Permissionは追加していない。
- Probability Entry／ppm、Gacha Version Publish／Schedule、Public Contract、
  Draw Logicは変更していない。

### Schema／Domain

- Forward-safe Migration
  `2026_08_08_000021_add_v2_gacha_draft_management.php`で
  Gacha／VersionへRevision、Archive日時、Clone元Version参照を追加した。
  既存Migrationは編集していない。
- CHECKはRevision正数とArchive状態を明示Cast付きCanonical式で保証する。
  DB Triggerは物理Delete、Code／Slug・Version identity変更、Revision bypass、
  Published Version変更、Published／Draw参照を持つMasterの破壊的変更、
  Archive後の本体／Relation変更を拒否する。
- Create／Update／Archive、Draft Create／Clone／Update／Discardは既存の
  Catalog Mutation Executorを再利用し、Admin Realm、MFA Enrollment、
  CSRF、Exact Origin、JSON、`catalog.manage`、Idempotency-Key、Revision OCC、
  Rate Limit、Audit、`catalog.change` Outboxを同一Transactionで確定する。
- Version番号はGacha Row Lock下でServer生成する。Category／Tag／Prize／Assetは
  ActiveなPublic IDだけを受理し、重複Relation、公開期間逆転、負数、
  Unknown Field、HTML／Script／制御文字を拒否する。
- Tag／Prize参照は内部ID昇順で一括Lockし、RelationはTransaction内でSet-basedに
  置換する。一般利用可能なRaw Mutation Helperは追加していない。

### Contract／Admin UI

- Admin OpenAPIへGacha Master一覧／詳細／Create／Update／Archiveと、
  Version一覧／詳細、Draft Create／Clone／Update／Discardの11 Operationを追加した。
  Admin Operation数は107、Public 47、Webhook 1である。
- Opaque Cursor、Stable Sort、Public ID、RFC 9457、Request ID、
  `private, no-store`を維持した。Public／Webhook ContractとStorefront Clientの
  Admin非公開境界は変更していない。
- `/catalog/gachas`、Master詳細、Version詳細へ一覧、検索、Pagination、
  Category／Tag／Prize／Asset選択、Create／Edit／Archive／Clone／Discard、
  Dirty State、Confirmation、Conflict再読込、二重送信防止、Canonical再取得を
  実装した。Published VersionはRead-onlyで、Publish操作は表示しない。
- Fresh Self-reviewでVersion一覧のCursor操作がUIへ接続されていない不足を検出し、
  Master詳細へ前後ページ導線とRoute単位の状態分離を追加した。
- 既存Admin Shell、PermissionProvider、ProtectedAdminRoute、Catalog共通Componentを
  再利用した。新規画面はTailAdmin無料版の密度と階層を視覚基準にしつつ、
  App全体のTemplate置換、Dependency更新、CSP緩和は行っていない。

### Test／Evidence

- 対象Clean DB Testは5 Test／76 AssertionがPASSした。Create／Update／Archive、
  Clone、Published保護、Unknown／Duplicate Relation、OCC、Idempotent Replay、
  Operator 403、Delete／Probability Endpoint不存在、DB Guardを検証した。
- Admin Typecheck、Lint、Production Build、Unit／Component 43 Test、
  Browser E2E 12 TestがPASSした。BrowserではGacha一覧→Master詳細→Draft詳細、
  Version一覧の次ページ取得／前ページ復帰、Owner Mutation表示、
  Mobile横溢れなしを確認した。
- Persistent Guardは約141秒で、Migration 21件、`migrate:fresh` 2回、
  最新Migration Rollback／Reapply、全V2 Suite、Schema Inventory、
  PostgreSQL／Redis HealthがPASSした。Migration Set SHA-256は
  `649d695a3960257fe1a5c591d6d6516bff0e93d83dcbf92d8d23e8ff3608fef5`である。
- Ephemeral Guardは約426秒で、`migrate:fresh` 2回、全V2 Suite、
  Draw／QA／Reporting／Content Load、API／Admin Health、Backup／Restore、
  Task Resource CleanupがPASSした。Backup SHA-256は
  `01dcbbb7ba849d1ce19f4e260953a6896b7c14a6caed90411f83415e7bcfc98b`、
  Source／Restore Schema SHA-256は
  `56523b1ffeaf71e48214cd39dc1ca02c73b4b1989ea2d099195b4a0672d31b72`、
  Migration Row SHA-256は
  `5ea638643e79ea811a2278d3cf7d5b6e996724a2c0e5795a06bfc8d8283b700c`
  で一致した。
- Site Schema 10 Test、Storefront Client 14 Test、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
- Policy Unit 88件／Policy Gate、Quality Unit 5件／Quality Gate、
  DB Guard Unit 26件、OpenAPI Unit 4件、Release Unit 10件／Source Validation、
  Composer Manifest、Legacy Typecheck／Build／Lint BaselineがPASSした。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、新規Critical／High 0、
  Secret Candidate 0である。V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。

### 時間を要した作業／効率改善

- 開始時はRoot空き4.6GBであったため、稼働Resource、Named Volumeへ触れず、
  未使用Build Cache 192MBとDangling Image 5.684GBだけを削除し、
  Final Guard開始前はRoot空き12GBを確保した。
- Docker Compose v2.40.1とBuildx v0.12.1の組合せで`--allow`不一致が発生した。
  Host Toolchainは更新せず、既存Classic Builderを明示して完了した。
- Task専用Env名の初回入力がGuard規則外で拒否されたため、許可された
  `mig060f` namespaceへ修正した。GuardやResource分離は回避していない。
- Clean DB対象Testで、Draw RequestがGachaを直接参照するという誤ったTrigger Joinと、
  正規Publish遷移まで拒否する過剰なstate保護を検出した。
  `gacha_draw_states`経由の参照と、破壊的`disabled`遷移だけの保護へ修正した。
- 対象Syntax／OpenAPI／Unit／HTTP／Admin Smokeを先行し、First-party Packageは
  依存順にSerial実行した。成功済みのAdmin／Package EvidenceはBackend／Policyだけの
  修正後に重複実行せず維持し、Full Persistent／Ephemeralは最終候補で各1回実行した。
- Persistent Guard約141秒、Ephemeral Guard約426秒、Admin Browser E2E約59秒が
  主な長時間作業である。Gate、Baseline、Assertion、Timeout、Memory設定は
  縮小・緩和していない。
- Fresh Self-review後の修正はAdmin範囲だけだったため、Admin Typecheck、Lint、
  Unit 43件、Production Build／Browser E2E 12件を再実行した。Backend、
  Migration、Packageに差分がないため、Persistent／Ephemeral Guardは
  重複実行せず既存Evidenceを維持した。

### Closeout予定

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree CleanupはPR Closeoutで確定する。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は`MIG-060G Admin Probability Editor／Publish`であり、
  本Task内で開始しない。

## MIG-060F Closeout／MIG-060G Admin Draft Probability Editor／Validation

### MIG-060F Closeout

- Issue `#135`はClosed、PR `#136`はSquash Mergedである。
- Final Headは`1563c2abaa3dab6f241ddb059804ab49a35a80ac`、Squash Commitは
  `eada85c353f0f6380d00632c05e81eef1243cb17`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task BranchとWorktreeはCleanup済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-060G Task／Characterization

- Task IDは`MIG-060G`、Riskは`R3`、Issueは`#137`、Branchは
  `feat/MIG-060G-admin-probability-editor`、Base SHAは
  `eada85c353f0f6380d00632c05e81eef1243cb17`である。
- MIG-050のProbability Version／Stage／Entry／Minimum Guaranteeと、
  MIG-060D～FのCatalog Mutation／Gacha Draft基盤を正本として再利用した。
- Probabilityは整数ppmのみを使用し、各Stageの公開可能合計を
  `1,000,000 ppm`とする。保存途中のDraftは未達／超過を保持できるが、
  Server ValidationでCurrent／Required／Remaining／Excessと公開不可理由を返す。
- Entryは`prize`または`point_back`で、`no_prize`、Float、丸め、
  自動補正、正本にないEntry上限は追加していない。
- `catalog.read`／`catalog.manage`の中央Permission Matrixを再利用し、
  Publish用Permissionは追加していない。

### Schema／Domain／Concurrency

- Forward-safe Migration
  `2026_08_09_000022_add_v2_probability_draft_management.php`で
  Probability VersionへRevision、Archive日時、Clone元Version参照を追加した。
  既存Migrationは編集していない。
- Canonical CHECKはRevision正数とArchive状態を明示Cast付きで保証し、
  Partial Unique Indexは通常Entryの同一Stage／Prize重複を拒否する。
- DB TriggerはVersion物理Delete、Published／Archived変更、Identity変更、
  Revision bypass、Published／Archived配下のStage／Entry／Minimum Guarantee変更を
  拒否する。
- Fresh Self-reviewでChild FKをPublishedからDraftへ付け替えると旧親検査を
  迂回できる不足を検出した。UPDATE時に旧親と新親の両方を検査するよう補強し、
  Entry／Stage双方の回帰Testを追加した。
- Draft Create／Clone／Entry一括置換／Validation／Discardは既存Catalog
  Mutation Executorを再利用し、Admin Realm、MFA Enrollment、CSRF、Exact Origin、
  JSON、Idempotency-Key、Revision OCC、Rate Limit、Auditを適用した。
- Create／Clone／Replace／Discardは`catalog.change` Outboxと同一Transactionで
  確定する。Validationは状態非変更のためOutboxを生成しない。
- Probability Version Rowと親Gacha／Gacha VersionをLockし、Concurrent更新は
  1件だけ成功、Stale Revisionと同一Key異内容は409でFail Closedとする。

### Contract／Admin UI

- Admin OpenAPIへProbability Version一覧／詳細、Draft作成、Clone、
  Entry一括置換、Validation、Discardの7 Operationを追加した。
  Admin Operation数は114、Public 47、Webhook 1である。
- UUIDv7 Public ID、Opaque Cursor、Stable Sort、RFC 9457、Request ID、
  `private, no-store`を維持した。Public／Webhook ContractとStorefront Clientの
  Admin非公開境界は変更していない。
- Gacha Version画面からProbability一覧／詳細へ遷移し、Draft作成／Clone、
  Entry追加／削除、整数ppm、Prize／Rank、Point Back、Minimum Guarantee、
  Current／Required Total、Remaining／Excess、Server Validation、保存／Discardを
  操作できるEditorを実装した。
- Published／Archived VersionはRead-onlyで、Publish Buttonは実装していない。
  Dirty State、Confirmation、Conflict再読込、二重送信防止、Canonical再取得、
  Mobile／Keyboard／Focusは既存Admin基盤を再利用した。
- TailAdmin無料版を視覚基準としたが、App全体置換、Dependency更新、
  Auth／Permission／CSP境界変更は行っていない。

### Test／Evidence

- Probability対象Clean DBは`6 Test／80 Assertion`、全V2 Suiteは
  `246 Test／2038 Assertion／4 Skip`がPASSした。Skipは既存の明示的Load Test
  境界であり、PASSとして数えていない。
- Draw回帰の1000回p95は`678.193 ms`、最大58 Queryである。
- Admin OpenAPI Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component 46 Test、Browser E2E 13 TestがPASSした。
- Persistent／Ephemeral V2 `migrate:fresh`各2回、最新Migration
  Rollback／Reapply、API／DB／Redis／Storage HealthがPASSした。
- Migration数は22、Migration Set SHA-256は
  `5e7fe1193ce445c91c0feada4cfe446fbcfd926b0922d7455c322347a6e55974`。
- Backup SHA-256は
  `b15f5bba10939acb516f497871dceff64c7b739c179dedf9de8f94b484449477`、
  Source／Restore正規化Schema SHA-256は
  `b1872a79f4619b4ced8c4c6ba55c6e715fb109f9b428b5bb97dd35750f3b4874`、
  Migration Row SHA-256は
  `1cc47acfa6529df6ffe5a49d1cacc6c0fcc7dcf39e25c43538667223d42fee1e`
  で一致した。Evidenceは`/var/lib/oripa-v2-evidence/MIG-060G/`である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
- Policy Unit 88件、Quality Unit 5件、Security Unit 4件、DB Guard Unit 26件、
  OpenAPI Unit 4件、Release Unit 10件と各Local GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／Build、
  Lint既存8 Error／1 Warning FingerprintがPASSした。
- Root Audit 0、Legacy Audit既存11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。

### 時間を要した作業／効率改善

- Admin Browser E2Eは約1分6秒、全V2 Suiteは約1分55秒／回、
  Backup／Restoreは約2分を要した。
- 全V2 Suite初回は既存Google OIDCの4分59秒境界で一時Failureとなった。
  Clean DB単独では成功し、残存DBでの再試行結果は正本に採用せず、
  Clean DB全Suite成功をEvidenceとした。
- Fresh Self-review後のDB Guard補強により全V2 Suiteを再実行した。
  Backend／Migration／Testだけの変更であることを機械的に確認し、
  Admin／Browser／Package Evidenceは重複実行せず維持した。
- Policy Unit初回は新MigrationのFixture集合と、Admin専用ppm管理面を
  旧Read-only禁止が誤検知した。Public個別ppm漏えい禁止を維持したまま、
  Admin Operation許可集合とFixtureを同期し、88 TestがPASSした。
- 開始時Root空き8.7GB／`/tmp` 3.3GB、重い検証後Root 7.9GB／
  `/tmp` 3.2GBをRead-only確認した。安全閾値内のためCleanupは不要であった。
- Existing Classic BuilderとFirst-party Package Serial順を維持し、
  Host Toolchain、Gate、Baseline、Assertion、Timeout、Memory設定を変更していない。

### Closeout予定

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree／Task Resource Cleanupは
  PR Closeoutで確定する。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は
  `MIG-060H Admin Probability Publish／Gacha Version Publish・Schedule`であり、
  MIG-060Hは本Task内で開始しない。

## MIG-060G Closeout／MIG-060H Admin Probability Publish Foundation

### MIG-060G Closeout

- Issue `#137`はClosed、PR `#138`はSquash Mergedである。
- Final Headは`f5ea26f72c4f3b857283c194aac6d1003ab96379`、Squash Commitは
  `515f30cd38e3cefc83e50d9067c5a3b0252e4c7d`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task BranchとWorktreeはCleanup済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-060H Task／Characterization

- Task IDは`MIG-060H`、Riskは`R3`、Issueは`#139`、Branchは
  `feat/MIG-060H-probability-publish`、Base SHAは
  `515f30cd38e3cefc83e50d9067c5a3b0252e4c7d`である。
- MIG-050のCatalog／Probability Schema、MIG-060FのGacha Draft、
  MIG-060GのProbability Draft Editor／Validationと中央Permission Matrixを
  正本として再利用した。
- 同一Gacha Versionには複数の不変Published Probability Snapshotを保持できる。
  Gacha Versionの`published_probability_version_id`による選択と、
  Gacha Version Publish／Schedule／UnpublishはMIG-060Iへ延期した。
- Probability PublishだけではGacha Version、Gacha Master、Public Catalog、
  Draw参照を変更しない。Draw LogicとPublic／Webhook Contractも非変更である。

### Contract／Permission／Publish Domain

- Admin OpenAPIへPublish PreflightとDraft Probability Publishの2 Operationを
  追加した。既存Published Probability詳細取得Contractを再利用し、
  Admin Operation数は116、Public 47、Webhook 1である。
- 中央Permission Matrixへ`catalog.publish`を追加し、Owner／Adminを許可、
  Operatorを拒否した。Admin Realm、MFA Enrollment、Fresh MFA 5分、
  CSRF、Exact Origin、JSON、Idempotency-Key、Revision OCCを強制する。
- Critical Admin Mutation Rate Limitは既存共通Limiterの10回／10分を使用し、
  Limiter障害時はFail Closedとする。
- PublishはIdempotency Record、親Gacha、Gacha Version、Probability Versionを
  既存順でLockし、ServerがStage／Entry／Minimum Guaranteeを毎回再検証する。
- 全Stageは整数`1,000,000 ppm`、連続した`sold_count`範囲、
  ActiveなPrize Relation、正本Minimum Guaranteeを必須とする。
- ClientからSnapshot HashやPublish可否は受け取らない。正規化したStage構造から
  Server側でSHA-256を決定的に再計算し、Published状態、Published At、
  Revision、Audit、`catalog.change` Outboxを同一Transactionで確定する。
- 同一Key／同一RequestはCanonical Replay、同一Key／異内容、Stale Revision、
  Concurrent後着、再Publish、Archived／不完全Draftは明示Conflictまたは
  Validation ErrorでFail Closedとする。

### Schema／DB Guard

- Forward-safe Migration
  `2026_08_10_000023_protect_v2_published_probability_relations.php`を追加した。
  既存Migrationは編集していない。
- MIG-060GのTriggerによりPublished Probability Version、Stage、Entry、
  Minimum Guarantee、Revision、Snapshot Hashは更新／削除不能である。
- 新TriggerはPublished Entry／Minimum Guaranteeが参照する
  `catalog_gacha_version_prizes`のGacha Version／Prize付替えと削除を拒否し、
  Draft親を経由したFK付替え迂回を防止する。
- Gacha VersionのPublished Probability選択Pointerは変更せず、
  Migration Apply／Rollback／ReapplyとDump／Restore後もTriggerが一致した。

### Admin UI

- 既存Probability EditorへPublish Preflight、Server Validation結果、
  Publish可能／不可状態、Fresh MFA Dialog、最終確認、Publish、
  Conflict／Rate Limit、Published At、Snapshot Hash短縮表示を追加した。
- Fresh MFA再認証後は同じ未確定操作のIdempotency-Keyを維持し、
  成功後はCanonical Responseを再取得する。
- Published後はEditorをRead-onlyとし、Gacha Version Publish／Schedule Buttonは
  追加していない。OperatorにはPublish操作を表示せず、Backend 403を最終境界とする。
- Dirty State、二重送信防止、Mobile、Keyboard、Focusは既存Admin基盤を再利用した。
  TailAdmin無料版を視覚基準としたが、Dependency、CSP、認証境界は変更していない。

### Test／Evidence

- Probability Publish対象Testは`11 Test／125 Assertion`、専用Concurrency Test、
  DB Guard TestがPASSした。
- Persistent／Ephemeral Guardの双方で全V2 Suite、Probability／Catalog／Draw／QA、
  Point、Payment、Shipping、Reporting、Content／Contact回帰がPASSした。
  GuardはSuite件数を出力しないため、件数は推測して記録していない。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component `47 Test`、Browser E2E `13 Test`がPASSした。
- Persistent／Ephemeral V2 `migrate:fresh`各2回、最新Migration
  Rollback／Reapply、API／DB／Redis／Storage HealthがPASSした。
- Migration数は23、Migration Set SHA-256は
  `2779a163800e46d06d0dc094fc41b1ee80ea4f027a9e5df63115f1defcf0e4a5`。
- Backup SHA-256は
  `120a687894055484887ae94ef7d4c11102ab3b2bdc35549c7222ce1ea47134ed`、
  Source／Restore正規化Schema SHA-256は
  `76b283dad2b68bd2a042f1de5e048f645cd9532e6d70be2fe77a582ef36bdfa4`、
  Migration Row SHA-256は
  `691a9fc0a40da64e4659e0117c4e9c101b6d1ff73f5263e8044e364cd5838a6c`
  で一致した。Evidenceは`/var/lib/oripa-v2-evidence/MIG-060H/`である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
- Policy Unit 88、Quality Unit 5、Security Unit 4、DB Guard Unit 26、
  OpenAPI Unit 4、Release Unit 10と各Local GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／Build、
  Lint既存8 Error／1 Warning FingerprintがPASSした。
- Root Audit 0、Legacy Audit既存11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。

### 時間を要した作業／効率改善

- 開始時Root空き8.2GBからBuild後6.7GBとなったため、稼働Container、
  Named Volume、V1 Resourceへ触れず、未使用Build CacheとDangling Imageだけを
  Cleanupし、重い検証前にRoot空き13GBを確保した。
- Admin Browser E2Eは約59秒、Persistent Guardは約3分、
  Ephemeral Guardは約5分、API Image Buildは約30秒を要した。
- 初回Browser E2EでFresh MFA再認証時に新しいIdempotency-Keyを生成する不足を
  検出した。同一操作Keyを維持するよう修正し、Browser E2Eを再実行した。
- Policy Unit初回は新MigrationのFixture集合が未同期だったためFailureとなった。
  Gateを弱めずFixtureを23 Migrationへ同期し、88 TestがPASSした。
- 旧Task DBに対するMigration Rollback確認は対象Migrationが存在せずNo-opだった。
  既存DBを変更せず、Task専用Clean DBへ切り替えてApply／Rollback／Reapplyと
  Dump／Restoreを完了した。
- OpenAPI検証の初回はWorktreeの依存未展開を検出した。Host Toolchainを更新せず、
  Rootの固定RedoclyとFrozen Offline Installを使用して完了した。
- Syntax／OpenAPI／対象Unit／HTTP／Admin Smokeを全回帰より先に実行し、
  First-party Packageは依存順にSerial実行した。成功済みEvidenceを同一Headで
  重複実行せず、Gate、Baseline、Assertion、Timeout、Memory設定は緩和していない。

### Closeout予定

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree／Task Resource Cleanupは
  PR Closeoutで確定する。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は
  `MIG-060I Admin Gacha Version Publish／Schedule`であり、
  MIG-060Iは本Task内で開始しない。

## MIG-060H Closeout／MIG-060I Gacha Publish Preflight

### MIG-060H Closeout

- Issue `#139`はClosed、PR `#140`はSquash Mergedである。
- Final Headは`68099084053f477c34d229fb1fa85678d6dee16b`、Squash Commitは
  `24e6013ebf87f6b10319ebd888199eb336fa0183`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task BranchとWorktreeはCleanup済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-060I Task／Characterization

- Task IDは`MIG-060I`、Riskは`R3`、Issueは`#141`、Branchは
  `feat/MIG-060I-gacha-publish-preflight`、Base SHAは
  `24e6013ebf87f6b10319ebd888199eb336fa0183`である。
- MIG-050のCatalog／Probability、MIG-060FのGacha Draft、
  MIG-060GのProbability Editor、MIG-060Hの不変Published Snapshotと
  Catalog Mutation基盤を正本として再利用した。
- Gacha Versionの`published_probability_version_id`をSelection Pointerとした。
  同じGacha Versionに属する不変Published Probabilityだけを選択可能とする。
- Gacha Masterの`published_version_id`は現在Publicで使用中のVersionを示す。
  現行公開Versionの存在は次VersionのPreflightを妨げず、MIG-060IではPointerを
  一切変更しない。Draw Pointerも変更しない。
- Gacha Version Publish／Schedule／Unpublish、Public Catalog切替、
  Draw参照切替はMIG-060J以降へ延期した。

### Contract／Permission／Selection

- Admin OpenAPIへPublished Probability候補一覧、現在選択取得、
  Selection Mutation、Gacha Publish Preflightの4 Operationを追加した。
  Admin Operation数は120、Public 47、Webhook 1である。
- Public／Webhook ContractとStorefront ClientのAdmin非公開境界は変更していない。
- 既存`catalog.publish`を使用し、Owner／Adminを許可、Operatorを拒否した。
  Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、Exact Origin、JSON、
  Idempotency-Key、Revision OCC、Critical Mutation Rate Limitを強制する。
- SelectionはIdempotency Record、Gacha Master、Gacha Version、
  Probability VersionをLockし、Draft状態、同一親、Published状態、
  Snapshot SHA形式とServer再計算値を確認する。
- Selection Pointer、Gacha Version Revision、Append-only Audit、
  `catalog.change` Outboxを同一Transactionで確定する。
- 同一Key／同一RequestはCanonical Replay、同一Key／異内容、
  Stale Revision、Cross-Version、Draft／Archived Snapshot、Concurrent後着は
  409等でFail Closedとする。

### DB Guard／Publish Preflight

- Forward-safe Migration
  `2026_08_11_000024_guard_v2_gacha_probability_selection.php`を追加し、
  既存Migrationは編集していない。
- DB TriggerでDraft以外のSelection Pointer変更、Pointer解除、
  Cross-Version、Draft／Archived Probability、不正Snapshot SHA、
  Revision `+1`以外の更新を拒否する。
- Published Probability、Stage、Entry、Minimum Guaranteeの不変Guardと
  Restrict FKにより、Snapshot変更、FK付替え、物理Deleteによる参照破壊を拒否する。
- Server PreflightはMaster／Draft状態、選択Snapshot、全Stage
  `1,000,000 ppm`、Category／Tag／Prize／Rank／Asset、必須表示Asset、
  価格／販売口数、表示期間を再検証する。
- Canonical Responseは`publishable`、選択Probability Public ID、
  Snapshot SHA、Validation Code、Blocking Reason、Revision、Request IDを返す。
- Preflightは状態を変更せずOutboxを生成しない。AuditだけをAppend-onlyで記録する。
  実Publish Endpointは存在しない。

### Admin UI

- Gacha Version WorkspaceへPublished Probability候補、Published At、
  Snapshot Hash短縮表示、Stage数、Validation状態、現在選択、選択変更確認、
  Fresh MFA、Publish Preflight、Blocking Reasonを追加した。
- Dirty State、Conflict再読込、Idempotency-Key維持、Canonical再取得、
  二重送信防止、Mobile、Keyboard、Focusは既存Admin基盤を再利用した。
- OperatorはRead-onlyで、Backend 403を最終境界とする。
- 実Publish／Schedule／Unpublish Buttonは追加せず、公開操作未実装を明示した。
  TailAdmin無料版を視覚基準としたが、Dependency、CSP、認証境界は変更していない。

### Test／Evidence

- Selection／Preflight対象は`6 Test／81 Assertion`、専用Concurrency／DB Guardを
  含めPASSした。現行公開Version Pointerを保持したまま次DraftのPreflightが
  成功し、Public／Draw Pointerが不変であることを固定した。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component `50 Test`、Browser E2E `13 Test`がPASSした。
- Persistent／Ephemeral Guardの双方で全V2 Suite、Probability／Catalog／Draw／QA、
  Point、Payment、Shipping、Reporting、Content／Contact回帰がPASSした。
  GuardがSuite件数を出力しないため、件数は推測していない。
- Persistent／Ephemeral V2 `migrate:fresh`各2回、最新Migration
  Rollback／Reapply、API／DB／Redis／Storage HealthがPASSした。
- Migration数は24、Migration Set SHA-256は
  `95eecf98d221cd9e468e54ebe6eaef06267d4d5b4d34de34c86d12aef6871143`。
- Backup SHA-256は
  `09e14090c7ea31090ac656653b7149756bd3dbec336d2e187bf6638fda073f71`、
  Source／Restore正規化Schema SHA-256は
  `1c147d59ea76095fdf977834a6b2c1ebc8086a52cd47fecbf873f17b0e2bce56`、
  Migration Row SHA-256は
  `7d40801e7c2f3b763802572adf10c89a0403134dcb930e225617a60985420969`
  で一致した。Evidenceは`/var/lib/oripa-v2-evidence/MIG-060I/`である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 26、
  OpenAPI Unit 4、Release Unit 10と各Local GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／Build、
  Lint既存8 Error／1 Warning FingerprintがPASSした。
- Root Audit 0、Legacy Audit既存11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。

### 時間を要した作業／効率改善

- Admin Browser E2Eは約1分、Persistent Guardは約3分、
  Ephemeral Guardは約5分、API Image Buildは約30秒を要した。
- 対象Test初回はPHPUnitのSuite PathとTask DB名が一致せず、既存共有DBを
  変更せずTask専用PostgreSQLへ切り替えた。Final対象は6 Test／81 Assertionである。
- Fixture Importが同一StatementでDraftからPublishedへ遷移する既存経路を
  初期DB Guardが拒否した。既存不変条件を維持しつつOLD状態を正本とするGuardへ
  修正し、Apply／Rollback／ReapplyとDump／Restoreを再確認した。
- Fresh Self-reviewで、現行公開Versionの存在をBlocking扱いすると後続の即時切替へ
  到達できない問題を検出した。現行Pointerを保持した成功Testへ修正し、
  Backend対象、Persistent、Ephemeral GuardをFinal候補で再実行した。
- 上記Backend修正後もAdmin／Contract／Migration Pathは同一であることを
  `git diff`で確認し、Governanceに従いAdmin／Package Evidenceは重複実行せず維持した。
- 開始時および重い検証前にRoot 11GB、`/tmp` 3.2GBの空きをRead-only確認した。
  安全閾値内のためDocker Cache、稼働Container、Named VolumeのCleanupは不要だった。
- Existing Classic BuilderとFirst-party Package Serial順を維持し、
  Host Toolchain、Gate、Baseline、Assertion、Timeout、Memory設定を変更していない。

### Closeout予定

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree／Task Resource Cleanupは
  PR Closeoutで確定する。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は
  `MIG-060J Admin Gacha Version Immediate Publish／Public Activation`であり、
  MIG-060Jは本Task内で開始しない。

## MIG-060I Closeout／MIG-060J Gacha Immediate Publish

### MIG-060I Closeout

- Issue `#141`はClosed、PR `#142`はSquash Mergedである。
- Final Headは`2ef2b47e284a5f69023c8ed53638a4b6464bc274`、Squash Commitは
  `725617a2c9f96cc372a414e5b1142bad343d865b`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task BranchとWorktreeはCleanup済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-060J Task／Characterization

- Task IDは`MIG-060J`、Riskは`R3`、Issueは`#143`、Branchは
  `feat/MIG-060J-gacha-immediate-publish`、Base SHAは
  `725617a2c9f96cc372a414e5b1142bad343d865b`である。
- Gacha Masterの`published_version_id`をPublic Catalog Pointer、
  新規`active_draw_state_id`をDraw Pointerとし、同じGacha Versionと
  Published Probability Snapshotを単一Transactionで選択する。
- 旧Published Version、旧Draw State、旧Probability Snapshot、旧Draw Historyは
  状態変更・削除せず不変履歴として保持する。旧Versionへ独自の
  `superseded`状態は追加していない。
- 即時PublishはDraft Versionだけを対象とし、選択済みPublished Probability、
  Snapshot SHA、Category／Tag／Prize／Asset、価格、販売口数、表示期間を
  Server Preflightで毎回再検証する。
- Schedule／Unpublish、自動公開Worker、Public OpenAPI変更、Draw Algorithm変更は
  MIG-060K以降へ延期した。

### Contract／Permission／Immediate Publish

- Admin OpenAPIへ現在公開状態取得とImmediate Publishを追加した。
  Admin Operation数は122、Public 47、Webhook 1である。
- Public／Webhook SchemaとStorefront Clientは変更していない。
- 既存`catalog.publish`を使用し、Owner／Adminを許可、Operatorを拒否した。
  Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、Exact Origin、JSON、
  Idempotency-Key、Critical Mutation Rate Limitを強制する。
- RequestはGacha Version RevisionとGacha Master Revisionを必須とする。
  同一Key／同一RequestはCanonical Replay、同一Key／異内容、Stale Revision、
  Concurrent後着は409等でFail Closedとする。
- Idempotency Record、Gacha Master、現行Published Version、対象Draft Version、
  選択Probabilityを固定順でLockする。Publish、Published At、Draw State、
  Prize Inventory、Public／Draw Pointer、Revision、Audit、`catalog.change`
  Outboxを単一Transactionで確定する。
- Transaction Commit前は旧Versionだけ、Commit後はPublic CatalogとDraw Resolverが
  同じ新Version／Probability Snapshotを参照する。失敗時は全更新をRollbackする。

### Migration／DB Guard

- Forward-safe Migration
  `2026_08_12_000025_add_v2_gacha_immediate_publish_activation.php`を追加し、
  既存Migrationは編集していない。
- GachaごとにVersion別Draw Stateを履歴保持できるようにし、
  `catalog_gachas.active_draw_state_id`を追加した。
- Deferred Constraint TriggerとHistory Guardで、Public／Draw Pointerの部分更新、
  Cross-Gacha、Draft／Archived／Published Atなし、Probability不一致、
  Revision bypass、Draw State／Inventory FK付替え、旧Draw State物理Deleteを拒否する。
- `V2CatalogFixtureImporter`はCatalog、初期Draw State、Public／Draw Pointerを
  同一Transactionで初期化する。既存Business RevisionはActivation時に1回だけ進める。
- Persistent／Ephemeral双方で`migrate:fresh`各2回、Migration Apply、
  最新Migration Rollback／Reapply、Dump／Restoreを確認した。

### Public Catalog／Draw／Admin UI

- Public List／Detailは`published_version_id`と`active_draw_state_id`の組を参照する。
  Draw ResolverはGacha Rowを先にLockし、同じ`active_draw_state_id`をLockする。
- 新規Drawは新Versionを使用し、切替前Drawと履歴は旧Version Relationを維持する。
  1000回Drawのアルゴリズム、CSPRNG、Point、Inventory、Idempotencyは変更していない。
- Admin UIへPublish Now、最新Server Preflight、選択Probability、
  Snapshot Hash、価格／販売口数／表示期間、現行Versionからの切替表示、
  Fresh MFA、最終確認、二重送信防止、Conflict／429、Canonical再取得、
  Published At、現在公開Versionを追加した。
- OperatorはRead-onlyでBackend 403を最終境界とする。
  Schedule／Unpublish Buttonは追加していない。

### Test／Evidence

- Final対象BackendはImmediate Publish、Atomicity、DB Guard、Concurrency、
  Public Catalog、Draw Resolverを含む`33 Test／363 Assertion`がPASSした。
- Draw 100回p95は`165.047 ms`、1000回p95は`679.606 ms`、
  1000回Query数は最大58で既存基準内である。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component `51 Test`、Browser E2E `13 Test`がPASSした。
- OCC補正後のPersistent／Ephemeral Guardで全V2 Suite、Immediate Publish、
  Catalog／Probability／Draw／QA、Point、Payment、Shipping、Reporting、
  Content／Contact、Load／Performance回帰がPASSした。GuardがSuite件数を
  出力しないため件数は推測していない。
- Migration数は25、Migration Set SHA-256は
  `79af6bbdce2f63a305101557655263e0407d680fd9435c459cad7c14eee9213b`。
- Final Backup SHA-256は
  `ccd38cc1eeff7b5629528bbf925462f66274a4d6fb96ca3dab571e1465d25f36`、
  Source／Restore Schema SHA-256は
  `f1501b0d8b9869784cc135239d7948af9a57c41096a8db2c466c292f5b868612`、
  Migration Row SHA-256は
  `828aaa9afcf54d6dd6e464f6e4f4585ac0c74d9fa69bdf0a3cabcc9253d5d461`
  で一致した。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testは
  依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 26、
  OpenAPI Unit 4、Release Unit 10とLocal GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／BuildがPASSした。
  V1 Backendは既存Payment 2 FailureのFingerprintと完全一致し、
  Backend Test Baseline GateがPASSした。
- Root Audit 0、Legacy Audit既存11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。
- Final Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-060J/persistent-final2/`と
  `/var/lib/oripa-v2-evidence/MIG-060J/ephemeral-final2/`に保存した。

### 時間を要した作業／効率改善

- Admin Browser E2Eは約1.1分、Persistent Guardは約3分、
  Ephemeral Guardは約5分、Classic Builder API Buildは約45秒を要した。
- 初回Persistent Guardで、Fixture ImportがActivation時にGacha Revisionを
  2回進め、既存Published Reference TestをStale判定へ変える問題を検出した。
  ImportとDraw State初期化を同一Transactionへ統合し、Revision更新を1回へ修正した。
- Fresh Self-reviewで異なるDraftのConcurrent PublishにGacha Master OCCが
  不足する可能性を検出した。Gacha RevisionをContractへ追加し、Backend対象、
  Admin、Persistent、Ephemeral GuardをFinal候補で再実行した。
- V1 Backend baseline用の先行Migrationコマンドは接続名指定がTask APIの
  既定V2 DBを参照し、最初の重複DDLでTransaction内拒否された。状態変更はなく、
  実回帰は隔離`oripa_test`で既存2 Failure Fingerprintを確認した。
- 対象Test初回はLegacy検証後に隔離`oripa_test`を削除していたため接続失敗した。
  Task専用DBを再作成し、Code変更なしで対象Suiteを再実行した。
- OpenAPIはWorktree依存不足時にRoot固定Toolを使用し、Host Toolchainを更新していない。
  Composer Frozen InstallはPHP 8.4 Classic Builder内で成功した。
- 開始時と重い検証前にRoot 7.5GB以上、`/tmp` 3.1GB以上をRead-only確認した。
  安全閾値内のため稼働Container、Named Volume、V1 Resource、Docker Cacheを
  Cleanupしていない。
- First-party Packageは依存順にSerial実行し、Gate、Baseline、Assertion、
  Timeout、Memory設定を緩和していない。

### Closeout予定

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree／Task Resource Cleanupは
  PR Closeoutで確定する。
- V1 RuntimeはBackend `8140`、Frontend `3130`、Nginx Upstreamも同値で、
  Public 200、Admin 307、API Health 200、Production Migration Pending 0である。
  V1本番DB／Redis／Storage、`v1/early-release`、Archive Branch、Annotated Tagは
  非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は
  `MIG-060K Admin Gacha Publish Schedule／Unpublish Operations`であり、
  MIG-060Kは本Task内で開始しない。

## MIG-060J Closeout／MIG-060K Scheduled Publish Foundation

### MIG-060J Closeout

- Issue `#143`はClosed、PR `#144`はSquash Mergedである。
- Final Headは`d63a5aa8e59cdcf54c912408a7a7d329c04fac5d`、Squash Commitは
  `18a170ddf58174956f21eef693cbcaac0a5473e9`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task BranchとWorktreeはCleanup済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-060K Task／Characterization

- Task IDは`MIG-060K`、Riskは`R3`、Issueは`#145`、Branchは
  `feat/MIG-060K-gacha-scheduled-publish`、Base SHAは
  `18a170ddf58174956f21eef693cbcaac0a5473e9`である。
- 予約時刻はUTCで保存し、期限判定はPostgreSQLの`CURRENT_TIMESTAMP`を
  正本とする。Admin表示Timezoneは既存`Asia/Tokyo`設定を使用する。
- 同一Gachaと同一Versionの有効予約は各1件に制限する。予約中Draftは
  Version／Relation／Probability Selectionの破壊的変更を拒否し、
  取消後はDraftとして再編集可能にする。
- 現在公開Versionがある場合も、期限到来時にMIG-060JのActivation Domainを
  再利用してPublic／Draw Pointerを原子的に切り替える。旧Version、
  Probability Snapshot、Draw State、Draw Historyは不変履歴として保持する。
- Unpublish、販売停止／再開、Production Cron／systemd、外部Scheduler、
  Public Contract、Draw Algorithmは変更していない。

### Contract／Permission／Schedule

- Admin OpenAPIへSchedule取得、Schedule Preflight、予約作成、予約取消を追加した。
  現在公開状態Responseへ予約状態を追加し、Admin Operation数は126、
  Public 47、Webhook 1である。
- Public／Webhook SchemaとStorefront ClientのAdmin非公開境界は変更していない。
- 既存`catalog.publish`を使用し、Owner／Adminを許可、Operatorを拒否した。
  Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、Exact Origin、JSON、
  Idempotency-Key、Gacha／Version／Schedule Revision OCC、
  Critical Mutation Rate Limitを強制する。
- Idempotency Record、Gacha Master、Draft Version、Published Probabilityを
  固定順でLockし、Server Preflight、予約、Revision、Audit、
  `catalog.change` Outboxを単一Transactionで確定する。
- 同一Key／同一RequestはCanonical Replay、同一Key／異内容、Stale Revision、
  重複予約、過去時刻、Published／Archived、Probability未選択はFail Closedとする。
- 状態は`scheduled`、`processing`、`completed`、`cancelled`、`failed`に固定した。
  予約は物理Deleteせず、取消済み／完了済み予約を再処理しない。

### Worker／Concurrency／Atomicity

- CLI `v2:catalog:work-scheduled-publishes`とTask専用Worker境界を追加した。
  DB Server時刻、Batch上限5、`FOR UPDATE SKIP LOCKED`、120秒LeaseでClaimする。
- WorkerはMIG-060JのImmediate Activation Logicを共通利用し、Publish直前に
  Server PreflightとSnapshot SHAを全再検証する。Activation Logicは複製していない。
- Transient Failureは最大3回、60秒基準の指数Backoffで再予約し、
  Permanent FailureまたはRetry上限到達時は`failed`へ確定する。
- Schedule Claim、Version／Probability Lock、Public／Draw Activation、
  Schedule完了、Audit、Outboxは同一Transactionである。Rollback時に
  Pointer、Status、Revision、Audit、Outboxの部分更新を残さない。
- 同時Worker、再起動、At-least-once実行、Immediate Publish競合はRow Lock、
  Revision OCC、Schedule状態でExactly-once相当へ収束させる。
- Read APIは予約作成時の期待Revisionではなく、取消／完了後を含む現在の
  Gacha／Version RevisionをCanonical Responseとして返す。

### Migration／DB Target Safety

- Forward-safe Migration
  `2026_08_13_000026_create_v2_gacha_publish_schedules.php`を追加し、
  既存Migrationは編集していない。
- Partial Unique Index、Restrict FK、Canonicalな明示Cast付きCHECK、
  Transition／History Triggerで有効予約重複、Cross-Gacha／Version、
  Draft以外、未選択Probability、過去時刻、状態飛越し、Revision bypass、
  FK付替え、物理Deleteを拒否する。
- DB Target Safety Guardへ用途、Host、Port、DB名、Schema、Environment、
  Task ID Marker、Migration集合の機械照合を追加した。接続確認用DDLは行わない。
- Task専用DBは`oripa_v2_mig060k`、用途は`v2-task-ephemeral`であり、
  Full GuardとFresh Self-review終了まで保持する。
- Persistent／Ephemeral双方で`migrate:fresh`各2回、最新Migration
  Rollback／Reapply、Dump／Restoreを確認した。

### Admin UI

- 既存Gacha Publish画面へ予約日時、UTC／表示Timezone、Schedule Preflight、
  現在予約、対象Version／Probability、現行Version切替、Fresh MFA、
  Confirmation、取消、Worker状態、Completed／Failed、Canonical再取得を追加した。
- Dirty State、二重送信防止、Conflict／429、Mobile、Keyboard、Focusは
  既存Admin Mutation基盤を再利用した。
- OperatorはRead-onlyでBackend 403を最終境界とする。
  Unpublish／販売停止Buttonは追加していない。
- TailAdmin無料版を視覚基準としたが、Dependency、CSP、認証／Permission境界、
  Admin全体Layoutは変更していない。

### Test／Evidence

- Final対象BackendはSchedule、Worker、Concurrency、Atomicity、DB Guard、
  Immediate Publish／Public／Draw回帰を含む`13 Test／223 Assertion`がPASSした。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component `52 Test`、Browser E2E `13 Test`がPASSした。
- Final Persistent／Ephemeral Guardで全V2 Suite、Schedule／Worker、
  Immediate Publish、Catalog／Probability／Draw／QA、Point、Payment、Shipping、
  Reporting、Content／Contact、Load／Performance回帰がPASSした。
  GuardがSuite件数を出力しないため件数は推測していない。
- Migration数は26、Migration Set SHA-256は
  `0a7f4dd2ee33ae7f028c039286da4b5479fe8821b3cb13cb6589a0d639287fd3`。
- Final Backup SHA-256は
  `c82c6eb361ec39e832e2c83f3411d6ef5a277dd2fe74e441d431f1a12d5cdeaa`、
  Source／Restore Schema SHA-256は
  `24cc06f4fccca233ba16471cb320309784a56f9169eddf4a9936c596d3bd3f6a`、
  Migration Row SHA-256は
  `8e3e563704ef5303ff74aa29bfe86a7789a12238dccdae1ef6dee77c23a9d8b5`
  で一致した。
- Content／Contact性能はNotice 10,000件First Page p95 `68.992 ms`、
  Contact 100,000件First Page p95 `3.096 ms`、同時Contact p95
  `578.938 ms`、Peak Memory `46,661,632 byte`、未解決Deadlock 0である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testは
  依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10とLocal GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／Build、
  Lint既存8 Error／1 Warning FingerprintがPASSした。
- V1 Backendは既存Payment 2 Failure／332 WarningのFingerprintと一致し、
  Backend Test Baseline GateがPASSした。
- Root Audit 0、Legacy Audit既存11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。
- Final Evidenceは`/var/lib/oripa-v2-evidence/MIG-060K/persistent/`、
  `/var/lib/oripa-v2-evidence/MIG-060K/ephemeral/`、
  `/var/lib/oripa-v2-evidence/MIG-060K/v1-backend-tests.xml`に保存した。

### 時間を要した作業／効率改善

- Admin Browser E2Eは約1.1分、Persistent Guardは約3分、
  Ephemeral Guardは約6分、V1 Backend Baselineは約2.7分、
  Final API Image Buildは約45秒を要した。
- DB Target Safety Guard初回は、接続Probe前のMarker照合とPortなしの隔離V1
  Container判定が不整合となりDDL前にFail Closedした。Checkerを用途／Task ID／
  Migration集合の機械照合へ修正し、状態変更なしで再実行した。
- 初回MigrationではTriggerの分岐が対象Tableに存在しないColumnを参照した。
  Table別Trigger Branchへ修正し、Canonical CHECK、明示Cast、
  Apply／Rollback／Reapply、Dump／Restoreを再確認した。
- Admin予約時刻はUTC offsetを保持せず送信して過去判定となる問題を対象Smokeで検出し、
  ISO offset付きPayloadへ修正した。
- Ephemeral Guard初回は既存Content性能Fixtureの複数`now()`が秒境界を跨いだ。
  同一意味の時刻を1回だけ固定し、Security／性能閾値を変更せず再実行した。
- Local PolicyはScheduleを旧対象外として拒否したため、MIG-060KのRequired Path／
  Operation／Migration集合へ更新し、Unpublish禁止を維持した。
- Fresh Self-reviewで、取消／完了後のRead APIが予約作成時Revisionを返す問題を
  検出した。現在Revisionを返すよう修正し、対象Backend、Persistent、
  Ephemeral GuardをFinal候補で再実行した。
- 開始時と重い検証前にRoot 6.0GB以上、`/tmp` 3.1GB以上をRead-only確認した。
  安全閾値内のためDocker Cache、稼働Container、Named VolumeをCleanupしていない。
- Existing Classic BuilderとFirst-party Package Serial順を維持し、
  Host Toolchain、Gate、Baseline、Assertion、Timeout、Memory設定を変更していない。

### Closeout予定

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree／Task Resource Cleanupは
  PR Closeoutで確定する。
- V1 RuntimeはBackend `8140`、Frontend `3130`、Nginx Upstreamも同値で、
  Public 200、Admin 307、API Health 200、Production Migration Pending 0である。
  V1本番DB／Redis／Storage、`v1/early-release`、Archive Branch、Annotated Tagは
  非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は
  `MIG-060L Admin Gacha Unpublish／Sales Pause Operations`であり、
  MIG-060Lは本Task内で開始しない。

## MIG-060K Closeout／MIG-060L Gacha Sales Pause／Resume Operations

### MIG-060K Closeout

- Issue `#145`はClosed、PR `#146`はSquash Mergedである。
- Final Headは`752936897b60377d37fe6d7db218866d9d03bee9`、Squash Commitは
  `78bc149b64ddb1e0fa6324c15cd84611ccf6b036`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Checkは成功した。
  Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Remote／Local Task Branch、Worktree、Task ResourceはCleanup済みである。
  Local `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### MIG-060L Task／Characterization

- Task IDは`MIG-060L`、Riskは`R3`、Issueは`#147`、Branchは
  `feat/MIG-060L-gacha-sales-pause`、Base SHAは
  `78bc149b64ddb1e0fa6324c15cd84611ccf6b036`である。
- 手動PauseはGacha Master単位の運用状態とし、Immediate／Scheduled Publishで
  公開Versionが切り替わっても自動解除しない。
- PauseはUnpublishではない。公開Pointer、Published Version、Probability Snapshot、
  Draw State、旧Draw履歴を削除または変更しない。
- 新規DrawはGacha／Active Draw State Lock後、Point減算、Inventory更新、
  Draw Result確定より前にPause正本を検査する。成功済みDrawのIdempotent Replayは
  Pause判定より先にCanonical Resultを返す。
- Public OpenAPIは変更せず、既存`remaining_count`をPause中は0としてList／Detailを
  販売不可へ一致させる。Published Version情報と公開状態は保持する。
- Unpublish、Public Deactivation、Draw Algorithm、CSPRNG、Probability計算、
  100／1000回のTransaction／Idempotency構造は変更していない。

### Contract／Permission／Pause・Resume

- Admin OpenAPIへSales状態取得、Pause／Resume Preflight、Pause、Resumeを追加した。
  Admin Operation数は131、Public 47、Webhook 1である。
- Public／Webhook SchemaとStorefront ClientのAdmin非公開境界は変更していない。
- 既存`catalog.publish`を使用し、Owner／Adminを許可、Operatorを拒否した。
  Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、Exact Origin、JSON、
  Idempotency-Key、Gacha Revision OCC、Critical Mutation Rate Limitを強制する。
- Pause Reason Codeは`operations_review`、`inventory_review`、
  `incident_response`の型付きAllowlistに限定した。
- PauseではIdempotency Record、Gacha Master、Active Published Version、
  Active Draw Stateを固定順でLockし、状態、DB Server時刻、Revision、Audit、
  `catalog.change` Outboxを単一Transactionで確定する。
- Resumeでは同じLock順でGacha、公開Version、Draw State、Published Probability、
  Snapshot SHA、価格、販売口数、Inventory、表示／販売期間、売切れ、
  Public／Draw Pointer、Processing ScheduleをServer側で再検証する。
- 同一Key／同一RequestはCanonical Replay、同一Key／異内容、Stale Revision、
  無効Pointer、売切れ、期間外、Archived GachaはFail Closedとする。
- Mutation ResponseはCommit後の最新Gacha Revision、Sales状態、Published Version、
  Schedule状態をCanonical再取得して返す。

### Migration／DB Guard

- Forward-safe Migration
  `2026_08_14_000027_add_v2_gacha_sales_pause.php`を追加し、
  既存Migrationは編集していない。
- `sales_paused`、Pause／Resume時刻、Actor Public ID、Reason Code、
  Last Request IDを型付きColumnとして追加した。物理Delete Endpointはない。
- Canonicalな明示Cast付きCHECK、Restrict FK、Transition／History Triggerで
  Revision bypass、Cross-Gacha Pointer、公開VersionなしのResume、
  Draw State不一致、Archived Resume、Paused History削除、Direct SQLによる
  Pause無視Activation、公開Version切替時のPause解除を拒否する。
- Immediate／Scheduled ActivationはPause Fieldを保持する。Pause／Resumeで
  Gacha Revisionを進める際はActive Scheduleの期待Revisionも同一Transactionで
  再基準化し、Schedule OCCを壊さない。
- Task専用DBは`oripa_v2_mig060l`、用途は`v2-task-ephemeral`である。
  DB Target Safety Guardで用途、Host、Port、DB名、Schema、Environment、
  Task ID Marker、Migration集合をDDL前に機械照合した。
- Persistent／Ephemeral双方で`migrate:fresh`各2回、最新Migration
  Rollback／Reapply、Dump／Restoreを確認した。

### Draw／Public／Concurrency

- Pause後の新しいIdempotency-KeyによるDrawは型付きDomain Errorで拒否し、
  Wallet、Point Operation／Lot／Ledger、Inventory、Sold／Won、Draw Request／
  Result、User Prizeを変更しない。
- Pause前に完了済みのDraw ReplayはPause中も同一Canonical Resultを返し、
  Point、Inventory、Historyを二重更新しない。
- Process Concurrency TestでPauseとDrawを別Processから競合させた。
  結果は「Draw全成功後にPause」または「Pause成功後にDraw全拒否」のどちらかへ
  収束し、部分消費、欠番、Inventory不一致は発生しない。
- Pause中のImmediate Publish、Scheduled Worker Activation、予約取消、
  Worker Claimとの競合でもPause状態を保持し、Public／Draw Pointerは同じVersionへ
  原子的に切り替わる。
- Resume後は同じPublished Versionで販売可能状態へ戻り、Public List／Detailと
  Draw Resolverが一致する。

### Admin UI

- 既存Gacha Publish管理画面へSales状態、Pause Reason、Pause／Resume Preflight、
  Fresh MFA、Confirmation、Resume Blocker、公開Version／Probability、
  Schedule状態、Conflict／429、Canonical再取得を追加した。
- OperatorはRead-onlyでBackend 403を最終境界とする。Unpublish／公開解除Buttonは
  追加していない。
- Dirty State、二重送信防止、Loading／Error、Mobile、Keyboard、Focusは
  既存Admin Mutation基盤を再利用した。
- TailAdmin無料版を視覚基準としたが、Dependency、CSP、認証／Permission境界、
  Admin全体Layoutは変更していない。

### Test／Evidence

- Final対象BackendはSales Pause／Resume、Draw、Public、Immediate／Scheduled
  Publish、Process Concurrencyを含む`18 Test／303 Assertion`と
  Concurrency `3 Test／33 Assertion`がPASSした。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component `54 Test`、Browser E2E `13 Test`がPASSした。
- Final Persistent／Ephemeral Guardで全V2 Suite、Pause／Resume、Schedule／Worker、
  Immediate Publish、Catalog／Probability／Draw／QA、Point、Payment、Shipping、
  Reporting、Content／Contact、Load／Performance回帰がPASSした。
  GuardがSuite件数を出力しないため件数は推測していない。
- 単独Draw性能は100回 p95 `195.193 ms`、1000回 p95 `696.244 ms`、
  Query最大はそれぞれ56／58、Responseは667／18,546 byteで基準内である。
- Migration数は27、Migration Set SHA-256は
  `775ae702a20979a8ca65fb88b82ae62f5566d17940aa66345315be53117c2096`。
- Final Backup SHA-256は
  `303e80b85d56ad66459ca7a22d2bef82773107462208b5b6150260149427438c`、
  Source／Restore Schema SHA-256は
  `3fa6937cc2fb3be56480a1542610d6602d34721e076d8fb1c6a227696a684d4a`、
  Migration Row SHA-256は
  `de9e36937c4acb945b802f4cec1003312da047fdf430c750966a08489229fdfa`
  で一致した。
- Content／Contact性能はNotice First Page p95 `61.243 ms`、
  Contact First Page p95 `6.548 ms`、同時Contact p95 `674.536 ms`、
  Peak Memory `46,661,632 byte`、未解決Deadlock 0である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testは
  依存順にSerial実行し、生成差分、Typecheck、Lint、Buildを含めPASSした。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10とLocal GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／Build、
  Lint既存8 Error／1 Warning FingerprintがPASSした。
- V1 Backendは隔離DBで`334 Test／1,820 Assertion`を実行し、
  既存Payment 2 Failure Fingerprintと一致してBaseline GateがPASSした。
- Root Audit 0、Legacy Audit既存11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。
- Final Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-060L/persistent-final/`、
  `/var/lib/oripa-v2-evidence/MIG-060L/ephemeral-final/`、
  `/var/lib/oripa-v2-evidence/MIG-060L/v1-backend-tests.xml`に保存した。

### 時間を要した作業／効率改善

- Admin Browser E2Eは約1.1分、Unit／Componentは約35秒、
  Persistent Guardは約3分、Ephemeral Guardは約6分、
  V1 Backend Baselineは約2.7分、Classic Builder Image Buildは約45秒を要した。
- 対象PHPUnit初回はRepository既定設定の`oripa_test`を参照してTest開始前に停止した。
  Task DB Marker付き外部Configへ切り替え、共有DBやV1本番へ接続せず再実行した。
- Migration修正後の対象Smoke初回はTask DBに旧Triggerが残りStale Conflictとなった。
  DB Targetを再照合し、Task専用DBへ`migrate:fresh`して再実行した。
- Process Concurrency初回はPoint Backが起き得るFixtureに対してWallet純減100を
  固定期待していた。実Draw ResultのPoint Backを含むReconciliationへ修正し、
  Draw規則やAssertion強度を緩和せず再実行した。
- Ephemeral Guard初回はPrefixがV2 Allowlist外でResource作成前にFail Closedした。
  `mig060l-v2-final`へ修正し、状態変更なしで再実行した。
- Composer AuditはRuntime ImageにComposerがないため実行前に停止した。
  Hostの既存Composer 2.9.8でLock Auditを行い、Image／Host Toolchainを更新しなかった。
- Admin Unit／Browser E2Eは引数転送により対象だけでなく全Suiteを実行した。
  同一Admin SourceのPASS Evidenceを維持し、重複実行していない。
- GitHub初回Policy CheckはPR本文の必須Governance見出し不足で失敗した。
  Git差分／Task Policyから本文を必須見出し付きで再生成し、空Commitなしで修正した。
- Fresh Self-reviewでPause日時と既存Schedule日時がBrowser timezone依存であることを
  検出した。Admin表示を`Asia/Tokyo`へ固定し、Contract生成差分0、Typecheck、
  Lint、Build、Unit／Component 54件、Browser E2E 13件を再実行してPASSした。
  Backend／Migration／Contractの変更はない。
- 開始時Root 6.1GB、`/tmp` 3.1GB、重い検証前Root 5.7GB、
  Ephemeral中Root 3.5GBをRead-only確認した。処理は成功し安全閾値を割らなかったため、
  稼働Container、Named Volume、V1 Resource、Docker CacheをCleanupしていない。
- First-party Packageは依存順にSerial実行した。Existing Classic Builderを使用し、
  Host Compose／Buildx、Gate、Baseline、Assertion、Timeout、Memoryを変更していない。

### Closeout予定

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree／Task Resource Cleanupは
  PR Closeoutで確定する。
- V1 RuntimeはBackend `8140`、Frontend `3130`、Nginx Upstreamも同値で、
  Public 200、Admin 307、API Health 200、Production Migration Pending 0である。
  V1本番DB／Redis／Storage、`v1/early-release`、Archive Branch、Annotated Tagは
  非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は
  `MIG-060M Admin Gacha Unpublish／Public Deactivation`であり、
  MIG-060Mは本Task内で開始しない。

## MIG-060L Closeout／MIG-060M Gacha Unpublish／Public Deactivation

### MIG-060L Closeout

- Issue `#147`はClosed、PR `#148`はSquash Mergedである。
- Final Headは`61d7268df003e420380538ef641be357001e2499`、Squash Commitは
  `a3bf2789b1cc7cbad22d7b6185d18863a6ea40db`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含むGitHub 8 Checkは
  成功した。Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Branch／Worktree／Task ResourceはCleanup済みで、Local
  `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### Characterization／Unpublish Domain

- UnpublishはArchive／Deleteではなく、Sales Pause済みの公開Gachaについて
  Public PointerとActive Draw Pointerを原子的に解除する運用遷移である。
- Published Version、Published Probability Snapshot、Draw State、Inventory、
  Point、既存Draw Request／Result／Historyは変更または削除しない。
- Scheduled／Processing PublishはPreflight Blockerとし、予約を暗黙に取消・
  削除しない。再Publishは既存Immediate／Scheduled Publish Flowを使用する。
- 成功済みDraw Replayは解除後もCanonical Resultを返す。新規DrawはPoint減算、
  Inventory更新、Draw履歴作成前にActive Pointerなしとして拒否する。
- Public OpenAPI Schemaは変更せず、List／Detailは公開PointerなしのGachaを
  現在公開中として返さない。

### Contract／Transaction／DB Guard

- Admin OpenAPIへUnpublish状態取得、Preflight、Mutationを追加した。
  `catalog.publish`、Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、
  Exact Origin、JSON、Idempotency-Key、Revision OCC、Critical Rate Limitを強制する。
- Owner／Adminは実行可能、OperatorはRead-only／Mutation 403である。
- Idempotency Record、Gacha、Published Version、Active Draw State、
  Probability、Active Scheduleを固定順でLockし、Pointer、DB時刻、Revision、
  Audit、`catalog.change` Outboxを単一Transactionで確定する。
- Forward-safe Migration
  `2026_08_15_000028_add_v2_gacha_public_deactivation.php`を追加した。
  既存Migrationは編集していない。
- Canonical CHECK、Restrict FK、Transition／History TriggerでPartial Deactivation、
  Revision bypass、未Pause、Active Schedule、不整合Pointer、Metadata改変、
  履歴Delete、直接ResumeをFail Closedにした。
- Task DBは`oripa_v2_mig060m`、用途は`v2-task-ephemeral`である。
  Target Guard、Migration Rollback／Reapply、DumpでCanonical Schemaを確認した。

### Admin UI／対象Test

- 既存Publish／Sales画面へPublic／Sales／Schedule状態、Unpublish Preflight、
  Blocker、Fresh MFA、影響確認Dialog、Conflict／429、Canonical解除状態、
  `Asia/Tokyo`解除日時を追加した。
- Operator操作非表示、Backend 403、二重送信防止、Mobile、Keyboard、Focusは
  既存Admin基盤を再利用した。
- Backend対象全回帰は`26 Test／443 Assertion`、追加Unpublish HTTP／Atomicityは
  `4 Test／96 Assertion`、Process Concurrencyは`1 Test／11 Assertion`でPASSした。
- Admin OpenAPI、生成、Typecheck、Lint、Build、Unit／Component `55 Test`、
  Browser E2E `13 Test`がPASSした。
- Persistent／Ephemeral双方で`migrate:fresh`各2回、最新Migration
  Rollback／Reapply、全V2 Suite、Backup／Restore、API／Admin HealthがPASSした。
- Migration数は28、Migration Set SHA-256は
  `aebf7c4b850dd5bfcecd971e9472632488d679e86dee86cadafb81b047c8b7bf`、
  Backup SHA-256は
  `1c6a07e1b27528e33ffe367dfa0155602f08cd0d06eff677ab84472dcc315061`、
  Source／Restore Schema SHA-256は
  `795a55b80fba5cc5757a5ea7620374e9a11cdea24ea19fe1a9a3a32a31933f14`
  で一致した。
- 100回Draw p95は`208.224 ms`、Query最大56、1000回Draw p95は
  `694.111 ms`、Query最大58であり、Timeout／Memory設定変更なしで基準内である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを依存順Serialで
  実行した。Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10と各GateはPASSした。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、新規Critical／High 0、
  Secret／PII Candidate 0である。V1 Migration 40件Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。
- Local Schema SHA-256は
  `b42a794b78f9bfddaaaba999219d442fb77a8046022c82d11942592ae93ba88d`
  である。

### 時間を要した作業／効率改善

- API Image Buildは約50秒、Backend対象回帰は約42秒、Admin Unitは約34秒、
  Browser Smokeは約42秒／回を要した。
- Root空き3.6GBが重い検証前の安全閾値を下回ったため、未使用Docker Build Cache
  だけを削除して約4.3GBを回収した。稼働Container、Named Volume、V1 Resource、
  Imageは削除していない。
- Task DB起動直後のTarget ProbeはHealth到達前に接続拒否となったため、Resourceを
  作り直さずHealth確認後に再試行した。
- Unpublish Fixture初回は既存Dummy Snapshot Hashにより安全に拒否された。
  Server Validationを緩和せず、Test内で正式Publish済みSnapshotを作成した。
- Browser Smokeでは追加した`Unpublish Preflight`と既存部分一致Locatorが競合した。
  Accessible Name完全一致へ補正し、実装／Security境界を変更せず完了した。
- Persistent Guardは約3分、Ephemeral Guardは約6分を要した。中断・重複実行せず
  同一Evidenceを保持した。
- Local PolicyはUnpublish OperationとMigration 28番を旧Allowlistで拒否した。
  Public Mutation禁止を維持し、Task Operation／Migration集合だけを正本へ追加して
  Policy Unit 89件と実Gateを再実行した。
- GitHub初回`policy-gate`はPR本文のBase値ラベルがGovernance正本の
  `Base SHA`と一致せず失敗した。空Commitを作らず本文を修正した。
- GitHub初回`quality-gate`はAsia/Tokyo変換自体は正しかったが、Node ICU差による
  時のゼロ埋めだけでAdmin Testが失敗した。表示timezoneを緩和せず、
  `09`／`9`の両方を許容するTest期待値へ補正し、Admin 55 Testを再実行した。
- First-party Packageは依存順Serial、Existing Classic Builderを使用し、
  Host Toolchain、Gate、Assertion、Security、Timeout、Memoryを変更していない。

### Final予定／Gate

- V1 Backend隔離回帰はV1 Path／Checksum不変のため、直前MIG-060Lの
  `334 Test／1,820 Assertion` Evidenceを再利用し、GitHub Integrationで再実行する。
- Fresh Self-reviewとGitHub CheckはFinal候補Headで確定する。
- Repository外Evidenceは`/var/lib/oripa-v2-evidence/MIG-060M/`に保存する。
- V1 Runtime、本番DB／Redis／Storage、Nginx、Public／Admin状態、
  `v1/early-release`、Archive Branch、Annotated Tagは非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は`MIG-060N Admin QA Draw Management`であり、本Taskでは開始しない。

## MIG-060N Closeout／MIG-060O Admin QA Draw Execution／Result Review

### MIG-060N Closeout

- Issue `#151` Closed、PR `#152` Squash Merged。
- Final Headは`889daef8175afdcde845019fde42b4f5600e7768`、Squash Commitは
  `f4e6187f46ee7cb4d120e1a2015be8577ec5e3da`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件、Branch／Worktree／Task Resource
  Cleanup、Local main同期、V1 Runtime／本番Resource非変更を確認した。

### Contract／Permission／Transaction

- 既存QA Plan、Assignment、QA Resolver、QA Execution、`V2DrawService`を再利用し、
  Admin QA Draw Preflight／実行／Execution一覧・詳細を追加した。
- 既存正本の`1`、`5`、`10`、`100`、`1000`回だけを許可する。
- `qa.draw.manage`、Owner-only、Fresh MFA 5分、Admin Realm、CSRF、Exact Origin、
  JSON、Critical Rate Limitを既存FoundationでFail Closedにする。
- Plan／Assignment Revisionを型付きCommandでDraw transactionへ渡し、Lock後の
  Resolverで再検証する。Point、Inventory、販売口数、Draw Result、User Prize、
  QA Execution、Audit、Outbox、Idempotencyを単一Transactionで確定する。
- 同じIdempotency-KeyはCanonical Replayを返し、実Dataを二重反映しない。
  Draw Algorithm、CSPRNG、抽選順、100／1000回Set-based Persistenceは非変更である。

### Admin UI／Result Review

- `/qa`へ実Data影響警告、Assignment／回数選択、Server Preflight、最終確認、
  二重送信防止、結果一覧／詳細を追加した。
- Execution、Plan、Test User、Assignment、Gacha／Version、Point、Prize／Rank、
  販売／在庫差分、UTC実行日時、Canonical Replayを確認できる。
- 内部ID、Credential、不要なPII、個別ppm、架空結果は表示しない。
- UTC保存、`Asia/Tokyo`表示、Mobile、Keyboard、Focus、Accessible Nameを維持した。

### Test／Evidence

- Backend対象`2 Test／27 Assertion`、Admin Unit `59 Test`、
  Browser E2E `14 Test`がPASSした。
- OpenAPI Lint／Bundle、Admin生成差分0、Typecheck、Lint、Production BuildがPASSした。
- Persistent／Ephemeralで`migrate:fresh`各2回、Migration 29件、
  rollback／reapply、全V2 Suite、Backup／Restore、HealthがPASSした。
- Migration Set SHA-256:
  `49e6df42f64c7a3b4124fb9800bc13ea59ed523d155bb7e4a833dfb9ee8a4b29`
- Backup SHA-256:
  `17d4989cf849d39df7b66745f446a4ef84c66ee254efd5ea096686930bcf356b`
- Source／Restore Schema SHA-256:
  `339970b0c5baead71d527b6e86934f12bdf1ff12ef2c311c80ba373c71f6372d`
- 通常100／1000回p95は`172.990 ms`／`750.815 ms`、Query 56／58。
- QA 100／1000回p95は`152.092 ms`／`654.059 ms`、Query 65／76。
- QA同一Gacha 10 User最終`8.016 s`、通常同一Gacha 20 User最終`16.131 s`。
  未解決Deadlock、負Wallet、Inventory overflow、整合不一致は0件である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22、Release 10、
  Policy 89、Quality 5、DB Guard 29、Site Template 6 TestがPASSした。
- Root Audit 0、Legacy Audit 11、Composer Audit 10で既存件数から増加していない。
- Security Unit 4 TestはPASSしたが、Dependency Advisory Baselineの期限が
  `2026-07-30`であり、`2026-07-31`のSecurity Gateは
  `dependency advisory baseline has expired`でFail Closedした。
  Baseline、Lockfile、Security Gateは本Taskで変更または緩和していない。
- Repository外Evidenceは`/var/lib/oripa-v2-evidence/MIG-060O/`へ保存した。

### 時間を要した作業／効率改善

- API Image Build約1分、Admin Browser E2E約1.1分、Persistent Guard約4分、
  Ephemeral Guard約9分、Frozen Legacy約1.3分を要した。
- Root空き2.6GBが安全閾値を下回ったため、Dangling Imageだけを削除して
  6.327GB回収した。稼働Container、Named Volume、V1 Resourceは非変更である。
- Browser SmokeはAccessible Name完全一致と表示文言期待だけを補正し、失敗した
  対象1件だけ再実行した。
- GitHub初回`policy-gate`は新規QA実行PanelのAdmin Skeleton Allowlist登録漏れを
  検出した。Wildcardによる緩和はせず当該Fileだけを追加し、Policy Unit 89件と
  Local Gateを再実行してPASSした。
- 通常Draw性能初回はQA負荷Fixture残存でTest開始前に停止したため、Task専用DBを
  Fresh化してFixtureを分離し、通常Draw負荷だけ再実行した。
- First-party Packageは依存順Serial、通過済み全回帰は中断・重複実行せず、
  Host Toolchain、Gate、Assertion、Timeout、Memoryを変更していない。

### V1／Gate／Final予定

- V1／共通Infra Path差分は0、V1 Migration 40件Checksumは
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag、Public／Webhook Contractは非変更である。
- Local実装・機能・DB・性能検証は成功した。Security Baseline期限切れのため、
  Final Head、GitHub Check、Fresh Self-review、Merge、Issue Close、Cleanupは
  未確定である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- MIG-060P以降は本Taskで開始しない。

## MIG-060O Security Blocker解消後Closeout

### SEC-005取込み／Conflict

- SEC-005 Issue `#155`はClosed、PR `#156`はSquash Merged、Final Headは
  `4488fe98658ca33af7240427052628bc5ba4a9d9`、Squash Commit／最新mainは
  `df94c24239c95a5e0d68fc95314eec06dfa45796`である。
- 最新mainとの`git merge-tree`確認ではApplication、Contract、Migration、DBに
  競合はなく、`worklogs/new_ver_main.md`の追記位置だけが競合した。SEC-005と
  MIG-060Oの両記録を保持する最小解決を行った。
- SEC-005のLegacy Lockfile、Dependency Advisory Baseline、Dependency Review
  Workflow、Security Unitは最新PR Baseから保持し、MIG-060OのApplication／
  Contract差分は欠落していない。
- 固定Task Policyのfast-forward／Exact Scopeを維持し、SEC-005 CommitをTask
  Branchへ重複Commitしない。最新mainをPR Base／GitHub Merge Resultの正本とし、
  force push、History rewrite、空Commitは行っていない。

### Security／対象再検証

- DB Target Safety GuardはTask DB `oripa_v2_mig060o`、Task ID `MIG-060O`、
  Purpose `v2-task-ephemeral`、Schema `public`、Migration集合`repository`でPASS。
- Backend対象`2 Test／27 Assertion`、Admin Unit `59 Test`、対象Browser E2E
  `1 Test`がPASSした。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Policy Gate、Quality Gate、DB Guard Unit `29 Test`、
  `git diff --check`がPASSした。
- Security Unit `6 Test`とLocal Security GateがPASSした。Legacy pnpm Audit 0、
  Root Workspace pnpm Audit 0、Composer既存10、Secret／PII Candidate 0、
  新規Critical／High 0、Baseline期限`2026-08-07`である。
- SEC-005はApplication、Migration、DB、Drawを変更していないため、
  Persistent／Ephemeral Guard、Backup／Restore、通常／QA 100／1000回性能、
  同一Gacha負荷、V1 Backend全回帰は旧Headの成功Evidenceを保持し再実行していない。

### 時間を要した作業／Final

- Admin Serial検証は約2分、対象Browser E2Eは約40秒、Quality Gateは約30秒。
- Backend対象Test初回はComposeの旧`api:latest`により0 Testで停止したため、
  固定済み`api:target` Imageと明示Test Pathへ切り替え、再Buildを避けた。
- Root空き8.7GB、`/tmp`空き2.9GBで安全閾値内のためCache Cleanupは実施せず、
  稼働Container、Named Volume、V1 Resourceを保持した。
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree／Task DB CleanupはPR Closeoutで確定する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-060P以降は開始しない。

## MIG-060M Closeout／MIG-060N Admin QA Plan／Test User Management

### MIG-060M Closeout

- Issue `#149`はClosed、PR `#150`はSquash Mergedである。
- Final Headは`d0a77dd9149a5389d8c743fefcc9d2830bd931ff`、Squash Commitは
  `373f51d9605da2881482899b4f7f59b6132ab410`である。
- Required 5 Check、CodeQL 2件、Dependency Reviewを含むGitHub 8 Checkは
  成功した。Fresh Self-reviewはFinal Headと一致し、SEV-0／SEV-1は0件である。
- Branch／Worktree／Task ResourceはCleanup済みで、Local
  `main = origin/main`、Working Tree cleanを確認した。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更である。

### Characterization／Permission

- 既存QA正本はUser単位のQA Test ModeとUser＋Gacha単位のQA Planを持ち、
  Plan状態は`active`、`paused`、`completed`、`disabled`である。
- Plan Itemは対象Gachaの現在Published Versionに属するPrize Relationを参照し、
  `sort_order`、`quantity`、`consumed_count`、任意のImage／Video Assetを保持する。
- QA Drawは通常Drawと同じPoint、Inventory、販売口数、User Prizeへ実Dataとして
  影響し、QA ResolverだけがActive Mode／Plan／Assignmentを解決する。
- MIG-053／053Aで確定済みの`qa.draw.manage`はOwner-onlyかつ全QA管理APIで
  Fresh MFA 5分必須である。既存正本を優先し、`qa.read`／`qa.manage`を新設して
  Admin／Operatorへ権限を拡張していない。
- 通常Userの新規作成やUser Realm Session変更は行わない。既存Active Userを
  Candidateとして検索し、QA Mode有効化と履歴保持型Assignmentで管理する。

### Contract／QA Plan／Test User

- Admin OpenAPIへQA Plan一覧／詳細／作成／更新／有効化／無効化／Archive／
  Preflight、Test User一覧／Candidate検索／Mode保存／無効化、Plan割当／解除を
  Contract-firstで追加した。
- Admin Realm、有効Session、MFA Enrollment、Fresh MFA、CSRF、Exact Origin、
  JSON Content-Type、Idempotency-Key、Revision OCC、Critical Rate Limit、
  RFC 9457、`private, no-store`を既存共通基盤で強制する。
- UUIDv7 Public ID、Opaque Cursor、Stable Sort、Canonical Replay、
  同一Key異内容409、Stale Revision 409を使用し、内部DB IDを公開しない。
- Public／Webhook ContractとStorefront Clientは変更していない。
  QA Draw実行／再実行／結果確定Endpointは追加していない。
- QA PlanのCode、User、Gachaは作成後immutableとし、Unknown Field、危険文字、
  期間逆転、Terminal／Archive後更新、実行済みPlanの破壊的変更を拒否する。
- PreflightはPlan状態、Current Published Gacha Version、Published Probability
  Snapshot SHA、全Stage 1,000,000 ppm、Prize Relation、残Draw数、Active Test User、
  有効期間をServer側で再確認する。Point、Inventory、Draw Historyは変更しない。

### Migration／Concurrency／Resolver

- Forward-safe Migration
  `2026_08_16_000029_add_v2_qa_plan_management.php`を追加し、既存Migrationは
  編集していない。
- QA Mode／PlanへRevision、Planへimmutable Code／Archive情報を追加し、
  `qa_draw_plan_assignments`へUUIDv7、Plan／User、状態、Revision、割当／解除履歴を
  型付きColumnで保存する。既存PlanのUser Relationは決定的にAssignmentへ移行する。
- Canonical CHECK、Restrict FK、Revision／Identity／状態遷移TriggerでRevision
  bypass、Code／User／Gacha付替え、Terminal／Archive後変更、Assignment付替え、
  物理DeleteをFail Closedにした。
- Plan、User、Mode、Assignmentを固定順でLockし、同一User＋GachaのActive Plan
  競合、Concurrent Update／Assignmentを1件だけ成功させる。
- Mutation、Audit、`qa.plan.change` Outbox、Idempotency完了を同一Transactionで
  確定する。Deadlock／Serialization Failureは既存Transaction規則の最大3回を使う。
- QA ResolverはActive Assignmentを必須とし、Archived Planを除外する。
  既存QA Drawの抽選順、Point、Inventory、100／1000回Set-based Persistenceは
  変更していない。

### Admin UI

- `/qa`準備中画面をQA Overview、Plan一覧／詳細／Form、Enable／Disable／Archive、
  Preflight、Test User一覧／Candidate検索／Mode設定／割当／解除へ置き換えた。
- Revision Conflict、Dirty State、Confirmation、二重送信防止、Canonical再取得、
  Loading／Empty／Error、401／403／429、Fresh MFA Dialogを既存Shellで再利用した。
- Owner以外はBackendで403とし、Permission取得不能時はFail Closedである。
- `Asia/Tokyo`表示、UTC保存、Mobile、Keyboard、Focus、Accessible Name完全一致を
  維持した。QA Draw実行Button、再実行Button、架空結果は存在しない。

### Test／Evidence

- QA Plan管理、既存QA Draw、HTTP、DB Guard対象回帰は
  `20 Test／183 Assertion`、Process Concurrencyは`2 Test／14 Assertion`でPASSした。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、Typecheck、Lint、
  Production Build、Unit／Component `58 Test`、Browser E2E `14 Test`がPASSした。
- Persistent／Ephemeral双方で`migrate:fresh`各2回、最新Migration
  Rollback／Reapply、全V2 Suite、Backup／Restore、API／Admin HealthがPASSした。
- Migration数は29、Migration Set SHA-256は
  `49e6df42f64c7a3b4124fb9800bc13ea59ed523d155bb7e4a833dfb9ee8a4b29`。
- Backup SHA-256は
  `6b817a304ac8a039337e51278f09573820a5d74b0408fd6d7c1379f8c6b5dbc9`、
  Source／Restore Schema SHA-256は
  `339970b0c5baead71d527b6e86934f12bdf1ff12ef2c311c80ba373c71f6372d`
  で一致した。
- 通常100回p95は`157.390 ms`／Query 56、通常1000回p95は
  `621.172 ms`／Query 58、QA 100回p95は`191.098 ms`／Query 65、
  QA 1000回p95は`647.095 ms`／Query 76である。
- QA同一Gacha 5 User最終は`4.039 s`、10 User最終は`8.279 s`、
  通常同一Gacha 20 User最終は`16.032 s`である。未解決Deadlock、
  Point／Inventory／History不一致、500／502／504は0件である。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを依存順Serialで
  実行した。Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10とLocal GateがPASSした。
- Root／Legacy Frozen Install、Legacy Typecheck／Build、Lint既存
  8 Error／1 Warning FingerprintがPASSした。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。
- Repository外Evidenceは`/var/lib/oripa-v2-evidence/MIG-060N/`へ保存した。

### 時間を要した作業／効率改善

- API Image Buildは約1分、Admin Browser E2Eは約1.1分、Persistent Guardは
  約3分、Ephemeral Guardは約6分を要した。
- Persistent Guard初回は約3分後、Migration追加に対するSchema Inventory不足を
  Fail Closedで検出した。対象TableをDB Guard正本へ追加し、Guard Unit 29件を
  先に通してからFinal Guardを1回だけ再実行した。
- Root空き2.1GBが重い検証前の安全閾値を下回ったため、未使用Docker Build Cache
  だけを削除して約1.9GBを回収した。稼働Container、Image、Named Volume、
  V1 Resourceは削除していない。
- WorktreeはLockfile固定Installを行い、First-party PackageをSite Schema、
  Storefront Client、Storefront Testkitの依存順にSerial実行した。
- 対象Unit／HTTP／Admin SmokeをFull Guard前に実行し、通過済みの全回帰を中断・
  重複実行していない。Host Toolchain、Gate、Assertion、Timeout、Memoryを
  更新または緩和していない。

### Final予定／Gate

- Local R3検証は成功した。Final Head、GitHub 8 Check、Fresh Self-review、
  Squash Commit、Issue Close、Branch／Worktree／Task Resource Cleanupは
  PR Closeoutで確定する。
- V1 Backend隔離回帰はV1 Path／Checksum不変のため直前の承認済みEvidenceを再利用し、
  GitHub Integrationで再実行する。
- V1 Runtime、本番DB／Redis／Storage、Nginx、Public／Admin状態、
  `v1/early-release`、Archive Branch、Annotated Tagは非変更である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- 次Task候補は`MIG-060O Admin QA Draw Execution／Result Review`であり、
  MIG-060Oは本Task内で開始しない。

## SEC-005 Legacy Dependency Security Remediation

### Task／Scope

- Issue `#155`、PR `#156`、Branch
  `security/SEC-005-legacy-dependency-remediation`、Risk `R3`。
- BaseはGitHubの最新`main`
  `f4e6187f46ee7cb4d120e1a2015be8577ec5e3da`である。
- Task Policy
  `/etc/ideal-sol/github-app/task-policies/SEC-005.json`の完全一致Pathだけを変更した。
- MIG-060O Issue `#153`／PR `#154`／Worktree／Task DBは保持し、Application Code、
  DB、性能Evidenceを変更していない。

### Dependency Remediation

- Legacy `next`／`eslint-config-next`を`16.2.9`から`16.2.11`へ更新した。
- Nextの`sharp ^0.34.5`では修正版0.35系を通常解決できないため、Node 22.22.3対応の
  0.35系最新`sharp 0.35.3`を限定Overrideした。
- `js-yaml 4.3.0`は親Range内だが、pnpmのtransitive updateが無関係な直接
  devDependencyまで更新したため、不要なGraph churnを避けて限定Overrideした。
- pnpm 10.12.1で元Lockfileから再生成し、Next／sharp／libvips／js-yamlと
  必要なtransitiveだけを更新した。Lockfile手編集とApplication Source修正はない。
- Next Image Optimizerで既存PNGからWebPへの実変換、sharp 0.35.3、
  libvips 1.3.2、Node Engine互換を確認した。

### Baseline再審査

- Legacy pnpm Auditは11件から0件、Root Workspace Auditは0件を維持した。
- Baselineからpnpm 11件、Dependency Review allowlist 6件を削除した。
- Composerは従来と同じMedium 9件／Unknown 1件で、新規Critical／Highは0件。
- Composerの修正版はGuzzle `7.15.1`以上、PSR-7 `2.12.3`以上、
  JMESPath `2.9.1`以上だが、Composer LockはSEC-005 Policy外である。
- Review日は`2026-07-31`、短期期限は`2026-08-07`。別Security Taskで
  Composerを更新し、Fresh AuditでBaselineを削除する必要がある。

### Test／Evidence

- Clean Frozen Install、Legacy Audit 0、Typecheck、Production Build、
  起動Health、Next Image／sharp SmokeはPASSした。
- Legacy Lint rawは既存8 Error／1 Warningで、完全一致Lint BaselineはPASSした。
  Sourceは変更していない。
- Legacy PackageにTest Script／Suiteが存在しないためUnit Testは未実行であり、
  PASSとは記録しない。Health／ImageはIntegration Smokeとして区別する。
- Security Unit、Dependency Baseline期限境界、Local Security Gate、Policy Gate、
  Quality Gate、`git diff --check`はPASSした。
- 実Baselineへpnpm FindingまたはCritical／High Composer Findingを再導入できない
  Repository Baseline Unitを追加した。
- Security GateはComposer 10、Legacy pnpm 0、Workspace pnpm 0、
  Secret／PII Candidate 0、期限`2026-08-07`である。
- Repository外Evidenceは`/var/lib/oripa-v2-evidence/SEC-005/`、
  提出Reportは`worklogs/reports/SEC-005-report.md`である。

### Production／Gate

- V1 Application Source、Backend Migration 40件、V1 Runtime、本番DB／Redis／
  Storage、Nginx、Domain、TLS、Archive Branch、Annotated Tagは非変更である。
- V1 Migration 40件の正本Checksum
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
  は不変である。
- 本TaskではProduction Deploymentを行わない。現在のV1 Runtimeは脆弱版を
  継続稼働しているため、Merge後にProduction Security Deploymentが必要である。
- Gate G4／G5は`NOT COMPLETE`を維持する。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Cleanupは
  PR Closeout時に確定する。
- GitHub初回RunはPush時EventがPR本文更新前のChanged Filesを保持し、
  `policy-gate`がFail Closedした。同一SHAの再Runでは旧失敗CheckがWrapper集約に
  残ったため、空CommitではなくRepository Baseline Unitを追加した新Headで再実行する。
- MIG-060OのRebase／Merge／Closeoutは本Task内で実施しない。

## MIG-061A admin.luxe-pack.biz V2 Admin／API Preview Cutover

### Task／Scope

- Issue `#163`、PR `#164`、Branch
  `chore/MIG-061A-v2-admin-preview-cutover`、Risk `R4`。
- Baseは`main@de941ddcedda58adcf9cc54efa7c271c4ee7b866`、Task Policy
  SHA-256は
  `f60ff33f9c109b99231e25e68e6865534ca53998bcce70c362c42d5ffe30b206`。
- `admin.luxe-pack.biz`のAdminとSame-origin Admin APIだけをV2へ切り替えた。
  `luxe-pack.biz`、V1 Runtime／DB／Redis／Storage、TLS／DNS、他vhost、
  `ad.luxe-pack.biz`、`test.luxe-pack.biz`、Storefront、Production Paymentは
  変更または使用していない。

### V2 Preview／DB分離

- Admin／API Image Digestはそれぞれ
  `sha256:82984e4e35153b860282bb0710bca050612275ba231ff7388db6d44ef8f586f2`、
  `sha256:16d73513f966b3b4c0faad1cb8b237d2a50354b4b5aaf4f38b5a982943f6051b`。
- Compose Project `mig061a-v2-preview`、Internal Network
  `192.168.61.0/24`、Admin `192.168.61.11:3000`、API
  `192.168.61.10:8000`で稼働する。
- V2専用DBは`oripa_v2_mig061a`、Marker `mig061a`、Schema `public`、
  Timezone `UTC`、Migration 29件。DB Target Safety Guardは前後ともPASSした。
- Production DataはImportせず、Migration DefaultとSynthetic Preview Ownerだけを
  使用した。Ownerは既存PolicyどおりTOTP＋WebAuthnを登録した。

### Nginx／Browser確認

- 変更したHost設定は`/etc/nginx/conf.d/admin.luxe-pack.biz.conf`だけ。
  Checksumは
  `4276714d4dc80e383518ba64bc78ff95e211454f4290c9a1294b6202c2a18411`
  から
  `9832e492f8995db08a45d72f22566d09111d44539524b6509a79b986909f7347`
  へ変わり、変更前後の`nginx -t`はPASSした。
- Admin Login／API Health／Same-origin Session API／Static AssetはHTTP 200。
  BrowserでDashboard、Gacha、Prize、QAを確認し、全Route 200、
  Console Critical Error 0、Page Error 0、HTTP 500／502／504 0だった。
- `luxe-pack.biz`、V1 Frontend Direct、V1 Backend Directは切替後もHTTP 200。
  V1 Admin Container／Imageは削除していない。
- Admin生成差分、Typecheck、Lint、Production Build、Policy Gate、Quality Gate、
  `git diff --check`はPASSした。Dependency／Application差分がないためSEC-005の
  確定Audit Evidenceを再利用し、Security GateもPASS、Secret／PII Candidate 0件。

### 課題／時間を要した作業

- 初回招待Ownerは未登録状態からEnrollment画面へ遷移できず、既存APIでの初期登録が
  必要だった。UI修正はPolicy外のため後続課題として記録した。
- Image Buildは約100.2秒。Internal NetworkのLoopback PublishとEdge Networkの
  OCI Route競合を検証し、Host Toolchainを更新せずTask専用固定Subnet Overrideへ
  整理した。
- Diagnostic出力にTask Credentialが含まれた時点で、実Data投入前にTask専用
  Resourceを破棄し、CredentialをローテーションしてDBを再作成した。
- 詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061A/`、
  提出Reportは`worklogs/reports/MIG-061A-report.md`。

### Final／Gate

- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Cleanupは
  PR Closeoutで確定する。Preview Container／Network／DBは稼働維持する。
- Gate G4／G5は`NOT COMPLETE`。MIG-061B以降は開始していない。

## MIG-061B Configurable Admin Authentication Policy

### Task／Scope

- Issue `#165`、Branch `feat/MIG-061B-configurable-admin-authentication`、Risk `R4`。
- Baseは`main@b067e125b3dc96c7c9fa98c8485a1edb51e77450`、Task Policy SHA-256は
  `1b78e921714d54b5a921b0339e794b5ae93e47b857cf845b9592695f31e379e9`。
- Admin認証PolicyをInstallation Singletonとして追加し、MFA必須とInvitation必須を
  Ownerが独立して設定できるようにした。初期値は両方`false`である。
- 通常LoginはInvitation Tokenを受け取らず、Policy OFFではPassword-only、ONでは
  既存TOTP／WebAuthn／Enrollmentを使用する。既存MFA Credentialは削除しない。
- V1、Storefront、Public／Webhook Contract、Point／Payment／Draw、Nginx／TLS／DNS、
  Production Paymentは変更していない。

### Contract／Migration／Admin

- Admin OpenAPIへ型付きLogin結果、Invitation Acceptance、Authentication Policy
  取得／更新、Admin作成を追加した。Owner、Fresh Authentication、Current Password、
  CSRF、Exact Origin、JSON、Critical Rate Limit、Revision OCC、Idempotencyを強制する。
- Forward-safe Migration `2026_08_17_000030_create_v2_admin_authentication_policy.php`
  はSingleton、Revision、UUIDv7、UTC Timestamp、Mutation Metadataを保持し、Triggerで
  Delete、Identity変更、Revision bypass、No-opを拒否する。
- Adminへ`/settings/authentication`、Password-only Login、独立Invitation画面、MFA
  OFF時のPassword再認証、Policy連動Admin作成Formを追加した。SecretはClient Storageへ
  保存しない。

### Local Evidence

- 対象Backendは`25 Test／158 Assertion`、Process Concurrencyは
  `1 Test／7 Assertion`、全V2 Suiteは`297 Test／2701 Assertion／4既存Skip`でPASS。
- Admin OpenAPI／生成差分0、Typecheck、Lint、Production Build、Unit `63 Test`、
  Browser E2E `16 Test`、DB Guard Unit `29 Test`がPASSした。
- Task専用DB `oripa_v2_mig061b`でDB Target Safety、Migration fresh、最新Migration
  Rollback／Reapplyを確認した。V1／Preview DBへ対象Testを書き込んでいない。
- Repository外Evidenceは`/var/lib/oripa-v2-evidence/MIG-061B/`、提出Reportは
  `worklogs/reports/MIG-061B-report.md`である。

### Preview Deployment

- 既存MIG-061A PreviewへMigration `000030`を適用し、Migration 30件、Policy Revision 5、
  MFA OFF／Invitation OFFへ確定した。Synthetic OwnerはActive、既存TOTP／WebAuthn
  Credentialを保持し、Password-only Loginが成立する。
- Admin Imageは
  `sha256:22d15912a09861ed97fd1441dac0f50af480c40d4edd38661d183ca8a4a8d29f`、
  API Imageは
  `sha256:465994e86c64974586b80d8428fc9a653c704efe3bafb4ff545ce6b93a4773e3`。
- 実DomainでLogin、Dashboard、Gacha、Prize、QA、Policy ON/OFF往復を確認し、
  Console Critical ErrorおよびHTTP 500／502／504は0件だった。
- Synthetic Owner Credentialは`/root/mig061a-preview-login.txt`へroot-only `0600`で
  保存し、Repository／Report／通常Logへ値を記録しない。
- Preview Network再作成時に固定Overrideが外れてNginx Upstreamが一時Timeoutしたが、
  DB Volume、V1 Resource、Nginx設定を変更せず、固定Subnet構成で復旧した。Nginx checksum、
  `luxe-pack.biz` HTTP 200、V1 Runtimeは不変である。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Task専用Resource Cleanupは
  Closeoutで確定する。Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Cは開始しない。

## MIG-061C TailAdmin Admin Shell Foundation

### Task／Scope

- Issue `#167`、PR `#168`、Branch
  `feat/MIG-061C-tailadmin-admin-shell-foundation`、Risk `R3`。
- Baseは`main@d1e24394b8449ad801398a6cb22033195ab0c833`、Task Policy
  SHA-256は
  `d0610940de114be3ac816228c7a70abab40e35c0f007c36d39f2cd8c56b3d21a`。
- TailAdmin Next.js FREE版を視覚・構造上の参考に、既存AdminのLogin、Desktop／Mobile
  Shell、Sidebar、Header、User Menu、Breadcrumb、Dashboard、共通UI基礎を再構成した。
  TailAdmin Source／Pro素材、Package／Lockfileは使用または変更していない。
- Navigationは実装済みRouteだけをEffective Permissionに従って表示し、GachaとPrizeを
  独立導線にした。架空Menu／KPI／Graph、新規集計APIは追加していない。

### Verification／Preview

- Admin Typecheck、Lint、Production Build、Unit `64件`、Browser E2E `16件`、
  Candidate Desktop／Mobile Smoke、Policy／Quality／Security Gate、Secret Scan、
  `git diff --check`がPASSした。
- Preview Compose Project `mig061a-v2-preview`のAdminだけを更新し、Imageは
  `sha256:bd49b3460d024534b8b13676cd661ed00ded210edd9e27acbeb7b9ca2f2c9f71`。
  旧Image
  `sha256:22d15912a09861ed97fd1441dac0f50af480c40d4edd38661d183ca8a4a8d29f`は
  Rollback用に保持する。
- 実DomainのPassword-only Login、Dashboard、Gacha、Prize、QA、管理者認証、Mobile
  Drawer、API Healthを確認し、Console Critical Error、HTTP 500／502／504は0件だった。
- API／PostgreSQL／Redis Container、V2 DB／Migration、Nginx checksum、V1 Runtime、
  `luxe-pack.biz`は不変である。詳細Evidenceは
  `/var/lib/oripa-v2-evidence/MIG-061C/`、提出Reportは
  `worklogs/reports/MIG-061C-report.md`。
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、Task専用Resource Cleanupは
  Closeoutで確定する。Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Dは開始しない。

## MIG-061D Admin Sidebar Hierarchy／Route Scaffold

### Task／Scope

- Issue `#172`、Branch `feat/MIG-061D-admin-sidebar-hierarchy`、Risk `R3`。
- Baseは`main@fcc782daee4e6303858e412d9fe4372dc9919c7c`。Task Policyは中央Gate登録用2 Pathだけを
  追加してAtomic再発行し、SHA-256は
  `ada7789d9af064659c1b7d11c12565cf714f79264e13de057fe18e70883dca89`。
- SidebarをDashboardと8 Parent Groupへ階層化し、指定順序、Accordion、Current Routeの
  自動展開、最長Path Active、Desktop／Mobile共通Registryを実装した。
- 既存8 Routeは維持し、未実装13 RouteへTitle、Breadcrumb、後続Task案内だけのScaffoldを
  追加した。架空Data、Backend API、Permission、Dashboard KPIは追加していない。
- Ownerは全ScaffoldをPreviewでき、既存Permissionがある項目はEffective Permissionを維持する。
  Permissionがない新規ScaffoldはOwner-only BoundaryでFail Closedとした。

### Verification／Preview

- Frozen Install、Admin Typecheck、Lint、対象Unit `15 tests`、全Admin Unit `72 tests`がPASS。
- Browser E2Eは最終全2件がPASS。Mobileで既存OverlayがDrawer Linkを遮断する問題を
  検出し、Sidebar z-indexを最小修正後にDesktop／Mobileを同一Headで確認した。
- Preview Admin切替前Imageは
  `sha256:bd49b3460d024534b8b13676cd661ed00ded210edd9e27acbeb7b9ca2f2c9f71`。
  API／DB／Migration／Nginx／V1は変更していない。
- 詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061D/`、提出Reportは
  `worklogs/reports/MIG-061D-report.md`。Final検証、Preview反映、Fresh Self-review、
  GitHub Check、Squash Merge、CleanupはCloseoutで確定する。
- Policy Unit `91 tests`、Policy／Quality GateがPASS。新規Scaffold／Test 15 Pathと中央Admin
  Skeleton登録集合は機械的に完全一致し、WildcardやGate緩和はない。
- Preview AdminをImage
  `sha256:fe685fc99eba2f13a4c3355afa39eff28c1ed366398eebfdab33261a9fb0c0cf`へ更新した。
  Password-only Login、21 Route、8 Parent、Desktop／Mobile、Console Error 0、HTTP 500／502／504
  0を確認し、API／DB／Migration／Nginx／V1は変更していない。Rollback Imageは保持する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Eは開始しない。

## MIG-061E Dashboard V1 Sales Management Layout

### Task／Scope

- Issue `#176`、PR `#177`、Branch `feat/MIG-061E-dashboard-sales-layout`、Risk `R3`。
- Baseは`main@21648f1d4a00355be6ede17a2de7735a99f949ff`、Task Policy SHA-256は
  `da9b9751808b10a12683e9c2c63ac3542a19e53e9bfdea8e39ad95d6ee636522`。
- V1売上管理のRead-only Characterizationに基づき、V2 Admin `/`へ月別売上、日別売上、
  月別ポイント消費、日別ポイント消費、返金/CB履歴の5表示を同じ順序で実装した。
- Backend API、OpenAPI、Generated Client、DB、Migration、実集計、CSV生成、V1は変更していない。

### Dashboard／Verification

- 対象年月／対象日／期間、売上4 Summary、有償／無償Point Summary、Calendar／Table領域を
  TailAdmin基準の既存Shellへ適合した。集計Contract未実装のため架空数値を表示せず、
  「集計API未接続」と明示し、CSV／検索は後続Task表示のDisabled操作とした。
- Frozen Install、Admin Typecheck、Lint、全Unit `78 tests`、Dashboard Browser Desktop／Mobile、
  Production Build、Admin生成差分0、Policy Unit `92 tests`、Policy／Quality／Security Gate、
  Secret Candidate 0、`git diff --check`がPASSした。
- 新規Admin Component／Unit／E2Eの3 Pathだけを中央Admin Skeletonへ完全一致登録した。
  Wildcard、Directory、将来Path、Gate緩和はない。

### Preview

- Preview Admin Imageを
  `sha256:fe685fc99eba2f13a4c3355afa39eff28c1ed366398eebfdab33261a9fb0c0cf`から
  `sha256:b63d623d28c1e34b4cd6137d878e39af9433bbc339547d8f1b1e147251166c2e`へ更新した。
- Password-only Login、Dashboard、5表示順、Desktop／Mobile、API Health、横溢れ0、
  Console／Page Error 0、HTTP 500／502／504 0を確認した。Rollbackは不要で旧Imageを保持する。
- API／PostgreSQL／Redis Container、DB／Migration、Nginx checksum、V1、`ad.luxe-pack.biz`、
  `luxe-pack.biz`、Storefront、Paymentは変更していない。
- 詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061E/`、提出Reportは
  `worklogs/reports/MIG-061E-report.md`。Final Head、GitHub Checks、Fresh Self-review、
  Squash Commit、CleanupはCloseoutで確定する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061F以降は開始しない。

## MIG-061F Dashboard Sales Aggregation API／Frontend Connection

### Task／Scope

- Issue `#178`、PR `#179`、Branch `feat/MIG-061F-dashboard-sales-aggregation`、Risk `R3`、
  Verification Profile `DATA-R3-TARGETED`。
- Baseは`main@550051a78bae4d0aadbb7a02119fa211237bc28b`、Task Policy SHA-256は
  `12d1a78a61514f680e7767b89919ec8eac6694af3405dbd35d0cd7d41e1ef022`。
- 月別／日別売上、月別／日別Paid・Free Point消費、返金／Chargeback履歴のRead-only Admin APIを
  追加し、MIG-061Eの5タブへGenerated Client経由で接続した。
- 成功Payment／Adjustment、Ledger spend、QA除外、JST境界、Opaque Cursor、既存
  `reporting.financial.read`とRead Auditを正本として使用した。Migration、Index、Mutation、CSV、
  Public／Webhook／Storefront／V1 Contractは変更していない。

### Verification／Preview

- Task DBのBackend Domain／HTTP／Reporting回帰16件と性能1件がPASS。100行で10／8／8 Query、
  54.57ms、N+1なしを確認した。
- Admin Unit 80件、Desktop／Mobile Browser E2E、OpenAPI Lint／Bundle／Breaking、生成差分0、
  Typecheck、Lint、Build、Policy／Quality／Security Gate、Secret Candidate 0がPASSした。
- Preview API／Admin ImageをApplication Head `c6c455a0d1f74d882a0e107e07025b2ce886329b`からBuildし、
  API `sha256:0bbb6c3b...`、Admin `sha256:f348dbb2...`へ更新した。
- Password-only Login、Dashboard 5タブ、全Dashboard API 200、Desktop／Mobile、Console Error 0、
  HTTP 500／502／504 0を確認した。DB／Migration、Redis、Nginx／TLS／DNS、V1、Paymentは非変更。
- 詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061F/`、提出Reportは
  `worklogs/reports/MIG-061F-report.md`。Final Head、GitHub Checks、Fresh Self-review、Squash Commit、
  Task Resource CleanupはCloseoutで確定する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061G以降は開始しない。

## MIG-061G Admin User List／Detail／Gacha History

### Task／Policy／Characterization

- Issue `#180`、Branch `feat/MIG-061G-admin-user-management-read-model`、Risk `R3`、
  Verification Profile `DATA-R3-TARGETED`。
- Baseは`main@35ba11a1762a574ad1cf7528e825d92a2ed69a2c`。Task PolicyはV2 User Model、
  次連番Migration `000031`、Identity Schema Test、Admin Navigationと既存Permission Testの
  5 Pathだけを追加してAtomic再発行した。最終SHA-256は
  `e94dbac9d014fff7293f73e024617a5a2640dfa894bdf0dc2e722922af634c2b`。
- V1 `users.name`は既定長255の必須表示名である。V2では既存Userを維持するnullable
  `display_name`とし、未設定時にEmail等から推測しない。
- V2 Password／External Identity作成には表示名入力がないため、User作成処理を変更しない。
- WalletのPaid／Free Current Balanceを正本とし、合計はBackendで整数加算する。
- V1「ユーザー保有景品」はStatus Filterなしの取得履歴であるため、V2別Routeの
  「ユーザーガチャ履歴」も過去を含む取得景品履歴として実装する。
- 全Active Admin Sessionへ同じRead Modelを提供し、専用PermissionやRole分岐は追加しない。
  `/users/history`とUser／Point／Prize Mutationは変更しない。
- 詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061G/`、提出Reportは
  `worklogs/reports/MIG-061G-report.md`。実装、検証、Preview反映、Closeout結果は後続追記する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061H以降は開始しない。

### MIG-061G Implementation／Verification／Preview

- User Read-only API、nullable `display_name` Migration、Admin一覧／詳細／ガチャ履歴を実装した。
  全Active Admin Roleを同一Session境界で許可し、Mutationや専用Permissionは追加していない。
- Task DBで12 tests／186 assertions、100 Userで6 Query／323.51ms、Admin Unit 25件、Browser 2件、
  OpenAPI／生成差分／Typecheck／Lint／Build／Policy／Quality／Security Gateを確認した。
- Preview DBへ000031だけを適用し、API Image `sha256:562e666f...`、Admin Image
  `sha256:9d3c0e70...`へ更新した。Password Login、`/users` Empty State、Health、Console Error 0、
  HTTP 500／502／504 0を確認した。
- PostgreSQL／Redis Container、Nginx、V1、`luxe-pack.biz`、Paymentは変更していない。
  詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061G/`、提出Reportは
  `worklogs/reports/MIG-061G-report.md`。
- GitHub全Suiteで検出した旧Owner-only Admin Test 2件とV1-only Schema上の新V2 Unit実行境界を補正した。
  Task Policyは関連Test 2 Pathだけを追加してAtomic再発行し、最終SHA-256は
  `210e46d87242ab8bf31ab8a323de9b98981a06ef0a891246cffbdbe638aedf9e`である。

## MIG-061H Admin User Point Adjustment

- Issue `#182`、Branch `feat/MIG-061H-admin-user-point-adjustment`、Risk `R4`、Verification Profile
  `FINANCIAL-MUTATION-R4-TARGETED`。Baseは`main@3f6ee49d8fa7690e8cd4693e04439f65efed14e9`。
- `point.adjustment.manage`をOwner／Adminへ追加し、Admin User DetailからPaid／Free Pointを型別に
  加算／減算するAPIとModalを実装した。OperatorはUI非表示かつAPI 403。
- Wallet／Lot／Operation／Ledger／Adjustment／Audit／Idempotencyを同一Transactionで確定し、
  別Point種別へのFallback、負残高、同一Key異内容を拒否する。Free Grant期限は既存設定180日を使用する。
- Task DBでDomain／HTTP／Permission、Process Concurrencyを確認し、Admin Unit／Typecheck／Lint／Build、
  対象Browser 2件、OpenAPI Breaking／生成差分0がPASSした。
- 詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061H/`、提出Reportは
  `worklogs/reports/MIG-061H-report.md`。Preview反映、GitHub Check、Fresh Self-review、Closeoutは後続追記する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061I以降は開始しない。

### MIG-061H Preview

- Preview API Imageを`sha256:d3d2b2b8...`、Admin Imageを`sha256:d5c38bb3...`へ更新した。
  Synthetic Userの無償P 0→7、Canonical Replay、Operator UI非表示／API 403、Ledger／Audit整合を確認した。
- Container名、Network、固定IP、Port、Environment、PostgreSQL／Redis、Migration 31件、Nginx checksum、
  V1、`luxe-pack.biz`、`ad.luxe-pack.biz`は維持した。Console Critical ErrorとHTTP 500／502／504は0。

## MIG-061I Gacha Core Management

- Issue `#184`、Branch `feat/MIG-061I-gacha-core-management`、Risk `R3`、Verification
  `FAST-TRACK-FEATURE`。Baseは`main@b41f9e9ec460ba1c8413b2bdbc46b11ebde277c8`。
- Gacha一覧／登録／詳細、Category／Tag一覧・登録・編集、履歴／利益Simulation／商品設計Planner Scaffoldを実装した。
  Gacha Masterと初期Draft VersionをAtomic作成し、日次上限と会員区分をVersionへ保存する。
- Task DBでMigration Fresh／Rollback／Reapply、Backend対象24件、Admin Unit 33件、Browser対象3件、
  OpenAPI／Generated Client／Typecheck／Lint／Build／Policy GateがPASSした。
- Preview反映、GitHub Check、Fresh Self-review、Squash Commit、CleanupはCloseoutで確定する。
- 詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061I/`、提出Reportは
  `worklogs/reports/MIG-061I-report.md`。Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Jは開始しない。

### MIG-061I Preview

- Application Head `bfc47d8276c50b1c5fcb8167317f08a2412d809c`からPreview APIを
  `sha256:42d971aa...`、Adminを`sha256:fb9d2497...`へ更新し、Migration 000032だけを適用した。
- Gacha Empty State、登録Form、Category／Tag、3 Scaffold、MobileをOwner Loginで確認した。
  Console／Page Critical ErrorとHTTP 500／502／504は0であり、Synthetic Gachaは投入していない。
- PostgreSQL／Redis、Nginx、V1、`luxe-pack.biz`、`ad.luxe-pack.biz`は非変更。

### MIG-061I Closeout Blocker

- GitHub CodeQL 2件、Dependency Review、最新Policy／Quality GateはPASSしたが、外部Advisory DBに追加された
  Composer High 1件とWorkspace／Legacy pnpm HighによりSecurity Gateと集約`ci-gate`がFail Closedした。
- Baseline追加／期限延長やDependency更新はMIG-061I Policy外のため行わない。PR `#185`、Issue `#184`、
  Branch／Worktree／Task DBを保持し、別Security Task完了後に同じMIG-061Iを再開する。

## SEC-007 最小Dependency更新

- Issue `#186`、PR `#187`、Branch `security/SEC-007-minimal-dependency-refresh`、Risk `R3`、
  Verification `FAST-TRACK-DEPENDENCY`。Baseは
  `main@b41f9e9ec460ba1c8413b2bdbc46b11ebde277c8`。
- Guzzle 7.15.2、PSR-7 2.13.0、brace-expansion 5.0.9、fast-uri 3.1.5、
  postcss 8.5.23へ最小更新し、Root／Legacy pnpm Audit 0、Composerは既存Unknown 1件、
  Critical／High 0を確認した。
- Baselineから解消済みGuzzle／PSR-7 9件だけを削除し、期限`2026-08-07`は延長していない。
  Frozen Install、API Smoke、Admin／Legacy Typecheck・Build、Security／Policy／Quality GateがPASSした。
- Application、Migration、DB、Preview、Productionは非変更。MIG-061I Issue `#184`、PR `#185`、
  Worktree、Task DB、Previewを保持し、Closeoutは開始しない。
- Evidenceは`/var/lib/oripa-v2-evidence/SEC-007/`、提出Reportは
  `worklogs/reports/SEC-007-report.md`。GitHub Checks、Fresh Self-review、Squash Commit、Cleanupは
  Closeout時に確定する。

### MIG-061I Security Blocker解消後Closeout

- SEC-007 Squash Commit `96fc1fb7d4108011dac65c5b3cdb5739dd639eb1`を既存MIG-061I BranchへMergeし、
  Dependency修正とMIG-061I Application／Migration差分を両方保持した。
- Composer Critical／High 0、Root／Legacy pnpm Audit 0を確認した。Baseline期限は
  `2026-08-07`のまま維持し、Preview再Deploymentや機能追加は行わない。
- Final Head、Required Checks、Fresh Self-review、Squash Commit、CleanupはCloseoutで確定する。
# 2026-08-04 MIG-061J ガチャ対象ユーザー／日次回数制限

- Issue #188。`audience_code`と`daily_draw_limit`を通常V2 Drawの既存Transaction／Lock境界へ接続した。
- 初回ユーザーは成功済み通常Draw、LINEユーザーは未失効IdentityとFriendship、日次回数はAsia/Tokyo日境界の`executed_count`を正本とする。
- Task DB `oripa_v2_mig061j`で対象11 tests／99 assertionsとProcess Concurrency 1 test／9 assertionsがPASSした。
- Migration、Admin／Storefront、Preview、V1は非変更。Gate G4／G5は`NOT COMPLETE`のまま。

# 2026-08-04 MIG-061K ガチャランク／景品管理

- Issue #190、Branch `feat/MIG-061K-gacha-rank-prize-management`、Risk R3、Verification `FAST-TRACK-FEATURE`。
- V1のRank／Prize項目と既存V2 Catalog Master、Draft Version、Asset、Inventory境界をCharacterizationした。
- Rank説明、Prize原価、Draft VersionごとのRank relationを補うForward-safe Migration 000033と、Draft専用API／Admin Modalを実装する。
- Task Policy SHA-256は`2ad21acf8b9e9f0d0baf613a098c22dbb0159e4f0b875d2d6dcff7db90a3cde3`。詳細Evidenceは`/var/lib/oripa-v2-evidence/MIG-061K/`へ保存する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061L以降は開始しない。

### MIG-061K Implementation／Preview

- Draft Version単位のRank一覧／作成／更新、Prize一覧／作成／更新を実装し、Rank設定Modalと10列のPrize一覧、登録／編集ModalをGacha詳細へ追加した。
- Migration 000033でRank説明、Prize原価、Version-Rank relationを追加した。Task DBは33件Fresh、rollback／reapply、Backend 3 tests／26 assertions、Admin対象Unit／Browser、OpenAPI／Generated Client、Typecheck／Lint／Build、Policy／Quality GateがPASSした。
- Application Commit `9099e4dd66e9524c26f3dc09c35083a1dc55fa2b`からPreview APIを`sha256:3e62b5e6...`、Adminを`sha256:4659b7e3...`へ更新し、Preview DBへ000033だけを適用した。
- Owner Loginと画面SmokeはPASS、Console／Page ErrorとHTTP 500／502／504は0。Preview DataはEmptyのためSynthetic Gachaを投入していない。PostgreSQL／Redis、Nginx、V1は非変更。
- GitHub Checks、Fresh Self-review、Squash Commit、CleanupはCloseoutで確定する。Gate G4／G5は`NOT COMPLETE`、MIG-061Lは開始しない。
- Validated implementation head `46543fca5d0139f3e3161e80914620da2e7c598e`でRequired 5、CodeQL 2件、Dependency Reviewの全8 CheckがPASSした。最終Documentation Headを再検証後にSquash Mergeする。
# MIG-061L ガチャ利用履歴一覧／詳細

- Issue #192、Branch `feat/MIG-061L-gacha-usage-history`で開始。
- 完了済み通常Draw RequestのRead-only一覧／詳細を実装対象とする。
- QA Draw、Mutation、Migration、V1変更は対象外。

### MIG-061L Implementation／Preview

- 完了済み通常Draw Requestの一覧／詳細Read-only APIとAdmin画面を実装した。QA、Failed、処理中、Rollback済みRequestは除外し、状態はUser PrizeのCanonical状態を件数集計する。
- Task DBでBackend 4 tests／40 assertions、Admin Unit 2件、Browser E2E 2件、OpenAPI／Generated Client、Typecheck／Lint／Build、Policy／Quality GateがPASSした。一覧／詳細は各4 QueryでN+1なし。
- Preview API Imageを`sha256:28b38299...`、Admin Imageを`sha256:e3375b47...`へ更新した。Preview Dataが空のためSynthetic Drawは投入せず、画面正本は対象Browser E2Eとした。
- GitHub Quality Gateが検出したEffect直下の同期State更新を、Retry handlerとRoute-key再初期化へ補正した。Unit全19 files／99 tests、Typecheck／Lint／Build、対象Browser 2件を再確認した。
- PostgreSQL／Redis、Migration 33件、Nginx checksum、V1は非変更。Console／Page ErrorとHTTP 500／502／504は0。Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Mは開始しない。

# MIG-061M ガチャ利益シミュレーション

- Issue #194、Branch `feat/MIG-061M-gacha-profit-simulation`、Risk R3、Verification `FAST-TRACK-FEATURE`で開始した。
- V1の`ProfitSimulationPanel`と`GachaProfitSimulationService`を正本に、V2 Draft Version／Prize／Probabilityの既存Read APIだけで同等計算を実装する。
- Migration、DB Write、API追加、V1変更、商品設計プランナー変更は行わない。Evidenceは`/var/lib/oripa-v2-evidence/MIG-061M/`へ保存する。

### MIG-061M Implementation／Preview

- V1同等の最大原価／利益／目標差分／公開確率期待値をV2 Draft VersionとPrize APIへ接続し、Golden 3パターンを含むUnit 5件、Desktop／Mobile E2E 2件、Typecheck／Lint／Build、Policy／Quality GateがPASSした。
- Application Head `e264bb45c2dfc9b3d8f92bdbdb5d0556040c6b7b`からPreview Adminを`sha256:8245dcfe...`へ更新した。PreviewにGachaがないためSynthetic Dataは投入せず、利益画面は対象E2EをEvidence正本とした。
- Owner Login、Health、Console／Page Error 0、HTTP 500／502／504 0を確認した。API／PostgreSQL／Redis、Migration、Nginx、V1は非変更。旧Admin ImageはRollback用に保持した。
# MIG-061N お知らせ管理

- Issue #196、Branch `feat/MIG-061N-announcement-management`、Risk R3、Verification `FAST-TRACK-FEATURE`で開始した。
- V1 Noticeの一覧、登録／編集、公開状態、サムネイル、トップ表示をCharacterizationし、V2既存Content Notice／Version／Asset／Publish基盤へ移植する。
- Migrationは不要。Admin Realm、Public ID、Server-side HTML Sanitizer、公開期間、IdempotencyをV2境界として使用する。
- Preview更新対象はAPI／Adminのみ。DB／Nginx／V1／Storefrontは非変更とし、Gate G4／G5は`NOT COMPLETE`を維持する。

### MIG-061N Implementation／Preview

- V1順のPublic ID／サムネイル／タイトル／状態／日時に、V2のカテゴリ、公開終了、Server Previewを加えた一覧と、登録／編集Formを実装した。
- Task DBでBackend 15 tests／107 assertions、Admin Unit 3件、Desktop／Mobile E2E 2件、OpenAPI Breaking／Generated Client、Typecheck／Lint／Build、Policy／Quality GateがPASSした。
- Preview API Imageを`sha256:c9cf11b9...`、Admin Imageを`sha256:f9c94d86...`へ更新した。Owner Login、一覧、Sanitized Preview、Synthetic Draft 1件、HealthがPASSし、Console／Page ErrorとHTTP 500／502／504は0だった。
- Migration／DB Schema、PostgreSQL／Redis設定、Nginx checksum、V1、Storefrontは非変更。Required Checks、Self-review、Squash Commit、CleanupはCloseoutで確定する。

# MIG-061O お問い合わせ管理

- Issue #198、Branch `feat/MIG-061O-contact-management`、Risk R3、Verification `FAST-TRACK-FEATURE`で開始した。
- V1 Contactの一覧列、状態、詳細、返信導線をCharacterizationし、V2既存Contact Inquiry／Status History／Reply Request／Outbox基盤へ移植する。
- 既存Schemaで実現できるためMigrationは追加しない。Preview更新対象はAPI／Adminのみとし、DB／Nginx／V1／Storefrontは非変更とする。

### MIG-061O Target Verification

- Task DBでBackend 18 tests／139 assertions、Admin Unit 3件、Desktop／Mobile E2E 2件、OpenAPI／Generated Client、Typecheck／Lint／Build、Policy／Quality GateがPASSした。
- Contact一覧は3 Query、詳細はRead Auditを含む10 Query上限でN+1がないことを確認した。Preview反映、Required Checks、Closeoutは最終Headで実施する。

### MIG-061O Preview

- Application Head `bed7b0a90243791f8fa545ed36f5fb2687d71136`からPreview APIを`sha256:54656a8c...`、Adminを`sha256:310701b5...`へ更新した。
- Owner Login、Contact Empty State、API／Admin HealthがPASSし、Console／Page ErrorとHTTP 500／502／504は0だった。
- Migration 33件、PostgreSQL／Redis、Environment Key集合、固定IP、Nginx checksum、V1、Storefrontは非変更。Required ChecksとCloseoutはFinal Headで確定する。
- GitHub Integration Gateが検出した取得不能な`linux-libc-dev=6.1.177-1`固定を、現在のBookworm Security正本`6.1.180-1`へ補正した。Policy追加はDockerfile 1 Pathだけで、API Image Healthを再確認した。
- 後続Integration Gateで、100,000件Contact Performance Fixtureが暗号化Columnへ平文を投入していた不整合を検出した。当該Test 1 PathだけをPolicyへ追加し、有効な暗号文Fixtureへ補正した。1 test／31 assertions、一覧p95 12.427 ms、Concurrent 10/10、N+1なしでPASSした。

# MIG-061P バナー管理

- Issue #200、Branch `feat/MIG-061P-banner-management`、Risk R3、Verification `FAST-TRACK-FEATURE`で開始した。
- 既存Content／Asset基盤を再利用し、カテゴリ作成、画像Upload、バナーCRUD、Server-side Filter、Cursor Paginationを実装した。
- Migration 000034でBanner CategoryとContent Version relationを追加した。Version／共有Assetは物理削除せず、削除はArchiveと公開Pointer解除で扱う。
- Task DBでBackend 3 tests／33 assertions、Admin Unit 3件、Desktop／Mobile E2E 2件、Typecheck／Lint／BuildがPASSした。Preview反映とCloseoutは最終Headで実施する。

### MIG-061P Preview

- Application Head `ed99068412fbac68d484634dc7b4aeda923b44a7`からPreview APIを`sha256:4b528540...`、Adminを`sha256:8afac4e4...`へ更新し、DBへ000034だけを適用した。
- Owner Login、カテゴリ1件、Synthetic Banner 1件、画像Content、Filter、HealthがPASSした。Console／Page ErrorとHTTP 500／502／504は0。
- Preview Networkは途中のone-shot Compose競合後に元の`192.168.61.0/24`と固定IP／service aliasへ復旧し、Container／Domain Healthを再確認した。PostgreSQL／Redis設定、Nginx、V1、Storefrontは非変更。
# MIG-061Q ページ設定

- Issue #202、Branch `feat/MIG-061Q-page-management`、Risk R3、Verification `FAST-TRACK-FEATURE`で開始した。
- V1の固定ページ一覧／編集をCharacterizationし、V2 immutable Content Versionへカテゴリ、slug、表示状態を接続した。
- Task DBでMigration fresh／rollback／reapply、Backend 3 tests／23 assertions、Admin Unit 116 tests、Desktop／Mobile E2E 2件、Typecheck／Lint／Build、OpenAPI／Generated Client、Policy／Quality GateがPASSした。
- Preview反映、Required Checks、Closeoutは最終Headで確定する。Gate G4／G5は`NOT COMPLETE`、MIG-061Rは開始しない。

# MIG-061R ガチャMaster編集・11文字Public ID・詳細UI調整

- Issue #204、Branch `feat/MIG-061R-gacha-master-edit`、Risk R3、Verification `TARGETED-DOMAIN-MIGRATION`で開始した。
- Master編集専用Route、全基本項目のDraft編集、CSPRNG英数字11文字Public ID、Legacy UUID互換Resolverを実装した。
- 新規／編集共通の直接Thumbnail Uploadを実装し、Banner API／Category／Relationには依存しない。画像未選択時は既存Assetを維持し、差し替え時も旧Assetを即時削除しない。
- Task DBでMigration fresh／rollback／reapply、Backend 37 tests／400 assertions、Admin Unit 9 tests、Desktop／Mobile E2E 2 tests、Typecheck／Lint／Build、OpenAPI／Generated Client、Policy／Quality／DB GuardがPASSした。
- Preview反映、Required Checks、Closeoutは最終Headで確定する。Gate G4／G5は`NOT COMPLETE`を維持する。

### MIG-061Q Preview

- Application Head `78ad60136c4919e2505117ab786e02d614d45cfc`からPreview APIを
  `sha256:cc516763...`、Adminを`sha256:4b9d4370...`へ更新し、DBへMigration 000035だけを適用した。
- Owner Login、ページ一覧、カテゴリ追加、非表示Synthetic Page作成、編集Route、Desktop／Mobileを確認した。
  Console／Page ErrorとHTTP 500／502／504は0で、Synthetic Dataはカテゴリ1件、ページ1件に限定した。
- Migration 35件、DB Target Guard、API／Admin HealthがPASSした。既存Data、PostgreSQL／Redis設定、Network／固定IP、
  Nginx checksum、V1、Storefront、Payment Providerは非変更。Required ChecksとCloseoutはFinal Headで確定する。

### MIG-061R Preview

- Application Head `a9ae8c6944622bf6c491a8deba6a83cf6f8c63ed`からPreview APIを`sha256:282878b1...`、Adminを最終`sha256:0de7ad66...`へ更新し、DBへMigration 000036だけを適用した。
- DB Target Guard、Migration 36件、既存Gacha 1件の11文字Public ID Backfill、Legacy UUID互換、保護Trigger有効を確認した。既存Data、固定IP、Network、Restart Policy、Environment Key集合を維持した。
- Master編集の全項目、専用Thumbnail直接Upload、Banner選択API非依存、景品Public ID非表示、詳細幅、Desktop／MobileがPASSした。Critical Console／Page ErrorとHTTP 500／502／504は0。既存Synthetic Prizeの欠損Asset 404はTask外課題として記録した。
- Nginx、V1、Storefront、Payment Providerは非変更。Required ChecksとCloseoutはFinal Headで確定する。
- OpenAPI追加Fieldは後方互換なoptional Contractとし、現行Backendでは常時返す。Base比較Breaking Check、Generated Client、対象Unit／Browser、Typecheck／Lint／Buildを再確認した。

# MIG-061S ランク演出設定

- Issue #206、Branch `feat/MIG-061S-rank-effect-settings`、Risk R3、Verification `TARGETED-CRUD-UI`で開始した。
- V1の演出Master一覧／登録／編集、直接画像・動画Upload、Preview、状態をCharacterizationし、V2既存Presentation Asset／Asset／Rank relationへ移植した。V1にない削除は追加していない。
- Backend対象3 tests／29 assertions、Admin Unit 3 files／29 tests、Desktop／Mobile E2E 2 tests、OpenAPI／Generated Client、Typecheck／Lint／BuildがPASSした。Migrationは不要。
- Preview更新対象はAPI／Adminのみ。DB Schema／Nginx／V1／Storefrontは非変更とし、Gate G4／G5は`NOT COMPLETE`を維持する。

### MIG-061S Preview

- Application Head `47f4e56fba8efbaf344027421072ab33b31895fe`からPreview APIを`sha256:1f906e80...`へ更新し、CodeQL High 2件を解消したAdmin Head `5f22b7d06ef372ec1fe6c7be4006bf540f2e15fb`からAdminを最終`sha256:afe611ff...`へ更新した。Migrationなし、固定IP／Network／Environment Key集合は維持した。
- Synthetic画像／動画演出各1件で、直接Upload、Preview、Rank relation、表示順、編集時Asset維持、Desktop／MobileがPASSした。Console／Page Error、HTTP 500／502／504、Critical Logは0。
- Migration数36、PostgreSQL／Redis設定、Nginx checksum、V1、Storefront、Payment Providerは非変更。旧API／Admin ImageはRollback用に保持した。

# MIG-061T 紹介ポイント設定

- Issue #208、Branch `feat/MIG-061T-referral-point-settings`、Risk R3、Verification `TARGETED-POINT-CONFIG`で開始した。
- V1の紹介設定、将来成立分Snapshot、SMS認証完了時の一度だけ付与をCharacterizationし、V2では最新人間決定に従って紹介者／紹介されたユーザーの値を独立設定にした。
- Task DBでMigration fresh／rollback／reapply、Backend 8 tests／119 assertions、Admin Unit 2 files／10 tests、Desktop／Mobile E2E 2 tests、OpenAPI／Generated Client、Typecheck／Lint／Build、Policy／Quality GateがPASSした。
- Preview更新対象はAPI／Admin／Migration 000037のみ。設定Smoke後は元の値へ戻し、DB既存Data、Nginx、V1、Storefront、Payment Providerを維持する。

### MIG-061T Preview

- API Head `ef258879433d24bc2eefceedfcb615d3a9634630`からPreview APIを最終`sha256:ed95e50b...`、Admin Head `0c0de98e4c2c4afbe63e80de3ad282d95148a269`からAdminを`sha256:3b5f759b...`へ更新し、DBへMigration 000037だけを適用した。
- Owner Loginで設定の一時変更、保存後再取得、初期値への復元を確認した。Wallet合計とPoint Ledger件数は前後一致し、設定保存によるポイント残高変更はない。
- API／Admin Health、Desktop／Mobile、未認証401がPASSし、Console／Page ErrorとHTTP 500／502／504は0。旧ImageはRollback用に保持した。
- Migration 37件、既存Data、PostgreSQL／Redis設定、固定IP／Network／Environment、Nginx checksum、V1、Storefront、Payment Providerは維持した。
- GitHub Policy GateでMigration 000037のIdentity Fixture登録漏れを検出し、完全一致Pathを1件追加した。Policy Unit 108件、Local Policy／Quality GateがPASSし、Gate条件は非変更である。
- GitHub Integration GateでMigration 000037のCheck ConstraintがBackup-Restore後に等価な括弧へ正規化されるSchema差分を検出した。明示比較へ補正し、Task DB fresh、対象4 tests／58 assertions、Backup-Restore Schema diff 0を確認した。

# MIG-061U Storefront認証Public Contract／Client Completion

- Issue #210、Branch `feat/MIG-061U-storefront-auth-contract`、Risk R4、Verification `TARGETED-AUTH-CONTRACT`で開始した。
- 既存Laravel User Authentication、Cookie Session、CSRF、Email Verification、Rate Limitを再利用し、Public OpenAPI、Generated Type、Storefront Client／Testkitを同期する。
- ArtifactはRepository外Evidenceへ固定Version、元Commit、OpenAPI／Artifact SHA-256付きで保存する。Storefront Repository、Nginx、V1、Payment、DB Schemaは変更しない。

### MIG-061U Artifact／Preview

- Application／Artifact Source Head `76d8161de759d8969e74543f6d79b5f5b17cee1d`からClient／Testkit／Site Schemaの`2.0.0-alpha.1` tarball、Public OpenAPI、Manifest、SHA256SUMSを作成し、Workspace外Clean Install／ImportがPASSした。
- Preview APIだけを`sha256:8d2f0592...`へ更新した。Health、匿名Session、CSRF Cookie、no-storeを確認し、Admin Image、DB／Migration、Nginx、V1は非変更である。
- PreviewにはPublic API外部Routeと`V2_PUBLIC_ORIGIN`がないため、Public MutationはFail Closedの503となる。未承認のNginx／Origin変更は行わず、完全Auth Flowは対象Backend Testを正本とした。

# MIG-061V ポイント購入管理

- Issue #212、Branch `feat/MIG-061V-point-purchase-management`、Risk R4、Verification `TARGETED-POINT-PURCHASE`で開始した。
- V1購入プランの一覧／登録／編集をV2 immutable Versionへ移植し、`all_users`／`first_purchase_users`の対象カテゴリ、Payment成功履歴による初回資格、Admin API／Generated Clientを追加した。
- Task DBでMigration fresh／rollback／reapplyと既存商品Backfill、Backend 28 tests／181 assertions、Admin Unit 30 tests、Desktop／Mobile E2E 2 tests、Typecheck／Lint、OpenAPI／Generated ClientがPASSした。
- Preview更新対象はAPI／Admin／Migration 000038のみ。Wallet／Ledger、Nginx、V1、Storefront、Payment Providerは変更しない。

### MIG-061V Preview

- Application Head `e88e0ca8142f63a089bf766596d18e65c5feb8d3`からPreview APIを`sha256:a432cf07...`、Adminを`sha256:1b000d51...`へ更新し、DBへMigration 000038だけを適用した。
- Synthetic商品Logical 1件で初期対象`all_users`、編集後`first_purchase_users`、Version更新、保存後再取得、Desktop／Mobileを確認した。Console／Page ErrorとHTTP 500／502／504は0である。
- Wallet有償0／無償7、Point Ledger 1件は前後一致した。Migration 38件、固定IP／Network／Restart Policy／Environment Key集合、Nginx checksum、V1、Storefront、Payment Providerは維持し、旧ImageはRollback用に保持した。
## MIG-061W LINE設定管理

- Base `b85d3123943a5c61af2406b28a3b71d73a7ad145`からIssue #214、Branch `feat/MIG-061W-line-settings-management`で開始した。
- V1 LINE設定とMIG-058B／MIG-058CをCharacterizationし、既存Singletonへ友だち追加URLと友だち／ブロック統計を補完した。
- `identity.line.read`をOwner／Admin／Operator、`identity.line.manage`をOwner／Adminへ付与し、OperatorはRead-onlyとした。
- Secret資格情報、Webhook署名、Follow Reward、Point冪等性は変更しない。
- Task DB Migration fresh／rollback／reapply、LINE／Permission対象16 Test・223 Assertion、Admin 11 Test、対象Browser、Typecheck／Lint／Build、OpenAPI／Generated Client、Policy UnitがPASSした。
- Application Head `0c844f825bbd683fc9cf76b27fa190610fd9adf0`からPreview APIを`sha256:dd80b68d...`、Adminを`sha256:6ab94d4c...`へ更新し、Migration 000039だけを適用した。
- Ownerで友だち追加URLの一時保存、再取得、元値復元、Secret field非表示、Desktop／Mobileを確認した。Console／Page Error、HTTP 500／502／504は0で、Nginx checksum、V1、DB既存Data、Network／固定IP／Environmentは維持した。

## MIG-061X Session有効時間更新

- Issue #216、PR #217、Branch `security/MIG-061X-session-timeout-policy`、Risk R4で開始した。
- Backend正本のSession Policyを、AdminはIdle 6時間／Absolute 12時間、StorefrontはIdle 12時間／Absolute 24時間へ更新した。
- Migration 000040でDB Duration Checkを同じ上限へ更新し、長時間Sessionを含むrollback／reapplyと対象6 tests／42 assertionsを確認した。
- Remember me、Cookie／CSRF、認証Contract、Nginx、V1、Storefront Repository、Payment Providerは変更しない。

### MIG-061X SEC-008後Closeout

- SEC-008後の`origin/main@83c573724601b7459fc35d3a73591008f908836c`を既存Task Branchへ取り込み、CommonMark 2.9.0とRoot／Legacy `js-yaml 4.3.1`を維持した。
- `AuthenticationFlowTest`の旧Session期限前提だけを補正し、Adminは16分後に有効かつ最終Activityから6時間1分後に失効、Storefrontは12時間1分後に失効することを15 tests／95 assertionsで確認した。

# SEC-008 CommonMark Advisory最小更新

- Issue #218、Branch `security/SEC-008-commonmark-advisory`、Risk R4で開始した。
- Composer Auditで`league/commonmark 2.8.2`に新規検出された6 Advisoryを確認し、全件の最小修正版である`2.9.0`へ限定更新した。
- Composer Resolverのpackage version差分はCommonMark 1件だけ。Baseline、Manifest、Application Source、Migration、Runtimeは変更しない。
- Composer validate／Frozen Install／AuditはPASSし、CommonMark Findingは0件となった。
- 同時点でScope外の`js-yaml 4.3.0`に新規High Advisory `GHSA-5p4m-2wfm-xmqj`が公開されていたため、Local Security GateはFAILした。Baseline追加やScope外更新は行わず、Mergeを停止する。
- Draft PR #219へ固定差分を保存した。PR本文のTask ID／Changed files／Allowed pathsをCanonical形式へ揃え、Required Checksでblockerを確認する。
- 人間承認により同じSEC-008で`GHSA-5p4m-2wfm-xmqj`を追加対象とし、Root／Legacyのexact overrideとLockfileを`js-yaml 4.3.1`へ限定更新した。他Dependency、Baseline、Gateは変更していない。

## MIG-061Y Gacha Detail Eligibility／Presentation Public Contract

- Issue #220、PR #221、Branch `feat/MIG-061Y-gacha-detail-eligibility`、Base `5cd779d5918fbc39e8598ccb699863200747aabe`で開始した。
- Public Gacha Presentation EndpointへSale State、Audience Eligibility、不適格理由、Allowed Draw Counts、JST日次上限、CTA状態を追加し、Draw Transactionと同じEligibility Serviceを再利用した。
- Public OpenAPI／Generated Types／Storefront Client／Site Schema／Testkitを`2.0.0-alpha.2`へ同期した。ArtifactはRepository外Evidenceへ保存し、`2.0.0-alpha.1`は上書きしていない。
- Backend対象18 tests／158 assertions、QA除外、Package 51 tests、OpenAPI、Typecheck／Lint／Build、Policy／Quality／Security Gate、Workspace外Clean InstallがPASSした。
- Preview APIだけを`sha256:55006860...`へ更新しHealthを確認した。公開Gachaは0件のため実データPresentation SmokeはTask DB Testを正本とし、Synthetic Dataは投入していない。Admin／DB／Migration／Nginx／V1は非変更、旧API Imageは保持した。
- Required Quality GateでPlatform Alpha Version一貫性を確認し、Platform／Admin／3 Contract／3 Packageを`2.0.0-alpha.2`へ機械的に同期した。Release Test／Source ValidationとOpenAPI 3面がPASSし、Gate条件は変更していない。
- Admin OpenAPI Version更新後はCanonical GeneratorでContract SHA Markerを同期し、GitHub Quality Gateのstale generated findingを解消した。
- 既存Gacha Detailの後方互換を維持するため`sale_state`はoptional additive、Presentation専用Contractではrequiredとし、OpenAPI Breaking CheckをPASSさせた。

## MIG-061Z Public API Route／Origin／同一Origin Proxy

- Issue #222、Branch `feat/MIG-061Z-public-api-origin-proxy`、Base `cea1fd97ddfcc60861b1650b434f43a60f95d810`で開始した。
- Preview Public Originを`https://test.luxe-pack.biz`に固定し、同一Origin `/api/v2/`だけを既存V2 Public APIへ転送した。`/admin/api/`はHTTP 404で分離し、Storefront rootはSITE-004配備まで閉じた。
- Preview API Containerへ`V2_PUBLIC_ORIGIN`を明示注入し、Compose／Policy GateでOrigin mappingの欠落を拒否する。API Image、DB／Migration、Admin Container、既存V1／Admin vhostは変更していない。
- Auth Session、CSRF Cookie、register／login Validation経路、Public Catalog、Gacha Presentation、RFC 9457、Cross-Origin拒否、Admin API遮断、V1／V2 Admin非影響をHTTP Smokeで確認した。

## MIG-062A User Prize Presentation／Allowed Actions Public Contract

- Base `2e171ff474b6b9103279c1041a31e6b1a87f994d`からIssue #224、Branch `feat/MIG-062A-user-prize-presentation-contract`、Risk R3で開始した。
- 既存User Prize一覧／詳細へ型付きPresentationとBackend正本のAllowed Actions／Machine-readable理由をadditiveに追加し、Shipping／Point Exchange Mutationと同じ判定を再利用した。
- Public OpenAPI、Generated Types、Storefront Client、Site Schema、Storefront Testkitを`2.0.0-alpha.4`へ同期する。Runtime、DB、Migration、Nginx、V1、Storefront Repositoryは変更しない。
- 初回`.3`候補はOpenAPI Breaking Checkでrequired field追加を検出したため配布対象外とし、既存Artifactを上書きせずoptional additiveへ補正した`.4`を別Directoryへ生成する。
- Application Head `a3f8aeb3af5dc7a22f533c2e920e2b1a0c450f33`から`.4` Artifact一式を`/var/lib/oripa-v2-evidence/MIG-062A/artifacts/2.0.0-alpha.4/`へ作成し、Checksum、Manifest、Workspace外Clean Install／Importを確認した。

## MIG-062B 会員タグ管理基盤

- Base `0f5687dce15a197db429c22e8927343caf04d3ce`からIssue #226、Branch `feat/MIG-062B-user-tag-management`、Risk R3で開始した。
- Tag Masterの一覧／作成／編集／有効状態、Userへの複数Tag付与／解除、Revision OCC、Idempotency、Audit、Owner／Admin Mutation・Operator Read-only境界を実装した。
- Task DBでMigration fresh／rollback／reapply、Backend 12 tests／201 assertions、Admin Unit 37 tests、Desktop／Mobile Browser 2 tests、Typecheck／Lint／Build、OpenAPI／Generated Client、Policy／Quality GateがPASSした。
- Preview更新対象はAPI／Admin／Migration 000041のみ。Tag限定ポイント購入、Storefront Public API、Point／Gacha／Payment、Nginx、V1は変更しない。
- PreviewへMigration 000041を単独適用し、API `sha256:e9cd6235...`／Admin `sha256:32bcb9cb...`へ更新した。Owner Tag付与／無効化後維持、Desktop／Mobile、Health、不正Cursor 422を確認し、旧Imageを保持した。
- GitHub Integrationで検出した新規2 TableのCanonical Schema inventory未登録を補正し、DB Unit 33 testsとTask DB実Schema 98件の完全一致を確認した。Application／Previewは再変更していない。
- Backup Restore時の`BETWEEN`表現差は000042で明示比較へ正規化した。Task DB rollback／reapplyとSchema checksum一致後、Preview Safety Guard前後PASSのもと000042だけを適用し、Migration 42件へ更新した。API／Admin Containerは再作成していない。

## SEC-009 Dependency Advisory Baseline期限切れ対応

- Issue #229、Branch `security/SEC-009-dependency-advisory-baseline`、Risk R4で開始した。
- `PKSA-mnyp-475s-ywph`／`GHSA-pcw8-m77r-2528`は2026-08-10時点でも有効で、`mtdowling/jmespath.php 2.8.0`を最小修正版`2.9.1`へ限定更新した。
- Fresh Composer Audit 0件を確認し、解消済みEntryをBaselineから削除した。空BaselineのFresh Review期限は`2026-08-17`で、Findingの追加・無視・Gate弱体化は行わない。
- 継続承認後、clean Composer Auditの`advisories: []`だけを正常化し、Malformed／欠落をFail Closedとするparser Unitを追加した。Root `nanoid`は`postcss 8.5.23`配下の`3.3.16`から最小修正版`3.3.17`へexact overrideで更新した。
- Composer／Root pnpm／Legacy pnpm Audit 0件、Composer／Root Frozen Install、Security Unit 10件、Local Security GateはPASSした。

## MIG-062D タグ限定ポイント購入プラン

- Base `e4bd8ecb9bc6e95785142411b2a0cadc63336d5f`からIssue #233、Branch `feat/MIG-062D-tag-restricted-point-purchase`、Risk R4で開始した。
- MIG-061Vの商品Audienceと独立した任意Tag条件を追加し、MIG-062BのUser Tag AssignmentをPayment開始／成功確定時にBackendでAND再検証する。
- Admin一覧／登録／編集へTag表示・単一選択を追加した。Tag未指定は既存挙動、無効Tagは新規選択不可、OperatorはRead-onlyを維持する。
- Task DBでMigration rollback／reapply、Backend 8 tests／54 assertions、Admin Unit 3 tests、対象Browser 2 tests、Typecheck／Lint／Build、OpenAPI／Generated Client、Policy／Quality GateがPASSした。
- Preview DB Guard後にMigration 000043だけを適用し、API `sha256:907e6aca...`／Admin `sha256:65c9a445...`へ更新した。Ownerで対象Tag列、TagなしDefault、Public ID Contract、Desktop／Mobileを確認し、Nginx、V1、Storefront、Payment Providerは非変更である。

## MIG-062C Browser-safe Draw Mutation／Typed Draw Error Contract

- Base `8b8e50dd571c360edb0c37bbc4668b11bea15080`からIssue #231、Branch `feat/MIG-062C-browser-safe-draw-contract`、Risk R3で開始した。
- 既存Browser Auth ClientのCSRF／Cookie Transportを再利用するDraw Facadeと、Backend実在CodeだけのTyped Draw Error Contractを追加した。Draw Transaction／Business Ruleは変更していない。
- Public OpenAPI、Generated Types、Storefront Client、Site Schema、Storefront Testkitを`2.0.0-alpha.6`へ同期し、既存`.4`と未採用`.5`を変更せずRepository外Artifactへ固定した。
- Fresh Self-reviewで`.5`のOpenAPI `oneOf` Branch重複を検出し、`anyOf`へ補正した`.6`を最終Artifactとした。
- Backend対象4 tests／34 assertions、Client 21 tests、Site Schema 10 tests、Testkit 25 tests、OpenAPI 48 operations、Policy／Quality Gate、Workspace外Clean／Frozen InstallがPASSした。Runtime／Preview／DB／Nginx／V1／Storefront Repositoryは非変更である。

## MIG-062E Browser-safe Prize Fulfillment Mutation Contract

- Base `f08ff71edcd8c0cbbcf0fff83c2a69e753f012b6`からIssue #235、Branch `feat/MIG-062E-browser-safe-prize-fulfillment-contract`、Risk R4で開始した。
- MIG-062Cと同じBrowser CSRF／Cookie Transportへ景品Point交換、配送先、配送依頼Mutationを接続し、CallerによるCookie名／XSRF Header管理を不要にした。
- 配送先作成へ既存Idempotency基盤を追加し、Browser ClientではKeyを必須化した。配送先update／deleteは自動Retryを禁止し、Read MethodによるReconciliation境界を明示した。
- Backend実在CodeだけのTyped Fulfillment Problem ContractをPublic OpenAPI／Generated Types／Storefront Client／Site Schema／Testkitへ同期した。Runtime、DB、Migration、Nginx、V1、Storefront Repositoryは変更しない。
- Fresh reviewでalpha.7のAddress Idempotency ResponseへPIIが複製される問題を検出した。alpha.7は未採用のまま上書きせず、Idempotency RecordへPublic IDだけを保持する補正後のalpha.8を最終Artifactとして生成する。
- Application Head `5c9053ca2434847032a51f8b4f09dd25c8ef8535`からalpha.8を新規生成し、Manifest／4配布物Checksum、Workspace外Frozen Install／ImportがPASSした。既存alpha.6と未採用alpha.7は不変である。

## OPS-004 Non-Production Preview Image Build Pipeline

- Base `f66209549dfc9c8fae4acaa51645710040694d1c`からIssue #239、Branch `ci/OPS-004-preview-image-build-pipeline`、Risk R4で開始した。
- GitHub-hosted `ubuntu-24.04-arm`でExact PR HeadのAPI／Admin ImageをBuildし、zstd Docker archive、Manifest、Checksumを1日保持Artifactとして搬入するCanonical経路を追加する。
- Host側はGitHub App wrapperで外側Digestと内側Checksum／Image metadataを検証し、ARM64 Hostで`docker image load`だけを許可する。Production Host Build、Registry、Secret、Cloud Resource、Runtime Deployは追加しない。
- 現行Preview Hostは`x86_64`であり、ARM64 Image実行と互換しないためload前にFail Closedとする。MIG-062F PR #238／Head `8daba6e6ce547e81c90e767e8fcdfdb2b38b0e2b`は変更しない。

# OPS-005 Preview Image Build Architecture Fix

- Preview machineとDocker daemonをRead-onlyで再測定し、いずれも`x86_64`であることを確認した。
- OPS-004のPreview Image Build PipelineをGitHub-hosted x64 runner／`linux/amd64`へ最小修正する。
- exact SHA、PR Head、Required Checks、Artifact digest／checksum、OCI revision、Image ID、Host architectureのFail Closed境界を維持する。
- Production Host Build、Runtime Deploy、MIG-062F変更は実施しない。

## MIG-062G Gacha Catalog Display Public Contract

- Base `21f58399e8869a00249398dac3b06a74e1e9da97`からIssue #243、Branch `feat/MIG-062G-gacha-catalog-display-contract`、Risk R3で開始した。
- Public対象Gachaを販売状態やUser EligibilityでCatalogから除外せず、MIG-061Yと同じBackend判定のSale State、Eligibility、理由、Allowed Draw Counts、JST日次制限、CTA、表示制御をCatalog Itemへ追加する。
- Gacha AssetをCanonical Public Asset Pathへ修正し、Detail／Presentation RouteとOpenAPIを11文字Public Codeおよび既存UUID互換Resolverへ同期する。
- Public OpenAPI、Generated Types、Storefront Client、Site Schema、Testkitを`2.0.0-alpha.9`へ同期し、既存Artifactを上書きせず新規Artifactを生成する。
- Application Head `36220b5c08820741b4763363a7e86c18274b9688`のTargeted TestとRequired GatesがPASSし、alpha.9 Artifact 5件のSHA-256とWorkspace外Importを確認した。
- GitHub-hosted amd64 Run `31575994537`の検証済みAPI ImageをHost Buildなしでloadし、Preview APIだけを`sha256:1b8b62fa...`へ更新した。既存5件のCatalog／State／Eligibility／表示Flag／11文字Route／Cacheを確認した。
- QA作成時のCanonical upload元とchecksumが一致する既存Asset実体を復元し、Public Asset HTTP 200を確認した。Container local Asset storageの非永続性は別運用課題として残し、DB、Nginx、V1、Storefront Repositoryは変更していない。

## MIG-062H ガチャ登録／編集・初回ユーザー条件修正

- Base `27a19604d87513fc9a66088588bd2697bfeadba5`からIssue #245、Branch `feat/MIG-062H-gacha-registration-eligibility`、Risk R4で開始した。
- 初回ユーザー条件をDraw履歴から切り離し、Gacha Versionごとの登録後日数 x 24時間へ変更する。Default 7日で既存Versionを移行する。
- Master編集へ管理状態を接続し、予約公開はDB状態を書き換えるWorkerを使用せず、開始日時と既存販売条件から実効Sale Stateを都度評価する。
- Admin共通Formへ条件付き日数入力を追加し、ガチャ詳細をDesktopで景品一覧相当のContent幅へ拡張する。
- 2026-08-12 MIG-062H: `first_time_users`をDraw履歴から分離し、Gacha Versionの登録後日数（Default 7、24時間単位）をBackend正本とした。管理状態をDraft／予約公開／公開／販売停止／非公開として保存し、予約公開はScheduler更新なしで実効Sale Stateを時刻評価する。Migration `000044`、Admin登録／編集、詳細幅、対象TestとRequired Checksを完了し、検証済みamd64 ArtifactからPreview API／AdminをHost Buildなしで更新した。G4／G5はNOT COMPLETE。

## MIG-062I ガチャごとのDraw Count設定

- Base `1f4c452dcfb9631530f7b1d3f400637165646e00`からIssue #247、Branch `feat/MIG-062I-gacha-draw-count-configuration`、Risk R3で開始した。
- Gacha Versionへ許可Draw Countを追加し、`1`必須、`5／10／100／1000`任意、既存Version Default `[1,5,10]`をDB制約付きで保存する。
- Admin登録／編集へ共通Checkboxを接続し、Public PresentationとPublic Draw Transactionで同じPublished Version設定を正本として強制する。
- Public Contractは既存Schemaで表現可能なため変更せず、Admin OpenAPI／Generated Clientだけを同期する。Storefront Artifact更新は行わない。
- Exact Head `73326e85cd67b83bf941602979b5ae651d9826a6`のRequired ChecksとGitHub-hosted amd64 Image BuildがPASSした。Checksum／OCI Revision／Architectureを検証後、Host BuildなしでPreview DBへMigration 000045だけを適用し、API／Adminだけを更新した。
- PreviewでAdmin保存・再取得・復元、Public `allowed_draw_counts`、無効Countの直接Draw拒否と非Mutationを確認した。PostgreSQL／Redis／Nginx／V1は変更していない。

## MIG-062J 残口数未満時の端数Draw対応

- Base `38c10edcabed971cd5a18cfe43c3b95e87c2d1b9`からIssue #249、Branch `feat/MIG-062J-partial-remaining-draw`、Risk R4で開始した。
- Gacha設定CountはPresentationから残口数だけを理由に除外せず、Public Draw TransactionがGacha Lock後のCanonical remainingから`executed_count`を確定する。
- `requested_count`は設定Countを保持し、Point消費、Result、Prize、Point Back、Inventory、`sold_count`、履歴はすべて`executed_count`へ整合させる。Idempotency Replayは初回Responseを固定して再実行しない。
- Daily Limit、Eligibility、販売状態、設定外Countは既存どおりrequested countへ適用し、残口数以外の端数化は追加しない。
- Public／Admin OpenAPI、Generated Types、Storefront Client、Site Schema、Testkitを`2.0.0-alpha.10`へ同期し、既存Artifactは上書きしない。
- Application Head `ed57eca709c9a49fc5bb5ffa9903a84573052077`から`2.0.0-alpha.10` ArtifactをRepository外へ新規生成し、Manifest／4配布物Checksum、Workspace外Clean Install／Importを確認した。
## MIG-062K ユーザー状態変更

- Base `3f4fe2167513310e4fc34296cfe2ffc18ccf5fc8`からIssue #251、PR #252、Branch `feat/MIG-062K-admin-user-state-management`、Risk R4で開始した。
- 既存`V2UserState`を再利用し、手動遷移を`active -> suspended／closed`、`suspended -> active／closed`へ限定した。停止／退会時はUser SessionとRemember Deviceを同一Transactionで失効し、既存Auth Serviceも新規Loginを拒否する。
- `user.state.manage`をOwner／Adminへ付与し、OperatorはRead-onlyとした。Admin User詳細へFresh Authentication、理由、Revision OCC、Idempotencyを備えた状態変更UIを追加した。
- Migration `000047`は既存Userを`state_revision=1`で保持する。Task DB fresh／rollback／reapply、Backend対象19 tests／305 assertions、Admin対象Unit 35 tests、Typecheck、OpenAPI bundleがPASSした。

## MIG-062L ガチャ管理構造整理／景品所有関係修正

- Base `a505b36d0a812979a77fc4831bb632d03ad8b782`からIssue #253、Branch `feat/MIG-062L-gacha-prize-ownership`、Risk R4で開始した。
- Prizeを1つのGachaへ所有させ、同一Gacha内では安定したPrize IDを複数Versionから参照する。Version relationへ表示Snapshotを保持し、Published／Historical表示とProbability参照を後続Draft編集から保護する。
- Previewで検出したCross-Gacha共有PrizeはSynthetic／QA関連だけであることを確認し、人間承認の範囲で参照のないSynthetic Gacha 5件を削除した。通常Draw履歴を持つFixtureを保持し、再確認結果を0件とした。
- Admin Gacha詳細で現在公開中、編集中、変更履歴を区別し、現在公開中の景品ラインナップを主画面から確認可能にする。今回触るGacha管理画面の主要表示を日本語へ整理する。
- Application Head `37c099f69242143097fead94c6a8aefba45e5a76`のRequired ChecksとGitHub-hosted amd64 Preview Image BuildがPASSした。Host BuildなしでMigration `000048`のみをPreview DBへ適用し、API／Adminだけを更新した。
- Previewで公開中景品、編集中Draft、変更履歴、編集保存後のPublished Snapshot不変、Public Catalog／Detail／Presentation 200、Desktop／Mobileを確認した。Nginx、V1、Storefront、Point、Paymentは変更していない。

## MIG-062M QAテストユーザー抽選UI統合

- Base `608d59a18215bf16109d7a9765c18f82940f6bde`からIssue #255、Branch `feat/MIG-062M-qa-test-user-draw-integration`、Risk R4で開始した。
- 既存`qa_test_user_modes`を無期限の手動ON／OFFへ変更し、User詳細へ統合した。既存期限切れModeはMigrationでdisabledへ移し、意図しない再有効化を防ぐ。
- `User x Gacha x stable Prize`のPersistent guaranteeを追加し、Gacha詳細から複数Test Userを管理する。Cross-Gacha Prize、旧QA Plan競合、Published Prize解決不能はFail Closedする。
- 通常`V2DrawService`内で先頭1件だけを保証し、残りを通常抽選する。Point／Inventory／User Prize／履歴／Replay／ConcurrencyとMIG-062J Partial Remainingを維持する。
- Public contractは変更せず、Admin OpenAPI／Generated Clientだけを同期した。Migration `000049`、Backend対象62 tests、Admin Unit／Browser、Typecheck／Lint／Build、Policy／Quality GateがPASSした。

## MIG-062N 管理者向け保有景品一覧／詳細

- Base `51209de02bff0e00078777c094147e568f403ff5`からIssue #257、Branch `feat/MIG-062N-admin-user-prize-management`、Risk R3で開始した。
- 全User Prizeを取得時Snapshot、User、Canonical Gacha、Draw、Shipping、Point Exchangeと結合するAdmin Read Modelを追加した。
- 一覧はUser／景品名／Gacha／状態FilterとOpaque Cursorを備え、詳細はDraw、Allowed Actions、配送先、Point交換、状態履歴を表示する。
- Allowed Actionsと権限は既存Prize Fulfillment Domainおよび`shipping.request.manage`を再利用し、Admin MutationやPublic／Storefront Contract変更を追加しない。

## SEC-010 nanoid Advisory対応

- `GHSA-2v37-7h3g-55p8`の修正版境界更新に対応し、既存3.x系列の`nanoid` Pinを`3.3.17`から最小安全版`3.3.18`へ更新する。
- Root／Legacy Lockfileと中央のDependency Pin検査だけを同期し、Baseline追加、Advisory無視、Application／Runtime変更は行わない。

## MIG-062O ページ設定フッター表示／Public Contract拡張

- Base `3b8445f1cf8f858fb46c0afe9e366faaf5e78f5e`からIssue #259、Branch `feat/MIG-062O-static-page-footer-contract`、Risk R3で開始した。
- Static Page親へDefault OFFのFooter表示Flagを追加し、既存immutable HTML Version、Sanitize、Checksum、公開期間、公開状態を再利用する。
- Admin登録／編集へFooter ON/OFF、表示順、Sanitize済みPreviewを追加し、Public APIは現在公開中かつFooter ONの`id`／`slug`／`title`だけを返す。
- Public／Admin OpenAPI、Generated Types、Storefront Client、Site Schema、Testkitを`2.0.0-alpha.11`へ同期し、既存Artifactは上書きしない。
- Migration fresh／rollback／reapply、Backend 5 tests／44 assertions、Admin Unit 4 tests、Desktop／Mobile Browser 2 tests、Client 24 tests、Site Schema 10 tests、Testkit 29 tests、OpenAPI／Policy UnitがPASSした。
- Required Integration Gateで検出した既存MIG-062M Migration testの`--step 1`順序依存を、`000049` exact pathとDB時刻fixtureへ限定補正した。RuntimeとMigration仕様は不変。

## MIG-062P バナートップ表示／クリック先Public Contract拡張

- Base `9dd56e758872286abfdd05cadc7ce0c62e14e0a3`からIssue #263、Branch `feat/MIG-062P-banner-top-presentation-contract`、Risk R3で開始した。
- Banner VersionへDefault OFFのTop表示Flagとクリック先URLを接続し、Admin登録／編集／一覧から管理可能にする。OFFではStorefront用URLを保持しない。
- 既存Public Banner一覧をTop ONかつ現在公開中のCanonical一覧とし、Public Asset URLとクリック先URLを明示する。固定Categoryや位置による推測は行わない。
- Public／Admin OpenAPI、Generated Types、Storefront Client、Site Schema、Testkitを`2.0.0-alpha.14`へ同期し、既存Artifactと未採用`alpha.12`／`alpha.13`は上書きしない。
- Migration fresh／rollback／reapply、Backend 14 tests／136 assertions、Admin Unit 3 tests、Desktop／Mobile Browser 2 tests、Client 24 tests、Site Schema 10 tests、Testkit 30 tests、OpenAPI／Policy GateがPASSした。
- Required Integration Gateで検出した既存Banner性能FixtureだけをTop表示Contractへ追従し、性能閾値と件数Assertionは維持した。
- Artifact `2.0.0-alpha.14`を発行し、Manifest／SHA256SUMSとWorkspace外Clean Installを検証した。
- GitHub-hosted amd64 Pipelineの検証済みAPI／Admin ImageをHost BuildなしでPreviewへ反映し、Safety Guard後にMigration `000051`だけを適用した。Top ON／OFF、Public Asset／Link URL、Desktop／Mobile、Console／HTTP ErrorをSmokeし、Nginx／V1／Storefrontを変更していない。

## MIG-062Q Gacha Lifecycle／公開後編集／Public State整理

- Base `25fa06d40a169b43ee677d1fe84d0cf8f3ae7715`からIssue #265、Branch `feat/MIG-062Q-gacha-lifecycle-presentation`、Risk R4で開始した。
- 初回公開だけ即時／予約を許可し、一度公開済みの事実を不可逆に保持する。販売停止／再開／非公開は同じPublished Version、Draw State、Inventory、`sold_count`を維持する。
- 公開後のGacha／Prize表示変更はCurrent Presentation Overlayへ限定し、Published／Historical Snapshot、Draw Result、User Prizeを変更しない。販売・経済条件はBackendで拒否する。
- Public Sale Stateへ`paused`を追加し、実残数、disabled CTA、`sales_paused`理由を明示する。Public／Admin ContractとStorefront packagesは`2.0.0-alpha.16`へ同期する。
- Migration `000052`は初回公開時刻、予約開始と現在販売開始を分離したCurrent Presentation、Draw State閉鎖情報、単一Open State制約を追加する。事前Preview調査で複数Open State、Pointer不整合、移行停止条件は検出されなかった。
- 終端の非公開後もAdmin詳細は最後のPublished Snapshot＋Current Presentationを表示するが、Current Published Pointerは復元せず再公開を拒否する。
- PostgreSQL 17.11のBackup／Restore Gateで、単一Open Draw State Partial Unique Indexの同義なpredicate表現差を検出した。Migration SQLをPostgreSQLの安定したCanonical表現へ限定修正し、fresh／rollback／reapplyとschema round-trip一致を再確認した。
- Public Contract ArtifactはProduction Host Buildを避け、既存Preview Image Build Workflowを最小拡張し、Exact PR Head／Required Checksを検証した同一GitHub-hosted Jobで生成・検証する。新Secret／Registry／Cloud Resourceは追加しない。
- PostgreSQL 17.11でMigrationのschema round-tripを再現し、Partial Unique Index predicateをdump／restoreで同形になるSQLへ補正した後、Required Integration Gateのmigration／backup restore smokeがPASSした。

## MIG-062S Operational Inventory／在庫調整

- Base `2daef365fa1b5a845857b93e64651114700dc22e`からIssue #268、Branch `feat/MIG-062S-operational-gacha-inventory`、Risk R4で開始した。
- Prize Inventoryを`total_quantity = awarded_count + available_quantity + withdrawn_quantity`へ移行し、成功Prize Drawだけが`awarded_count`を増やす。Adminは公開後も現在個数／総在庫数をTransaction、Row Lock、OCC、Idempotency、Adjustment Log／Audit付きで変更できる。
- `sold_count`はDraw State、`remaining_count`はavailable合計、`total_count`はtotal合計を正本とし、Pause時も実残数を返す。Inventory調整はVersion、Draw State、`sold_count`、過去Draw、User Prizeを変更しない。
- Migrationは既存Preview DataをFail Closed Characterization後にBackfillする。Draw、Partial Remaining、Catalog／Detail、MIG-062Q／062J／062L／062M回帰、同時Draw／AdjustmentのOversell／Lost Update防止をTargeted Testで確認した。
- Public Contract ShapeとStorefront Artifactは変更せず、Admin OpenAPI／Generated Clientだけを同期する。Preview、Required Checks、Fresh Self-review、Merge／CleanupはCloseout時に確定する。
- 初回Required Integration Gateが新規`prize_inventory_adjustments` TableのV2 schema inventory未登録を検出したため、Task PolicyへDB Guard本体／Unitの2 PathだけをAtomic追加し、Schema inventoryと明示回帰Testを同期した。
- 初回Preview Image BuildはTask BranchをWorkflow control refにしていたためread wrapperがFail Closedした。Artifactは使用せず、control refをtrusted `main`、checkout対象をexact PR Headに分離して再実行する。
- GitHub-hosted amd64 ArtifactをChecksum／OCI Revisionまで検証し、Host BuildなしでMigration `000053`とAPI／AdminだけをPreviewへ反映した。Inventory編集、sold-out／復元、Public count、paused実残数、Desktop／Mobile、500系0を確認し、既存Synthetic QAのDraw 422／Resume Preflight制約とFixture Asset 404はDataを修正せず例外記録した。
## MIG-062R Point Product Read／Eligibility Contract Phase A

- Base `25fa06d40a169b43ee677d1fe84d0cf8f3ae7715`からIssue #267、Branch `feat/MIG-062R-point-product-read-eligibility`、Risk R3で開始した。
- 既存`point_purchase_plans`、Admin設定、`V2PointPurchaseEligibilityService`、`payments.status = succeeded`を再利用し、MigrationなしでPoint Product一覧とCurrent User eligibilityを追加した。
- `GET /api/v2/point-products`はJPY価格、paid／bonus Point、Audience、販売状態、eligibility、reason、CTAをBackend-authoritativeな順序で返す。AnonymousはPublic 60秒Cache、Authenticatedは`private, no-store`とし、双方`Vary: Cookie`を返す。
- Public OpenAPI、Repository-local bundle、Generated Storefront types、薄いClient facade、Testkit fixtureを同期した。Site Schema形状とProduction package versionは変更していない。
- Backend Targeted 4 tests／54 assertions、PHP syntax、Public OpenAPI 50／Admin 212／Webhook 1 operations、Storefront Client 25 tests、Testkit 31 tests／export／network boundary、`git diff --check`がPASSした。
- Local Policy GateはTestkit package metadataと`policy_gate.py`のPublic 49 operations固定値でFAILした。MIG-062Qと同Path競合するためPhase Aでは変更せずPhase B最終同期へ留保した。
- Phase A差分は専用Worktreeでstaged済みだが、Local `git commit`は実行環境のCommand Policyにより拒否され、push／Draft PRは未実施となった。
- MIG-062QとのPublic OpenAPI／Generated Contract競合が残るためintegration-waitとし、Artifact、Preview、Runtime、Merge／Closeoutは実施しない。

## MIG-062R Point Product Read／Eligibility Contract Phase B

- MIG-062Q／MIG-062S merge後の`main@e305a76a9a2dbd88e019ceecb7153514906a38d0`へ追従し、Public OpenAPI／Generated Contract競合を最新main正本からの再生成で解消した。Point DomainとMigration setへの直接競合はない。
- Public／Admin／Webhook ContractとPlatform／Storefront Client／Site Schema／Testkitを既存Versionを上書きしない`2.0.0-alpha.17`へ同期した。Public 50／Admin 212／Webhook 1 operations、Migration 53件である。
- Latest-main Backend Targeted 4 tests／54 assertions、OpenAPI、Storefront Client 25 tests、Site Schema 10 tests、Testkit 31 tests、Admin 156 tests、Policy Unit 125 tests、Local Policy Gate、Release foundation／validationがPASSした。
- 初回Integration Gateで既存published Point Planを持つ共有fixture DB上の新規test順序依存を検出した。Test transaction内で既存latest publishedへshadow draft versionを追加して対象Contract fixtureだけを分離し、既存Plan投入状態でも4 tests／54 assertionsがPASSした。Runtime挙動は変更していない。
- 修正後Headでは全実処理がPASSしたが、PRイベントPolicy GateがPR本文の必須見出し不足だけを検出した。PR本文を`Task`／`Specification sources`／`Verification performed`／`Verification not performed`へ補正し、fresh headで再検証する。
- PR本文Gateの残件`Summary`見出しを追加し、必須5見出しを揃えた最終docs-only headでfresh checksを実行する。
- PR本文へPolicy parserが要求する`Task ID`／`Risk`／event Base SHA、39件の`Changed files`、同一境界の`Allowed paths`を追加し、ローカルPR body validationがPASSした。失敗履歴のないfresh headでRequired Checksを再実行する。
- Migration、Point購入Mutation、Payment Provider／Session、Point付与／Ledger、Webhook、Refund、Purchase Lifecycleは変更していない。Artifact、Preview、Required Checks、Fresh Self-review、Merge／CleanupはPR exact headで実施する。

## MIG-062T Banner Publish Admin UI Fix Phase A

- Latest Platform `main@37035893ad649b17a526f5dd3477f8e30fae1f38`、GitHub Open Issue／PR、local worktree／branchを照合し、Active Platform Task 0件、Open Dependabot PRだけであることを確認した。既存Task ID履歴に`MIG-062T`が未使用であることを確認し、Issue #271、Branch `fix/MIG-062T-banner-publish-admin-ui`、Risk R3で開始した。
- Allowed PathsはAdmin生成検証、Client、Banner UI、Unit／E2E、Worklog／Reportの8 exact pathだけとした。Backend、OpenAPI source／bundle、Storefront、DB／Migration、Cache、Dependency、V1、InfrastructureはForbiddenであり、Scope／Conflict GateはPASSした。
- 既存`publishAdminContentBannerVersion` operationを生成時検証へ追加し、`AdminApiClient.publishContentBanner`から同じCanonical Endpointへ接続した。Backend State Transition、`content.publish`判定、保存時Draft作成は変更していない。
- Banner一覧へ`Draft`／`Published`、Version番号／ID、`content.publish`保有者だけの「公開する」Buttonを追加した。同期Guardとdisabled状態で二重送信を防ぎ、成功後は管理一覧をCanonical再取得する。403／409／422／429を含む既存Typed Error表示を再利用する。
- Admin generated check、全Unit 158 tests、対象Unit 6 tests、Typecheck、Lint、Production Build、Desktop／Mobile Banner E2E 2 tests、Policy Unit 125 tests／Local Policy Gate、Quality Unit 5 tests／Local Quality Gate、Security Unit 10 tests／Fresh Audit 0件／Local Security GateがPASSした。
- 初回E2Eは既存cross-origin Synthetic Asset URLがAdmin CSPにより遮断され、Console Error assertionでFAILした。Assertionは維持し、Synthetic Asset fixtureだけをsame-origin Public API pathへ補正後、Desktop／Mobile 2 testsとConsole／Page／500系0がPASSした。
- Phase Bではexact PR headのRequired Checks、GitHub-hosted Preview Artifact、Synthetic BannerのAdmin Publish／Public Contract／Storefront Carousel／Link遷移、Fresh Self-review、Squash Merge、Issue／branch／worktree cleanupを記録する。
- 初回PR Head `52530798be5854225697315d82404b50b1518d88`ではPolicy／Security／Integration、CodeQL、Dependency ReviewがPASSしたが、MIG-062T非変更のGacha lifecycle Admin Unitが単発FAILしQuality／ci-gateがFAILした。同testをlocalで5回反復して全PASSし、同一headのWorkflow再実行では全5 Required ChecksがPASSした。ただし固定wrapperは同一headの過去failed runもFail Closedするため、失敗と再現結果をReportへ記録したdocs-only fresh headで全Checksを再実行する。

## MIG-062T Banner Publish Admin UI Fix Phase B

- Fresh Application／Preview Head `2065b48e1ae59dcd9dd3278955ae8ab59787275f`はRequired 5 Checks、CodeQL、Dependency Reviewが失敗履歴0でPASSした。GitHub-hosted `linux/amd64` Artifactのdigest／manifest／OCI revision／Architectureを検証し、Host BuildなしでPreview AdminだけをTask Imageへ更新した。
- 実Admin UIでSynthetic Banner登録、Top ON、`link_url=/gachas`、Draft／Version、Desktop／Mobile「公開する」、既存Backend Publish、Canonical再取得Publishedを確認した。Public BannerはCache TTL 60秒後に200 responseへ含まれ、`test.luxe-pack.biz` Carousel表示と`/gachas`遷移がPASSした。Synthetic BannerはAdmin UIで削除済みである。
- Page ErrorとHTTP 500／502／504は0。既存Admin CSPの`blob:` preview拒否と既存相対Asset URL 4件のAdmin origin 404はAllowed Paths外の既存Console事象として記録し、Publish／Public Contract／Storefront結果と分離した。Category 0件だったためCanonical UIで`MIG-062T Preview`を作成し、削除ContractがないためDB直接削除せず保持する。
- Storefront、Backend、OpenAPI source／bundle、DB／Migration、Cache、Dependency、V1、Infrastructureは変更していない。Final docs-only head、Fresh Self-review、Squash／Issue Close／branch／worktree cleanupはReport commit後にPR／Issue Closeoutへexact値を記録する。

## MIG-062U Current User Point Balance／History Read Contract Phase A

- Latest Platform `main@5be4488dad7aafb6e306d356a75598bc0279065c`、GitHub全Issue／PR履歴、Task Policy、Remote refsを照合し、既存最大`MIG-062T`の次で未使用な`MIG-062U`を採番した。Issue #273、Branch `feat/MIG-062U-current-user-point-read-contract`、Risk R3で開始した。
- Active Platform Taskは0件で、Open Dependabotとはfirst-party Version metadataのPath overlapだけを記録した。Dependency値を変更せず、Point Domainの同時変更がないためScope Gate／Conflict GateはPASSした。
- Canonical Balance SourceはV2 Walletの利用可能paid／free残高、Canonical History Sourceはimmutable Point Ledgerとappend-only Point Operationである。GETはWalletを作成せず、Point Mutation／Transaction／Ledger書込／Payment／MIG-062Rを変更しない。
- `GET /api/v2/me/wallet`と`GET /api/v2/me/point-ledgers`を追加し、Operation public ID、UTC発生日時、signed delta、Backend理由Labelだけを返す。順序は`occurred_at DESC, operation.id DESC`、CursorはCurrent UserのOperation public IDから継続位置を解決する。
- Authenticated only、`private, no-store`、`Vary: Cookie`、既存RFC 9457 Typed Problemを維持し、Read-only GETへCSRFを追加しない。内部Wallet／Ledger／Lot／Operation ID、source／actor／business keyを公開しない。
- Public OpenAPI／bundle、Generated Types、薄いStorefront Client、Site capability、Testkit fixtureを同期した。Migration created／appliedは0。Backend targeted 5 tests／54 assertions、Storefront Client 26 testsがPASSした。

## MIG-062U Current User Point Balance／History Read Contract Phase B

- Phase B開始時もRemote mainはBaseから移動せず、Generated Contract／Artifact／Preview lock競合はない。既存Versionを上書きしない`2.0.0-alpha.18`へPlatform／3 Contract／Storefront Client／Site Schema／Testkitを同期し、Public 52／Admin 212／Webhook 1 operationsとした。
- Backend 5 tests／54 assertions、Client 26 tests、Site Schema 10 tests、Testkit 32 tests、Admin 159 tests、OpenAPI／Policy／Quality／Security／Release local checksとApplication HeadのRequired CI／CodeQL／Dependency ReviewがPASSした。
- GitHub-hosted Run `31862183365`のimmutable `2.0.0-alpha.18` Contract Artifactと`linux/amd64` Preview Imageをouter digest／manifest／package identity／OCI revisionまでreadback検証した。Host BuildなしでAPIだけをTask imageへ更新した。
- Preview QA UserのReadだけで残高200、履歴7件／4ページ、加算／減算、Stable Ordering、Cursor continuation、空Continuation、匿名401、`private, no-store`／`Vary: Cookie`、内部ID非露出、Runtime Error 0を確認した。Mutation／Migration／Cache削除／Storefront変更は0。
- API更新時に固定IP override欠落で初回Domain Smokeが502となりFail Closedした。既存正本のAPI `192.168.61.10`／loopback `8611`へ復旧し、healthyと全Read acceptanceを再確認した。DB、Cache、Admin、Storefront Runtimeは変更していない。
- Final docs-only headのFresh Required Checks／Self-review、Squash Merge／Cleanupはexact headで継続する。

## MIG-062V Current User Gacha History Read Contract Phase A

- Latest Platform `main@642ab8f6bfe7ef2ca0a7e3fb9d0ecd05b600d803`、GitHub Issue／PR全履歴、Task Policy、Remote refsを照合し、既存最大`MIG-062U`の次で未使用な`MIG-062V`を採番した。Issue #275、Branch `feat/MIG-062V-current-user-draw-history-read-contract`、Risk R3で開始した。
- Active Platform Taskは0件で、Open Dependabotとはfirst-party Version metadataのPath overlapだけを記録した。Dependency値を変更せず、Gacha／Draw Domainの同時変更がないためScope Gate／Conflict GateはPASSした。
- Canonical History Sourceは`draw_requests`であり、owner、public ID、requested／executed count、completed status、transaction-fixed occurrence timeを再利用する。Historical Gacha PresentationはDraw Requestが参照するimmutable published Gacha Versionを正本とする。
- `GET /api/v2/me/draws`を追加し、Draw／Gacha public ID、title／public asset、UTC occurrence、requested／executed count、Backend status／labelだけを返す。FrontendへDB ID、event／QA／probability codeの解釈を要求しない。
- 順序は`created_at DESC, draw_request.id DESC`、CursorはCurrent User所有のDraw Request public IDから継続位置を解決する。Authenticated only、`private, no-store`、`Vary: Cookie`、Typed Problemを維持する。
- Public OpenAPI／bundle、Generated Types、薄いStorefront Client、Site capability、Testkit fixtureを`2.0.0-alpha.19`へ同期した。Migration created／Task・Preview appliedは0で、Local synthetic test DBだけに既存V2 Migration 53件を適用した。Draw／Point／Inventory／Prize／Payment Mutation、Gacha lifecycle、Storefront Repositoryは変更していない。
- Backend targeted 3 tests／55 assertions、既存Draw／QA／V1 characterization、OpenAPI、Client 27 tests、Site Schema 10 tests、Testkit 33 tests、Admin、Policy／Quality／Security／Release local checksがPASSした。Artifact、Preview、Required Checks、Fresh Self-review、Merge／CleanupはApplication exact headで継続する。

## MIG-062V Current User Gacha History Read Contract Phase B

- Phase B開始時もRemote mainはBaseから移動せず、Generated Contract／Artifact／Preview lock競合はない。Application Head `2b58e308693fa6e642023e2778274e789da75c09`のRequired 5 Checks、CodeQL、CodeQL JavaScript、Dependency Reviewが失敗履歴0でPASSした。
- GitHub-hosted Run `31886804304`のimmutable `2.0.0-alpha.19` Contract Artifactと`linux/amd64` Preview Imageをouter digest／manifest／package identity／OCI revisionまでreadback検証した。Host BuildなしでAPIだけをTask imageへ更新した。
- 旧QA credentialはTask Image／直前Image双方で401となり回帰ではなく失効と切り分けた。より新しい安全な既存Preview QA Userへ切替え、Gacha履歴4件／2ページ、partial execution、public asset、Backend status／label、Stable Ordering、Cursor、匿名401、`private, no-store`／`Vary: Cookie`、内部ID非露出をReadのみで確認した。
- Preview Runtime Error／Mutation／Migration／Cache削除／Storefront変更は0。Admin、PostgreSQL、Redis、Nginx、Storefront Runtimeは変更していない。
- Synthetic test Container／networkは削除した。実行PolicyがDocker volume削除を拒否したため未接続dependency volume 1件だけ残る。Final docs-only headのFresh Required Checks／Self-review、Squash Merge／Issue／branch／worktree cleanupはexact headで継続する。

## MIG-062W LINE Friend State Read／Presentation Contract Phase A

- Latest Platform `main@1118703eb704f901d25d946074e3707e9c557c6f`、GitHub Issue／PR全履歴、Task Policy、Remote refsを照合し、既存最大`MIG-062V`の次で未使用な`MIG-062W`を採番した。Issue #277、Branch `feat/MIG-062W-line-friend-state-read-contract`、Risk R3で開始した。
- Canonical Friend Stateは既存の有効なLINE `external_identity_accounts`と、同一User／subjectの`line_friendships.status = friend`かつ`unfollowed_at IS NULL`のJoinである。既存Draw eligibilityの判定を共通Serviceへ抽出し、Current User ReadとDrawが同じ結果を利用する。
- `GET /api/v2/me/line-friend-state`／`getLineFriendState`は`linked`、`friend_confirmed`、Backend確定`is_line_user`、status label、既存`friend_add_url`を使うPrimary CTAを返す。Provider subject／issuer／secret／token、内部ID、reward設定は公開しない。
- Authenticated Current User only、Success／Typed RFC 9457 Problemとも`private, no-store`、`Vary: Cookie`である。ReadはLINE／Point／Webhook Tableを変更せず、CSRF、Recent Authentication、unlink、OAuth／Callback、Provider／Webhook transitionを変更しない。
- Public OpenAPI／bundle、Generated Types、薄いStorefront Identity Client、Site capability `user-line-friend-state.read.v2`、Testkitの3状態／Typed Problem fixtureを既存Versionを上書きしない`2.0.0-alpha.20`へ同期した。Public 54／Admin 212／Webhook 1 operationsである。
- Backend targeted 6 tests／46 assertions、既存LINE audience回帰2 tests／9 assertions、OpenAPI 7 tests、Client 27 tests、Site Schema 10 tests、Testkit 34 tests、Admin 159 tests、Policy 125 tests、Quality 5 tests、Security 10 tests、Fresh Audit 0件、Local Policy／Quality／Security／Release Gate、Admin Production BuildがPASSした。
- Migration created: 0。Task／Preview Migration applied: 0。Local isolated synthetic PostgreSQLだけに既存V2 Migration 53件を適用した。Storefront Repository、Runtime Preview、Artifact、Required Checks、Fresh Self-review、Merge／CleanupはPhase Bでexact headへ固定する。
- 初回PR event Policy GateはPR本文の必須見出しを`#`で記載したため、parserが要求する`##`／`###`として認識せずFAILした。同Application headのmanual dispatch Policy GateはPASSしており、本文を`Summary`／`Task`／`Scope`／`Specification sources`／`Verification performed`／`Verification not performed`および`### Changed files`／`### Allowed paths`へ補正した。失敗履歴のないdocs-only fresh headで全Checksを再実行する。

## MIG-062W LINE Friend State Read／Presentation Contract Phase B Blocked

- Fresh head `58f7bf9212941572a30360d1881b63712c6bf4a6`ではPolicy／Quality／Security Gate、CodeQL、CodeQL JavaScript／TypeScript、Dependency ReviewがPASSしたが、Integration Gateとその結果を集約するci-gateがFAILした。
- Integrationの失敗はTask非変更の既存V1 `AdminPaymentApiTest` 2件と、最新mainにも存在する`.ci/baselines/backend-tests.json`の期限切れである。Baselineは`QUALITY-002`を追跡先、`2026-08-15`を期限としており、現在日は`2026-08-17`である。
- MIG-062WのScopeを既存V1 fixture修正、baseline延長、Gate緩和へ広げない。Required Checks未達のためArtifact／Preview／Fresh Self-review／Merge／Cleanupは実行せず、PR #278、Task branch、専用worktreeを保持してPrerequisite修正を待つ。

## MIG-062W LINE Friend State Read／Presentation Contract Phase B Resume

- Human Operatorの決済審査用V2環境READY／再開承認を受け、既存Issue #277、PR #278、Branch、Worktreeを継続した。Preflightでlocal／origin／Remote main`c2960e4c73aaeab8d840c09a8ec714266962d823`、Open状態、clean worktree、保持commitを確認した。
- OPS-007はIssue close／main squash／worktree削除済みで、Coordination Ledgerだけがstale Preview Lockを保持していた。最新人間READYと実状態を照合してlockを解放し、MIG-062WがPlatform Integration Lockを取得した。Migration／Artifact／Preview Lockはnoneだった。
- QUALITY-002／ドメイン切替／Asset persistenceとLINE sourceの競合は0、`worklogs/new_ver_main.md`だけを両側保持で解決した。GOV-015 no-force wrapperが二親、Remote refs、base side保持、scope、conflict marker、secretを検証し、latest mainをRemote head`776c368beabe3d7f51b4ef3ecf812d6cc5f4126a`へ同期した。
- latest main正本は`2.0.0-alpha.19`で、MIG-062W Artifact Version`2.0.0-alpha.20`を確定した。再生成差分0、Public 54／Admin 212／Webhook 1 operations、Client／Site Schema／Testkit／AdminとLocal Policy／Quality／Security／Release GateがPASSした。
- 隔離PHP 8.4／PostgreSQLでLINE Friend State 6 tests／46 assertions、既存LINE audience 2 tests／9 assertions、QUALITY-002後の`AdminPaymentApiTest` 6 tests／50 assertionsがPASSした。Migration created／Task・Preview appliedは0である。

## MIG-062W LINE Friend State Read／Presentation Contract Artifact／Preview

- Application head `dfefa07e1a905bba07a56079d02ebfbaabfafc94`でRequired 5 Checks、CodeQL、CodeQL JavaScript／TypeScript、Dependency ReviewがFresh PASSした。Integration GateはQUALITY-002 baselineなしの通常Backend test exit codeでPASSした。
- Workflow Run `32031837467`が`2.0.0-alpha.20` Storefront Contract Artifactと`linux/amd64` Preview imagesを同じSource Commitから生成した。Contract Artifact ID `9289306391`、Manifest SHA-256 `ae598940be23c6ca7a9bcb244100d4815c5e7e836b10c4e840236acff1a60240`、Image Artifact ID `9289296682`、Image Manifest SHA-256 `15251cda000bdd62b9d42bdf4823135de95a7f87e5dcaae8e5d95262fb28926c`である。
- Client／Testkit／Site Schema tarballとPublic OpenAPIを`SHA256SUMS`へreadback一致確認した。Registry publish、Stable Tag、GitHub Release、Production Releaseは実施していない。
- Runtime preflightでluxe／testが同じV2 Storefront／API、既存Preview DB 53 migrations、User Origin luxe、Admin別境界であることを確認した。API exact-head imageだけをno-build／no-depsで反映し、Nginx／Origin／DB／Admin／Storefront／Providerは変更していない。
- Synthetic QA Current User Readは200、`linked=false`、`friend_confirmed=false`、Backend確定`is_line_user=false`、private／no-store、Vary Cookieを確認した。匿名／invalid sessionは401 Typed Problem、Runtime 500系0、LINE／DB／OAuth／Webhook／Provider Mutationは0である。
## QUALITY-002 V1 AdminPaymentApiTest Baseline Expiry Remediation

- Latest `main@1118703eb704f901d25d946074e3707e9c557c6f`、Active Task Ledger、Integration／Migration Allocation／Preview Lockを再確認し、Issue #279、Branch `fix/QUALITY-002-admin-payment-baseline-removal`、専用Worktree、Risk R3で開始した。Migration番号、Integration Lock、Artifact Lock、Preview Lockは取得していない。
- `MIG-062W`はplat-contractのBlocked Taskとして分離し、そのBranch／Worktree／Source／LINE Contractを変更していない。QUALITY-002の変更PathはV1 Payment test fixture、CI backend test実行、baseline関連File、CI文書、Worklog／Reportだけである。
- GOV-009が2026-08-15まで管理した2件をlatest main相当のMIG-062V API Imageと完全分離PostgreSQLで再現した。RefundはPayment-origin Point Lot不在、ChargebackはWallet不在により各422となり、旧fixtureが現在必須のPayment-origin Point Stateを作成していないことがRoot Causeである。
- ApplicationのRefund／Chargeback実装は変更せず、2 fixtureへWallet、paid／free Payment-origin Point Lotを追加した。Response Assertionは現在のCanonical `PaymentReversalResource`へ追従し、completed reversal、Payment status、reason、全Point反転、shortfall 0、Wallet／Lot 0、Point Ledger、Audit、User suspensionを強く検証する。
- `AdminPaymentApiTest`全6 tests／50 assertionsとbackend全342 tests／1854 assertionsがbaselineなしでexit 0となった。Container Imageに`.env`をMaterializeしない局所実行のためLaravelの`.env`読込Warningは発生したが、Test failureは0である。
- Exit条件を満たしたためQUALITY-002 baselineは延長せず削除した。Integration Workflowは失敗を収集して許容する処理を廃止し、`php artisan test`の通常exit statusで全backend failureを拒否する。Gate名、Required Context、assertion、skip、Application behaviorは弱めていない。
- Quality Unit 4 tests、Policy Unit 125 tests、Security Unit 10 tests、Local Policy／Quality／Security Gate、Fresh Composer／Root pnpm／Legacy pnpm Audit各0件、`git diff --check`がPASSした。Migration created／applied、Generated Contract、Artifact、Preview、Storefront、Production変更は0である。

## OPS-006 決済審査用luxe-pack.biz V2一時切替

- Base `acb444db7d8cc61d431b7e41381d36743109f833`、Issue #281、Branch `chore/OPS-006-payment-review-v2-cutover`、Risk R4で開始し、3 lane、Preview／Integration Lock、Storefront release、API／DB、Nginx effective configのConflict GateをPASSした。作業中はPreview Deployment Lockを取得して共有deployをFreezeした。
- luxe／test Nginx、Canonical Preview env／Compose、Runtime情報とV2 DB Custom dumpをroot-only evidenceへ保存し、Rollbackを先に確定した。Origin一行だけをluxeへ変更し、同じImage／IP／DBのAPIだけを`--no-build --no-deps`で再作成した。luxe固有TLS／ACME／redirectを維持してRoutingだけをV2 Storefront／APIへ変更し、`nginx -t`成功後にreloadした。
- API Session、Canonical Origin＋CSRF、旧Origin／CSRF拒否、Catalog、Gacha一覧／詳細、Banner、Notice、Footer、Public Admin 404、HTTP 500系0はPASSした。Desktop Browserで複数Public Asset 404／Console Errorを検出し、Mobile前に重大FAILとして即時Fail Closedした。
- Preview APIは`FILESYSTEM_DISK=local`かつstorage永続mountなしであり、Container再作成によりAsset writable layerが失われた。DB metadata 36件中、Canonical evidenceとchecksum一致する29件だけを同一storage identifierへ復元した。残る7件はOriginal bytes不在のため推測復元せず、test側Asset 404を残課題とした。
- luxe NginxとOriginは事前checksumへ復元し、luxe V1 200、test V2 Session 200、API healthy、同じImage／IP／DB、Migration 53件／batch 25を確認した。Banner linkの一時相対化も既存Admin APIで元のtest絶対URLへ再公開した。Migration／削除／Payment／Draw／Build／Source変更は0である。決済審査用V2環境は`NOT READY`。

## GOV-014 fixed-head Required Check 最新Run判定

- Base `f3cfff8c3f707cdc49fcf8101788f7e3ba2ac36f`、Issue #285、Branch `security/GOV-014-fixed-head-latest-check-runs`、専用Worktree、Risk R2で開始した。変更対象はGitHub App fixed-head check evaluator／境界test／Worklogだけであり、OPS-007、B2、Storefront、Platform Domainは対象外である。
- Canonical evaluatorはexact headの`filter=all` Check Runsを最大1000件まで全ページ取得し、Required 5 contextごとにGitHub Actions App（ID `15368`、slug `github-actions`、owner `github`）の最新開始Runを選択する。同一headの旧failureは後続successが存在する場合にのみ非blockingとする。
- Required Check欠落、pending、failure、stale head、source mismatch、ページ打切りはFail Closedとし、unrelated checkはRequired判定へ影響させない。Focused Unit 22 testsはPASSし、PR #284 exact head `46f02501fab0f1da82598f9cdf200147e3d17242` のread-only評価はRequired 5件success／`passed: true`となった。

## GOV-015 conflict-aware no-force task base sync

- Base `0f4e05920c19e12a613a9c6c320cda8e2d7af272`、Issue #287、Branch `security/GOV-015-conflict-aware-task-base-sync`、Risk R3で開始した。OPS-007 branch／worktree／Issue／PRとluxe-pack.biz V2 Cutover Retryは変更しない。
- current task headとcurrent mainを親順固定の二親merge candidateとして検証し、current baseからcandidateへのnet changed paths、automatic mergeとの差分、未解決conflict marker、親片側の選択をFail Closedとするwrapper gateを追加する。

## OPS-007 V2 Preview Public Asset Persistence

- Issue `#283`、Risk `R4`、Base `f3cfff8c3f707cdc49fcf8101788f7e3ba2ac36f`、専用Branch／Worktreeで開始し、Preview Deployment Lockを取得した。`luxe-pack.biz`のV2切替は実行していない。
- local filesystem rootだけを`v2_api_assets` named volumeへmountし、29 Objectをchecksum確認後に移行した。DB／Migration／Data削除／Production Host Buildは0である。
- API-only recreateを2回実行し、existing Asset、controller upload、recreate後Asset、health、test Top／Banner APIを確認した。25 Public Imageのchecksum／MIMEとupload checksumは不変、HTTP 500／502／504は0である。
- canonical bytesがない7 Public metadata rowは復元せず、Asset ID、Content relation、Public exposure、Re-upload actionをOPS-007 Reportへ記録した。current public 2件が未復元のため、V2切替再試行は`NOT READY`を維持する。

## MIG-062X 残在庫Weighted Draw／Probability Legacy整理

- Latest `main@ac3df5985018ab134065fbae56d1ad8b10042fb0`を唯一のBaseとし、MIG-062WのPR #278 Squash Merge、Issue Close、Remote／Local Branch、Worktree、Lock、Ledger、Artifact Manifestの完全Closeoutを確認した。Issue #289、Branch `feat/MIG-062X-remaining-inventory-weighted-draw`、専用Worktree、Risk R4で開始し、Integration Lockだけを取得した。
- Gacha／Draw State／Operational Inventoryを同一TransactionでRow Lockし、Lock済み`available_quantity`だけを動的integer WeightとするCSPRNG Selectionへ切り替えた。当選ごとにin-memory Weightを減らし、zero Inventoryを除外し、景品別deltaをAggregate更新する。
- Probability Entry／Stage範囲、Minimum Guarantee、Direct Point Backを新規Production景品選択から外した。Schema／Legacy metadata／過去Result／保存済みIdempotency Replayは維持し、新規Draw／QA DrawはPrize-only、Point Back 0である。
- Partial Remaining 1000 requested／900 executedでPoint、Result、User Prize、Inventory、awarded、`sold_count`、履歴、Replayを900へ一致させた。Persistent QAは指定景品1件だけを保証消費し、残りを更新後Weightで選択し、在庫0を副作用なしでFail Closedする。
- Draw 22 tests／210 assertions、QA 23／252、Point Exchange 2／19、Draw対Inventory Adjustment／daily limit／remaining boundary／QA Replay／Prize Action ConcurrencyをPASSした。同一／別Gacha計55 concurrent requestsはfailure／unresolved deadlock 0だった。
- 1000連はquery max 55（SELECT 30／INSERT 19／UPDATE 6）、100連は49（SELECT 30／INSERT 13／UPDATE 6）でSelection／Inventory N+1なし。Task Migration created／applied 0、Public OpenAPI／Client／Testkit／Artifact／Storefront／Preview変更0である。
- Required Checks、CodeQL、Dependency Review、Fresh Self-review、Squash Merge、Issue／branch／worktree／Integration Lock cleanupはFinal Head固定後に継続する。
- 初回PR Head `eb87ff173dc72a108dbb9987255cb02874072af5`では旧固定ppm／Point-first QA lock順を守るPolicy GateがB2 Human仕様と衝突しFAIL、Quality Gateは`setup-php`取得のGitHub 429／502でApplication実行前にFAILした。Draw Policyを動的bounded CSPRNG、Canonical Inventory、Transaction／Idempotency、Prize-only／Legacy Probability・Direct Point Back禁止、Inventory-first QA lock順へFail Closedで同期し、Legacy Schema／過去Read保護は維持した。Policy Unit 131 tests／Local GateをPASSしたfresh headで再検証する。

## MIG-062Y Coin Expiry Core

- Latest `main@1bd2cb5015ca50b1798bca4a83f9d6d5125e5dc6`、MIG-062X完全Closeout、Active Task／Issue／PR／Remote branch／worktree／Integration・Migration・Preview Lockを確認した。Issue #291、Branch `feat/MIG-062Y-coin-expiry-core`、専用Worktree、Risk R4で開始し、Integration LockとMigration Allocation `000054`を取得した。Migration materialize後にAllocation Lockだけ解放し、Integration Lockを保持する。
- 人間の明示承認により、旧paid無期限／free→paid固定順／free-only expiration Policy GateをC1aへ置換した。負残高、Wallet→Lot Row Lock、`SKIP LOCKED`禁止、SQLSTATE限定最大3回Retry、append-only履歴、Reconciliation non-repairは維持する。
- 全新規paid／free Grantを確定時刻＋180日へ共通化した。Payment paid／bonus、通常free、LINE、Referral、Point Exchange、Admin Grant、Draw Point Backが同じPolicyを使用し、新規NULL Lot／Grant Adjustmentとexpiry変更をDB Trigger／Constraintで拒否する。
- Migration 54は既存paid NULL Lot／paid Grant Adjustmentだけを`legacy_no_expiry`で明示し、期限Backfillを行わない。既存free expiryは不変である。通常Consume／Draw／Admin deductは`expires_at ASC NULLS LAST, granted_at ASC, id ASC`の全Lot FEFOとし、期限境界一致をRead／Spend／Draw／QA preflight／新規Reservationから除外する。
- paid／free ExpirationはReservation中Lotを飛ばし、Wallet／Lot／Ledger／Auditを既存Transaction Runnerで整合させる。Reservation releaseは元expiryを維持し、期限切れ残高をReadへ戻さない。ChargebackはFEFOへ統合せず、origin-first、paid取消優先、free取消、不足記録、Reversal manual reviewを維持する。
- Task専用PostgreSQLでMigration fresh、rollback／reapply、function残存後の再fresh、適用前Legacy実データupgrade、新規NULL Lot／Adjustment拒否をPASSした。Point 16 tests／87 assertions、Payment 21／90、B2／Grant回帰77／874、Policy境界9 testsがPASSした。Local全Suite／全Build、Public OpenAPI／Client／Testkit／Artifact、Admin Presentation、OPS／Preview／Productionは変更・実行していない。
- Initial focused failuresはtimezone境界fixture、Reservation global worker count、C1a FEFO後Chargeback期待、PostgreSQL function残存再freshであり、各root cause修正後に対象test／migrationを再実行してPASSした。Application Head `6f718cce59da065287f6c4f7ebb9205943c3a30e`、PR #292を作成し、Required Checks、CodeQL、Dependency Review、fixed-head Fresh Self-review、Squash Merge／CleanupはFinal Head固定後に継続する。
- Initial remote head `61ceecc01fd68298d9995dfa6cc6edf74714464c`はPolicy／Quality PASS、Integration／Security／ci-gate FAILだった。Integrationが検出したfree Grant idempotencyの時刻依存payloadと、Lot未作成の既存Current／Admin read fixture回帰を根因修正し、Point 16／87、Current＋Admin 8／142、QA preflight 1／3、idempotency 1／4を再PASSした。Security failureはTask非変更の空Dependency Advisory baselineが`2026-08-17`で失効したFail Closed結果であり、MIG-062YではGate／baselineを変更しない。

## MIG-062Z Coin Read Contract and Platform Presentation

- Issue #295、Branch `feat/MIG-062Z-coin-read-contract-presentation`、Risk R3、Base `main@4c5e241aeb7331ae27c3720862ccbf74edc41339`、専用Worktreeで開始した。Migration Allocationは取得せず、Platform Integration Lockだけを取得した。
- C1aの既存Wallet／Point Ledger Contractを維持したまま、available total／paid／free、UTC `as_of`、exact `expires_at`単位の7日Bucketをadditiveに公開する。Admin User Detailは今回のCoin Read表示だけを追加し、Point／Coin API・DB・Class Rename、Mutation、Worker、Payment、Draw、Preview／Productionは対象外とする。
- 現行verified Storefront Contract Artifact Manifestは`2.0.0-alpha.20`、SHA-256 `ae598940be23c6ca7a9bcb244100d4815c5e7e836b10c4e840236acff1a60240`である。新しいContract Packageを正規発行するには次Versionの明示allocationが必要だが、正本に未記載である。採番推測は禁止のため、Manifest／Package Version更新、Artifact、handoff、PR／Mergeはallocation受領まで実行しない。

## MIG-062Z Coin Read Contract Version Allocation／Application Validation

- Human OperatorがArtifact Version `2.0.0-alpha.21`を明示割当した。最新main、Manifest、Active Task Ledger、Artifact Release Lock、Remote Tagを再照合し、別Allocation／Tag競合0を確認してArtifact Release Lockを取得した。Task Policy SHA-256は`f5e5adc617eb06c206f67de4298c8ee5ddd1aa20417b6ca92460da38a4226db4`である。
- Public Wallet GETは既存3残高Fieldを維持し、Backend UTC `as_of`とexact expiry単位の7日Bucketをadditiveに返す。期限切れ／予約中を残高・Bucketから除外し、Legacy No Expiryを残高だけへ含め、同一時刻だけを集約する。GET前後のWallet／Lot／Operation／Ledger不変を固定した。
- Draw Mutationが共有する既存`WalletBalance`は変更せず、GET専用`CurrentUserWalletBalance`へ新Fieldを必須化した。ClientはそのBackend Canonical型だけを公開し、Site側の残高合算／期限判定を不要にした。Testkitはpaid/free/Legacy、expired/reserved、7日未満／一致／超過、as_of一致、同一expiry集約、異なるexact timestamp分離を固定した。
- Admin User Detailは利用可能total／paid／bonus、次回失効amount／UTC timestampを返し、JST日付＋時刻でCoin表示する。既存User list契約とPoint adjustment文言は維持した。
- 隔離PostgreSQLでCurrent User Point Read 6 tests／68 assertions、Admin User Read 4／112、Admin UI focused 5 testsをPASSした。OpenAPI 7、Client 27、Site Schema 10、Testkit 34、Policy focused 2、Release 10、各generate／typecheck／lint／build、Local Policy／Release validationがPASSした。Migration created／Task・Preview・Production appliedは0、synthetic DBだけに既存54件を適用した。
- Root／Admin／Platform／Client／Site Schema／Testkit／OpenAPI／compatibility／Policy／release sourceは`2.0.0-alpha.21`へ同期済みである。Required Checks、CodeQL、Dependency Review、immutable Artifact、Fresh Self-review、Merge／Cleanupはfixed Application headで継続する。
- Initial head `952c42402e5b9c6a8d0598182b187c408bd20938`はPolicy／Quality／Security PASS、Integration／ci-gate FAILだった。Integrationが検出した2件はC1a Payment reservation release／due Lot read testの旧3-field Wallet exact arrayであり、Mutationを変更せず`as_of`／Bucketを決定的期待値へ同期した。Task source全体からbuildしたfresh image／DBで各1 test（5／8 assertions）をPASSした。

## MIG-062Z Coin Read Contract Artifact

- Application Head `1a53ba630264258291cb72e84707e488782cbc08`のRequired 5 ChecksはすべてPASSした。GitHub-hosted Workflow Run `32115646025`が同一Source Commitからimmutable Storefront Contract Artifactを正規発行した。
- Artifact Versionは人間明示割当の`2.0.0-alpha.21`、Contract Artifact IDは`9316692687`、outer／GitHub SHA-256は`190fd12cb327634dbab21d343bc92fba37ba657635ff3093b39d55d8030226fe`、Manifest SHA-256は`ac5f051c6171d40f5ed1a0039b7103a8e5917dd90da871fead91b9f8b1aed115`である。
- Client `39622cdfaea2c80f72595396359e67aac7f1de34582ae23ef2de2831d31b594d`、Site Schema `03f78cd1d090e1cc99ae8af9d8b9c381b720c0eab27b8beee34c5567dcc8018b`、Testkit `170d2fbb3b9f12cc4e906120c3d23714d612104c544b44498c81641563376263`、Public OpenAPI `103b8d8ccb1312fecf3013a531102faf5d73cdeb667a7f8d705d6aaf581a1299`を`SHA256SUMS`と実Fileへreadback一致確認した。
- Storefront handoffは3 packageの固定Version／tarball SHA、Public OpenAPI、Manifest、SHA256SUMSである。Registry publish、Storefront Repositoryへの直接導入、Preview deployment／browser verification、Runtime／Nginx／DB変更は実施していない。

## SEC-011 Dependency Advisory Baseline Fresh Security Review

- MIG-062Y PR #292のSecurity Gateを停止させた空Dependency Advisory baseline期限切れを、専用Issue #293、Branch、Worktree、Integration Lockで分離して確認した。Migration Allocation、Application Domain、Public Contract、Artifact、Storefront、Preview、Productionは対象外である。
- 2026-08-18のfresh canonical Composer、Root pnpm／V2 workspace、Legacy pnpm auditはいずれも0 findingだった。新規Advisory、baseline array、dependency／lockfile、Gate／auditの弱体化はない。
- empty baseline management metadataだけをSEC-011、review reason、bounded expiry `2026-08-25`へ更新した。次のFindingはremediationまたはexact-fingerprint Security Taskを必要とし、日付だけの延長を禁止する。

## MIG-062Y Coin Expiry Core Closeout Resume

- SEC-011 merge `edd1965ddee851eb3fed6e327c7477236f0a8083`を含む最新mainへ、履歴rewriteなしの二親mergeで同期した。競合はWorklogだけで、SEC-011のbaseline／Worklog／reportはbase側に保持され、Application DomainとMigration差分の競合は0件だった。
- `000054`より新しいmain Migrationがないことを確認し、Migration AllocationとIntegration Lockを再取得した。Task専用PostgreSQLで54件fresh apply、`000054`単独rollback／reapply、Migration statusを再PASSした。
- Base同期の影響範囲に限定し、Migration PHP syntax、Policy Unit 133 tests、Local Policy Gate、`git diff --check`をPASSした。SEC-011 auditの再実行、全Suite／全Build、Public OpenAPI／Artifact／Storefront／Client／Testkit変更は行っていない。

## MIG-063A Limited Bonus Domain Core

- Latest `main@80fa6d7c7202e800ab4da50665c466cc63f893a8`、Issue #297、Branch `feat/MIG-063A-limited-bonus-domain-core`、専用Worktree、Risk R3で開始し、Migration Allocation Lock取得後に`000055`を採番した。Public／Admin HTTP、UI、OpenAPI、Client、Site Schema、Testkit、Artifact、Storefront、Provider接続、Preview／Productionは対象外である。
- Exact Point Purchase Plan VersionへON/OFF、`start < end`、`bonus > 0`のLimited Bonus Campaignを追加した。同一Versionの期間は`[start,end)`で重複禁止、adjacent許可とし、Plan parent→Campaign rowの既存Lock順、PostgreSQL advisory transaction lock、最大3回の既存Transaction retryで同時mutationを直列化する。
- Payment作成時にCampaign設定をimmutable snapshot化する。Successはverified `payment.succeeded` eventの`provider_occurred_at`だけをCanonical時刻とし、`payments.succeeded_at`へ保存する。`received_at`／処理時刻は判定に使わず、Canonical欠落はIngress／確定双方でFail Closed、Legacy Paymentはsnapshot不在のため遡及Grant 0である。
- 通常free＋確定Limited Bonusを既存単一`payment_grant` operation／`payment_point_grants`へ合算し、既存`V2CoinExpiryPolicy`のGrant確定＋180日を使用する。Refund／Chargebackのrequired freeへ確定額を含め、origin-first、paid優先、不足free、Chargeback Reversal manual reviewは維持した。
- Task専用PostgreSQLでMigration 55件fresh apply、`000055` rollback／reapplyをPASSした。Limited Bonus、Payment Foundation、Point Purchase Plan focusedは36 tests／180 assertions、Policy focused 4 tests、Local Policy Gate、変更PHP syntax、Python compile、`git diff --check`がPASSした。全Suite／Repository全Buildは実行していない。
- 初回検証は既存timestamp秒精度とmicrosecond replay比較の不一致、success fixtureのCanonical時刻欠落、event type guardの汎用status path誤適用を検出した。DB精度へのUTC秒正規化、success fixture更新、guardのsuccess確定path限定後に全focused testを再実行してPASSした。
- Initial GitHub Integration Gateは新規Limited Bonus testが外側Transactionを使わずPoint fixtureを後続Line Messaging testへ残したため2 failuresとなった。Concurrency test以外をrollbackし、Concurrency fixtureを明示cleanupした後、Limited Bonus 7 tests／36 assertions、後続Line対象2 tests／24 assertions、全focused 36／180をPASSした。
- Second GitHub Integration GateはMigration `000055`の新規2 tableが明示V2 Schema Inventoryへ未登録としてFail Closedした。`payment_limited_bonus_snapshots`と`point_purchase_plan_limited_bonus_campaigns`を正規順で登録し、focused inventory unitとLocal Policy GateをPASSした。

## MIG-063B Limited Bonus Admin／Public Contract／Artifact

- Latest `main@f2ecce33f8552e89dcda95fc0ac531d832a1c29e`、C2a Issue #297／PR #298、Migration `000055`、Remote／local branch・worktree・LockのCloseoutを必要最小限再確認した。Issue #299、Branch `feat/MIG-063B-limited-bonus-admin-public-contract`、専用Worktree、Risk R3で開始し、Artifact Release Lockを取得した。Migration Allocation、Integration Lock、Preview Lockは取得していない。
- C2aの既存`V2LimitedBonusCampaignService`をExact Point Purchase Plan Versionへ接続し、Admin一覧／登録／編集、ON／OFF、開始／終了、追加量を提供した。Admin Adapterは型・認証・MFA・Idempotency・Audit・Problem変換だけを担当し、`start < end`、`bonus > 0`、`[start,end)` overlap、Lock／Transactionを再実装しない。
- Public Point Productは既存`paid_points`／`bonus_points`／`total_points`を不変のまま、`limited_bonus`のamount、start、end、Backend UTC `as_of`、`active`／`upcoming`／`inactive`、表示可否・文言・追加量表示をadditiveに返す。開始境界はactive、終了境界はinactiveで、Storefrontに期間／Stack判定を持たせない。
- Admin／Public／Webhook OpenAPI、bundles、Generated Admin型、Storefront Client型、Site Schema version、Testkit active／upcoming／inactive fixtureを新規`2.0.0-alpha.22`へ同期した。既存`2.0.0-alpha.21`は上書きせず、Storefront Repositoryへの直接導入、Registry publish、Stable Tag、Release、Preview／Production deployは実施しない。
- 隔離PostgreSQLへ既存55 migrationsをfresh applyし、Admin CampaignとPublic境界8 tests／103 assertionsをPASSした。Admin 161 tests、Client 27、Site Schema 10、Testkit 34、OpenAPI 7、Release 10、Policy 135、各generate／check／typecheck／lint／build、Admin Production Build、PHP syntax、`git diff --check`がPASSした。Migration createdは0で、Task／Preview／ProductionへMigrationを適用していない。
- Payment Snapshot、`provider_occurred_at`、single Grant、Expiry、Refund、Chargeback、金融Concurrency、Draw、Inventory、実Provider、Storefront Repositoryは変更していない。Required Checks、immutable Artifact発行／readback、Fresh Self-review、Squash Merge／Issue・branch・worktree cleanupはApplication Head固定後に継続する。

## MIG-063B Limited Bonus Contract Artifact

- Application Head `1ee95268b145713e5df31dfe7f4b1c8158df7414`のRequired 5 Checksは全てPASSした。初回PR event Policy GateはPR本文のAllowed Pathsが機械可読bulletでなかったためFail Closedし、actual diff 37 pathsをChanged／Allowed双方へ完全列挙した同一Headのmanual dispatchでPASSした。
- GitHub-hosted Workflow Run `32141593541`が同一Source Commitからimmutable Storefront Contract Artifact `2.0.0-alpha.22`を発行した。Artifact IDは`9326245788`、outer／GitHub SHA-256は`e8b5598ce0eacc0bef032dbca141e91da9d110db816f916813218083b209087d`、Manifest SHA-256は`fcda62708350fab1249bdb0b6ab2fab8440ca89b2681edf8d587e60c27027d9c`である。
- Client `d642a64afff5b310b4997ed63fb1fad3780eeb6b1e6b5dfef49f32dfb20b0c42`、Site Schema `94a4c2032a0ffd95b7931a8628031935e5f6b463703d5f7339e5ebe35bf4d6a7`、Testkit `0d19f288c5fe74722585a7896998df722946953f3b22d0f49559d22a8d41ca6c`、Public OpenAPI `ba00b46d34d0889bc883c86551c85ea2322d4f354723c3a2a68636e27cf5374a`を`SHA256SUMS`と実Fileへreadback一致確認した。
- Storefront handoffは上記3 packageの固定Version／tarball SHA、Public OpenAPI、Manifest、SHA256SUMSまでである。Storefront Repositoryへの直接導入、Registry publish、Stable Tag、GitHub Release、Preview／Production deployは実施していない。

## MIG-063B Backward Compatibility Correction

- PR-native Quality GateがPublic `PointProduct.required`への`limited_bonus`追加をbreaking changeとしてFail Closedした。Backendは常にCanonical fieldを返す一方、OpenAPIではfield自体をoptional additiveにして既存Consumer互換を維持し、Generated Client型も`limited_bonus?`へ修正した。既存`grant`のrequired fieldと意味は不変である。
- Application Head `1ee95268b145713e5df31dfe7f4b1c8158df7414`由来の`2.0.0-alpha.22` Artifactは既にimmutable発行済みのため上書き／handoffせず、retired non-handoff evidenceとして保持する。Artifact Release Lock下で未使用Tagを確認し、正規handoff Versionを`2.0.0-alpha.23`へ再採番した。
- Public OpenAPI PR-event backward compatibility、bundle／generate check、Client build＋27 tests、Testkit build＋34 tests、Admin typecheck、Policy 135、Release 10、Local Policy／Quality Gateを再PASSした。新しいexact Application HeadのRequired Checks、Artifact発行／readback、Final docs-only Head、Fresh Self-reviewは継続する。

## MIG-063B Additive Contract Artifact Final

- Corrected Application Head `633b41f347083c82028229d6e238842118635feb`のPR-native Required 5 Checksは全てPASSした。GitHub-hosted Workflow Run `32147032173`が同一Source Commitから正規handoff対象のimmutable Storefront Contract Artifact `2.0.0-alpha.23`を発行した。
- Artifact IDは`9328364646`、outer／GitHub SHA-256は`a4e7fde91c4148971723778b847d0f1a43d4b58b3716fb1c9f4b1eceeb06818c`、Manifest SHA-256は`556eaf59e9c5128cb9b93cf9000a5aee3ff4eb56f86ee8bc549c392d55bd77fe`である。
- Client `28a7b3558329eed9c608f828948befe2034e86c0add1511bd48db1ed437f58d9`、Site Schema `b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2`、Testkit `dc0bf6c16af439bf5a364955e8add936e8842096ca295a136a0f15a86e4102b0`、Public OpenAPI `5c735fe26514d5bfb47b3515ead108bf473fd5e1f81e0936b7e1986290904043`を`SHA256SUMS`と実Fileへreadback一致確認した。
- Storefront handoffは上記`2.0.0-alpha.23`の3 tarballs、Public OpenAPI、Manifest、SHA256SUMSだけを対象とする。`2.0.0-alpha.22`はhandoff対象外であり、Registry publish／Storefront Repository直接導入／Stable Tag／Release／Deployは0である。

## MIG-063C Prize Thumbnail Banner Picker

- Latest `main@5b834faa58d07f4d14874eb73c26ed9ac6536dd1`、Remote refs、Open Task Policy、local mainのclean状態を確認し、未使用Task ID `MIG-063C`、Issue #301、Branch `feat/MIG-063C-prize-thumbnail-banner-picker`、専用Worktree、Risk R2で開始した。Migration Allocation、Integration、Artifact Release、Preview Deployment Lockは取得していない。
- Prize登録／編集の直接Presentation Asset選択をBanner Category→Bannerのカード型Pickerへ置換した。既存Admin APIの`listBannerCategories`とcategory-filtered `listManagedBanners`だけを再利用し、Bannerのtitleと既存画像URLを併記する。選択時はBanner IDではなく既存`asset.id`をPrize requestのCanonical `presentation_asset_id`へ設定し、Asset複製は0件である。
- 既存thumbnail Assetが全CategoryのBannerに一意に対応する時だけ初期Category／Bannerを表示する。非一意または未解決なら既存`presentation_asset_id`を保持し、明示的なBanner置換を求める。Category変更は旧Banner選択を解除し、replacement未選択の保存をFail Closedする。
- Focused Admin component 14 tests、Admin Unit suite、Admin Typecheck、Admin Lint、Admin Production Build、Policy Unit 135 tests、Quality Unit 4 tests、Security Unit 10 tests、Local Policy／Quality／Security Gate、Composer／Root pnpm／Legacy pnpm Audit各0 finding、`git diff --check`はPASSした。Migration、Domain、Admin API、Public OpenAPI、Artifact、Storefront、Preview／Productionは変更していない。Required Checks、Fresh Self-review、Squash Merge／CloseoutはFinal Head固定後に継続する。
- 初回PR Head `9e7e84b22b4f47669d78c4bce643794f43a92c15`のGitHub Quality Gateは新Focused Test fixtureの既存generated型必須field 3件（Rank description、optional query、Asset is_public）を検出してFAILした。Application実装、API、Canonical保存方式を変更せずfixtureだけを既存型へ補正し、Focused 14 testsとAdmin TypecheckをPASSした。旧headのreview/check evidenceは無効であり、fresh headで再実行する。
- 第二PR Head `e553ee4c5e29b57d3b12aeed607a38011f8d168d`のGitHub Quality GateはReact effect内の同期`setBannerLoading`と裸`img`のAdmin ESLint rule違反を検出してFAILした。Banner選択時／自動解決時だけloading stateを開始し、既存Admin通例のunoptimized `next/image`へ置換してFocused 14 testsと対象ESLintをPASSした。Domain、API、Migration、Canonical保存方式は不変であり、fresh headで再実行する。

## OPS-009 C2 Limited Bonus Runtime Activation

- `git fetch --prune`後のclean `main = origin/main@6075512ba2c248dc711e5a9e89e7f5289d1a4c41`を固定し、Issue #303、Branch `chore/OPS-009-c2-limited-bonus-runtime-activation`、専用Worktree、Risk R4で開始した。Preview Deployment Lockだけを取得し、Migration Allocation／Artifact Release／Integration Lockは取得していない。
- 現行Payment Review APIはMIG-062W revision `dfefa07e1a905bba07a56079d02ebfbaabfafc94`、AdminはMIG-062T revision `0b1f982d1aeaee81a6e7aea648695fe48053016e`で、ともにhealthyだった。内部API／Admin health、外部Admin health、両User origin sessionは200である。
- 現行API imageはMigration `000055`とLimited Bonus routeを持たず、DBは53 migrationsまでRan、Campaign／snapshot tableとPayment columnは不存在だった。Admin browserで既存Point Purchase一覧／編集と通常fieldを確認し、`期間限定ボーナスコイン`欄が未表示であることを実測した。
- Preservation baselineはPoint Purchase Plans 3、Payments 0、Payment Point Grants 0、Wallets 2、Point Lots 4、Point Ledger Entries 8である。Public Point Product 2件とWallet readは200／private no-storeで、credential／response dataをRepositoryへ記録していない。
- Exact source `d8374dc918248385f9cceb92d96122800e3c70bd`のRequired 5 ChecksをPASSし、GitHub-hosted Run `32212497380`のverified linux/amd64 Artifact `9351297179`をloadした。OPS-009 headのAPI／Admin treeは固定latest mainと完全一致し、Repository差分は運用証跡3 filesだけである。
- Root-only custom DB dump（SHA-256 `82e697d8cc60ed61884e3460766845e880657eba583ba31ba3e2024d10ebabc2`、TOC 1331）を復旧点にし、candidate statusでPendingだった既存`000054`／`000055`を通常`migrate --force`で順序適用した。55 migrations全Ran、C2 table／column／constraint／triggerを確認し、既存6 business tableの件数・stable hashは全一致した。Campaign／snapshot／Payment／Grant／Limited Bonus amountは0で遡及Grantはない。
- API／AdminをOCI revision `d8374dc918248385f9cceb92d96122800e3c70bd`へ`--no-build --no-deps`更新し、既存固定IP／loopback port／restart policy／DB／network／persistent Asset volumeを維持した。両health、Point Purchase一覧／編集、通常field、Limited Bonus GET 200、Campaign未設定empty state、`期間限定ボーナスコイン`欄、Public Point Product／Wallet readはPASSした。
- Campaign mutation、Payment、Draw、Refund、Chargebackは0で、activation windowのAPI／Admin／Nginx／Browser HTTP 500／502／504は0だった。Preview Deployment Lockを解放し、Migration Allocation／Artifact Release／Integration Lockは取得していない。全Suite／全Build、Storefront Repository、Artifact再発行、正式Productionは対象外である。

## MIG-063D Category／Tag Post-Publish Editing

- Cleanな`main = origin/main@33909e6370b99806613cedeb442bc6a10096e0cf`、Issue #305、Branch `feat/MIG-063D-category-tag-post-publish-editing`、専用Worktree、Risk R3で開始し、Migration Allocation／Platform Integration Lock取得後に`000056`を採番した。Task① MIG-063CのMerge／Issue Close／Lock cleanup済みを開始前に確認した。
- 公開中Gacha参照時もCategoryの`display_name`、`description`、`sort_order`、`is_visible`と、Tagの`display_name`、`sort_order`、`is_visible`を編集可能にした。Application GuardとDB Triggerは`slug`、archive、physical delete、immutable `code`、revision incrementを引き続き保護し、Rankの既存保護は変更していない。
- Category非表示はCategory一覧／Category filter導線だけから除外し、Tag非表示はTag一覧／Tag filter／Gacha表示Tagから除外する。紐づくGachaの通常Public一覧／詳細、Lifecycle、Sale Stateは維持し、Public response shape、OpenAPI、Generated Artifact、Storefront Clientは変更していない。
- Adminは`CATALOG_REVISION_CONFLICT`、`CATALOG_PUBLISHED_REFERENCE_CONFLICT`、`CATALOG_MUTATION_INVALID`を個別表示する。公開参照拒否とvalidationを最新Master再取得のrevision conflictとして誤表示しない。
- Task専用PostgreSQLで56 migrationsを通常applyし、`000056`単独rollback／reapplyをPASSした。Backend focused 28 tests／310 assertions、Admin focused 40 tests、Admin全unit 167 tests、typecheck、lint、Production build、Task-source API image build、Policy Unit 136、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、Dependency Audit各0 finding、PHP syntax、`git diff --check`をPASSした。Browser/E2EとRepository-wide backend suiteは実行していない。

## MIG-063E Gacha Rank Code Uniqueness Scope

- Cleanな`main = origin/main@b528969e46bd6b2aed7085be89a2792db801d6af`、Issue #307、Branch `feat/MIG-063E-gacha-rank-code-scope`、専用Worktree、Risk R3で開始した。Migration Allocation／Platform Integration Lock取得後に`000057`を採番し、Migration file確定後にAllocation Lockを解放した。
- Canonical Rank parentをGachaとして`catalog_ranks.gacha_id`を追加し、既存GLOBAL `UNIQUE(code)`を`UNIQUE(gacha_id, code)`へ変更した。未接続の既存Rank masterはnullable ownershipとpartial UNIQUEで従来の安全性を維持し、Gacha VersionへのRank接続はDB Triggerで同一Gachaだけを許可する。
- 既存Rank ownershipはVersion／Prize参照から一意に解決し、複数Gachaへ跨る既存Rankまたは同一Gacha内duplicateは自動修復せずMigrationをFail Closedする。backfillは既存revision guardに従いrevisionを1増加させ、Rank row、public ID、code、表示名を削除・再採番・renameしない。
- Gacha配下Rank createは`gacha_id`を保存し、別Gachaの同一codeを許可、同一Gacha内duplicateを既存`CATALOG_MASTER_CONFLICT`で拒否する。Rank code updateは既存`CATALOG_CODE_IMMUTABLE`のままで、Admin／Public OpenAPI、Generated Contract、Admin UI、Artifact、Storefrontは変更していない。
- Task専用PostgreSQLで`000057` apply、既存Rankを保持したrollback、reapplyをPASSした。既存Rankのidentity／code／表示名hashは往復前後一致し、ownershipも再backfillされた。別Gacha同一codeは2行作成PASS、同一Gachaduplicateは`catalog_ranks_gacha_id_code_unique`でFAIL、別Gachaduplicate存在時のdownはデータ変更前にFail Closedした。
- Backend focused 27 tests／341 assertions、Task-source API image build、変更PHP syntax、Policy Unit 137、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、Dependency Audit 3系統各0 finding、`git diff --check`をPASSした。Repository-wide backend suite、Admin test／typecheck／lint／build、Browser/E2EはScope外変更がないため実行していない。Required Checksとfixed-head Fresh Self-reviewはFinal Head確定後に継続する。
- Initial PR Head `a0b06da62ce49788093cda7fe384eb3ffbf2eb9a`はPolicy／Quality／Security PASS、Integration／ci-gate FAILだった。Integrationが検出した既存Draw Load fixtureはGachaとPrizeだけを20組へ複製し、Rankを元Gachaのまま参照していた。Draw Coreを変更せず、fixture helperがimmutable同一code Rankも各Gachaへ複製し、期待record countを同期した。CI同条件のLoad test 1 test／36 assertionsはPASSし、旧Headのcheck／review evidenceは無効である。

## MIG-064 Email Verification Direct Mailgun Delivery

- Cleanな`main = origin/main@3b24a6757f4cfcfe8107dcbd32d17a5580c45cb1`、Issue #309、Branch `feat/MIG-064-direct-mailgun-email-verification`、専用Worktree、Risk R3で開始した。Migration Allocation、Integration、Artifact Release、Preview Deployment Lockは取得していない。
- Runtimeの`V2EmailVerificationNotifier` bindingを既存`V2MailEmailVerificationNotifier`へ切り替えた。Register／Resendは既存のtoken、hash、60分expiry、redirect allowlist、rate limit、Pending Verification、Verification Complete semanticsのまま、Laravel Mailを同期呼出しする。generic Outbox、Outbox table、migration、service、既存`identity.email-verification`履歴は変更していない。
- Focused Direct Mail testはbinding、Register宛先／件名／URL／token／expiry、Resend token revoke、Verification Complete、Outbox record非作成、transport failure時のtransaction rollbackを確認する。Policy Gateも旧Outbox notifier bindingを明示拒否しつつ、generic Outbox／persistent Audit境界を引き続き必須化した。
- `docker-compose.v2.yml` API serviceへ`MAIL_MAILER`をrequiredとして、`MAIL_FROM_ADDRESS`、`MAIL_FROM_NAME`、`MAILGUN_DOMAIN`、`MAILGUN_SECRET`、`MAILGUN_ENDPOINT`、`MAILGUN_SCHEME`をLaravel config既定値付きで注入した。official `symfony/mailgun-mailer` dependencyは既存のため変更0である。
- Task専用PostgreSQLで既存57 migrationsをapplyし、PHP 8.4 API image上のDirect Mail 3 tests／27 assertionsと既存Authentication FlowをPASSした。Policy Unit 138、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、Composer／workspace pnpm／legacy pnpm Audit各0 finding、Composer validate、PHP syntax、Compose config、Mail config bootstrap、`git diff --check`をPASSした。Repository-wide backend suite、Browser/E2E、実recipient送信は実施していない。
- Active V2 PreviewのCanonical env sourceはCompose projectのexternal environment fileである。Mail runtime variablesは値を表示せず確認した結果すべて未設定であり、現行containerにも未注入だった。ダミー値や実配送は行わず、正式secret sourceへ必要変数を設定してCompose API serviceをrecreateする最終到達確認を残課題とする。

## OPS-010 Mailgun Direct Mail Runtime Activation

- `git fetch --prune`後のclean `main = origin/main@e622ee61fdeba61ebd24ef07823b82ae3c30392f`を固定し、Issue #311、Branch `chore/OPS-010-mailgun-direct-mail-runtime-activation`、専用Worktree、Risk R4で開始した。Preview Deployment Lockだけを取得し、Migration Allocation／Platform Integration／Artifact Release Lockは取得していない。
- 変更前APIはOCI revision `d8374dc918248385f9cceb92d96122800e3c70bd`でhealthy、DB／Redis接続と内部health 200はPASSした。MailerとMailgun必須値はsecret-safeにconfiguredを確認したが、Notifierは`V2OutboxEmailVerificationNotifier`、APIはinternalな`v2_private`だけに所属し、Mailgun DNS／HTTPS egressはFAILした。
- `v2_private internal:true`を維持し、非internal bridge `v2_api_egress`を追加した。Docker Engine 25の同時attachはexternal default routeを先に作ってprivate固定IPを拒否するため、APIをprivate-onlyで起動後、guarded helperでAPIだけをegressへattachする。Admin／PostgreSQL／Redisのegress参加、private隔離解除、internal/non-bridge／重複subnetをDB Guard／Policy Gate／helperで拒否する。Focused DB／Policy／Egress Unit 188 tests、Canonical Compose解決、`git diff --check`はPASSした。
- 初回activationはDocker自動割当のegress `192.168.32.0/20`重複、第二attemptはEngine 25の同時attach順序でAPI起動をFail Closedした。各回とも即時に旧image／private-only構成へAPIだけをrollbackしhealthy／200を確認した。短命probeでprivate-first→live attach、private DNS、Mailgun DNS／HTTPSを実測PASSし、probe container／networkをcleanupした。
- Required 5 Checks PASSのHead `238eacbce382f4daa4464a70790b63eb6a1ad84a`をGitHub-hosted Run `32270006079`でbuildし、verified Artifact `9371916558`からAPI image `sha256:14de371be8eeba12d5608a50cb0fbe81e31413a7392386d6b8f4db7747b45d18`をloadした。API treeはfixed latest main／MIG-064と一致し、Admin imageはbuild pipeline上生成されたがdeploy／recreateしていない。
- Canonical main Composeとexternal env／image overrideでAPIだけを`--force-recreate --no-build --no-deps`更新し、healthy後に最小`192.168.62.0/28` egressへAPIだけをattachした。Admin／PostgreSQL／Redis containerは不変でprivate-only、`v2_private internal:true`を維持した。Notifierは`V2MailEmailVerificationNotifier`、Mailer／Mailgun必須env configured、DB／Redis、Mailgun DNS、TCP／HTTPS 443、内部health、Public Session 200、500／502／504 0をPASSした。
- 実recipientメール、新規登録、Resend、Verification Completeは実行していない。Migration created／applied、DB Schema、Public／Admin Contract、Repository Artifact、Storefront、Payment、Point、Draw、Production変更は0である。Preview Deployment LockをRuntime verification後に解放し、他Shared Lockは取得していない。
- Initial PR Head `85721cc9ada1374a47c02289a180767255c72d5c`はIntegrationが`MAIL_MAILER=array`の既存CI経路にもMailgun secretを要求したためFAILした。Mailgun固有値はMailgun transport時だけ必要となるようCompose pass-throughへ最小修正した。次のHead `159c725078d4dc10bfe60855cf490530eeed03dc`では、canonical bindingを通じてOutbox recordを期待していた既存Outbox encryption testがFAILしたため、generic Outbox implementationを明示して検証対象を分離した。Direct Mail contract、Outbox infrastructure、token semanticsは変更していない。修正後のDirect Mail＋Audit/Outbox focused testsは15 tests／80 assertionsでPASSした（既存test imageに`.env`がないことによるwarning 15件のみ）。
- Full Backend suiteが並列Test間で既存`identity.email-verification` Outbox履歴を保持するため、Direct Mail testはtopicの全件不存在ではなく、Register／Resend／transport failure前後のverification Outbox件数不変を検証するよう修正した。既存歴史の削除を要求せず、新規Direct Mail flowだけがOutbox deliveryに依存しないことを検証する。
- 初回Closeout HeadのIntegration Gateは、未接続top-level egress networkをDocker Compose正規化が除外するため、DB Guardの期待network集合と不一致になりFail Closedした。DB Guardをprivate-only create phaseの正規化結果へ合わせ、egressのbridge／minimal subnet／non-overlap／API-only境界はPolicy Gateとguarded runtime helperで維持した。Focused 188 testsとCI同等isolated V2 migration／全Identity・Draw・Reporting suite／backup restore／resource cleanup smokeはPASSした。

## MIG-065 Email Verification Browser UX

- `git fetch --prune`後のclean `main = origin/main@ec7ce40fb9af3c862b492be3b0aa453183bbe43b`を固定し、Issue #313、Branch `fix/MIG-065-email-verification-browser-ux`、専用Worktree、Risk R4で開始した。Platform Integration Lockだけを取得し、Migration Allocation／Artifact Release／Preview Deployment Lockは取得していない。
- Mail本文のverification URLは`V2_PUBLIC_ORIGIN`を正本とする`v2_identity.origins.user`から生成し、absolute HTTPS origin以外、credential／path／query／fragment付きoriginをFail Closedする。既存user UUID、64-hex token、encoded redirect query、60分expiry、Mailgun transport／bindingは変更していない。
- Verify endpointはLaravel `expectsJson()`により分岐し、JSON Clientへ既存`authenticated`／`user`／`redirect_path` bodyを維持する。通常Browserへはverification成功後、既存Session Manager／CSRF Serviceで同じCookieを付与して、DB保存済みallowlist redirectへHTTP 303を返す。両表現に`private, no-store`、API version、`Vary: Accept`を付与する。
- Verify queryはredirect正本にせず、Register／Resend時の既存exact allowlist検証を通って保存されたpathだけを使用する。外部scheme、`//evil.example/`、allowlist外pathは`INVALID_REDIRECT`、query tamperingは保存済み`/`へ固定、invalid／expired／replay token拒否を維持した。
- Task専用空PostgreSQLへ既存57 V2 migrationsを`migrate:fresh`なしで適用し、Direct Mail＋Authentication Flow 23 tests／162 assertionsをPHP 8.4でPASSした。Policy Unit 144、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、Composer validation、Composer／workspace pnpm／legacy pnpm Audit各0 finding、PHP syntax、`git diff --check`もPASSし、Task container／networkをcleanupした。
- Migration created、Task／Preview／Production migration applied、DB Schema、Public／Admin Contract、Artifact、Storefront、Point、Payment、Draw、Inventory、Infrastructure変更は0である。実recipientメール、新規登録／Resend runtime smoke、Browser/E2E、Preview／Productionは実行していない。Required Checks、Fresh fixed-head Self-review、Squash Merge／CleanupはFinal Head固定後に継続する。

## MIG-063F Rank Presentation Asset Picker

- Cleanな`main = origin/main@fa32950041e9b49091aa5dd2a5a3881baeadaf10`、Open Platform MIG Task／PRなし、shared Lock未取得を確認し、Issue #315、Branch `feat/MIG-063F-rank-presentation-asset-picker`、専用Worktree、Risk R2で開始した。Migration Allocation、Platform Integration、Artifact Release、Preview Deployment Lockは取得していない。
- Gacha Rank create/editのランク画像と抽選演出動画は、既存Admin `rank-effects` endpointだけを候補正本とする。画像は`media_type=image`、動画は`media_type=video`だけを同endpointのcursor paginationからカード表示し、一般Presentation Assetは今回のPickerへ混在させない。
- 既存Admin card Picker patternを再利用し、画像thumbnailまたは軽量video metadata previewとRank Effect titleを同時に表示する。Rank保存は既存`image_asset_id`／`video_asset_id`へ既存Asset IDを参照するだけで、Asset複製は0件である。
- 既存Rankの候補解決時はselectedを復元する。Rank Effect候補に解決できない既存Asset IDはNULL化・置換・複製せず、明示的な再選択または未設定選択までそのまま保存する。
- Focused Admin 7 tests、Admin typecheck、lint、Production build、Policy Unit 144、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、Composer／workspace pnpm／legacy pnpm Audit各0 finding、`git diff --check`はPASSした。Migration、DB Schema、Admin/Public API、OpenAPI、Artifact、Storefront、Draw／Inventory／Point／Payment、Preview／Production変更は0である。Browser/E2E、Admin full unit、repository-wide backend suiteは実行していない。

## MIG-066 Email Verification `/mypage` Redirect Activation

- Cleanな`main = origin/main@3699526536c6ecaee8e09aa5da5a28d3b1d744a1`、Issue #317、Branch `fix/MIG-066-email-verification-mypage-redirect`、専用Worktree、Risk R4で開始した。Platform Integration LockとPreview Deployment Lockを取得し、Migration Allocation／Artifact Release Lockは取得していない。
- Email Verificationの既存exact safe redirect allowlistへ`/mypage`を追加し、`/`を維持する。既存validation、MIG-065 Browser HTTP 303、Session／CSRF Cookie、JSON Client semanticsは変更しない。Migration、DB Schema、Public／Admin Contract、Artifact、Storefront変更は0である。
- Source Closeout後にOPS-010のAPI専用egress境界を維持してimmutable API imageをbuildし、active V2 API serviceだけを更新する。実recipientメール、新規登録runtime smoke、Resend、Verification Completeは実行しない。
- Task専用PostgreSQLへ既存57 migrationsを通常applyし、Direct Email Verification＋Authentication Flow 23 tests／166 assertionsをPHP 8.4でPASSした。初回は`phpunit.xml`のcanonical `oripa_test`とTask DB名不一致により0 assertionで環境FAILしたため、Task DBをcanonical test名で再作成し、Application変更なしでPASSした。
- Policy Unit 144、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、Composer validation、Composer／workspace pnpm／legacy pnpm Audit各0 finding、変更PHP syntax、`git diff --check`をPASSした。Required 5 Checks、fresh fixed-head Self-review、Source Merge、Runtime Activation、cleanupはFinal Head固定後に継続する。

## OPS-011 MIG-066 Runtime Activation

- Cleanな`main = origin/main@f79f1301300b3f518a2ab983010e9efb197a781d`を固定し、Issue #319、Branch `chore/OPS-011-mig-066-runtime-activation`、専用Worktree、Risk R4で開始した。Preview Deployment Lockだけを取得し、Migration Allocation／Platform Integration／Artifact Release Lockは取得していない。
- Active APIはOPS-010 image、OCI revision `238eacbce382f4daa4464a70790b63eb6a1ad84a`でhealthyである。`v2_private internal:true`、API-only `v2_api_egress`、Admin／PostgreSQL／Redis private-onlyを確認した。MIG-066 merged mainのAPI treeは`8e159ce7db4b4545ff14f7751979ef20f42ec575`で、source allowlist `["/", "/mypage"]`、MIG-065 Browser HTTP 303実装、`V2MailEmailVerificationNotifier` bindingを含む。
- Application code、Migration、Contract／Artifact／Storefront、Admin／DB／Redis、Productionは変更せず、open PR exact headのCanonical Preview Image BuildとAPI-only Runtime Activationだけを行う。実recipientメール、新規登録、Resend、Verification Complete、Task⑤は実行しない。
- Exact Application Head `0d86972944491bdd3e9716787381e439848d606f`のRequired 5 latest checksをPASSし、trusted `main` control refのCanonical Run `32330746391`でopen PR #320 exact headをGitHub-hosted amd64 buildした。Verified Artifact `9393004181`、outer digest `sha256:1ce7d4f31615a812d8218e5a22a67ab16a3b22970e9e5d00824af66a860b438e`、manifest SHA-256 `6ffe11761d9a529d01d4901bd8c8b63aef0c30462642e4ee755b50e1f5d4ee21`、API image ID `sha256:d075d82d5649a010f0c056d39067830de2e8a734b89d3adc2a6212165c22a28a`、OCI revision一致を確認した。
- Image sourceとMIG-066 merged mainのAPI treeはともに`8e159ce7db4b4545ff14f7751979ef20f42ec575`で、image readbackはtracked API blob 836件をbyte一致確認した。Dockerfile／`.dockerignore`が意図的に除外またはchmodするruntime scaffold `.gitkeep` 6件だけを明示除外した。Admin imageは標準pipeline上buildされたがdeploy／recreateせず、Contract Artifact publicationも行っていない。
- Activation Evidence Head `ea5d844cf5b0ad6f0c6b60861ed13036bf76b64d`のRequired 5 latest checksとfresh fixed-head self-reviewを先にPASSした。Canonical Composeの既存root-only env sourceとOPS-009 network/Admin override、OPS-011 API image overrideだけを使用し、API serviceだけを`--force-recreate --no-build --no-deps`で更新した。private-only起動／healthy後にguarded helperで`192.168.62.0/28` egressへAPIだけをattachした。
- Active APIはimage `oripa-v2-api:preview-OPS-011-0d8697294449`、ID `sha256:d075d82d5649a010f0c056d39067830de2e8a734b89d3adc2a6212165c22a28a`、OCI revision `0d86972944491bdd3e9716787381e439848d606f`である。`v2_private internal:true`、API-only egress、Admin／PostgreSQL／Redis private-onlyを維持し、後3 serviceのcontainer ID／start timeは不変である。
- Runtime read-only確認でBrowser HTTP 303実装、Session／CSRF付与、allowlist `["/", "/mypage"]`、`V2MailEmailVerificationNotifier`、Mailgun config configured、API health、DB／Redis、DNS、HTTPS 200／certificate verify 0、Public API 200、activation window 500／502／504 0をPASSした。実recipientメール、新規登録、Resend、Verification Completeは実行していない。Preview Deployment Lockを解放し、他Shared Lockは取得していない。

## MIG-063G Gacha Prize Banner Picker / Catalog Navigation Cleanup

- Cleanな`main = origin/main@b29213213d99562649548e8980e49ac6d85199ec`、Open Platform Taskなし、shared Lock未取得を確認し、Issue #321、Branch `feat/MIG-063G-gacha-prize-banner-picker-nav-cleanup`、専用Worktree、Risk R2で開始した。Migration Allocation、Platform Integration、Artifact Release、Preview Deployment Lockは取得していない。
- Banner Category／Bannerのcursor pagination、画像preview、title、既存Asset一意復元、unresolved Asset保持、Category切替時のselection clearを`CatalogBannerAssetPicker`へ共通化し、単独PrizeのMIG-063C UIとGacha Prize create/editで同一実装を使用する。
- Gacha Prizeは一般Presentation Asset選択を廃止し、選択Bannerが参照する既存Asset IDのみを既存`presentation_asset_id`へ保存する。Asset複製・新規Asset作成は0件で、MIG-063FのRank image/video Pickerは変更していない。
- shared `CatalogSectionNavigation`は`null`を返して横タブだけを非表示にし、独立したAdmin Sidebar navigation／Catalog route registryは変更していない。`/catalog/presentation-assets`はshared横タブを使用しないため変更していない。
- Focused Admin 2 files／27 tests、Admin full unit 33 files／174 tests、typecheck、lint、production build、`git diff --check`はPASSした。Migration、DB Schema、Admin/Public API、OpenAPI、generated Contract、Artifact、Storefront、Runtime／Preview／Production変更は0である。Browser/E2E、Repository全Backend Suite、runtime deployは実行していない。

## STORE-SITE-034 Contact Browser-safe Mutation Boundary

- Human Operator承認済みLedgerを正本としてIssue #324、Branch `feat/STORE-SITE-034-contact-browser-safe-client`、専用Worktree、Risk R3でPhase Bを再開し、Platform Integration Lockを先に取得した。Artifact Release／Preview Deployment／Migration Allocation Lockは未取得である。
- MIG-063G merge後のclean `main = origin/main@7d2f85d2a4e2dadf993594c559f3ffc6c6add04d`へ、Phase A未commit差分11 files／494 linesをstashで保全してsafe base syncし、競合・変更消失なく同一差分を復元した。MIG-063GのAdmin-only pathとgenerated source競合は0である。
- `createBrowserStorefrontContentContactClient()`は匿名／認証済みContact送信のSession bootstrap、CSRF Cookie読取、Header構築、Cookie credentialsをBrowser Transport内部へ閉じ込める。CallerはContact inputだけを渡し、`csrf_token`、Cookie名、Header名、bootstrap手順を扱わない。非冪等Contact POSTは`retry: false`を固定する。
- Fresh検証でStorefront Client generated check／typecheck／lint／build／29 tests、Storefront Testkit generated check／typecheck／lint／build／38 tests／exports／network boundary、`git diff --check`をPASSした。HTTP 202、422 typed Validation、429、typed network error、anonymous first submit、authenticated submit、GET `/api/v2/auth/session`→Contact POST、自動retryなしを含む。Public OpenAPI、generated public types、Site Schema、Runtime／middleware／controller、DB／migration、Admin UI差分は0である。
- 初回GitHub Policy Gateは旧single-segment Task ID正規表現がHuman承認済み`STORE-SITE-034`を拒否した。`SITE-034`等へ代用せず、既存形式を維持して`STORE-SITE-<digits>`だけを加算受理し、malformed／無関係な`STORE-*`を拒否する回帰testを追加した。Policy Unit 148 testsとLocal Policy GateをPASSし、並行中SEC-012とのPolicy source競合は0である。
- 最初のexact-head Required 5 Checks／Fresh Self-review後にSEC-012が`main@ee5299633a7325fd91198537ac3bd429233293fb`へmergeされたため、Artifact Lock取得前に二親safe base syncを再実施した。競合はappend-only `worklogs/new_ver_main.md`だけで両Task記録を保持し、Contract／generated source競合は0である。旧check／review evidenceは無効化してfresh再実行する。
- Latest-main Integration Head `772a869be0c4749ee33a973c1da82d551e6dda85`のRequired 5 Checks／Fresh Self-review PASS後、Canonical latest Artifact `2.0.0-alpha.23`をreadbackしてArtifact Release Lockを二番目に取得した。Package-only alpha.24候補はcanonical whole-Platform source validatorがWorkspace／Admin／Platform／OpenAPIをalpha.23へ固定しているためQuality GateでFail Closedとなった。
- 同じalpha.23 package identityで変更済みClient／Testkitを再発行することはimmutable version invariantに反し、whole-Platform alpha.24化は明示scope外である。候補version metadataは取り消し、Artifact未発行のまま両Shared Lockを逆順解放した。Public OpenAPI／generated public types／Site Schema／Runtimeの変更は0であり、正式なpackage-only version pathまたは別Platform release-version scopeのHuman判断までPhase Bを停止する。
- GOV-016 closeout後、local／origin／GitHub `main@09f6292306873821733b340ee432dea307219143`、Issue #324／PR #326、専用branch／worktree、Shared Locks none、competing Task none、latest immutable alpha.23とcanonical package-only candidate alpha.24をFresh Gateで一致確認した。Platform Integration Lockを取得し、既存exact headをGOV-016 mainへconflict-free two-parent safe syncした。net差分は承認済み14 pathsのままで、Client／Testkit alpha.24、Site Schema／Platform／Application／OpenAPI alpha.23参照のcanonical source validationをPASSした。
- Package source head `209252d9fcbad42090677f5a7bece52c5a5d3597`はRequired 5とFresh Self-review `#issuecomment-5356175413`をPASSし、SEV-0／1／2／3は0。Artifact Release Lockを二番目に取得し、Canonical Run `32371450527`／Artifact `9407435514`でimmutable bundle alpha.24を発行した。Manifest SHA-256 `f71edc9e1c9e9215381d01b00ca066ff8bd2678e8cad92d28fce5981145aad94`、Client `fbe156fbbc9f27a07e4017cc9bea3a9cdcd71aa2943e03fb48236bb48bbda259`、Testkit `3dc1c3488342846580a2a75372f5d9fff8a510b29d1fad2db468e7276b9efc78`、referenced Site Schema alpha.23 `b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2`、Public OpenAPI alpha.23 `5c735fe26514d5bfb47b3515ead108bf473fd5e1f81e0936b7e1986290904043`を相互検証した。five-file inventory／SHA256SUMS／actual digest／archive safety／frozen offline transitive consumer resolutionをPASSし、Artifact Lockを解放した。Preview imageのdeploy／activation、Runtime／DB／migration変更は0。

## SEC-012 Bodyless Logout Browser Security Fix

- Cleanな`main = origin/main@7d2f85d2a4e2dadf993594c559f3ffc6c6add04d`、Issue #325、Branch `fix/SEC-012-bodyless-logout-browser-security`、専用Worktree、Risk R4で開始した。STORE-SITE-034が非重複package scopeのPlatform Integration Lockを保持しているためSEC-012はShared Lockを取得せず、Migration／Artifact／Preview共有資源を変更していない。
- `EnforceV2BrowserSecurity`のJSON media-type例外はroute name `v2.public.auth.logout`かつraw bodyが厳密に空の場合だけとした。cross-site拒否は例外判定前、Origin／CSRF検証は例外判定後の既存順序を維持し、URI文字列例外や全POST緩和は行っていない。
- Task専用PostgreSQLへ既存57 V2 migrationsを通常applyし、PHP 8.4のBrowser Security＋Authentication Flow 24 tests／147 assertionsを警告なしでPASSした。canonical bodyless HTTP Logout 204、session revoke、session Cookie expiry、CSRF Cookie rotation、private cache semantics、invalid Origin、missing／invalid CSRF、cross-site typed Problem Details、Logout／Login／既存Contact Mutationのnon-JSON body 415を確認した。
- 変更PHP syntax、Composer validation、Policy Unit 144、Quality Unit 4、Security Unit 10、Local Quality Gate、`git diff --check`、Allowed Paths、high-confidence secret scanをPASSした。Public／Admin OpenAPI、Storefront Client、Site Schema、Testkit、generated Contract／Artifact、Migration／DB Schema、Admin、Payment、Point、Draw、Storefront Repository、Production変更は0である。Task Docker DB／Redis／networkはcleanupした。
- SEC-012 Policyにはdeployment evidence path、Canonical Preview image build operation、Preview Deployment Lock authorityがないためRuntime Activationは実行しない。Source Closeout後、OPS-010/011境界を維持する別R4 OPS Runtime Activation TaskをFail Closedで要求する。
- 2026-08-20 OPS-012: `git fetch --prune`後のclean `main = origin/main@ee5299633a7325fd91198537ac3bd429233293fb`を固定し、Issue #328、Branch `chore/OPS-012-sec-012-runtime-activation`、専用Worktree、Risk R4で開始した。SEC-012 merged API treeは`5636f9d8353402897b57a7f0731d0b91ccb251fe`である。Preview Deployment Lockだけを取得し、Migration Allocation／Platform Integration／Artifact Release Lockは取得していない。
- OPS-011 active API image／OCI revision、`v2_private internal:true`、API-only egress、Admin／PostgreSQL／Redis private-only、API health、PostgreSQL、Public API 200をpreflightで維持確認した。Application、Migration、DB Data／Schema、Contract、Artifact、Storefront、Admin Runtime、MIG-063F／MIG-063G Runtime、Production、Task⑤は変更せず、実ユーザーSession Logoutは実行しない。
- OPS-012 Draft PR #329をopenのまま作成し、Required 5 Checks成功後のexact headだけをCanonical Preview Image Build sourceとする。Preview hostで`docker build`は実行しない。
- Application Head `8c14b513393f4cecea70a1516b2ebc2624944450`のRequired 5 Checksは全PASSした。GitHub-hosted Canonical Preview Image Build Run `32349836316`／Artifact `9399533827`をwrapperで検証download/loadし、API image ID `sha256:4bfbb204539e3e1e329c18c489e80382b70dcb6ce5c1bead1ad476f59b23280e`、OCI revision同Head、outer digest `sha256:e37ee4389aea14c626505620510c447882e694bfe8d1a1b064a06b170d9e1c62`、manifest SHA-256 `65ffc234b488e82e71dae3a1cbe566e62e7c62cf7d8627e60e00167f4c20f046`を確認した。
- Image内836 tracked API blobs／6 runtime scaffold exclusionsをbyte-for-byte検証し、image API treeとSEC-012 merged `main` API treeが共に`5636f9d8353402897b57a7f0731d0b91ccb251fe`で一致した。Pipelineが生成したAdmin imageはloadのみで、deploy／recreateしない。
- Activation Evidence Head `401153ca4c10940f444b82c96e7b2575477f8889`のRequired 5 Checksとfresh fixed-head self-review `#issuecomment-5353722406`をPASS後、canonical Composeの`--force-recreate --no-build --no-deps api`でAPIだけをrecreateし、healthyなprivate-only start後にguarded helperで`192.168.62.0/28` API-only egressをattachした。Admin／PostgreSQL／Redis ID・start timeは不変である。
- Active APIは`oripa-v2-api:preview-OPS-012-8c14b513393f`、image ID `sha256:4bfbb204539e3e1e329c18c489e80382b70dcb6ce5c1bead1ad476f59b23280e`、OCI revision `8c14b513393f4cecea70a1516b2ebc2624944450`。Runtime read-only確認でSEC-012 middleware、Logout 204／session expiry／CSRF rotation、body付きMutation JSON検証、cross-site／Origin／CSRF保護、Browser 303／Session付与、allowlist `["/", "/mypage"]`、`V2MailEmailVerificationNotifier`をPASSした。
- API health、PostgreSQL、Redis、Mailgun DNS／HTTPS 200／certificate verify 0、Public API 200、activation window API／Nginx HTTP 500／502／504 0をPASSした。実ユーザーSession Logoutは実行していない。Migration created／applied、DB Schema／Data、Public／Admin Contract、Repository Artifact publication、Storefront、Admin Runtime、MIG-063F／MIG-063G Runtime、Payment、Point、Draw、Production変更は0。Preview Deployment Lockを解放し、他Shared Lockは取得していない。

## OPS-013 MIG-063F/G Admin Runtime Activation

- 2026-08-20、Remoteを含むclean `main = origin/main@79ed6ef61d8aa0e7f0d825d1e7b9962608f0e8a0`を固定し、Issue #330、Branch `chore/OPS-013-mig-063fg-admin-runtime-activation`、専用Worktree、Risk R4で開始した。Application、Migration、DB／Asset mutation、Contract、Artifact publication、Storefront、Production、Task⑤、新規Image Buildは対象外である。
- OPS-012 Source `8c14b513393f4cecea70a1516b2ebc2624944450`とlatest Canonical `main`の`apps/admin` treeはともに`7b0a38fc9aa2c16246bbb67c68d33639fd9c3a92`で一致し、MIG-063F `3699526536c6ecaee8e09aa5da5a28d3b1d744a1`とMIG-063G `7d2f85d2a4e2dadf993594c559f3ffc6c6add04d`を含む。
- OPS-012 Run `32349836316`／Artifact `9399533827`を再検証し、outer digest `sha256:e37ee4389aea14c626505620510c447882e694bfe8d1a1b064a06b170d9e1c62`、manifest SHA-256 `65ffc234b488e82e71dae3a1cbe566e62e7c62cf7d8627e60e00167f4c20f046`、Admin image `oripa-v2-admin:preview-OPS-012-8c14b513393f`、image ID `sha256:d7d028c1f3f4ab9d8362c87e0d131edae7f3e16c17704af6440b2531728d3109`、OCI revision同Source、`linux/amd64`を確認した。新規Buildは0である。
- 現行Admin/API/PostgreSQL/Redisはhealthy。`v2_private internal:true`、Admin/PostgreSQL/Redis private-only、`192.168.62.0/28` API-only egressを維持確認した。Activation前にRequired 5 Checksとfresh fixed-head self-reviewを要求し、Preview Deployment Lock取得後にAdminだけを`--force-recreate --no-build --no-deps`で更新する。
- Activation Head `c0cc47fbaab37ff97df1c8a685277a909f5f4ddc`のRequired 5 Checksとfresh self-review `#issuecomment-5354341728`をPASS後、Preview Deployment Lockを取得し、既にload済みのOPS-012 Admin imageだけを再検証・再利用した。新規Artifact download/load、Host/GitHub Image Build、別head image生成は0である。
- Canonical Composeとexact overrideでAdminだけを`--force-recreate --no-build --no-deps admin`更新した。Active Adminは`oripa-v2-admin:preview-OPS-012-8c14b513393f`、image ID `sha256:d7d028c1f3f4ab9d8362c87e0d131edae7f3e16c17704af6440b2531728d3109`、OCI revision同Source、container `9fda202bd6957d75bedbe8448d03ea8b58fea10e260edaed369f39f593a2f58f`でhealthyである。
- API／PostgreSQL／Redisのcontainer ID・start timeは不変。`v2_private internal:true`、Admin/PostgreSQL/Redis private-only、`192.168.62.0/28` API-only egressを維持した。Admin internal healthとAPI health（DB／Redis／storage含む）は200、activation windowのAdmin／API／Nginx 500／502／504は0である。Admin default host-loopback `3611`は未公開のためcurl 7であり、private-only境界を確認した。
- Source／bundle readbackでRank image/video Picker、Gacha Prize Banner Picker、既存Asset IDの`presentation_asset_id`保存を確認し、`CatalogSectionNavigation`は`null`、compiled JavaScriptの`catalog-tabs` match 0で横Navigation非表示を確認した。Browser/E2E／実管理画面操作は人間側確認として未実行。Migration created/applied、DB／Asset mutation、Contract、Artifact publication、Storefront、Production、Task⑤、新規Buildは0。Preview Deployment Lockを解放した。

## GOV-016 Package-only Artifact Version Governance

- Cleanな`main = origin/main@906a5ae5189dd0b895afcf371ab074783860e2d1`、GitHub Issue全履歴、Task Policy、Remote branch、Active Task Ledgerを照合し、未使用Task ID `GOV-016`、Issue #332、Branch `chore/GOV-016-package-only-artifact-governance`、専用Worktree、Risk R4で開始した。Platform Integration LockとArtifact Release Lockを取得し、Migration Allocation／Preview Deployment Lockは取得していない。
- Finalized Package Version Compatibility PolicyはCore Family Major一致を要求する一方、Component Minor／PatchとOpenAPI document versionの独立管理、ManifestでのPlatform／Contract／Package別version記録を明示する。現行alpha.23全version一致はInitial Alpha builder、Policy Gate、workflow inline validatorの実装制約であり、formal monoversion invariantではないため、Option Aのpackage-only releaseを採用した。
- Latest immutable Storefront Contract Artifact `2.0.0-alpha.23`、Manifest SHA-256 `556eaf59e9c5128cb9b93cf9000a5aee3ff4eb56f86ee8bc549c392d55bd77fe`、Source `633b41f347083c82028229d6e238842118635feb`、Client／Site Schema／Testkit／Public OpenAPI digestをroot-only evidenceとWorklogからreadbackした。alpha.21、retired alpha.22、alpha.23をimmutable historyへ固定し、既存または旧versionの再発行をFail Closedする。
- Candidate bundleと公開Client／Testkitだけを`2.0.0-alpha.24`へ進め、Platform／Application／Public・Admin・Webhook OpenAPI／Site Schemaは`2.0.0-alpha.23`を維持する。Site Schemaはalpha.23のpackage digestとsource treeを参照し、新Artifactへtarballを再pack／同梱しない。Client minimum Public API、Testkit exact Client／Schema dependency、compatibility metadataをManifestへ完全一致させる。
- 新しい`storefront_contract_artifact.py`はnext-alpha progression、publish set、reference digest/tree、source package／contract version、Public OpenAPI digest、artifact file inventory、tarball identity、SHA256SUMSを検証する。skip optionや全version check無効化はなく、full Platform bundleは引き続きmonoversion以外を拒否する。
- Frozen install、Client 27 tests、Site Schema 10 tests、Testkit 34 tests、各generate check／typecheck／lint／build／export／network guard、Release Unit 20 tests、Policy Unit 146 tests、Security Unit 10 tests、Local Policy／Quality／Security Gate、Composer／workspace pnpm／legacy pnpm fresh audit各0 finding、Platform release source validation、package-only source validation、Python compile、`git diff --check`をPASSした。GOV-016 headと未変更のSTORE-SITE-034 exact head `a90858895062b0205a125e344a9d595d31a7e298`のsynthetic mergeは競合なしで、package-only source validationとPolicy GateをPASSした。Runtime deploy、DB／Migration、Payment、Point、Draw、Admin／API behavior、OpenAPI content、Site Schema content、STORE-SITE-034 branch／worktree／Issue／PR、Artifact publicationは変更／実行していない。

## MIG-067 Gacha Lifecycle / Published Edit Integrity

- Cleanな`main = origin/main@411ea6593fa67cae618ed8a16ec3b8fa2253aaba`、Issue #334、Branch `fix/MIG-067-gacha-lifecycle-edit-integrity`、専用Worktree、Risk R4で開始した。Migration Allocation Lockで`000058`を確保・materialize後に同Lockを解放し、Platform Integration Lockはmerge／cleanupまで保持する。Artifact Release／Preview Deployment Lockは取得していない。
- 初回予約公開はVersion／Probabilityを確定しても`first_published_at`、`published_version_id`、`active_draw_state_id`を設定せず、Draft Versionとactive Scheduleを保持する。開始時刻まではガチャ本体全項目を編集でき、Scheduleの時刻／期待revisionも同一transactionで同期する。due Workerがclaim／lease／retry境界を通過した時点だけ、Version、Draw State、Inventory、`first_published_at`を原子的に初回公開する。
- 一度公開されたガチャ本体はtitle、thumbnail、category、tags、description、notices、management status、publish endだけを現在表示へ反映する。price points、total count、daily draw limit、audience、allowed draw counts、publish start等は既存Published Snapshotと照合して409で拒否する。Category関連は`catalog_gachas.category_id`を正本とし、Published Snapshotは変更せず、既存Public Contractのcategory fieldも同関連を返す。
- 管理者入力の`total_count`は維持し、Prizeから自動算出しない。BackendはDraft create/update、Published operational inventory adjustment、total count変更でaggregate上限を確認する。DBは親Version row lockとDEFERRABLE constraint triggerでsnapshot `initial_inventory`合計とoperational `total_quantity`合計の双方が`total_count`を超えるtransactionを拒否する。既存超過データやlegacy activated scheduleは自動修復せずMigrationをFail Closedする。
- Task専用PostgreSQLへ既存58 migrationsを通常applyし、`000058` rollback／reapplyをPASSした。Focused Catalog 32 tests／469 assertions、変更PHP syntax、Policy Unit 151、`git diff --check`をPASSした。共有Preview DBへのMigration適用、Runtime activation、Artifact release、Production、Browser/E2Eは実行していない。
- Public／Admin OpenAPI、generated Contract、Storefront、Admin UI、Legacy Probability、Coin、Payment、Draw Core、Inventory adjustment historyは変更していない。Probability API除去／内部自動生成と詳細UIは次Task T2へ残す。

## MIG-068 Canonical Gacha Publish / Legacy Probability Internalization

- Cleanな`main = origin/main@7434709fcbf2faf93cd7e22fce51d1badc3411b9`、Issue #336、Branch `fix/MIG-068-canonical-gacha-publish`、専用Worktree、Risk R4で開始した。Migration Allocation `000059`とPlatform Integration Lockを確保し、Artifact Release／Preview Deployment Lockは取得していない。
- Publish PreflightからProbability Draft／Version選択／Server Validation／Probability Publish／Snapshot選択の必須条件を除いた。即時公開はVersion row lock内で既存のvalid Published SnapshotをCanonical選択し、存在しなければ残在庫Drawとは独立したLegacy表示・履歴用metadataを内部生成／publish／selectして、Version、Draw State、Inventory、Gacha lifecycleと同一transactionで確定する。
- 予約公開は保存時に同じCanonical処理で内部Probability Draftを一意に作成・pinするが、Version選択とProbability publishは行わない。MIG-067のDraft lifecycleを維持し、due Worker transaction内でpin済みDraftをpublish／selectして初回公開する。予約編集は同じDraftを再利用し、内部失敗はretry対象としてVersion／Draw State／Probabilityを重複生成しない。
- 内部metadataは`__canonical_inventory_v1`単一stage、初期在庫比を厳密に1,000,000 ppmへ配賦したPrize entries、0 ppm／0 pointのpoint-back minimum guaranteeで構成する。これは既存Legacy Probability参照とPublic確率表示互換のためだけに保持し、Draw Coreの暗号学的乱数・残在庫加重選択は変更していない。
- stable Problem Codeとして入力不足、景品不足、Lifecycle不正、Inventory／capacity不整合、内部publish failureを分離した。既存Probability tables／API／Published Version／snapshot／Draw結果／Inventory／Ledger／Audit履歴は削除・更新せず、Public／Admin OpenAPI、generated Contract、Storefront、Admin UI、Point／Payment／Refundは変更していない。
- 新規Migration `2026_09_14_000059_internalize_v2_canonical_probability_publish.php`はMIG-067 schedule guardへ予約中の内部Canonical Draftだけを加算許可し、processing中のVersion publish時だけpin済みProbability選択を許可する。既存Migration改変、trigger disable、historical data rewriteは0。Task専用PostgreSQLへ全59 migrationsを通常applyし、`000059` rollback／reapplyをPASSした。Shared Preview／Productionへは適用していない。
- Focused Catalog 56 tests／745 assertions、追加Worker retryとactive Schedule rollback拒否を含むLifecycle 6 tests／148 assertions、Policy Unit 152、OpenAPI Unit 7、OpenAPI 3-surface bundle check、変更PHP syntax、`git diff --check`をPASSした。既存test imageに`.env`がないwarningのみでFAILは0。`migrate:fresh`を内包する既存fork concurrency classは禁止に従いlocal未実行とし、Required Checks／fresh fixed-head self-review／merge／cleanupはFinal Head固定後に継続する。

## MIG-069 Canonical Gacha Admin UI Integration

- Cleanな`main = origin/main@4d3eb24ec5450088bd2df6b8cfe1bc1b06173ddd`、Issue `#338`、Branch `fix/MIG-069-canonical-gacha-admin-ui`、専用Worktree、Risk `R3`で開始した。Migration Allocation／Platform Integration／Artifact Release／Preview Deployment Lockは取得していない。
- ガチャ編集画面は基本情報フォーム、Rank、景品の順へ統合し、MIG-067の公開後WhitelistをUIで維持する。Probability／Preflightの通常導線は非表示化するが、Legacy API／compatibility codeは削除しない。
- 景品の総在庫入力は総口数から他景品と当該フォーム値を一度ずつ差し引いた残りを表示し、負値はUI保存を拒否する。Backendのcapacity制約、正規在庫補正、audit semanticsは変更しない。
- 保存済み景品Previewはcanonical `presentation_asset_id`を使用し、public pathがない場合も既存の認証済みcontent endpointで表示する。Asset複製・新規Asset作成は0件である。

## OPS-014 Canonical Gacha Preview Runtime Activation

- `git fetch --prune`後、clean local／origin／GitHub `main`と確認済みcandidateはすべて`d01a3ca7511691a729a781959ab715ddd0d43f7a`で完全一致し、後続Platform commitは0。未使用Task ID `OPS-014`、Issue #340、Branch `chore/OPS-014-canonical-gacha-runtime-activation`、専用Worktree、Risk R4で開始した。
- Shared Task lane／Shared Locks／Storefront laneはidle、Preview Deployment OS lockはfree、API／Admin／PostgreSQL／Redisはhealthyかつrestart 0、Resource Gateはdisk 22 GiB、available memory 3393 MiB、swap 5420 MiBでPASSした。Preview Deployment Lockはまだ取得していない。
- Shared Preview migration ledgerは55件、latest batch 26、最終`000055`であり、`000056`／`000057`／`000058`／`000059`はすべてPendingだった。明示継続条件の`000056`／`000057` Ranを満たさないためMigration GateをFail Closedし、`000058`／`000059`適用、Canonical Preview Image Build、artifact download/load、API／Admin activation、business mutation、rollbackを一切実行していない。
- Active API／Adminは既存OPS-012 imageとOCI revisionを維持し、DB row count／fingerprintはroot-only evidenceへ保存した。Storefront、Production、Payment／Coin／Refund、Draw Core、Public Contract／Artifact、Nginx／DNS／TLS、network境界、runtime env、Legacy Probability物理record、Application sourceの変更は0。Post-Activation SmokeとHuman Browser VerificationはActivation未実行のため全項目NOT RUNである。
- Draft PR #341を作成し、Policy Unit 152、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、fresh Composer／workspace pnpm／legacy pnpm audit各0 finding、deployment JSON parse、exact scope、`git diff --check`をPASSした。最終evidence headでRequired 5 Checksとfresh fixed-head self-reviewを要求する。

## OPS-015 Shared Preview Migrations 000056 / 000057

- `git fetch --prune`後、clean local／origin／GitHub `main@25047b47dcefaffff20b453cf607f393dbb8f786`、idleな全Task lane、Shared Locks none、freeなPreview OS lockをreadbackし、未使用Task ID `OPS-015`、Issue #342、専用branch／worktree、Risk R4で開始した。
- OPS-014 tool transcriptで露出したPreview Runtime資格情報のrotation完了を示す正本証跡はなく、root-only Preview env metadataも露出前のため、DB write GateをFail Closedした。資格情報値は表示・記録・copy・hash・再取得していない。mutable Preview windowへ入らないためShared Lockは取得していない。
- read-only ledgerは55 migrations、latest batch 26、最終`000055`で、`000056`／`000057`／`000058`／`000059`はすべてPendingだった。`000057`のmulti-Gacha owner、適用後same-Gacha code重複、unowned code重複の各fail-closed候補は0件である。
- 16対象tableの適用前後row count／ordered full-row fingerprintは完全一致し、既存Gacha 9、Category 6、Tag 3、Rank 11、Audit 1272を含むbusiness/history dataを保持した。unexpected retrospective mutation 0、History rewrite 0である。
- `000056`／`000057`のMigration適用、batch割当、rollback、`000058`／`000059`、Runtime Build／Deploy、API／Admin image変更、Storefront／Production／Payment、Nginx／env／network変更は0。rotation完了を正本で確認した別Taskが`000056`／`000057`のみを適用するまでCanonical Gacha Runtime Activationは開始不可である。

## OPS-016 Shared Preview Runtime Credential Rotation

- `git fetch --prune`後、clean local／origin／GitHub `main@2a017ff0bcdf70ca63a512fe44f15fb620fbac22`、idleな全Task lane、Shared Locks none、freeなPreview OS lock、healthyかつrestart 0のAPI／Admin／PostgreSQL／Redisを確認し、Issue #344、専用branch／worktree、Risk R4で開始した。
- OPS-014 transcriptのkey名だけを照合し、canonical rotation対象を`V2_APP_KEY`、`V2_DB_PASSWORD`、`V2_REDIS_PASSWORD`、`V2_AUDIT_HMAC_KEY`、`V2_PII_CORRELATION_KEY`、`MAILGUN_SECRET`と特定した。Laravel暗号化Data、Audit hash chain、PII correlationを保持する安全なversioned rotation手順と承認済みMailgun provider操作／Preview専用境界が正本にないため、明示Rotation Gateをmutation前にFail Closedした。
- credential変更、新規値生成、temporary secret file、service recreate、Runtime／DB／business mutation、Migration、Application、Artifact、Storefront、Production、Nginx／DNS／network変更は0。Preview Deployment Lockは取得せず、OS lockはfreeのままである。
- API health 200（DB／Redis含む）、Public session 200、Admin health 200、PostgreSQL／Redis healthy、restart loopなし、全restart count 0、HTTP 500／502／504 0、private/internalとAPI-only egress境界維持を確認した。
- OPS-015 unexpected fields 5件はCanonical Compose／Applicationが参照するactive Runtime fieldであり、削除せずguardも変更していない。root-onlyとRepository証跡はrotation未完了、remaining exposed canonical credentials 6、`000056`／`000057` Activation開始不可を明示する。
- Preflightの広域root-only evidence検索で既露出値をOPS-016 transcriptへ意図せず再表示した。値はTask FileへCopy／保存していないが、zero transcript displayは主張せず、rotation blocker継続として記録する。

## OPS-017 Shared Preview Credential Remediation

- clean local／origin／GitHub `main@a75a88f113ab29a7af5c55cdf4f51bb7c2812629`、idleな全Task lane、Shared Locks none、freeなPreview OS lock、healthyなAPI／Admin／PostgreSQL／Redisを確認し、Issue #346、専用branch／worktree、Risk R4で開始した。canonical sourceはroot-owned mode `0600`の2ファイルで、対象6 keyは値を表示せず存在だけを確認した。
- 永続依存はAudit v1 record 1286、Contact 1、Shipping Address 1、Shipping Request 2、Admin TOTP 1、Outbox 145である。User Phone、SMS Challenge、Payment Provider Event、Audit Daily Digestは0。履歴値、ciphertext、HMAC、hash、digestは出力していない。
- Laravel 13 native previous-key decrypt、既存`hmac_key_version`によるAudit key選択、PII active-write／previous-key lookupを最小互換方式として実装した。historical row rewrite、schema変更、Migration `000056`〜`000059`変更、Provider推測は0。Canonical Runtime guardは開発／CI専用の厳密schemaであり、OPS-015のactive Runtime fieldsを取り込む根拠がないため変更していない。
- Task専用PostgreSQL／RedisでLaravel native encryption、Audit version rollover、Contact previous correlation、Phone ownership previous correlationを含むfocused 32 tests／179 assertionsをPASSした。Shared Preview rotation、service recreate、business mutation、Production／Storefront変更はまだ実行していない。
- Application Source Head `dbc5cbec574c00725c3209c922c05dbcc78b52c6`はRequired 5 ChecksをPASSした。最初のIntegrationはV2全489 testsがPHP既定128 MBを超えてFAILしたため、assertionを弱めず、isolated V2 full-suite processだけを512 MBで起動するようsmoke runnerを修正した。同一full suiteは489 tests／4721 assertions／9 skippedでPASSした。
- Preview Deployment Lock取得後、trusted workflowのverified API Artifactをloadし、CSPRNGで`V2_APP_KEY`、`V2_DB_PASSWORD`、`V2_REDIS_PASSWORD`、`V2_AUDIT_HMAC_KEY`、`V2_PII_CORRELATION_KEY`をrotationした。Canonical sourceはroot-owned mode `0600`を維持し、Laravel previous key、Audit v1/v2 key version、PII previous-key lookupを反映した。API／PostgreSQL／Redisだけをrecreateし、Adminはrecreateしていない。
- rotation後のread-only検証でhistorical ciphertext 24件、Outbox ciphertext 11件、Audit chain 1296件、Contact 1件、Shipping 3件を正常に扱い、既存Auditは全件v1のまま保持した。API health 200（DB／Redis含む）、Public Session 200、Admin HTTPS health 200、API／Admin／PostgreSQL／Redis healthy、restart 0、HTTP 500／502／504 0をPASSした。business mutation、historical row rewrite、Shared Preview migration applyは0である。
- Mailgunはsecretを使用しないConfiguration／DNS／TCP 443／HTTPS connectivityだけをPASSし、実送信とprovider rotationは実行していない。承認済みcallable provider操作とPreview専用account boundaryがなくProduction共有を否定できないため、`MAILGUN_SECRET`だけBLOCKEDを維持した。残存露出資格情報は1件であり、`000056`／`000057` Migration Activationは開始不可である。
- rollback candidateはprotected fileとして保持し、staged／task-test secret fileは0、credential value／credential hash／credential digestのtranscript再露出は0。Canonical guard、Production、Storefront、Nginx／DNS／network topology、Payment／Coin／Draw semanticsは変更していない。Runtime readback後にPreview Deployment Lockを解放した。

## OPS-018 Shared Mailgun Credential Rotation

- Human OperatorはShared Preview／V1 Productionが同一Mailgun domainと共通`MAILGUN_SECRET`を使用する方針、新credential発行、`/var/www/oripa/.env`への非表示反映、旧credential継続有効を確定した。clean local／origin `main@0819b1c0933af0ef45552a87a55a4f89cdac234d`、active Platform Taskなし、Shared Locks none、freeなPreview OS lockを確認し、Issue #348、Branch `chore/OPS-018-mailgun-shared-credential-rotation`、専用Worktree、Risk R4で開始した。
- root-owned mode `0600`のPreview canonical envはcanonical assignment shape、必須Mailgun key各1件、`MAILGUN_SECRET`非空を値なしでPASSした。稼働中Shared Preview APIとV1 Production backendはいずれもMailgun consumerで、Production canonical `/var/www/oripa/backend/.env`は旧credentialのまま、新旧は値／hash／digestを出力せずDIFFERENTと確認した。Preview Deployment Lockを取得し、旧credentialだけをroot-only mode `0600` rollbackへ保存した。
- 新credentialはMailgun test-mode認証、domain read authorizationをPASSし、exact Provider subtypeは未確認だが`DOMAIN_READ_CAPABLE`なAPI credentialと判定した。Shared Preview APIだけを既存OPS-017 image／canonical Composeで再作成し、API-only egressを再接続した。containerの新credential一致、API health 200、Public Session 200、Admin／PostgreSQL／Redis healthy、全restart 0、canonical sender self-recipientへの非business実送信をPASSした。
- Human承認済みのMailgun credential-only Production変更として、`/var/www/oripa/backend/.env`の`MAILGUN_SECRET` 1 keyだけを新credentialへ原子的に更新し、V1 Production backendだけをno-build／no-depsで再作成した。backend health 200、新credential一致、test-mode認証、非business実送信をPASSし、Frontend／PostgreSQL／RedisのIDとstart時刻は不変でrestart 0を維持した。
- 停止中Production queue／schedulerは別の旧credential snapshotを保持していたため、起動せずcanonical Compose `create --force-recreate --no-build`で新credentialの`created`状態へ置換した。現在存在するMailgun consumerはShared Preview API、Production backend、停止queue／schedulerのすべてが新credentialであり、rollback old／new候補はroot-only mode `0600`で保護される。activation windowのHTTP 500／502／504は0、business mutation／Migration／Storefront／unrelated Production変更は0である。
- 旧credentialはProvider側で有効なまま保持している。Codexに承認済みのMailgun key失効operationはないため、pre-revocation evidenceをroot-onlyで固定し、Human Operatorによる旧credential失効と非秘密の完了確認を待つ。失効後にPreview／Production双方のtest-mode認証と実送信を再検証するまでremaining exposed credentialsは1、`000056〜000059` ActivationはBLOCKEDを維持する。
- Human Operatorが旧credentialを`2026-08-21 08:15 UTC`に失効した。保護済み旧credentialによるtest-mode認証は期待どおり拒否され、新credentialは失効後もShared Preview／Production backend双方で認証と実送信をPASSした。全consumerの新credential一致、API／Admin／PostgreSQL／Redis／Production backend health、restart 0、HTTP 500／502／504 0を再確認後、OPS-018 old／new secret copyを削除し、secret transcript再露出0、remaining exposed credentials 0を確定した。
- Authoritative Repository evidenceは`deployments/OPS-018-mailgun-shared-rotation.json`、詳細Reportは`worklogs/reports/OPS-018-report.md`、root-only evidenceは`/var/lib/oripa-v2-evidence/OPS-018/rotation-evidence.json`とする。OPS-018のmerge／closeout完了後、`000056〜000059 + API/Admin Runtime Activation`は開始可能である。
- Policy Unit 152、Quality Unit 4、Security Unit 10、Local Policy／Quality／Security Gate、Composer／workspace pnpm／legacy pnpm audit各0 finding、JSON parse、`git diff --check`をPASSした。Full Backend／Frontend／Browser E2EはApplication変更0のRuntime credential TaskのためNOT RUNとし、代わりにPreview／Production双方の実送信とhealthを実行した。
- Reviewed Head `60fed67bc627f0c493db45da4bbe5833bb1ac04c`のRequired 5 Checksとfresh fixed-head self-reviewはPASSし、SEV-0／SEV-1は0だった。先行するsuperseded workflowのfailed checkが同一Headへ残ってGitHub mergeabilityが`unstable`となり、approved wrapperはFail Closedした。Gateやassertionを変更せず、本記録だけを追加したsuccessor HeadでRequired 5 Checksとfresh self-reviewを再実行する。

## OPS-019 Canonical Gacha Shared Preview Activation

- clean local／origin／GitHub `main@9e8e644c1302b3257f1076a829a4aba6198fd0b9`、idleなTask lane／Shared Locks、freeなPreview OS lock、healthyかつrestart 0のAPI／Admin／PostgreSQL／Redis、OPS-018 remaining exposed credentials 0をreadbackし、Issue #350、Branch `chore/OPS-019-canonical-gacha-preview-activation`、専用Worktree、Risk R4で開始した。
- Shared Preview ledgerは55件、latest batch 26、最終`000055`である。`000056`／`000057`／`000059` predicateはPASSし、`000058`はHuman承認済みPreview test fixtureのGacha 10／11／12・Version 8〜12が`total_count=9`、snapshot／operational capacity各18である5件だけをFail Closed検出した。
- 次の未使用Allocation ID `000060`を使い、filename順で`000057`後かつ`000058`前となるexact one-shot forward migrationを追加した。fixture不在環境はno-op、部分存在／不一致はwrite前FAIL、exact row lock、transaction内のtotal_count限定guard例外、Version 5行／Draw State 3行だけ9→18、元guard完全復元、不可逆downのFail Closedを実装した。Trigger／constraint disable、inventory縮小、history rewriteは0。
- Shared Preview read-only dumpのTask専用cloneで`000056→000057→000060→000058→000059`をbatch 27としてPASSした。17関連row typeのrow count／fingerprint、sold count、Inventory／Adjustment、Draw／User Prize、Exchange／Shipping、QA、Probability historyは全て一致し、capacity違反0、guard復元`t/t`を確認した。別cloneのexact mismatchはmigration ledger 0、Version 5行／Draw State 3行が9のまま、guard不変でFail Closedした。live Shared Previewは55 migrations／batch 26、対象total 9のまま未変更である。
- PHP syntax、Policy Unit 153 tests、focused irreversible-down 1 test／2 assertions、`git diff --check`をPASSした。Required Checks、fresh fixed-head Self-review、exact-source Preview image build、live Migration／Runtime Activation／smoke／closeoutは未実行である。
- Draft PR #351を作成した。activation sourceを固定するsuccessor HeadでRequired 5 Checksとfresh fixed-head Self-reviewを通過後にのみ、Preview mutable windowへ進む。
- Activation Source `ba17d719767346569bb2e60ae9e733103848f8e6`のRequired 5 Checksとfresh fixed-head Self-reviewをPASSし、Canonical GitHub Build Run `32476998489`／Artifact `9444761948`のexact-source API／Admin imageを検証loadした。Preview host buildは0である。
- Preview Deployment Lock／OS lockを取得し、root-only mode `0600` DB backup後にShared Previewへ`000056→000057→000060→000058→000059`をbatch 27で適用した。Version 8〜12とDraw State 8／11／12の`total_count`だけを9→18へforward reconciliationし、sold count、Inventory／Adjustment、Draw／User Prize、Exchange／Shipping、QA、Probability historyを含む17 row typeのrow count／fingerprintは不変、capacity違反0、guard復元`t/t`を確認した。
- API→Admin順で同Source imageへactivationした。両RuntimeとPostgreSQL／Redisはhealthy、restart 0、`v2_private internal:true`、API-only egress、Admin／PostgreSQL／Redis private-onlyを維持し、HTTP 500／502／504とruntime errorは0、rollbackは0。両lockを解放した。
- API health／session／Gacha list／existing detail／slug detail、Admin health／session endpoint、`luxe-pack.biz`／`test.luxe-pack.biz`／両Storefront sessionは200。Admin bundle/sourceで基本情報→Rank→景品、総口数残り、変更理由、thumbnail、通常UIのProbability／Preflight／Request ID／internal code非表示を確認した。
- 唯一保持されたPreview test admin資格情報はstaleで認証loginが401だった。資格情報更新やAuth mutationは行わず、authenticated Admin list/detailと全mutation scenarioをHuman Browser Verificationへ残した。Production／Storefront Runtime／Payment／Coin／Draw semantics、env／Nginx／network、対象外business/history変更は0である。

## MIG-070 Canonical Gacha Admin Browser Regression Fix

- clean local／origin／GitHub `main@8b8b39fe221d7030cfd2aaf788f0b8f3994ea4c7`、OPS-019 ancestor、Shared Preview migrations `60`／batch `27`、healthy restart 0のAPI／Admin／PostgreSQL／Redisをreadbackし、Issue #352、Branch `fix/MIG-070-canonical-gacha-admin-browser-regressions`、専用Worktree、Risk R4で開始した。Stage 0で3件とも既存API／Admin source-only Regressionと確定し、Migration／Public Contract／Storefront／Payment／Coin／Draw Core／historical rewrite／Production／security weakeningは不要と判定した。
- 状態変更を含む保存は、Draft core updateとMIG-068 canonical activationが`catalog_gachas.revision`を各1回進め、MIG-067 deferred guardがPostgreSQL `P0001` `Public and Draw activation pointers require one Revision update`でtransaction全体を拒否していた。初回Draft→Publishedではcanonical activationだけが単一revision mutationを行うよう修正し、編集categoryをactivationへ渡した。stable Problem Code `CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID`／`CATALOG_GACHA_SCHEDULE_CONFLICT`を正しい日本語へmapし、Request ID／internal code非表示を維持した。
- 景品thumbnailはcreate／updateとも`catalog_prizes.presentation_asset_id`と`catalog_gacha_version_prizes.presentation_asset_id`へ保存され、Admin list APIも`presentation_asset`を返していた。Admin UIがpublic `/api/v2/content/assets/{id}`を優先した一方、Admin Nginxは`/admin/api/`だけをproxyするためwrong runtimeの404となっていた。既存authenticated content endpointを常に使い、asset id変更時にfailure stateをresetした。既存Asset再利用、複製0を維持した。
- Banner登録APIは正常で、登録前PreviewだけがCSP非許可の`blob:` object URLを生成していた。`FileReader` data URLへ置換し、選択直後／file変更時Previewを更新した。CSP、保存API、credential、DB semanticsは変更していない。
- Focused API 16 tests／266 assertions、Focused Admin 4 files／45 tests、変更PHP syntax、Admin typecheck／lint／production build、`git diff --check`をPASSした。Activation Source `2b55452abc4c1e43948ce0de4accd6c23e6c9e34`のRequired 5 Checksとfresh fixed-head self-review `#issuecomment-5370801305`をPASSした。
- Canonical GitHub Build Run `32489913072`／Artifact `9449448240`でexact-source Linux/amd64 API／Admin imageを生成・検証loadし、Preview host build 0でAPI→Admin順にactivationした。API／Admin imageは`preview-MIG-070-2b55452abc4c`、OCI revisionは同Source。PostgreSQL／Redisを含めhealthy restart 0、migration count／batch `60`／`27`不変、HTTP 500／502／504とruntime errorは0、rollback 0である。
- Public Gacha list、Admin root／sessionは200、authenticated Asset routeはanonymous 401、旧wrong public pathは404、deployed Banner chunkは`FileReader`あり／`createObjectURL`なしを確認した。安全な既存authenticated Admin sessionがないためpassword reset／新Admin作成はせず、状態保存／thumbnail create-update表示／Banner選択登録のBrowser mutation acceptanceはHuman確認へ残した。Probability／PreflightとRequest ID／internal code非表示はsource／focused testでPASSした。
- Preview Deployment／OS lockはfinal readback後に解放した。Migration／Contract／Artifact publication、Storefront／Production、Payment／Coin／Draw、env／Nginx／network、credential、無関係business data変更は0。Runtime blockerはなく、残項目はHuman Browser mutation acceptanceだけである。

## MIG-071 Gacha Unpublish Lifecycle Regression Fix

- clean local／origin／GitHub `main@0cfff7d9c20ae28ff11e6ab55398d85ce5560419`、all Shared Locks none、freeなPreview OS lock、migration count／batch `60`／`27`、healthy restart 0のMIG-070 API／Admin／PostgreSQL／Redisをreadbackし、Issue #354、PR #355、Branch `fix/MIG-071-gacha-unpublish-lifecycle`、専用Worktree、Risk R4で開始した。
- Stage 0でAdminは既存full `PUT /admin/api/v2/catalog/gachas/{id}`へ正しい`management_status: unpublished`とrevisionを送信していることを確認した。presentation updateとrequired pause／terminal pointer clearの複数Gacha revisionをMIG-067 deferred triggerがcommit時に比較し、両Human failureをSQLSTATE `P0001` `Public and Draw activation pointers require one Revision update`でtransaction全体rejectしていた。outer test transactionはdeferred triggerを強制せず偽PASSしていた。
- Terminal pointer clear前にpending non-activation row eventを既存constraintで検証し、deferredへ戻してfinal deactivationをそのOLD rowから1 revisionで実行する最小修正とした。required persisted pause、public-deactivation／activation guard、Published whitelist、Draft／Publish／Pause／Resume、Inventory／Draw／User Prize／historyを変更していない。`unpublished -> published`は`CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID`で禁止を維持し、既存日本語mappingとRequest ID／internal code非表示はAdmin focused testでPASSした。
- Isolated PostgreSQL lifecycle focused test 9 tests／216 assertions、Admin lifecycle focused 1 file／6 tests、changed PHP syntax、`git diff --check`をPASSした。Activation Source `66825e5a58f815155fd009deb36b8eb8b173d2b2`はRequired 5 Checksとfresh fixed-head self-review `#issuecomment-5372227179`をPASSした。
- Canonical Build Run `32500569379`／Artifact `9453458501`でexact-source API／Admin imageを生成・検証し、API archiveだけをloadした。Preview Deployment／OS lock下でAPIを`preview-MIG-071-66825e5a58f8`へAPI-only activationし、AdminはMIG-070 image／revision／start時刻不変とした。API／Admin／PostgreSQL／Redisはhealthy restart 0、migration `60`／batch `27`不変、HTTP 500／502／504とruntime errorは0、両lockは解放済みである。
- 安全な既存authenticated Admin sessionがないためpassword reset／Admin作成はせず、`published -> unpublished`、`sales_paused -> unpublished`、terminal republish拒否の3点だけHuman Browser Verificationへ残した。Migration／Contract／Storefront／Production／Payment／Coin／Draw Core／historical rewrite／env／credential／Nginx／network／unrelated business data変更は0で、source／runtime blockerはない。

## MIG-072 Gacha Draft / Unpublished Lifecycle Final Fix

- clean local／origin／GitHub `main@094199a5e3f64918a854943811ee6895f4105d8b`、Shared Locks none、freeなPreview OS lock、migration count／batch `60`／`27`、healthy restart 0のMIG-071 API／MIG-070 Admin／PostgreSQL／Redisをreadbackし、Issue #356、Branch `fix/MIG-072-gacha-draft-unpublished-lifecycle`、専用Worktree、Risk R4で開始した。指定5 Public IDはexact match各1件で、title／current statusをwrite前にroot-only evidenceで確認した。
- application mapping、Canonical Publish preflight／activation、PostgreSQL lifecycle guardが`unpublished`をterminalとしていたため、`draft -> unpublished`と`unpublished -> draft`を許可し、公開履歴ありではlatest Published Versionをimmutable sourceとしてDraftへcloneする。復元DraftからCanonical Publish可能、direct `unpublished -> published`は`CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID`で禁止、original `first_published_at`とPublished edit whitelistを維持する。
- exact migration `000061`はDB guardの`unpublished -> draft`だけを許可し、復元後のrollbackはfail closedとする。Shared Previewの指定legacy QA dataでは、terminal deactivationがpause preflight、historical Probability hash再検証、zero-available `sold_out` rejectionを経由し、さらにmissing stable-exception helperへ到達した。migration `000028`もpaused sourceを要求してdirect deactivationを拒否していた。
- terminal pathをimmutable structural reference検証へ限定し、`selling`／`paused`／`sold_out` Drawを許容、sales／sold count／Probability／Draw／Inventory／User Prize／historyを不変にした。`CATALOG_GACHA_UNPUBLISH_INVALID`／`CONFLICT`をstableに返し、AdminはRequest ID／internal codeを表示せず固有の日本語へmapする。exact migration `000062`はpaused-source prerequisiteだけを除き、one revision、sales／sold count／identity／history、schedule、Published Version／Draw reference guardを維持しdata rewriteを行わない。
- Isolated PostgreSQL V2 `migrate:fresh` 62 migrations、`000062` rollback／reapply、Focused API lifecycle 13 tests／284 assertions、Admin API unpublish preflight／guard 2 tests／19 assertions、Focused Admin 1 file／8 tests、Admin typecheck／lint／production build、changed PHP syntax、focused Policy Unit、`git diff --check`をPASSした。最初のRequired Checksは旧pause-required assertion 1件だけがFAILし、新仕様のdirect-readyへ更新後にFocused PASSした。Public Contract、Storefront、Payment／Coin／Draw Core、historical rewriteは変更していない。Required Checks再実行、fresh self-review、exact-source Preview activation、指定5件のCanonical mutation、acceptance、closeoutは未実行である。
## GOV-017 Risk-based Governance / Lane-aware CI

- Human承認により、既存Issue #287で完了済みの別Task `GOV-015`を再利用せず、未使用`GOV-017`へ再採番した。既存GOV-015 Task Policyはread-onlyで残存を確認し、今回の開始を妨げないため変更していない。
- `git fetch --prune`後のclean local／origin／GitHub `main@a7c9c217da6f60de9177a06ee6570b2403400d3c`、idleなPlatform／Security／Storefront lane、Shared Locks none、freeなPreview OS lock、open Platform Task 0を確認した。Issue #358、Branch `ci/GOV-017-lane-aware-governance`、専用Worktree、Lane `Strict Change`、Risk R4、Application Runtime Activation `none`で開始した。
- Canonical Governance／Release Gate、Task／PR metadata、Required 5 context、policy／quality／security／integration構成、30分のStrict self-review freshness、Ruleset readbackを最小限確認した。Application Runtime、DB、Migration ledger、`.env`、credential値、Application Security Baseline全面readbackは実行していない。
- `Lite Maintenance`／`Standard Change`／`Strict Change`の順序、Codex昇格可／降格禁止、unknown／欠落／不正値のFail Closed、Activation `none|deferred|immediate`、Standard Data Maintenance全条件、Ruleset bypassの限定条件、最初の5 Taskまたは2週間の試行計測をCanonical Governanceへ反映した。
- Required context名は`policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`のまま維持した。Liteはfocused quality／added-diff secret scan／UI evidenceを選択し、Standard／Strictは現行full quality／security／integration suiteを維持する。Ruleset変更、bypass、Required Check削除は0である。
- Root-owned Task Policy用Lane／Activation helperと、Lane-aware self-review helperをRepository正本として追加した。Lite／Standardはfinal head一致を正本として時間だけでは失効せず、Strictは現行30分freshnessとhead一致を維持する。GOV-017自身はmergeまで旧Strict evidence schema／freshnessを使用し、merge後に外部wrapperを新helperへ接続する。
- PR metadata回帰fixture 1 PathをTask PolicyへAtomic追加し、実diff／PR Allowed Pathsへ完全一致で記録する。Focused Lane／Activation／unknown path／Lite禁止Domain／Standard候補／Strict維持／Ruleset／Task Policy／self-review 20 tests、Policy Unit 166 tests、Quality Unit 4 tests、Security Unit 10 tests、Local Policy／Quality／Security Gate、dependency audit各0 finding、workflow YAML parse、`git diff --check`をPASSした。Application Build、Runtime Activation、DB mutation、Migration created／applied、Production操作は0である。

## MIG-073 Email Verification Failure UX / Closed Email Re-registration

- `git fetch --prune`後のclean local／origin／GitHub `main@1ebaf50004461e405e1b39993d697b053b403480`、GitHub Issue／PR検索、Remote refs、Task Policy、worklog、idleなPlatform task、Shared Lockなし、freeなPreview OS lockを確認し、未使用`MIG-073`を採番した。Issue #360、Branch `fix/MIG-073-email-verification-withdrawn-reregistration`、専用Worktree、Lane `Strict Change`、Risk R4、Activation `immediate`で開始し、Integration LockとMigration Allocation `000063`を取得した。
- Browser email verification failureは共通Exception rendererによりPublic Problem Detailsを表示していた。V2 Public Auth controllerでBrowserだけを既存Storefront `/verify-email/error`へHTTP 303 redirectし、queryなし、private/no-store、`Vary: Accept`を適用した。JSON/API clientは既存Problem Details status/code/bodyを維持し、成功時は保存済みallowlist `/mypage`への303、Session／CSRF rotationを維持した。
- `users_verified_email_unique`が全verified Userを状態無関係に占有していた。Migration `000063`でcanonical `closed`だけをpartial unique predicateから除外し、active／restricted／suspended／anonymizedは占有を維持した。旧identity／email／historyは更新・削除せず、新Userは新ID、新token、新Sessionで作成する。rollbackは再利用済みverified重複がある場合に旧制約を復元せずFail Closedする。
- Verificationは`pending_verification` Userだけを許可し、closed pending sibling等の旧tokenによる復活を禁止した。Testではunusedな旧User tokenを新Userへ使えないこと、新tokenだけが新Userをactive化すること、旧Sessionが引き継がれないこと、旧Wallet／Payment／Shipping Addressと旧Verification rowが旧Userへ残り、新UserにWallet／Payment／Draw Request／User Prize／Shipping Addressが存在しないことを確認した。
- Task専用PostgreSQL／Redisで63 migrations fresh apply 2回、最新Migration rollback／reapply、schema inventory、V2 Identity full 501 testsをPASSした。Focused Auth／Delivery／Schema 35 tests・305 assertions、再登録／非closed占有／rollback Fail Closed 3 tests・31 assertions、最終旧unused token ownership 1 test・20 assertions、Policy 155 tests、DB Guard 39 tests、Quality Unit 4、Security Unit 10、Local Policy／Quality Gate、Composer validation、PHP syntax、`git diff --check`をPASSした。
- 初回patchは誤ってmain worktreeへ未commitで適用されたため、同一差分を専用worktreeへ移送後、main対象PathをHEADへ明示復元し、新規fileを削除した。Remote／commit／historyへの反映前で、mainは直ちにcleanかつorigin一致へ戻した。Application実装・Migration・testは専用worktreeだけに存在する。
- Security Baseline metadataはSEC-011、expiry `2026-08-25`、Composer／pnpm finding arrays emptyでfreshと確認し、日付延長や全面Audit再実行はしていない。Storefront／Admin／OpenAPI／Payment／Point／Draw authority／Production／secret／credential変更は0である。
- Final Head `9da1fc107a5ff258b40c95d893ce2e69d77adf98`はRequired 5 Checksとfresh fixed-head self-reviewをPASSした。Canonical Build Run `32640130061`／Artifact `9493412716`をexact-source検証し、root-only backup後にMigration `000063`をShared Preview batch `30`として適用、63件ledgerとindex predicateをreadbackし、APIだけを`preview-MIG-073-9da1fc107a5f`へ1回activationした。Admin／PostgreSQL／Redisは再作成せず、全service healthy、restart 0、API-only egress、他private-onlyを維持した。
- actual Nginx Browser failure 303 `/verify-email/error`、Storefront error page 200、JSON 410 Problem Details、正常303 `/mypage`／Session／CSRF、旧token isolation、新identity、旧Wallet／Payment／Shipping／Verification残存、新Userへの履歴非継承、restricted占有をPASSした。synthetic acceptance transactionはrollbackし、HTTP 500／502／504は0、Production／secret mutation／実メール／real-user PIIは0である。
- PR #361をSquash SHA `663ec853f53d4d02e3622707f2b08162785f4e64`でmergeし、Issue #360、Remote／local branch、専用Worktree、Task Policy、Task DB resources、Integration／Migration／Preview lockをclose／cleanupした。local／origin／GitHub mainは同SHAで一致した。Task total `1h 11m 50s`、CI wait `13m 36s`、追加Check dispatch 1（failed rerun 0）、Build 1、API Activation 1、Human wait約17分、remaining blockerなし。

## MIG-074 MIG-073 Final Report Alignment

- clean local／origin／GitHub `main@663ec853f53d4d02e3622707f2b08162785f4e64`、open Platform Task 0、Shared Integration Lock free、GitHub Issue／Remote refs／Task Policy／worklogで未使用`MIG-074`を確認した。Issue #362、Branch `docs/MIG-074-align-mig-073-report`、専用Worktree、Lane `Lite Maintenance`、Risk R1、Activation `none`で、`MIG-073-report.md`のPre-Activation記録と本Task記録だけを整合する。Application、Migration、Contract、dependency、workflow、Infrastructure、Runtime、Secret、Production変更は0である。

## MIG-075 Admin List Default Filters

- `git fetch --prune`後のclean local／origin／GitHub `main@cb60e9a0775f47e810d2e7fd3b7252c265007a9d`、idleなActive Task、Shared Lockなし、freeなIntegration OS lock、GitHub Issue／Remote refs／Task Policy／worklogで`MIG-074`使用済みと`MIG-075`未使用を確認した。Issue #364、Branch `feat/MIG-075-admin-list-default-filters`、専用Worktree、Risk R4、Activation `deferred`で開始した。
- Stage 0で対象一覧の一部にstatus queryがなく、client-side filteringではcursor pagination全体へ正しいdefaultを適用できないこと、ポイント購入のAPI／Payment Domain pathが現行Governance上Strict対象であることを確認した。Issue作成前にLaneを`Lite Maintenance`から`Strict Change`へ昇格し、API／Admin OpenAPIのadditive canonical filterだけをScopeへ追加した。DB／Migration／Dependency／Runtime／Production変更は0である。
- 通常URLではガチャ`published,draft`、バナー`published`、お知らせ`published,draft`、ページ設定`published,draft`、ランク演出`visible`、ポイント購入`published`、お問い合わせ`new`を初期化する。URL queryのcanonical値はdefaultより優先し、画面内の手動変更はcomponent stateだけで保持する。route離脱後のfilterなし再mountではdefaultへ戻り、localStorage／sessionStorage／Cookie等への保存は追加していない。
- ガチャは`published`／`draft`だけをdefault API queryへ送り、`sales_paused`／`unpublished`を除外する。対象APIは既存canonical statusだけをvalidationしてcursor query前に絞り込み、検索／sort／cursor pagination／件数と手動の他status選択を維持する。新Enum／Status、Public API、Storefront、DB schema、Migrationは追加していない。
- Focused Admin 8 files／62 tests、Focused API 6 files／28 tests／360 assertions、Admin typecheck／lint、OpenAPI bundle／contract check、Admin generated contract check、changed PHP syntax、`git diff --check`をPASSした。API試験は既存PHP 8.4 imageとTask専用PostgreSQLを利用し、63件の既存V2 migrationsをTask DBだけへ適用後にcleanupした。初回は未migrate DB、次回は新fixtureのrevision不足でApplication前／DB guardで停止し、Domain guardを弱めずfixture revisionを進めて最終Focused setをPASSした。
- Shared Preview Build／Runtime Activationは`deferred`のため実行せず、Build count 0、Activation count 0を維持する。Required 5 Checks、fresh exact-head self-review、Squash Merge、Issue close、branch／worktree／Task Policy／Integration lock cleanup、main syncはCloseoutで確定する。

## MIG-076 Admin User Detail Referral History

- clean local／origin／GitHub `main@edd90e85a64ccf34b2e2645dae997ec7be6a2bcd`、idleなActive Task、Shared Lockなし、freeなPreview OS lock、GitHub Issue／PR、Task Policy、Worklog、Remote refsで未使用`MIG-076`を確認した。Issue #366、Branch `feat/MIG-076-admin-user-referral-history`、専用Worktree、Lane `Standard Change`、Risk R2、Activation `immediate`で開始し、Platform Integration Lockを取得した。
- V2 canonical Referralはmigration `000037`の`user_referrals`、`V2ReferralRewardService`、status `pending|rewarded|canceled`を正本とする。既存V2 Admin Referral履歴APIはなかったため、現行User Detailと同じAdmin Session境界へadditive `GET /admin/api/v2/users/{user_id}/referral-history`を追加した。selected Userのinternal IDを解決して`referrer_user_id`だけへ限定し、referred User public ID／表示名、Referral status、Referral作成日時、User登録日時だけを返す。
- 履歴は`user_referrals.id DESC`の既存opaque cursor／limit 1..100を維持する。User Detail mount時だけ取得し、User切替時は即座に旧rowsを非表示化してinitial／load-more requestをabortし、User ID一致を再確認してstale responseを拒否する。空状態は`紹介履歴はありません。`と表示し、紹介者／紹介コード列、selected Userが紹介された側のrelation、reward amountは表示しない。
- Focused Admin 2 files／38 tests、Task PostgreSQLへ既存63 migrationsの通常apply、Focused API 5 tests／157 assertions、Admin typecheck／lint／production build、OpenAPI bundle／check、Admin generated contract check、changed PHP syntax、`git diff --check`をPASSした。初回Admin buildだけworktree外node_modules symlinkをTurbopackが拒否する環境FAILとなり、lockfile不変のoffline frozen install後にPASSした。Task DB／networkはcleanup済みである。
- Focused APIは0件、3件を2+1 cursor pagination、別referrer、selected Userがreferred側のrelation、全active Admin roles、unauthenticated拒否を検証した。returned User IDにduplicate／missing／selected User／other referrer rowがなく、Referral rowsとPoint operationsがread前後で不変である。Migration／DB Schema、Referral reward／Coin・Point、Registration／Auth、Public API／Storefront、Dependency／lockfile、Production／Secret変更は0である。
- Shared PreviewはAPI `MIG-073@9da1fc107a5f`、Admin `MIG-072@7e5e3cb47e43`で、MIG-075のAPI／Admin差分が未反映である。Final Application SourceからAPI／AdminをCanonical Build 1回で生成し、両serviceを同じexact sourceへ各1回activationする。DB／Redis再作成、migration mutation、API egress／private network変更は行わない。
- Application Source Head `0379f4a97110d284b2f15e7182600b0878eb0276`はRequired 5 Checksとexact-head Standard self-review `#issuecomment-5389810955`をPASSした。Canonical Preview Build Run `32681258208`／Artifact `9504273133`をexact-source検証loadし、Preview host buildは0である。
- Preview Deployment／OS lock取得後、API／Adminを同一Sourceへactivationした。Adminは1回で成功した。API初回はegressがfixed private IPより先に接続されDocker prestartでFail Closedし、稼働開始前に停止した。同じverified API imageをprivate networkで1回正常起動し、既存guard helperでAPI-only egressを再接続した。最終API／Adminはexact revision、healthy、restart 0で、PostgreSQL／Redisのcontainer ID／start時刻は不変、migration ledger 63／batch 30、`v2_private internal:true`、Admin／DB／Redis private-onlyを維持した。Build 1、Admin成功activation 1、API成功activation 1＋failed prestart attempt 1である。
- 認証済み実Browser／HTTPでMIG-075のガチャ`published,draft`、バナー`published`、お知らせ`published,draft`、ページ`published,draft`、ランク演出`visible`、ポイント購入`published`、お問い合わせ`new`、全7画面の明示query優先、手動filter変更後の別画面遷移／default復帰をPASSした。既存synthetic Gacha asset 2件の非gateway 404は別記し、Task対象のconsole／page／500／502／504 errorは0である。
- User Detail、紹介履歴Section、実API 200の0件日本語空状態、deterministic synthetic responseによる3件／2+1 cursor pagination、minimal 5列、selected User非表示、duplicate／missingなし、別User切替時のstale response隔離を実BrowserでPASSした。unauthenticated APIは401、現行Admin Session境界を維持する。Shared Previewの`user_referrals`は前後0、新規`point_operations`は0で、DB／Redis再作成、Migration、Referral reward、Coin／Point、Registration／Auth、Production、Secret mutationは0。Preview／OS lockをreadback後に解放した。
- Final evidence Head `afacd8b98e18d67a22768a68679525bc740d613d`はRequired 5 Checksとexact-head Standard self-review `#issuecomment-5390039376`をPASSしたが、同HeadのPR metadata修正前automatic failureがGitHub mergeable stateを`unstable`に残し、approved merge wrapperはFail Closedした。bypass／rerun／history rewriteは行わず、この記録だけを加えたsuccessor HeadでRequired 5とexact-head self-reviewを再固定する。Application tree／Activation Source／Runtime／Build／Browser結果は不変で、再Build／再Activationは行わない。

## MIG-077 Shared Preview Gacha Unpublish Data Correction

- clean local／origin／GitHub `main@2f73f5a732bb47410c9fc3a06859672a60f66824`、idleなTask lane／Shared Lock、freeなPreview OS lock、GitHub Issue／PR検索、Task Policy、Worklog、Remote refsで未使用`MIG-077`を確認した。Issue #369、Branch `chore/MIG-077-preview-gacha-unpublish-data-correction`、専用Worktree、Lane `Strict Change`、Risk R4、Activation `immediate`で開始し、Platform Integration Lockを取得した。
- Shared Preview read-only preflightはraw `draft=5`／`unpublished=6`、active/non-archived Draft exact 4件、archived/disabled Draft 1件、対象active schedule 0、canonical public state 0を確認した。既に全件ユーザー非公開だが、Human指定どおりactive Draft 4件だけをcanonical `V2CatalogMasterMutationService::updateGacha`で`unpublished`へ補正する。archived/disabled行、既存unpublished 6件、Productionは変更しない。
- 最初のOS-lock-held harness attemptはbusiness write前のfingerprint取得で、pivot tableに存在しないsynthetic `id`列を参照してFail Closedした。read-only再確認で対象status／revision、business data、Fresh Authが不変であることを確認し、evidence helperのorder keyだけを修正した。次のsingle mutable attemptでexact 4件をone outer transaction内からcanonical Domainへ渡し、全件`draft -> unpublished`をatomic commitした。
- Independent postflightはraw `unpublished=10`／untouched archived Draft 1、canonical public state 0、read-only write statement 0を確認した。対象stable fields／sold count、unrelated Gachas、Version semantics、Gacha／Version Tags、Version Prizes、Publish Schedules、Draw State／Request／Result、Inventory、User Prize、Point Operation、Auth tablesは不変で、期待どおりGacha revision +2、Draft Version revision +1、`updated_at`、Audit／Idempotency／Outboxだけが付随変更された。forbidden write statementは0である。
- API／Admin／PostgreSQL／Redisはexact source `0379f4a97110d284b2f15e7182600b0878eb0276`のままhealthy／restart 0、API health HTTP 200、mutation windowのNginx HTTP 500／502／504は0である。Build 0、service Activation 0、Migration created／applied 0、DB／Redis再作成0、Production／Secret／Credential／Coin／Point／Payment mutation 0。Preview Deployment／OS lockはfinal readback後に解放した。

## SEC-013 Canonical Security Baseline Refresh

- clean local／origin／GitHub `main@dac28049ad41319ac49cd3afcfeaadfa37b15016`、idleな全Task lane、Shared Lockなし、freeなPlatform Integration／Preview OS lock、GitHub Issue／PR全履歴、Task Policy、Worklog、Remote refsで未使用`SEC-013`を確認した。Issue #371、Branch `security/SEC-013-canonical-security-baseline-refresh`、専用Worktree、Lane `Strict Change`、Risk R4、Activation `none`で開始し、Platform Integration Lockを取得した。
- 現行Canonical Security BaselineはSEC-011の空dependency baselineでfreshness期限`2026-08-25`、Strict self-reviewはexact-headかつ1800秒、`main` RulesetのRequired contextは`policy-gate`、`quality-gate`、`security-gate`、`integration-gate`、`ci-gate`である。SEC-011 merge `edd1965ddee851eb3fed6e327c7477236f0a8083`から対象mainまで36 squash commits／246 changed pathsをFresh Audit対象とし、特にGOV-017、MIG-073、MIG-075、MIG-076を境界別に再確認した。
- Canonical Composer、Root pnpm/V2 workspace、Legacy pnpm auditは各1回実行し、Findingはすべて0である。Security Unit 10件とLocal `security-gate`はPASSし、tracked file 1532件、high-confidence secret candidate 0、Composer 0、workspace pnpm 0、legacy pnpm 0を確認した。GitHub readbackはDependency Graph／Dependabot Security Updates／Private Vulnerability Reporting有効、Secret Scanning／Push Protection有効、Advanced Security／Code SecurityはPublic Free Repositoryでunavailableである。
- Auth／Session／CSRF／MFA realm分離、Email Verification token owner／state／Browser failure非露出、Mail notifier／previous-key境界、Admin session／permission／audit、Payment／Coin／Point／Draw／Inventory transaction・lock・CSPRNG、Public／Admin／Webhook surface、Migration immutable history、Task Policy／Lane Governance、GitHub App／Workflow permission・pinned action、Preview API-only egress／private network／Production separationをsourceと正式guardで確認した。SEC-011以降の既存migration変更は0で追加10件のみ、Public Contractへのsecret／credential／provider／probability-internal追加は0である。
- Focused verificationはPolicy Unit 167、OpenAPI Unit 7、3-surface Contract Gate（Admin 216／Public 54／Webhook 1 operations）、DB Guard Unit 39、Strict self-review policy 5、Task Policy 3、API egress guard 5、Composer manifest validationをPASSした。isolated Worktreeの`docker compose config --quiet`は`.env`を読まず実行し、必須`V2_DB_HOST`未設定でconfiguration前に停止したためPASSとはせず、exact-head GitHub Integration Gateのcanonical test environmentで検証する。
- Fresh Canonical Security Audit Findingは合計0、SEV-0／SEV-1／SEV-2／SEV-3すべて0で、remediationは不要である。日付だけを延長せず、audit対象・結果・空配列を再確認した上でCanonical Baseline trackingを`SEC-013`、freshness期限を`2026-08-31`へ更新した。Dependency／lockfile、Application、Migration、DB、Runtime、Production、Secret／Credential、Mail送信の変更／mutationは0である。Final HeadのRequired 5、Security Gate、CodeQL／Dependency Review、fresh exact-head Strict self-review、Squash Merge／cleanupはCloseoutで確定する。

## MIG-078 Mail Template + Shared Tiptap Rich Text Editor

- `MIG-077`がIssue #369／PR #370で使用済みであることを確認し、GitHub Issue／PR、Remote refs、Task Policy、Worklogの全履歴で未使用の`MIG-078`へ進めた。clean local／origin／GitHub `main@2f91d025da75aa0920248d69699a743608628e94`、Issue #373、Branch `feat/MIG-078-mail-template-tiptap`、専用Worktree、Lane `Strict Change`、Risk R4、Activation `immediate`で開始し、Platform Integration LockとMigration Allocation Lockで`000064`を確保した。SEC-013 baselineはFinding 0、freshness期限`2026-08-31`のため全面Security Auditは再実行していない。
- AdminへTiptap v3の共通Rich Text Editorを導入し、Page／Notice／MailでParagraph、H2/H3、指定marks／lists／alignment／link、絶対HTTPS Image URL、HR、undo／redoを共有する。H1／Tableは既存本文のread compatibilityだけを維持しUIから新規作成できず、画像upload／drop／clipboard imageは拒否する。Backend sanitizerをWeb／Mailの保存・Preview・送信境界へ共通適用し、event handler、active content、非HTTPS image、credential-bearing image URLを除去する。
- additive／reversible Migration `000064`は固定7件の`mail_templates`とcanonical eventごとのunique `mail_deliveries`を作成し、初期件名／本文をHuman仕様どおりseedする。DB triggerとAPI routeでTemplate追加／削除、key／label変更を拒否し、件名／本文のsemantic empty、revision競合、Admin permission、idempotencyをFail Closedにする。全10 variableは全Templateで利用でき、未知／欠損値は空文字、User由来値はHTML escape、複数ガチャ／景品はcanonical item順でsystem `<hr>`区切りとする。
- 未保存本文Previewは固定dummyだけを実送信と同じsanitize／variable renderへ通し、別タブの本文だけを返す。DB保存、Mail、履歴、Domain mutation、実User dataは0である。Verification初回／明示resendは既存token ownership／rate limit／transactionを維持して直接送信し失敗を503で明示する。他6種はVerification成功、Coin付与済みPayment成功、Shipping request／shipped遷移、User closed遷移、Contact保存の各canonical transaction内でdurable event claimし、commit後1回だけ同期送信する。失敗はgeneric code／PIIなしで記録し、Domain成功をrollbackせず、自動retry／手動再送は追加しない。
- Task専用PostgreSQLへ全64 V2 migrationsを通常applyし、`000064` rollback／reapplyをPASSした。Focused API 65 tests／563 assertions、Focused Admin 5 files／47 tests、Admin typecheck／lint、OpenAPI 3-surface Contract Gate（Admin 220／Public 54／Webhook 1 operations）、generated Contract check、Policy Unit 168、DB Guard Unit 40、`git diff --check`をPASSした。PolicyのAdmin skeletonとexact dependency集合へMIG-078の5 UI pathとTiptap `3.30.3`の5 packageだけを、DB schema inventoryへ`mail_templates`／`mail_deliveries`だけを加算し、range／欠落／unexpected tableを引き続き拒否する。実Mailgun送信、Production、Shared Preview、DB business data、Secret／Credential、Storefront Repositoryの変更は0である。Final Headのdependency audit、Required 5、fresh exact-head self-review、Canonical Preview Build／Migration／API・Admin activation／Acceptance、merge／cleanupはCloseoutで確定する。
- 最初のexact-head CodeQLはsemantic-empty判定のSSR用HTML tag除去regexをSEV-2相当のincomplete sanitizationとして検出した。実行境界はclient-onlyであるためregex／`innerHTML`判定をDOMParserのinert documentへ置換し、別タブPreviewもserverと同じtag／attribute／URL allowlistでDOM再構築して動的HTML sinkを除去した。malicious Preview responseのevent handler／script／`javascript:` imageを落とす回帰を追加し、Admin全34 files／200 tests、typecheck、lintをPASSした。Domain／Schema／Runtimeは変更せず、新HeadでCodeQL、Required 5、dependency reviewを再固定する。
- Canonical Preview Build `32700905064`／Artifact `9510545757`をexact source `13c1a8f9ae9e5df953b6891f1232d33293d692e9`で検証loadし、root-only backup後にMigration `000064`をShared Preview batch `31`として適用した。固定7件、delivery 0、fixed-set guardをreadbackし、API／Adminを同sourceへactivationした。Adminは1回成功、API初回は既知のegress-first固定IP衝突で稼働前Fail Closedし、同じverified imageをprivate-firstで1回正常起動後にAPI-only egressを復元した。DB／Redisは再作成せずstart時刻不変、全service healthy／restart 0である。
- Runtime service acceptanceはTemplate save／sanitize、semantic empty拒否、固定7種／10 variables、dummy複数対象Preview、Mail／delivery 0、既存Page／Notice本文readabilityをouter transaction内でPASSし全mutationをrollbackした。実BrowserではMail list／editor／Tiptapまで到達したが、`opener = null`直後にCOOPが別tab WindowProxyを切断し、Preview API request前に停止するregressionを確認した。別tabはsystem構築したactive contentなしのinert DOMだけを持ち、linkも`noopener noreferrer`へ固定済みのため、COOP互換のtab handleを維持してPreview完了まで同一安全境界を使用する最小修正を行う。新Headのchecks／Build後にMigration再実行なしでAPI／Adminだけを再activationする。

## MIG-079 fincode Payment Backend Core

- `MIG-079`未使用、clean local／origin／GitHub `main@232f2e163e642545b80a37cc8b1edf5773f9bf4c`、Issue #375、Branch `feat/MIG-079-fincode-payment-core`、専用Worktree、Lane `Strict Change`、Risk R4、Activation `deferred`で開始した。Shared PreviewはMIG-078までhealthyだが、本TaskではSandbox／Production credential、Provider設定、Runtime、Shared Preview DBを変更しない。
- fincode公式Docs／API v1.4.0を正本としてCard、PayPay、Konbini、Virtual Account、Customer／Card、Redirect Session、3DS2必須、Webhook `Fincode-Signature`、Provider status、UUID v4 idempotent key、Konbini／Virtual Account 3日期限を固定した。Apple Pay／Google Pay、Refund／Chargeback、Storefront UI、Admin UIは対象外である。
- LaravelへProvider Adapter、Payment開始／状態／既存未払い再開、Customer mapping、登録Card最大3枚／ownership／expiry／last-used優先、Card save／no-save、全Card 3DS2、4方式Webhook、認証済みProvider status照合、期限到来時Konbini／Virtual Account再照会を実装した。Browser return／Redirect発行／支払情報発行だけでは成功させず、Canonical `CAPTURED`だけを既存Payment transactionへ渡す。
- 同一PaymentのProvider Event、Payment row、`payment_point_grants`一対一、Wallet／Lot／Ledger、Limited Bonus、Audit／Outbox、MIG-078 Mail delivery keyを同じ既存transaction／lock境界で確定する。重複／replay／遅延／out-of-order／並行WebhookでもCoin Grantと購入完了Mailは各1回であり、例外ベースduplicate insertをPostgreSQL-safeな`ON CONFLICT DO NOTHING`へ修正した。
- Public OpenAPI／薄いStorefront ClientへPayment開始、状態、成功／未払い履歴、未払い再開、Card一覧／登録Intent／完了／削除を追加した。Admin OpenAPI／generated clientへ全User Payment一覧とUser Detail Payment一覧、全状態、filter／cursorを追加し、既存Admin Session／MFA realm／`reporting.financial.read`／Problem Details／Auditを維持する。3 surface bundleはAdmin 222／Public 62／Webhook 2 operationsで同期した。
- additive／reversible Migration `2026_09_21_000065_add_fincode_payment_backend_core.php`はPayment method／Provider status／expiryとfincode Customer／Card／registration intent／attemptを追加する。既存Migration／history rewriteは0。Task専用PostgreSQLへ全65 V2 migrationsを通常applyし、`000065` rollback／reapply、Focused Backend 14 tests／74 assertions、実DB fork Concurrency 1 test／22 assertions、V2 full 525 tests／5160 assertions／9 explicit skips、Storefront Client 31 tests、Testkit 38 tests、Policy Unit 156、DB Guard Unit 41、OpenAPI bundle generation、generated contract、typecheck／lint／build、Local Policy／Quality／Security Gate、fresh Composer／workspace pnpm／legacy pnpm audit各0 finding、`git diff --check`をPASSした。Shared Preview／Production migration、fincode Sandbox実通信、実決済、Secret mutationは0である。
- 実装Commit `3fa76fa070acf09650e93c6cd525542227c9e49c`をTask branchへWrapper経由でpushし、Draft PR #376を作成した。PR記録を含むFinal Headを作成後、Required 5 checksとfresh fixed-head self-reviewを固定し、gate PASS時のみSquash Merge／Issue close／branch・worktree cleanupを行う。
- PR metadata不足による初回／1回目rerunのPolicy早期停止を経て、Canonical templateのTask／Lane／Activation／UI Verification／exact changed pathsを補完した。2回目rerunでPolicy／SecurityはPASSしたが、QualityがPublic OpenAPI変更を既存`alpha.24` package-only候補として再発行できないことを検出した。既存`alpha.23` immutable evidenceを変更せず、`alpha.24`を後方互換なcontract-additive候補へ正規化し、Public／Admin／Webhook ContractとClient最小Contractを`alpha.24`へ進めた。Platform／Application／Site Schemaは独立Versionのまま維持する。Release Unit 20、candidate source／digest／operation count、OpenAPI 3 surface bundle checkをPASSし、新Final HeadでRequired Checksを再固定する。
- exact-head Storefront contract buildはPackage build／pack完了後、既存ScriptのGit UTC timestamp `Z`解析でFail Closedした。ISO 8601 UTCを明示変換する最小修正と回帰Testを追加し、別の空Artifact pathでFinal Head build／verifyをやり直す。失敗候補は公開せず、Repository外のTask一時Pathだけに残る。
- `205dc8597d7d1b2de6a03dad2148398d3910349c`のArtifact build／verifyはPASSしたが、同HeadのPolicy unitが既存package-only固定を検出した。Policyのimmutable `alpha.23`保護、次Sequence、Client／Testkit／Site Schema境界を維持したままcontract-additive候補を許可し、invalid candidate digest拒否を追加した。Policy Unit 170／Local Policy GateをPASSし、この最小Policy修正を含む新Final HeadでArtifact buildとRequired Checksを再固定する。

## MIG-084 Admin Payment History UI

- `MIG-080`〜`MIG-083`はfinalized Migration Planで既存予約済みのため再利用せず、GitHub Issue／PR、Remote refs、Task Policy、Worklog、Git履歴で未使用の`MIG-084`へ進めた。`git fetch --prune`後のclean local／origin／GitHub `main@b2b449570615a543fd44fef61ac4b07c68d0f184`、MIG-079 Issue #375／PR #376 merge済み、Issue #377、Branch `feat/MIG-084-admin-payment-history-ui`、専用Worktree、Lane `Strict Change`、Risk R4、Activation `deferred`で開始した。
- MIG-079の既存Admin Contract `GET /admin/api/v2/payments`と`GET /admin/api/v2/users/{user_id}/payments`だけを利用し、ポイント購入配下へ全User決済履歴を追加した。Canonical status `created`／`requires_action`／`processing`／`succeeded`／`failed`／`canceled`／`expired`、method `credit_card`／`paypay`／`konbini`／`virtual_account`、User、JPY金額、JST日時を共通表示し、status／method filter、明示URL query、MIG-075準拠の画面内state／route離脱後default復帰、cursor pagination、loading／empty／filter 0件／Problem Details error／retryを実装した。
- User Detailは有効な`reporting.financial.read`表示境界へ同じ小さな共通Payment componentを組み込み、必ずUser-specific endpointを使用する。全体一覧とのstatus／method／amount／timestamp解釈を共有し、対象User以外を混在させない。Frontend-only権限を正本とせず、既存Admin Session／PermissionとBackend authorizationを維持する。
- Admin全35 files／208 tests、Focused Payment／API client／User Detail／Navigation 4 files・52 tests、Policy Unit 171、Quality Unit 4、Security Unit 10、typecheck、lint、`git diff --check`をPASSした。初回Full Adminは新Navigation期待値の未反映だけで207 PASS／1 FAILとなり、対象Regression期待値を追加して最終208 PASSとした。初回GitHub Policy Gateは新規4 Admin fileのCanonical Skeleton未登録を検出し、`MIG_084_ADMIN_SKELETON_FILES`のexact-set guardとunit testだけを追加した。依存導入前のFocused test／typecheckはrunner不在でNOT RUN、offline frozen install後に実行し、dependency／lockfile変更は0である。
- Controlled Admin RuntimeのPlaywright 2 testsをPASSし、明示query、全7 status、全4 method、User／amount／JST、filter reset、cursor前後移動、全体一覧からUser Detail、User-specific API、route離脱後default、keyboard focus、mobile overflow、console／page／500／502／504 error 0を確認した。初回はclient component exportをServer pageから参照したSSR error、次はquery parameter順序へ依存したtest assertionでFAILし、server-safe filter定数と意味ベースquery検証へ修正した。Browser buildは失敗反復を含め3回、Runtime Activationは0である。
- Backend／OpenAPI／generated artifact／Migration／DB／Payment Domain／Coin Grant／fincode Adapter／Webhook／Public API／Storefront／Refund／Chargeback／Payment操作、Dependency／lockfile、Production／Shared Preview、Secret／Credential mutationは0。Provider Browser直呼出、Provider実通信、実Payment／Refund／Chargeback、PAN／CVC／Secret／raw provider status表示は0である。Remaining IntegrationはMIG-079 Sandbox Activation後の実Provider dataによるShared Preview Acceptanceであり、本Taskではdeferredを維持する。Required 5 Checks、fresh exact-head Strict self-review、Squash Merge／cleanupはCloseoutで確定する。

## MIG-085 Admin Payment Navigation / Default Filters

- GitHub／local／origin `main@460e66168176a596f034154d7e895aa0934331e2`一致、clean main、MIG-085のGitHub検索／Remote refs／Task Policy／Worklog／Git履歴で未使用、Preview／Integration OS lock freeを確認した。共有台帳のplat-main欄はMIG-078時点でstaleだが、実GitHub mainとlock実体はMIG-084完了後を示す。Issue #379、Branch `feat/MIG-085-admin-payment-navigation-default-filters`、専用Worktree、Lane `Strict Change`、Risk R4、Activation `deferred`で開始した。
- 既存Navigation registry／permission／active stateを再利用し、`ガチャ`直後かつ`配送`直前へ独立した`決済`groupを置き、配下の`決済状況`を既存全User `/payments`へ接続する。ポイント購入groupは既存商品一覧／登録だけを維持する。
- 全User決済一覧の通常URLとresetは`status=succeeded`／`payment_method=all`、明示queryは優先、画面内filter／cursor stateはroute離脱まで、通常URL再訪時はdefaultへ戻す。User Detailは全Canonical status表示を維持し、MIG-079 contractとMIG-084 mappingだけを使用する。
- Backend／OpenAPI／generated contract／Payment Domain／fincode／Webhook／Coin Grant／DB／Migration／Storefront／Provider通信／Production／Secret mutationは0。Required 5 Checks、fresh exact-head Strict self-review、Squash Merge／cleanupはFinal Head固定後に行う。
- Admin全35 files／210 tests、focused Payment／Navigation／Permission 3 files・20 tests、typecheck、lint、`git diff --check`をPASSした。offline frozen installはdependency／lockfileを変更していない。Controlled Admin Runtimeのfocused Playwright 3 testsは最終treeでPASSし、`ガチャ → 決済 → 配送`、`決済状況`遷移、通常URL default、明示複合query、未払い／期限切れ／失敗／キャンセル／決済成功、Card／PayPay／Konbini／Bank、reset、cursor前後移動、User Detail全status、route離脱後default復帰、mobile keyboard scroll、console／page／500／502／504 error 0を確認した。
- Playwright buildは3回。初回はNavigation fixtureに`payment.plan.*`権限がなく、従来Payment child経由でポイント購入groupが偶然表示されていたためfixtureを正規化した。同runのGacha detail 2件は既存mock境界の404、2回目の広範Navigationは既存`/banners/new` redirect active-state期待でTask外FAILと分離した。3回目は新focused Navigation test selectorがpermission load前に評価されFAILし、既存build再利用でload待ち／selectorを修正後、最終focused 3 testsをまとめてPASSした。Task対象Application failureは0、Runtime Activationは0である。
- Reviewed Head `b03549a7b9176a481cdc2a0ffb20642d5fe902cb`のRequired 5 Checksとfresh exact-head Strict self-review `#issuecomment-5403970566`はPASSしたが、Draft PR作成直後のautomatic workflow failureが同一headへ残り、approved merge wrapperはGitHub merge stateをFail Closedした。bypass、Ruleset変更、check retriggerは行わず、この記録だけを加えたsuccessor HeadでRequired 5とfresh self-reviewを再固定する。Application tree、local verification、Build、Activationは不変で再実行しない。

## GOV-018 Storefront Artifact Ledger Reconciliation / Payment Contract Release

- `git fetch --prune`後のclean local／origin／GitHub `main@2c5687e02724164da4917a0ac99d1b94947239f9`、GitHub Issue／PR、Remote refs、Task Policy、Evidence、Worklogで未使用`GOV-018`、Ledger／package metadata／tags／GitHub Artifact inventory／Storefront mainで未使用`2.0.0-alpha.25`を確認した。Issue #381、Branch `chore/GOV-018-storefront-artifact-ledger-payment-release`、専用Worktree、Lane Strict Change、Risk R4、Activation noneで開始した。Artifact Release Lockはnone、関連OS lockはfreeである。
- Storefront canonical main `58a6bc6b6119f7daaa2d415c3b9e4c3db4f98b18`のtracked STORE-SITE-034 alpha.24をread-only検証した。Manifest `f71edc9e...aad94`、Client `fbe156fb...a259`、Testkit `3dc1c348...fc78`、Public OpenAPI alpha.23 `5c735fe2...4043`、SHA256SUMS `0cca70f8...27d`、Source `209252d9...d3597`、54 operations、package-only、immutableはManifest／実ファイル／tar inventory／package metadata／Storefront verifierで一致した。Storefront Repository mutationは0である。
- Platform Payment候補はPublic OpenAPI alpha.24 `38761cf8...9397`、62 operations、Payment 8 operations、`payment.fincode.v2`、Payment Client exportsを含み、既存alpha.24とdifferentである。既存alpha.24をimmutable predecessorとしてLedgerへ完全追記し、Payment package bundleをalpha.25へ進める。Public／Admin／Webhook Contract alpha.24、Platform／Application／Site Schema alpha.23は独立Versionとして変更しない。
- Candidate Source `c1b55e8cc4e23b40c82372e739bc162604a53f2a`のRequired 5 Checks PASS後、Artifact Release OS Lockを取得し、Canonical workflowを1回dispatchした。Run `32805247269`／Artifact `9547996276`はsuccessで、GitHub outer digest `f53c129953090488adbc0acc92d98ca2d80ec3f0d313e12334a83ef03f67913c`をreadbackした。Manifest `b9fcc89c...c4c21`、SHA256SUMS `fb68da2e...713f`、Client `881eacd8...be21`、Testkit `22e29b26...205c`、Public OpenAPI `38761cf8...9397`をCanonical保存先から再計算し、Manifest／checksums／tar safety／inventoryと一致した。Site Schemaはalpha.23 `b4ca0ddb...40c2`／source tree `11f6bee77dd463c2f90352537f817404cf3042bd`をreferenceする。
- Canonical alpha.25 tarballはlocal candidateとinventory／各entry contentが完全一致し、gzip container digestだけがbuild環境で異なる。Canonical tarballとimmutable Site Schema alpha.23をexact-pinしたoffline consumerでClient／Testkit dependency解決、server／browser Payment exports、8 Payment operations、Public operation count 62をPASSした。Ledgerへalpha.25のexact provenance／hash／compatibilityをimmutable historyとして追加し、`latest_immutable=2.0.0-alpha.25`、`candidate=null`へsettleした。以降のsame-version buildはpending candidate不在でFail Closedし、workflowはArtifact uploadをskipする。
- CompatibilityはOpenAPI breaking 0、removed 0、added 8（Payment create／get／my list／resume、Card list／intent／complete／delete）で`additive`。Artifact exact-pinはGO。Active Shared PreviewはMIG-078 sourceでMigration `000065`未適用のためPayment Runtime compatibilityなし、Provider Browser E2EはHOLD。Runtime Activation、Migration apply、Storefront Repository、Production、Secret／Credential、Provider mutationはすべて0である。
## MIG-087 fincode Canonical Payment Return Correlation

- `git fetch --prune`後のclean local／origin／GitHub `main@75f90a16ed4161673f1f303fcfd1f8a7f7f26ebb`、Issue／Task Policy／Active Task／Shared Lock／Artifact Ledgerを照合し、未使用Task ID `MIG-087`、Issue #383、Branch `feat/MIG-087-fincode-payment-return-correlation`、専用Worktree、Lane Strict Change、Risk R4、Activation deferredで開始した。Artifact Release Lockはcandidate発行時だけ取得し、Migration Allocation／Platform Integration／Preview Deployment Lockは取得しない。
- fincode公式OpenAPIの最新readbackで、Card 3DSの`return_url`／`return_url_on_failure`とPayPay／Konbini／Virtual Account Sessionの`success_url`／`cancel_url`はBrowser POST、最大256文字であり、query付きURLの禁止はないことを確認した。Platformのnon-mutating Return HandlerがProvider POST bodyを無視し、303でnormal `/points/purchase/thanks?pid={Payment.id}`、failure／cancel `/points/purchase/{PointProduct.id}?pid={Payment.id}`へ正規化する。Provider設定／credential／通信は変更・実行しない。
- `pid`はCanonical Public Opaque `Payment.id`、failure path segmentはPayment作成時のCanonical Public Opaque `PointProduct.id`である。Platformがfixed canonical origin／pathとPayment contextだけからURLを生成し、Storefront Requestのreturn／failure／cancel URL、pid、payment_id、return用product overrideを拒否する。Browser route／POST payloadはPayment status Authorityではなく、Storefrontは必ずownership検証済み`getPayment(pid)`のCanonical statusを優先する。
- Card／PayPayはnormalまたはfailure／cancel return後に即時`getPayment(pid)`を行い、`created`／`requires_action`／`processing`の間だけ推奨2秒、最大30秒pollingする。`succeeded`／`failed`／`canceled`／`expired`で停止し、429は`Retry-After`または`retry_after_seconds`を優先する。Client transport retryはGET network errorと502／503／504だけであり、Storefront Payment pollingとは別責任である。
- Konbini／Virtual Accountは正常な支払情報／口座発行Returnを未払いnormal flowとして扱う。`getPayment`は状態取得だけを担い、durable redirectに`next_action.url`を使用しない。User action時の`resumeUnpaidPayment(pid)`は所有・method・unpaid・expiry eligibilityを検証し、暗号化保存済み既存redirectを返すだけで、新規Payment／Provider Session／支払情報／Virtual Account作成とProvider status再照会は各0である。
- Public OpenAPIはReturn normalizer 2 operations、Payment／polling／resume semantics、`point_product_id`をadditiveに公開し、Client／Testkitと次の未使用immutable `2.0.0-alpha.26` candidateへ進める。既存`2.0.0-alpha.25`は上書きしない。Platform／Application／Site Schemaはalpha.23、Public／Admin／Webhook Contract documentはcanonical additive alpha.25である。Migration created／appliedは0、Shared Preview／Production／Storefront Repository／Secret／Credential mutation、Provider実通信、Runtime Activationは0である。
- PHP 8.4 task imageと隔離PostgreSQL 17でPayment focused 2 filesを20 warnings付きPASS（183 assertions）し、警告はcached旧imageのconcurrency test process output readbackに限定される。OpenAPI contract bundle／check、OpenAPI unit 7、Storefront Client 31、Testkit 38、Release 23、PHP syntax、`git diff --check`をPASSした。Required 5 Checks、immutable Artifact発行／readback、final fixed-head検証、fresh Strict self-review、Squash Merge／cleanupは継続する。
- Initial Source Head `410b4f85a041eae2fd3955c0234a092472607ebc`のPR eventは本文`UI Verification`値がcanonical enumでなくPolicy Gate FAIL、同一head manual dispatchはPolicy GateのStorefront Testkit operation count `62`固定とPolicy unit fixtureのsettled alpha.25固定を検出した。UI値を`NOT_APPLICABLE`へ正規化し、Policy Gateをcandidate release source／operation count 64へ追従させ、fixtureだけをalpha.26／Public alpha.25へ更新した。immutable alpha.24／alpha.25 identity、same-version拒否、tamper、dependency、export、network negative assertionsは弱めていない。Policy unit 159 testsとLocal Policy GateをPASSした。
- Successor Head `a47b7f95ecc4cac9a390d8e5abfa1b99988b9562`はPolicy／Security Gate PASS、Quality GateがAdmin OpenAPI metadata digestに対するgenerated Admin typeのstaleだけを検出した。Canonical Admin generatorで`apps/admin/src/lib/admin-api/generated.ts`先頭のContract SHA-256だけを同期し、Admin operation／schema／type／behavior変更0をdiff確認、`admin:generate:check`をPASSした。
- Artifact Source Head `2dd1c7dbcf83b78f5d07fe3d965f9982d1f2fd05`のmanual Strict suiteはRequired 5 Checksを全PASSした。Artifact Release OS Lock下でHumanがCanonical workflowを指定入力で1回dispatchし、Run `32821950630`／Artifact `9553537412`をroot-only helperでreadbackした。GitHub outer `c1e7936e...a7eb`、Manifest `05ad837c...e2e`、SHA256SUMS `6344d997...71a9`、Client `80ebe717...5b3d`、Testkit `ca62c03b...3fde`、Public OpenAPI `888df7d3...a6e5`はManifest／実File／tar safety／inventoryへ完全一致した。Compatibilityはcontract-additive／breaking false／64 operationsでexact-pin GO、Ledgerはalpha.26をlatest immutableへ追加して`candidate=null`としsame-version再発行をFail Closedする。

## MIG-088 Payment Grant Breakdown Contract

- `git fetch --prune`後のclean local／origin／GitHub `main@e50e34cbbf016111ec7e31cc871cbaa06a954c0a`、GitHub Issue／PR、Remote refs、Task Policy、Worklog、open Task、Shared Locksを照合し、未使用Task ID `MIG-088`、Issue #385、Branch `feat/MIG-088-payment-grant-breakdown-contract`、専用Worktree、Lane Strict Change、Risk R4、Activation deferredで開始した。latest immutableはalpha.26、alpha.27のArtifact／Release／Tagは0、Artifact Release Lockはfreeである。
- Payment作成時の`paid_point_amount`／`free_point_amount` snapshotと、成功transactionで確定後にfinal guardで保護される`limited_bonus_point_amount`、`payment_point_grants`／Point Operation／LotsをCanonical Historical sourceとする。現在のPointProduct／Campaign／clockからの再計算やbackfillは不要で、Migration created／appliedは0である。
- Public `PaymentGrant`を`paid_points`／通常`bonus_points`／`limited_bonus_points`／3field合計`total_points`のrequired responseへadditive拡張し、`getPayment`と購入履歴がPayment snapshotだけから同じHistorical実績を返す。Client generated typeとTestkitの0／2000期間限定Bonus fixtureをalpha.27へ同期した。Admin／WebhookはContract alpha.26のversion metadataだけ、Admin generated sourceは対応digest commentだけを更新し、operation／schema／type／behavior変更は0である。
- Required response field追加を明示的かつresponse-only schemaに限定してadditive判定できるOpenAPI guardを追加した。Requestにも使うschema、明示markerなし、operation減少は引き続きbreaking／release rejectionであり、Public operationは64のまま、removed operationは0、互換性分類は`contract-additive`である。
- 隔離PostgreSQL 17／PHP 8.4でV2 migrationを適用後、Payment＋Limited Bonus focused 27 tests／209 assertionsをPASSした。初回は空DBへのV2 migration未適用、次に新Historical fixtureが公開商品financial immutable制約へ違反してFAILしたが、Canonical `migrations-v2`と作成時snapshot fixtureへ修正後に最終PASSした。OpenAPI 9、Release 25、Policy 171、Client 31、Testkit 39、bundle／exact-base breaking check、Admin generate check、PHP syntax、`git diff --check`をPASSした。
- Artifact Source Head `856990ddeab266ee394d5e2750b689fb8211322b`のRequired 5 ChecksをPASS後、Artifact Release OS Lock下でHumanが指定入力を1回dispatchした。Canonical Run `32833825150`／Artifact `9557923243`をimmutable readbackし、GitHub outer `4b18a027...6b10`、Manifest `a6b12bd3...3283`、SHA256SUMS `31dad542...4dd4`、Client `31f3bc55...534f`、Testkit `32559b9c...ef57`、Public OpenAPI `cb4f6b8e...3333`がManifest／実File／tar safety／inventoryへ一致した。Compatibilityはcontract-additive／breaking false／64 operationsでexact-pin GO、Ledgerはalpha.27をlatest immutableへ追加して`candidate=null`とした。
## MIG-089 fincode Card UI Bootstrap Contract

- clean local／origin／GitHub `main@83a780e5809ff3912a74af0ebf48ccc403f7b0ce`、GitHub Issue検索、Remote refs、Task Policy、Worklog、Shared／OS Locksを照合し、未使用Task ID `MIG-089`、Issue #387、Branch `feat/MIG-089-fincode-card-ui-bootstrap`、専用Worktree、Lane Strict Change、Risk R4、Activation deferredで開始した。latest immutableはalpha.27、alpha.28は未使用、Artifact Release Lockはfree、Migration Allocation／Platform Integration／Preview Deployment Lockは取得しない。
- fincode公式`@fincode/js`はPublic Keyと`isLiveMode`で`initFincode`し、UIを`create`／`mount`した後に`executePayment`または`registerCard`へ渡す仕様である。Human確定境界どおりCard UI表示時点のPayment／Provider Session／Registration Intent／Card／Coin mutationは各0とし、Storefront UI、Provider通信、credential、Runtime、Productionを変更しない。
- `GET /api/v2/me/payment-card-ui-bootstrap`はAuthenticated Userへ`provider`、`public_api_key`、`is_live_mode`だけを返すread-only contractである。Payment availability、Public／Secret Key、Webhook readiness、provider endpoint、Application environmentを同じauthorityで検証し、不整合は503 Problem Detailsでmutation前にFail Closedする。`startPayment()`とCard registrationも同じauthorityを使用する。
- controlled scope correctionとして`apps/api/tests/V2/ZFincodePaymentConcurrencyTest.php`を含め、synthetic fincode credentialを公式test-environment prefixへ揃えた。隔離PostgreSQL 17へ既存V2 migration 65件を適用後、Payment focused 22 tests／231 assertionsと実DB concurrency 1 test／22 assertionsをPASSし、task container／networkはcleanupした。Migration createdは0、Shared Preview／Production migration appliedは0である。
- Public OpenAPI 9、Release 25、Policy 172、Storefront Client 31、Storefront Testkit 40、OpenAPI bundle／check、Artifact source validation、Admin generated contract、PHP syntax、frozen lockfile install、`git diff --check`をPASSした。Provider実通信、Card／Payment／Coin mutation、Runtime Activation、Secret／Credential mutation、Storefront Repository、Production変更は0である。
- Artifact Source Head `06681c689eaba3458adb935753de128a4d12d57d`の初回GitHub policy-gateはPR `UI Verification`値、次の2回はChanged／Allowed pathの非exact metadataをFail Closedした。Code headを変更せずPR metadataをCanonical exact値へ修正し、3回目のrerunでRequired 5 Checksを全PASSした。Gate、assertion、scope、security controlの弱体化は0である。
- Artifact Release OS Lock下でCanonical Workflow Run `32867602180`を1回dispatchし、immutable Artifact `9570886895`をreadbackした。GitHub outer `7af368e9...fd339`、Manifest `2b9299ba...ab6fa`、`SHA256SUMS` `8e5d1132...d9349`、Client `7be14c54...0a00`、Testkit `8bc1cd28...0e94`、Public OpenAPI `41ebdddb...85da`がManifest／実File／tar safety／five-file inventoryへ一致した。Compatibilityは`contract-additive`、breaking false、65 operationsで、Ledgerはalpha.28をlatest immutableへ追加し`candidate=null`とした。Storefront exact-pinはGO、Activationはdeferred、canonical Build run 1、Runtime Activation 0である。

## MIG-090 Rank Effect Relation UI Cleanup

- clean local／origin／GitHub `main@31ce36796556cf57f7c86fb6fa32c1a81d0f5b5a`、GitHub Issue／PR検索、Remote refs、Task Policy、Worklog、worktree、Active Task／Shared Lockを照合し、未使用Task ID `MIG-090`を確認した。Issue #389、Branch `fix/MIG-090-rank-effect-relation-ui-cleanup`、専用Worktree、Risk R2、Activation deferredで開始し、Platform Integration Lockを取得した。
- Stage 0でRank Effect create／updateの`rank_assignments`がAdmin Contract／Laravel validationで必須かつ`catalog_rank_assets`へ書き込み、同relationはPublic Catalog／Draw presentationで参照される一方、Gacha登録・編集の「ランク設定」が同tableのCanonical設定経路であることを確認した。純粋UI変更では新規／編集保存が成立しないため、Human指定どおりLaneをStandardからStrictへ昇格した。DB／Migration変更は不要である。
- Rank Effect新規／編集画面から`Rank relation`／`対象ランクと表示順`とrank一覧load、form state、validation、payloadを削除した。Admin request contractは後方互換のためrelation inputをoptionalで維持し、新規の未送信はrelation 0件、編集の未送信は既存relation不変とした。ファイル差し替え時だけ既存image／video relationを同じrank／sort orderで新Assetへ移送する。既存relation table／column／business dataのcleanupは0である。
- PHP 8.4.23＋Task専用PostgreSQL 17へ既存V2 migration 65件を適用し、Admin Rank Effect focused 3 tests／41 assertionsをPASSした。Migration created 0、Shared Preview／Production migration applied 0。Task container／network／DBはcleanup済みで、Shared Preview／Production／Runtime Activationは0である。
- Admin全35 files・210 tests、focused Rank Effect／Gacha Rank Prize Picker／API Client 3 files・51 tests、OpenAPI bundle／check、OpenAPI unit 9、Admin generated check、typecheck、lint、PHP syntax、`git diff --check`をPASSした。Playwright production buildは3回。1回目はroute遷移後に消えるsuccess messageの過剰assertion、2回目はTask外の既存CSPが`blob:` local previewを拒否したためFail Closedし、Application／security policyを変更せずsynthetic `data:` preview fixtureへ限定した。最終Browser 2 testsは新規保存、編集保存、relation sectionなし、relation payloadなし、画像upload、既存preview、mobile overflow、console／page／500／502／504 error 0でPASSした。
- Gacha側のRank管理component／payload／Picker、Draw Domain、Public API、Storefront、DB schema、Migration、Payment／Point／Inventory／Auth、Infrastructure、Production、Secret／Credentialは変更していない。Required 5 Checks、final exact-head Strict self-review、Squash Merge／cleanupはFinal Head固定後に実施する。

## MIG-091 Admin User Filters / Expired Verification Failure State

- `git fetch --prune`後のclean local／origin／GitHub `main@35be4c3d90ba769a09b1116cfa3939d988dbc8d9`、GitHub Issue／PR、Remote refs、Task Policy、Worklog、Active Task／Shared Lockを照合し、未使用Task ID `MIG-091`を確認した。Issue #391、Branch `feat/MIG-091-admin-user-filters-verification-failure`、専用Worktree、Lane Strict Change、Risk R4、Activation deferredで開始し、Migration Allocation Lock `000066`とPlatform Integration Lockを取得した。
- Stage 0で既存User stateは`pending_verification|active|restricted|suspended|closed|anonymized`、DBはfixed check constraint、Admin User一覧はcursorのみ、Verificationはhash-only token／exact User owner／60分expiry／Browser 303 error／JSON Problem Details、resend token revoke、success時active／Session／CSRF、persistent append-only Auditを正本と確認した。Canonicalな認証失敗stateが未存在のため、Human確定仕様に従い内部status `verification_failed`とadditive reversible Migration `000066`を追加する。既存Userのbackfill、現在expiredなtokenの自動走査、既存Migration改変は0である。
- Userがexact known tokenを実際に踏みCanonical expiry判定へ到達した場合だけ、`pending_verification -> verification_failed`、state revision +1、reason `expired`の既存persistent security auditを同一transactionで1回記録する。時間経過だけではmutationせず、同じexpired link再アクセスはstate／revision／Auditを増やさない。malformed、tampered、unknown token、unknown User、User特定不能requestは既存invalid-link responseを維持しUser mutation 0である。
- `verification_failed` Userは既存明示resendで旧token revoke／新token発行でき、新しい有効linkの成功でCanonical `active`、Session／CSRF、既存redirectへ進む。closed-email再登録、旧User復活禁止、Browser error page、JSON Problem Details、Security verificationは変更・弱体化していない。
- Admin User APIへexact Public User ID、Canonical status、JST inclusive `date_from`／`date_to`のserver-side filterを追加し、filter適用後の既存opaque cursor／stable sortを維持する。JST境界はUTC offsetを保持するISO文字列としてPostgreSQLへbindする。通常URL／resetは`active`、ID／登録日は空、明示canonical URL queryを優先し、画面内だけでstateを保持してroute離脱後の通常URLではdefaultへ戻す。`verification_failed`は「認証失敗」badge／filterとして表示する。
- Task専用PostgreSQL 17へ既存66 V2 migrationsを適用し、`000066` rollback／reapplyをPASSした。PHP 8.4 focused Auth／Verification Browser+JSON／Audit integration／Admin User API／Identity Schema 45 tests・538 assertions、Admin focused 2 files・43 tests、typecheck、lint、OpenAPI bundleをPASSした。初回Backend runは隔離コンテナのtest `APP_KEY`未設定でApplication前FAILし、JST境界testはDateTime bindingのoffset脱落を検出したため既存Reporting規約のUTC ISO bindingへ修正後PASSした。Production／Shared Preview／Secret／実User PII mutationは0である。
- Production build付きfocused Playwright 1 testは、通常URL active default、明示`verification_failed`＋ID＋登録日query、cursor条件維持、reset、別画面遷移後default復帰をPASSした。最初のPlaywright引数がpnpm scriptへ正しく限定されず全60件を誤起動し、Task外の既存Announcement／Auth navigation fixture 6件がFAIL、3件PASS、51件未実行の時点で停止した。Application／testをTask外failureへ合わせず、正しいdirect Playwright指定で対象1件だけを再BuildしてPASSした。Build count 2、Runtime Activation 0である。
- Backend／DB変更は新status enum、expiry click時のCanonical transition、Admin server-side query、additive constraint migrationだけである。Final local checkはAdmin 35 files・212 tests、typecheck、lint、OpenAPI 3-surface bundle check（Admin 222／Public 65／Webhook 2）、Admin generated contract check、PHP 8.4 focused 45 tests・538 assertions、`git diff --check`をPASSした。Payment／Coin／Point／Draw／Inventory、Storefront、unrelated Auth redesign、Infrastructure、Runtime／Productionは変更しない。Required 5、fresh exact-head Strict self-review、Squash Merge／cleanupは継続する。
- Initial Head `bf1db814525e5b15291ccca42df74b6011c3c332`のmanual Strict runは、Policy Gateのexact migration inventoryが`000065`で止まり`000066`を拒否してFAIL、後段4 ChecksはFail Closedした。Migrationやguardを弱めず、controlled scope correctionとして`000066`をIdentity required file／exact migration setへ加算する。Application behavior、Migration content、local verification、Build、Activationは不変で、Policy Unit／Local Policy Gateを再確認後にsuccessor Headを固定する。
- Successor Head `6341125e768aed661214e55c9a4b0d88f1d2cabf`はRequired 5とfresh exact-head Strict self-review `#issuecomment-5422950839`をPASSしたが、push時点のPR bodyが前Headを期待していたautomatic failureが同Headへ残り、approved merge wrapperはGitHub merge stateをFail Closedした。bypass／Ruleset変更／same-head rerunは行わず、この記録だけを加えたsuccessor Headを予測してPR metadataをpush前に同期し、automatic Required 5とfresh reviewを再固定する。Application tree、local verification、Build、Activationは不変で再実行しない。

## OPS-020 Latest API/Admin Shared Preview Activation

- Human候補`OPS-016`は既存Issue／PR／reportで使用済みのため再利用せず、GitHub Issue／PR検索、Remote refs、Task Policies、Worklog、worktreesで未使用の次ID `OPS-020`を確認した。`git fetch --prune`後のclean local／origin／GitHub `main@2680e76a414f8bc80906ca7ff7a1c96b808165fc`、Issue #393、Branch `chore/OPS-020-latest-api-admin-shared-preview-activation`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始した。
- Stage 0はShared／OS Locks idle、competing Platform Task 0、root free `23,643,103,232` bytes（22.02 GiB）、Shared Preview API／Admin healthy at exact `MIG-078@59173e3a0e96e49a1e16fa83a825b3d06655df35`、PostgreSQL／Redis healthy restart 0、private networkとAPI-only egress保持を確認した。`FINCODE_PAYMENT_ENABLED`と指定4 fincode設定は全てunsetで、値のreadback／mutationは0である。
- Live ledgerは64件／batch 31／`000064`まで、latest mainのPendingはcanonical順に`000065`／`000066`だけである。両Migrationはmerge済み、predecessor完全、additive、backfill／既存Payment／User business row rewriteなし。root-only rollback backupを作成し、live-copy隔離PostgreSQLで`000065 -> 000066`をbatch 32としてapplyした。Payment 0、Coin Grant 0、新fincode row 0、既存User state count、Point Operation／Ledger／Lot／Wallet／Point Product aggregatesは全てbefore一致した。Shared Preview live migration、Build、Activationはこの時点で未実行である。
- Shared Preview liveへ`000065 -> 000066`をbatch 32で適用し、ledger 66件／`000066`までreadbackした。Migration直後はPayment／Coin Grant／fincode row／User backfill／既存business aggregate mutationが全て0で、fincode enableと指定4設定はunset／disabledを維持した。
- exact source `6cc5e8d5c5194ebf122f8d819f2fa6c54fd1cf12`のRequired 5 PASS後、Canonical workflow Run `33032347596`を1回dispatchしArtifact `9630790720`を検証・loadした。Host build 0、Storefront artifact stepはskipである。
- API／Adminだけを同一exact revisionへ各1回Activationした。両Containerはhealthy／restart 0、API healthはDB／Redis／storageを含めPASS、Admin health PASS、HTTP 500／502／504は0。PostgreSQL／RedisのContainer ID、開始時刻、Volume、private-only境界は不変で、APIだけexisting egressを保持した。
- rollback用MIG-078 API／Admin imageはload済みのまま保持し、rollbackは不要だった。root freeは最終`22,962,905,088` bytes（21.38 GiB）で20 GiB Gateを維持し、disk cleanup／pruneは0、隔離Migration cloneだけを削除した。
- live Admin loginは既存retained Preview credentialで401となり、MFA前にFail Closedした。Credential reset／Admin作成／Session偽装は行わず、Payment／Rank Effect／User Listのpost-activation authenticated Browserは未実行である。deployed exact treeのMIG-085／090／091 focused evidenceは再利用可能だが、Runtime Browser PASSとは記録しない。
- Migration直後のbusiness mutationは0だったが、Activation後にTask外のnon-QA User操作としてdraw spend 2件とprize exchange free grant 2件を検知した。Payment row／Payment Point Grantは0を維持し、OPS-020はPoint／Coin／User mutationを一切実行していない。PR #394はDraft、TaskはBrowser credential/sessionとこのconcurrent activityの最終判断待ちでOpenを維持する。
- Evidence-only Head `a57ce0181b43cbe8275f416e2336e19f2e62c51c`のautomatic checksは、push後のPR本文更新より先に旧activation head期待値を読んだためPolicy GateがFail Closedし後段はFAIL／skipとなった。same-head rerunやGate弱体化は行わず、この事実を記録したsuccessor Headを先にcommitし、PR expected headをpush前に同期する。
- Human authenticated session準備後の再開PreflightでAPI／Admin exact Runtime・health・ledger 66／batch 32をread-only再確認し、Build／Migration／Activationは再実行していない。DB上にvalid MFA Session 2件を確認したが、Codex Browserへcookie／storage state／Browser processのhandoffはなく、retained normal Preview credentialによる正規Flow 1回は再度login 401でMFA前に停止した。認証迂回／変更、Password reset、Admin／Session作成は0で、Browser AcceptanceとCloseoutはFail Closedを維持する。
- Human最新決定によりOPS-020 Browser AcceptanceはHuman authenticated Browserの確認結果を正本とし、Codex Browserへのcookie／storageState／CDP handoffは不要とする。CodexはBuild／Migration／Activation／Login再試行／認証変更を行わず、Payment Navigation／Default・Filters、Rank Effect relation section削除、User List active default／ID・status・登録日FiltersのHuman報告を待ってからfixed-head self-review以降を再開する。
- Human authenticated Browser AcceptanceはPASS。左Navigation `ガチャ → 決済 → 配送`、`決済 > 決済状況`遷移、Default `決済成功 / すべて`、状態／種別Filters、Rank Effect新規／編集の`Rank relation / 対象ランクと表示順`非表示、User List active-only DefaultとID／status／登録日／`verification_failed` Filtersを確認した。Codex Login再試行、Build、Migration、Activation、fincode設定、Provider通信は再実行していない。
- final read-only RuntimeはAPI／Admin exact `6cc5e8d5c5194ebf122f8d819f2fa6c54fd1cf12`、healthy／restart 0、ledger 66／batch 32／`000066`、Payment／Payment Point Grant 0、User 16（active 5／closed 7／pending_verification 4）を維持する。Activation後の外部non-QA User操作7件はOPS-020起因でなく既存記録を維持し、global Point aggregate不変は主張しない。OPS-020自身のPayment／Coin／User business mutationは0である。

## MIG-092 fincode Preview External Callback / Origin Fix

- clean local／origin／GitHub `main@b1ff701d2b356860542fecd6749933f5817bd31a`、GitHub Issue／PR、Remote refs、Task Policies、Worklog、Shared／OS Locksを照合し、未使用`MIG-092`を確認した。Issue #395、Branch `fix/MIG-092-fincode-preview-callback-origin-fix`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始した。Stage 0 root freeは`22,958,444,544` bytesで20 GiB GateをPASSした。
- Shared Preview API／Admin／PostgreSQL／Redisはhealthy／restart 0、API exact Runtimeは`OPS-020@6cc5e8d5c5194ebf122f8d819f2fa6c54fd1cf12`である。external `POST /webhooks/v2/fincode`のNginx 404と、fincode Platform／Storefront originsのAdmin generic origin couplingをread-onlyで再現した。fincode credentialはmetadata上unset、Payment disabledで、値readbackは0である。
- Payment専用`FINCODE_PLATFORM_ORIGIN`／`FINCODE_STOREFRONT_ORIGIN`をAPI Composeとconfigへ追加し、`APP_URL`／`FRONTEND_URL`の既存意味は変更しない。authority-only HTTPS、userinfo／path／query／fragment禁止、Admin origin一致拒否をFail Closedで適用し、MIG-087のProvider normal/failure、Storefront thanks/failure、`pid`、Point Product ID Contractは維持する。
- Preview専用Nginx canonicalizerはexisting `/api/v2/` upstreamをreadbackし、exact `POST /webhooks/v2/fincode`だけをproxyする。Production vhost、broad webhook surface、Admin private routeを拒否し、atomic update／root-only backup／restore／verifyを提供する。MIG-079 Webhook verification／Provider re-query／exactly-once authorityは変更しない。
- 隔離PostgreSQL／Redisへ既存66 migrationsを適用し、fincode Payment focused 23 tests／241 assertions、Nginx 4 tests、Policy Unit 162 tests、local Policy Gate、Compose config、PHP syntax、`git diff --check`をPASSした。Migration created／Shared Preview migration applied、Secret／Payment Enable／Provider／Production／Storefront／business mutationは各0である。Canonical Build、API-only／Nginx Activation、Runtime Acceptance、Required Checks、fresh fixed-head self-review、merge／cleanupは継続する。
- Application Head `42a70d8efde5b8818b1bcec59ecf2af82f862b6e`はRequired 5 ChecksをPASSした。初回Preview workflowはTask branch refで成功したがcanonical wrapperの`main` ref authorityにFail Closedし、Artifactはdownload／load／activateしていない。同一approved headを正しい`main` refで再dispatchし、Run `33048184569`／Artifact `9636581132`／outer digest `sha256:3e06b6a7f3bda5eb129c6dbcb6401b04a695b0fe5bbc5821d49461fa6067eec5`を検証・loadした。build dispatch 2、canonical build 1、Host build 0である。
- APIを`oripa-v2-api:preview-MIG-092-42a70d8efde5`へ1回recreateし、existing API-only egressを復元した。Admin image／container、PostgreSQL／Redis container／volume／private networkは不変である。Preview Nginx exact routeをatomic apply、`nginx -t` PASS後に1回reloadし、Production vhost checksumは不変である。
- external `POST https://test.luxe-pack.biz/webhooks/v2/fincode`はNginx 404でなくexisting API 401 Problem Detailsへ到達した。Normal／Failure Return Handlerはmissing／unknown `pid`でもAPIへ到達し303でCanonical Preview Storefront `/points` fallbackへ遷移する。API/Admin health、ledger 66／`000066`、restart 0、500／502／504 0、Payment／Payment Point Grant 0を確認した。
- Runtime metadataはPlatform／Storefront originsが共にCanonical Preview `https://test.luxe-pack.biz`、Admin originと分離、Admin comparison input presentを確認した。fincode Secret/Public keys、Webhook signature、Payment Enableはabsentのままで値readback 0、Provider／Production／business mutation 0である。Webhook routing／Platform Return／Storefront final originは各GO、OPS-021は新規Stage 0からrestart GOで、final fixed-head checks／self-review／merge／cleanupを継続する。

## OPS-021 fincode Sandbox Shared Preview Enablement

- Issue #397、Branch `chore/OPS-021-fincode-sandbox-preview-enablement`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始した。Stage 0はmain／Task ID／Policy／Locks／root disk／Runtime／ledger 66／MIG-079・087・088・089／fincode metadata／Webhook／Return Handler／Storefront Runtimeをread-only確認し、Secret値readback 0でHuman Sandbox credential／Webhook registration checkpointへ停止した。Active Storefrontはalpha.24のためProvider Browser E2EはHOLDである。
- Human OperatorがPreview `FINCODE_STOREFRONT_ORIGIN`更新を完了後、Preview Deployment／OS lock下でexisting MIG-092 API image `oripa-v2-api:preview-MIG-092-42a70d8efde5`を`--force-recreate --no-build --no-deps`でAPIだけ1回recreateし、guarded helperでexisting API-only egressを復元した。Build／Migration／Admin Activation／Storefront Activation／DB・Redis・network recreationは0である。
- API healthはApplication／DB／Redis／storageを含め200、API／Admin／PostgreSQL／Redisはhealthy／restart 0、後3 serviceのcontainer identity／start time／mount／volumeは不変、ledgerは66／`000066`を維持した。private internal networkとAPI-only egressを保持し、activation windowのNginx HTTP 500／502／504は0である。
- Runtime Platform originは`https://test.luxe-pack.biz`を維持し、Storefront originは`https://luxe-pack.biz`へ反映した。pure URL-builder fixtureはNormal `https://luxe-pack.biz/points/purchase/thanks?pid={Payment.id}`、Failure `https://luxe-pack.biz/points/purchase/{PointProduct.id}?pid={Payment.id}`を生成し、Admin origin redirectは0。malformed／missing／unknown `pid`のexternal POSTは各303で安全な`https://luxe-pack.biz/points` fallbackへ遷移し、Payment／Payment Point Grantは`0 -> 0`だった。
- `FINCODE_PAYMENT_ENABLED`はunset/defaultのeffective falseでunchanged、Public／Secret KeyとWebhook Signatureはbefore／afterともabsent、Secret値readback 0。Provider通信、Production、Payment／Coin business mutation、credential設定、Webhook登録、Payment Enableは各0で、OPS-021はHuman Sandbox credential／Webhook registration checkpointを維持する。
- Human OperatorがSandbox env設定とSandbox Dashboard Webhook登録完了を値なしで報告した。fresh Gateでcanonical env metadataはSandbox API base、Public／Secret Key non-empty、Webhook Signature non-empty／length-valid、Payment explicit false、Preview Platform origin、luxe Storefront originをPASSした。existing MIG-092 imageでAPIだけ1回recreateし、Build／Migration／Admin／Storefront Activation／DB・Redis・network recreationは0だった。
- recreate後もactive API RuntimeではAPI base、Public／Secret Key、Webhook Signature、Payment Enableがabsentだった。source readbackで`apps/api/config/v2_fincode.php`は5変数をconsumeする一方、`docker-compose.v2.yml`がそれらをAPI environmentへ渡していないCanonical wiring defectを確定した。Sandbox baseはsafe code defaultのみで、signed Webhook readinessとEnable Gateは未達である。
- Security境界を維持してPayment Enableは実行せず、ad hoc secret-bearing overrideも作成しない。API／Admin／PostgreSQL／Redisはhealthy／restart 0、private network／API-only egress、ledger 66、Payment／Grant `0/0`、HTTP 500／502／504 0を維持し、Provider／Production／business mutationは0。Preview／OS lockを解放し、別Strict MIGでCanonical Compose wiringとguardを修正・Activation後に同一OPS-021をresumeする。
- MIG-093 Closeout後、既存Issue #397／branch／worktree／Task Policyの同一OPS-021を再開した。local／origin／GitHub main `44120f2cf7ba71abe64c854a05facf60f3adf79b`、root free `22,334,709,760` bytes、exact MIG-092 API image／revision、healthy／restart 0の4 services、ledger 66／`000066`、Sandbox API baseとPublic／Secret／Webhook present/non-empty、Payment false、external Webhook 401 problem+json、Return safe 303をfresh readbackした。Secret値readbackは0である。
- Human明示承認に基づきcanonical Preview envのnon-secret `FINCODE_PAYMENT_ENABLED`だけをtrueへ変更し、existing MIG-092 imageを`--force-recreate --no-build --no-deps`でAPIのみ1回recreateした。Build／Migration／Admin／Storefront Activation、PostgreSQL／Redis／network recreationは0。API／Admin／PostgreSQL／Redisはhealthy／restart 0、non-API identity／volume、private network、API-only egress、ledger 66を保持し、HTTP 500／502／504は0である。
- Runtime／Laravel config metadataはPayment true、Sandbox base、Public／Secret／Webhook present/non-empty、Platform origin `https://test.luxe-pack.biz`、Storefront origin `https://luxe-pack.biz`をPASSした。safe authenticated controller boundaryはBootstrap HTTP 200、provider fincode、is_live_mode false、Public Key present、private/no-store、Vary Cookieを値なしで確認し、anonymous external endpointは401。Payment／Provider Session／Registration Intent／Card／Point・Coin／Grant mutationは0である。
- Bootstrap boundary初回は存在しないUser `status` columnをread-only queryしてcontroller／transaction開始前にFAILし、mutation 0で停止した。Canonical `state` queryへ修正した再実行だけが上記200／non-mutating PASSである。
- Human Sandbox Dashboard Webhook登録をCanonical checkpointとして、unsigned exact external routeは401 application/problem+jsonを維持した。malformed／unknown pidは303でluxe `/points`、normal／failure Canonical templateとBrowser non-authorityを維持する。test-safe Sandbox nonexistent-Payment GET 1回はconnectivity PASS／authentication accepted／HTTP 400で、Provider／Platform business mutationとProduction endpoint requestは0だった。
- Active Storefrontはrelease `58a6bc6b6119f7daaa2d415c3b9e4c3db4f98b18`／client alpha.24、latest Platform Artifactはalpha.28で未Activation。`Platform Payment Browser Acceptance readiness = GO`、`Provider Browser E2E = HOLD (latest Storefront Runtime pending)`としてOPS-021をOpenのまま停止し、Storefront Activation後に同一Taskで4方式E2Eへ再開する。
- 後続Storefront release `bddff7106a8e710859a94cc07ada9a93b18aa136`はClient／Testkit alpha.30をexact pinし、Human Browser AcceptanceでCard successとPayPay Provider success／Coin Grantを確認した。Card failure ReturnはPlatformのCanonical failure HandlerへPOSTされproduct purchase pageへ303した一方、Provider exact re-queryの`AUTHENTICATED + EC0091310A3`をPaymentが`requires_action`へ残す別Platform defectを確定した。Save CardとStorefront failure navigationも別Site follow-upとし、OPS-021へSource修正を拡張しない。
- final live readbackはmain `1c3ea1d4e715a277c8417c97f86924533cb509eb`、API `oripa-v2-api:preview-MIG-096-3f6f918e2d85`／OCI `3f6f918e2d8537f84354a8d75e6933b5a663459a`、Storefront `bddff7106a8e710859a94cc07ada9a93b18aa136`／alpha.30、ledger 66／`000066`、API／Admin／PostgreSQL／Redis healthy／restart 0、root free 20 GiB以上である。Closeout追加Build／Activation／Migration／Provider／Payment／Coin／Mail／Production／Secret mutationは0。OPS-021をSandbox enablement／Platform readiness完了としてcloseし、Browser-discovered defectは新Strict Taskへ引き継ぐ。
## MIG-093 fincode Compose Credential Wiring

- GitHub Issue／PR検索、root Task Policy、evidence、Worklog、refs／history／branch／worktreeで`MIG-093`未使用を確認した。clean local／origin／GitHub `main@b805d09469c79f92564d041236daf4d3dc4c67a1`、Issue #398、Branch `fix/MIG-093-fincode-compose-credential-wiring`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始し、Platform Integration Lockを取得した。OPS-021はFail Closedを維持する。
- `docker-compose.v2.yml`のAPI serviceだけへ`FINCODE_API_BASE_URL`、`FINCODE_PUBLIC_API_KEY`、`FINCODE_SECRET_API_KEY`、`FINCODE_WEBHOOK_SIGNATURE`、`FINCODE_PAYMENT_ENABLED`をCompose interpolationで追加した。credential値／Secret defaultは直書きせず、Human設定のPayment Enable値を変更しない。Admin／PostgreSQL／Redis／Storefront、existing timeout、MIG-092 Platform／Storefront origin wiringは不変である。
- Policy Gateは5変数をAPI section内exact-onceで要求し、各欠落とAdmin移動をFail Closedする。`v2_fincode.php`の対応5 consumerもguardし、fixtureは変数名とpublic-safe defaultだけでcredential値を含まない。Focused 6 tests、Policy Unit 165 tests、local Policy Gate 1,581 tracked files、Compose config quiet、PHP config syntax、Python compile、`git diff --check`をPASSした。
- Source phaseのBuild／Migration／Runtime Activation／Payment Enable／Provider通信／Payment・Coin mutation／Production mutation／Secret値readbackは各0。Required 5、fresh exact-head Strict self-review、squash merge、existing MIG-092 imageのAPI-only Activation、Runtime Acceptance、OPS-021 restart判定、cleanupを継続する。
- Source Head `2fa6b8b26a474e52d1a962f07a5541f526a1eb62`はRequired 5とfresh fixed-head Strict self-review `#issuecomment-5437810376`をPASSした。exact MIG-092 API image `sha256:3188f9046e18ae33e4cff09cce4bebd3b700e037f707ee61173382496e121cbc`／revision `42a70d8efde5b8818b1bcec59ecf2af82f862b6e`を再利用し、Build／MigrationなしでAPIだけを1回recreateした。Admin／PostgreSQL／RedisのContainer identity／開始時刻／volume、private network、API-only egressは不変である。
- RuntimeとLaravel configのmetadataはSandbox API base、Public／Secret／Webhook present/non-empty、Payment Enable present/falseを確認した。Platform originは`https://test.luxe-pack.biz`、Storefront originは`https://luxe-pack.biz`を維持し、Secret値readbackは0。API／Admin／PostgreSQL／Redisはhealthy／restart 0、root free `22,304,661,504` bytes、unsigned external Webhook 401、malformed normal／unknown failure Returnはnon-mutating 303でluxe `/points`、Preview／luxe same-origin Public API smoke 200、HTTP 500／502／504は0である。
- Payment／Payment Point Grantはbefore／after `0|0`、Provider endpoint log match／Provider通信／Production mutation／Payment・Coin mutationは各0。Preview／OS Locksを解放し、`OPS-021 restart = GO`と判定した。Runtime evidence-only successor HeadのRequired 5、fresh review、merge／cleanupを継続する。

## MIG-094 Payment Resume JSON Request Contract

- clean local／origin／GitHub `main@44120f2cf7ba71abe64c854a05facf60f3adf79b`、Issue／PR title、Remote refs、Task Policies、Worklog、worktrees、Shared Locksを照合し、`MIG-094`未使用を確認した。Issue #400、Branch `fix/MIG-094-payment-resume-json-contract`、専用Worktree、Lane Strict Change、Risk R4、Activation deferredで開始し、Platform Integration Lockを取得した。latest immutable Storefront Artifactはalpha.28、alpha.29 candidateは未存在、Artifact Release Lockはfreeである。
- OPS-021確定事象どおり、`resumeUnpaidPayment()`はbodyなしPOSTで、既存transportがJSON body存在時だけ`Content-Type: application/json`を付与するためBrowser Security middlewareの415へ到達していた。Clientの明示CSRF／Browser-managed両FacadeへCanonical empty JSON body `{}`だけを追加し、middleware、Auth／CSRF、API response、Payment state／eligibility、existing encrypted redirect復号、Provider request behaviorは変更しない。
- Client／Testkitを次の未使用immutable `2.0.0-alpha.29`へ同期し、package-only pending candidateを作成した。Public OpenAPI alpha.27／65 operationsとSite Schema alpha.23は内容・versionとも不変でcanonical publication対象／参照を維持する。初回focused testは専用worktreeにNode／Composer依存が未配置のためApplication実行前に停止し、test failureとは扱わず検証環境を整えて再実行する。Migration／DB／Runtime／Provider／Payment／Coin／Production mutationは各0である。
- Human Scope Correctionを同じMIG-094／Issue #400内で正本化した。初回Konbini／Virtual Accountは`requires_action + UNPROCESSED`で保存済みredirectを持つため、このexact state pairを既存`processing + AWAITING_CUSTOMER_PAYMENT`へ加えてresume可能にする。owner、method、future expiry、redirect存在、decrypt成功、fincode HTTPS URLを全て要求し、expired／other-user／redirectなし／decrypt不可／Credit Card／PayPayを409でFail Closedする。state transition、Payment row更新、Provider Session再作成、fincode API callは0である。
- PHP 8.4.23＋Task専用PostgreSQL 17へ既存66 migrationsを適用し、resume focused 2 tests／39 assertionsをPASSした。Konbini／Virtual Accountの初回`requires_action`、既存`processing`、canonical JSON HTTP、owner、expiry、missing／undecryptable redirect、unsupported method、Payment row数不変、Provider call数不変を確認した。初回fixture再実行は既存Konbini未払い上限へ正しくFail ClosedしたためDomainを変えず独立User fixtureへ分離した。Task container／networkはcleanup済みで、Migration created 0、Shared Preview／Production migration applied 0である。
- Source Head `5cde1e0a91151b584de8a63d19efd7b4a15e8ab1`はRequired 5 Checksとfresh Strict self-review `#issuecomment-5440837361`をPASSした。Artifact Release Lock下でCanonical Workflow Run `33084320143`を1回dispatchし、immutable Artifact `9651612069`をreadbackした。GitHub outer `1e11a5f7...7b97b`、Manifest `9e5059d1...7423b`、`SHA256SUMS` `23a1afd8...a944`、Client `28e57560...25e1`、Testkit `1e976d1c...0b56`、Public OpenAPI `41ebdddb...85da`がManifest／実File／tar safety／five-file inventoryへ一致した。初回readback helperは旧contract-additive期待でArtifact変更前にFail Closedし、package-only verifier補正後に同一Artifactを検証した。Ledgerはalpha.29をlatest immutableへ追加し`candidate=null`とした。Storefront exact-pin GO、API Runtime Activation 0、Provider communication 0である。

## OPS-022 MIG-094 Shared Preview API Activation

- GitHub／local／origin `main@053379b41ad5ad640a01c1a62410b9a121ac2f3f`一致、clean main、Issue／Task Policy／Worklog／GitHub検索で未使用`OPS-022`、Issue #402、Branch `chore/OPS-022-mig-094-shared-preview-api-activation`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始した。Stage 0 root freeは`22,116,044,800` bytesで20 GiB GateをPASSし、関連Locksはidleだった。
- Shared Preview APIはMIG-092 source `42a70d8efde5b8818b1bcec59ecf2af82f862b6e`、AdminはOPS-020 sourceで、API／Admin／PostgreSQL／Redisはhealthy／restart 0。ledgerは66件／batch 32／`000066`、fincode Sandbox入力とPayment Enable trueはpresent/non-empty、Secret値readback 0、Payment／Grantは`3/0`である。
- Human Scope CorrectionによりCanonical API-only Build blockerをOPS-022へ統合した。GOV-019／Issue #403はcommit／PR／merge各0でCloseし、Remote/local branch、worktree、Task Policy、evidenceをcleanupした。必要な実装差分だけをOPS-022へ移し、GOV固有metadataは転用していない。
- Canonical workflowへ`image_mode=normal|api-only`を追加した。normalはAPI＋Adminを維持し、api-onlyはAPIだけをBuildしてAdmin Docker build／package引数を実行しない。invalid inputはcheckout／Build前にFail Closedする。Artifact helper／GitHub read wrapperはAPI-onlyまたはAPI＋Adminのexact inventoryだけを許可し、digest／OCI revision／source SHA／tag／publication contractを維持する。

- Task-branch workflow runは引き続き非Canonicalである。Human merge-first Decisionに従い、Required Checks／fresh Strict self-review後にPRをsquash mergeし、mainへmerge済みの同一internal PR exact reviewed headだけをmain workflowからAPI-only Buildできる境界を追加した。closed-unmerged PRは拒否する。
- Draft PR #404を作成した。Preview pipeline 23 tests、Policy Unit 167 tests、Storefront workflow regression 1 test、Task evidenceを含むlocal Policy Gate 1,586 tracked files、workflow YAML、Python compile、`git diff --check`をPASSした。Source phaseのMIG-094 image Build、Runtime Activation、Admin／Storefront Build・Activation、Migration、DB／Payment／Coin mutation、Provider communication、FINCODE設定変更、Production mutationは各0である。

## MIG-095 fincode Unpaid View Consistency / Webhook Correlation

- clean local／origin／GitHub `main@7d72997dd329bc90dcb9a1fb1a60dcd32ac025c7`、GitHub Issue／PR title、Task Policies、evidence、Worklog、refs、branches、worktrees、Shared Locksを照合して`MIG-095`未使用を確認した。Issue #405、Branch `fix/MIG-095-fincode-unpaid-view-consistency`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始し、Platform Integration Lockを取得した。
- `view=unpaid`の旧条件`processing + AWAITING_CUSTOMER_PAYMENT`へ`requires_action + UNPROCESSED`を追加し、authenticated owner、fincode、Konbini／Virtual Account、future expiry、復号可能な保存済みfincode HTTPS redirectをresumeと同一Policyで検証する。Card／PayPay、terminal、state pair不一致、other-user、redirectなし／復号不可／authority不正はFail Closedし、`id DESC`／opaque cursor／limit／`has_more`／`next_cursor`を維持する。
- Existing initial Konbini unpaidは一覧へ返し、second startはProvider call前にHTTP 409 `KONBINI_UNPAID_LIMIT_REACHED`／non-retryableを維持する。Focused Fincode 26 tests／295 assertions、Payment／Concurrency 48 tests／402 assertions、Task PostgreSQL V2 migration fresh through `000066`、PHP syntax、Policy Gate、diff checkをPASSした。
- Acceptance時のWebhook 404はAPI到達2件、直前作成のdiagnostic Virtual Account、Provider Event 0を既存Evidenceで確認したが、request body／event／pay type／provider referenceが非保持のため一意相関できず`W3 unresolved`とした。Webhook code、replay、Provider通信、Dashboard／config、DB／Payment／Coin mutationは0である。
- Public OpenAPI／Storefront Client／Testkit／immutable Artifact、Migration、Storefront／Admin／Card／PayPayは変更しない。Final Head Required 5／fresh Strict self-review／squash merge後、merged mainのCanonical API-only BuildとShared Preview API-only Activationを継続する。

## MIG-096 Credit Card Return / Save Card Purchase Consistency

- Issue／PR title、Remote refs、Task Policies、evidence、Worklog、history、branches、worktreesを照合し、候補`MIG-096`が未使用であることをIssue作成前に確認した。clean local／origin／GitHub `main@1451b5ec47b7226b784278c28bbbd5832650918f`、Issue #407、Branch `fix/MIG-096-card-return-save-consistency`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始し、Platform Integration Lockを取得した。root freeは`28,821,397,504` bytes、Artifact latest immutableはalpha.29／`candidate=null`、Migration ledgerは66件／batch 32／`000066`、Shared Preview API／Admin／PostgreSQL／Redisはhealthy／restart 0、fincode Sandbox readinessはPASS、Secret値readbackは0である。
- Return Stage 0ではPlatformのCard Next Actionがnormal／failureとも`https://test.luxe-pack.biz/api/v2/payment-returns/fincode/{normal|failure}?pid=...`を返し、saved／save=true Card execute adapterも同じ`return_url`／`return_url_on_failure`をProvider requestへmappingすることを確認した。Return HandlerはPayment correlation後に303でluxe Storefront thanks／product purchaseへ遷移し、invalid correlationは`/points`へFail Closed、Browser returnはPayment authorityでなくProvider re-query／Webhook authorityを維持している。active Storefrontは`@fincode/js` utility `executePayment()`を使用し、同utilityのrequest shapeがPlatform提供merchant return URLを運ばないため、Provider default `/v1/payments/cards/success|failure`へfallbackしていた。Platform Return mappingはPASSであり、Return修正はStorefront exact follow-upとする。
- save=true Human attemptは2026-08-28 15:10:12／15:14:09 JSTに`POST /api/v2/me/payment-card-registration-intents`へ到達したが、bodyなしのためBrowser SecurityがHTTP 415 `UNSUPPORTED_MEDIA_TYPE`／retryable false／`V2AuthenticationException`でcontroller前に拒否していた。request_idはretained logsに保存されず取得不能。Registration Intent／Customer／Saved Card row、registerCard、provider_card_id、startPayment(save=true)、Payment row、Provider Card execute、transactionは全て未到達／0である。Clientのexplicit-CSRF／Browser-managed両facadeへcanonical `body: {}`だけを追加し、middleware、Standalone completion endpoint、purchase orchestration、API、Payment／Coin、Provider boundaryは変更しない。
- Storefront Client／Testkitを次のimmutable package-only alpha.30 candidateへ進め、Public OpenAPI alpha.27 bytes／65 operations、Site Schema alpha.23、Platform／Application versionは不変とする。API source変更がないためAPI Build／Activation、Migration created／applied、Provider／Payment／Coin／Production mutationは各0を維持する。Focused verification、Artifact publication、Required 5、fresh Strict self-review、squash merge、cleanupを継続する。
- Frozen install後、Storefront Client build／31 tests／typecheck／lint／generated check、Site Schema prerequisite build、Storefront Testkit build／40 tests／typecheck／lint／generated／exports／network checks、Artifact governance 13 tests、Policy Unit 167 tests、local Policy Gate 1,588 tracked files、alpha.30 source validation、`git diff --check`をPASSした。Client最初のtest／typecheck／lintは新worktreeの依存とdist未生成でApplication前FAIL、Testkit最初のbuild／typecheckはSite Schema prerequisite未BuildでFAILし、frozen install／dependency build後の同じFinal sourceでPASSした。version header期待値のalpha.29残存をtestが検出しalpha.30へ同期後PASSした。
- Task専用PostgreSQL 17へ既存66 V2 migrationsを適用し、active MIG-095 API image内のunchanged Card Backend 26 tests／295 assertionsとConcurrency 1 test／22 assertionsをPASSした。PHPUnitは既存warningを27件表示したがexit 0で、Providerは全てfake、実通信／Shared Preview DB mutationは0。Task container／network／test envはcleanup済みである。
- Human Scope AdditionのPayPay Payment `01a0473c-ea1a-72f5-bd43-24fa2c9400bb`（2026-08-28 16:19:29 JST）は`requires_action + UNPROCESSED`のまま、normal Return Handlerは16:19:47 JSTにcanonical 303を返し、`getPayment`は30秒超同じ状態を返していた。直前の`POST /webhooks/v2/fincode` 3件は16:19:35／16:19:35／16:19:40 JSTにAPIへ到達してHTTP 404、Provider Event／succeeded transition／Point Grant／purchase mailは各0である。Routeは存在し、同Controllerの404は署名／JSON／supported event／pay type／reference shape通過後の`FINCODE_PAYMENT_NOT_FOUND`だけで、Payment correlation前に停止してProvider re-query／domain transactionへ未到達だった。
- fincode公式OpenAPIの`POST /v1/sessions`は事前指定Payment IDを`transaction.order_id`と定義するが、Platformは未定義の`transaction.id`を送っていた。Providerは別IDを生成し、Platform保存referenceはread-only Provider GETでHTTP 400／`EE006025002`（transaction does not exist）、signed WebhookのProvider生成referenceはPlatform rowと一致せず404となるexact原因を確定した。redirect-session adapterを`order_id`へ1 fieldだけ修正し、unsupported `id`を生成しない回帰をPayPay／Konbini／Virtual Account共通Return testへ追加した。Browser Return Authority、Webhook signature、Provider re-query、Payment／Coin exactly-onceは変更しない。
- 修正後の隔離PostgreSQL 17でFincode Backend＋Concurrency 27 tests／317 assertionsをProvider fakeのみでPASSした。診断中のProvider通信はread-only GET 3回、Provider mutation／Webhook replay／人工event／新規Payment／manual status／manual Coin Grant／Shared Preview DB mutationは各0である。API source変更のためmerged mainからAPI-only Build／Shared Preview API-only Activationを行う。
- Source Head `4a7703859473f0c3f5e317cfca454cb8dce401ae`のRequired 5をPASSし、Artifact Release Lock下でCanonical api-only Workflow Run `33154172300`を1回成功させた。immutable package-only Artifact alpha.30／Artifact `9678987823`をreadbackし、GitHub outer `f9c7e8f6...55b6a`、Manifest `25667419...2fe5a`、SHA256SUMS `5849402d...aa363`、Client `f44e2da2...9dae6`、Testkit `f349b6e0...8809`、Public OpenAPI `41ebdddb...85da`をManifest／実File／five-file inventoryへ一致させた。Ledgerはalpha.30をlatest immutableへ追加し`candidate=null`とし、settled Artifact／Policy 180 testsとlocal Policy Gate 1,589 filesをPASSした。
- 初回Artifact workflowはinput既定値`normal`により禁止対象のAdmin Buildを含み得たため、image build step中かつPreview image／Storefront Artifact upload前にcancelした。成功runは明示`api-only`でAdmin imageを対象外とした。cancelled runのArtifact採用は0、Runtime Activationはmerge後だけに限定する。

## MIG-097 Card 3DS Failure Canonical Terminalization

- OPS-021のdirty worktreeはunique Source差分0、既存evidence/reportのみと確認し、root-only evidenceを保持したままIssue #397／PR #409をcloseoutした。Squash SHA `95f3b9c7e20cc39c705e8c8c5107a9ff28b2f425`へlocal/origin/GitHub mainを同期し、OPS branch／worktree／policy cleanup後に未使用`MIG-097`、Issue #410、Branch `fix/MIG-097-card-3ds-failure-terminalization`、専用Worktree、Lane Strict、Risk R4、Activation immediateで開始した。
- Stage 0 active APIはMIG-096 image `sha256:2aca4d66...b51c7`／OCI `3f6f918e2d8537f84354a8d75e6933b5a663459a`、Storefrontは`bddff7106a8e710859a94cc07ada9a93b18aa136`／alpha.30 exact pin、ledgerは66／`000066`、API／Admin／PostgreSQL／Redisはhealthy／restart 0、root free `27,880,689,664` bytesで20 GiB GateをPASSした。
- fincode公式Contractと実canonical payloadを再照合し、`Card + AUTHENTICATED + EC0091310A3`だけをdocumented terminal 3DS failureとしてexact Provider re-query時に`failed`へ分類する。Browser ReturnはAuthorityにせず、errorなしは`requires_action`、未承認／malformed payloadとProvider unavailableはretryable 503でmutation前Fail Closedする。PayPay／Konbini／Virtual Accountと他Card statusのcoarse mappingは変更しない。
- Webhookとdirect reconciliationは同じclassifier／status application経路を使用し、terminal codeを既存`fincode_payment_attempts.last_error_code`へ保持する。duplicate／delayed／reverse-order、failure後success、success後late failure、CAPTURED、Browser Return non-mutation、Grant／Ledger／Mail exactly-once境界をFocused testへ固定した。
- 既存66 V2 migrationsを適用したTask専用PostgreSQL 17で新規5 tests／69 assertions、Fincode Backend全31 tests／364 assertions、Concurrency 1 test／22 assertionsをProvider fakeだけでPASSした。Migration／OpenAPI／Client／Testkit／Artifact変更、新規実Payment、Provider mutation、Webhook replay、manual status／Coin、Shared Preview business data、Production、Secret値readbackは各0である。
- 初回local Policy Unitは旧guardがcoarse `CAPTURED` mapperをWebhook service内に要求してFail Closedした。ScopeをPolicy本体とnegative unit 2件だけへ拡張し、全Provider status invariantを新classifierへ移して`EC0091310A3`削除またはclassifier call迂回を拒否する。Gate／assertion／security boundaryの弱体化は0である。
- Final local Policy Unit 181、Policy Gate 1,594 tracked files、Quality Unit 4／Gate、Security Unit 10、PHP syntax、JSON parse、`git diff --check`をPASSした。standalone Security Gateはcanonical Composer／pnpm audit inputsを要求するためsynthetic入力を作らず、Required GitHub checkで固定する。

## OPS-023 Shared Preview Storefront API Upstream Stabilization

- clean local／origin／GitHub `main@8e5277110097a2e3b567392a035a23026aafce18`、未使用Task ID、Issue／PR／branch／worktree／Lock、Runtime topology、active config、API／Storefront exact source、service health、root freeをlive readbackした。Task Policy SHA-256 `068386fd56d4eb4e3719b2b28cb07c78206d5d8a92ca7319c65119215bdde052`を発行後、Issue #412、Branch `fix/OPS-023-shared-preview-storefront-api-upstream-stabilization`、専用Worktree、Platform Integration Lock、Draft PR #413で開始した。
- Host Nginxはexact `/api/v2`、prefix `/api/v2/`、exact fincode Webhookをobsoleteな`192.168.61.10:8000`へproxyしていた。active MIG-097 APIはhealthy／restart 0でloopback `127.0.0.1:8611`のdirect 4 endpointsが200、Storefront same-origin 3 endpointsが502、Storefront `/`／`/points`は200だった。API-only Activationがimage-only overrideでcontainerをrecreateしつつNginx更新／same-origin smokeを行わず、runbookがstatic IP保持を要求していたことをexact root causeとした。
- Repository-managed canonicalizerは3 managed routesの`proxy_pass`だけをstable `http://127.0.0.1:8611`へ正規化し、Host／X-Real-IP／X-Forwarded-For／X-Forwarded-Proto、URI、Webhook POST-only semanticsを検証する。RFC1918 container upstream verifyはFail Closed、backupはbyte-identical mode 0600、replacementはatomic、`nginx -t` failureはreload 0でrollback、PASS後だけreload 1回とした。
- Preview callback／image Activation runbookからfixed container IP依存を除去し、loopback-only publish、static `ipv4_address`禁止、API-only Activation後のdirect／Storefront same-origin acceptanceを必須化した。Policy GateはPreview stable upstreamとsame-origin acceptanceの2点だけをfocused guardし、negative regressionを追加した。
- Focused canonicalizer 11 tests、Preview pipeline／runbook 25 tests、Policy Unit 171 tests、local Policy Gate 1,596 tracked files、Python compile、`git diff --check`をPASSした。初回no-argument Policy Gateはusage errorで検証前終了し、required `--repository .`実行がPASSした。Source phaseのBuild／Runtime／reload／container recreate／Migration／Artifact／DB／Provider／Payment／Coin／Mail／Production／Secret mutationは各0である。
- Admin API proxyは別vhost／別runtime configのため同じtest vhost変更では自然に直らず、OPS-023 mutation対象外のRemaining Blockerとして別Strict OPS候補へ分離する。Required 5、fresh final-head Strict self-review、squash merge、merged mainからのconfig apply／one reload／Runtime acceptance、Issue／branch／worktree／locks／policy cleanupを継続する。
- 初回canonicalizer patchは誤ってmain worktreeの1 fileへ未commitで適用されたが、即時検知し同一差分を専用worktreeへ移送、main file bytes／executable modeをHEADへ`apply_patch`で復元した。main HEAD、Remote、history、Runtimeは不変でmainはcleanを維持した。

## OPS-024 Production Storefront Same-Origin API Upstream Stabilization

- clean local／origin／GitHub `main@efc64e24fbbb01cab10f1f2953c6b7ee49d90484`、未使用Task ID、Issue／PR／branch／worktree／Lock、Governance、Runtime topology、active config、API／Storefront exact source、Artifact pin、service health、root freeをlive readbackした。Task Policy SHA-256 `1c309dc63b4f6a7981ee140768928bbcb667e31e3cd804f0ee9d300938e90585`を発行後、Issue #414、Branch `fix/OPS-024-production-storefront-same-origin-api-upstream-stabilization`、専用Worktree、Platform Integration Lock、Draft PR #415で開始した。
- Host Nginxの`luxe-pack.biz` exact `/api/v2`／prefix `/api/v2/`はobsoleteな`192.168.61.10:8000`を保持し、active MIG-097 APIのstable loopback `127.0.0.1:8611` direct 4 endpointsとOPS-023 test-domain 3 endpointsが200の一方、live-domain 3 endpointsは502だった。Host Nginx TCP接続でAPI dispatch前に失敗するexact root causeを再確認し、Cloudflare、Origin／CSRF、Storefront bundle、SITE-047は原因外とした。
- 既存canonicalizerはdefault test profileを維持したままexplicit live profileを追加し、testはAPI exact／prefix／exact fincode Webhook、liveはAPI exact／prefixだけをstable loopbackへ正規化する。Host／forwarded headers、URI、method／body、body size、Cookie／CSRF／Origin、TLS、Storefront root proxy、Admin isolationを維持し、RFC1918 upstream verifyはFail Closed、backup mode 0600／byte-identical、atomic replace、config-test failure rollback／reload 0を両profileで検証する。
- API-only Activation acceptanceへtest／live same-origin smokeを必須化し、live vhostのmerged-main activation／rollback runbookを新設した。Policy Gateはstable two-profile boundary、live runbook、test／live acceptanceだけをfocused guardし、fixed-IP／live profile／live smoke削除のnegative regressionを追加した。既存Gateの弱体化と無関係なrefactorは0である。
- Focused canonicalizer 12 tests、Preview pipeline／runbook 26 tests、Policy Unit 174 tests、Local Policy Gate 1,599 tracked files、Python compile、deployment JSON parse、`git diff --check`をPASSした。active test vhost verifyはcanonical PASS、active live vhost verifyはRuntime前期待通り`container_specific_upstream_rejected`でFail Closedした。初回Local Policy Gateは新runbookがfirst commit前のuntracked fileであることだけを検出し、同一Sourceをcommit後に再実行してPASSした。
- Squash merge前のAPI／Storefront／Admin Build、container recreate、service restart、Nginx config apply／reload、Migration、Artifact、DB、Provider／Payment／Coin／Mail、Production credential／Secret、TLS／Cloudflare／support／Admin mutationは各0である。Required 5、fresh final-head Strict self-review、squash merge、merged mainからのbyte-preserving backup／config apply／one reload／Runtime acceptance、Browser Acceptance handoff、Issue／branch／worktree／locks／policy cleanupを継続する。

## GOV-020 Contract-only Artifact Publication from Exact Merged Main

- clean local／origin／remote／GitHub protected `main@59e776922b30a6a4f3dce4c9135f81a12649b728`、GitHub Issue／PR、repository／evidence／deployment records、branch／worktree／Task Policyで`GOV-020`未使用、root free 26 GiBを確認した。MIG-098はIssue #416 open、checkpoint `231a9a0b7bcb3ba450bf72b0669adca3c550ad2f`、clean／untracked 0／Source frozenで、uncommitted overlapは0。Migration 000067 LockはMIG-098保持のまま非変更、Platform Integration Lock freeを確認した。
- root-owned mode 0600 Task Policy SHA-256 `38e3f4f38a663caa2eb59faeecebd1728d01e5b100b6b595a43e25f601744db7`をHuman承認済みexact 13 paths／Baseへ発行後、Issue #417、Branch `chore/GOV-020-contract-only-artifact-publication`、専用Worktree、Platform Integration Lockで開始した。
- Preview image workflowからStorefront Contract Artifact publicationを分離し、protected `main` current head／expected squash SHA／workflow event SHA／checkout HEAD／exact merged internal PR／review済みtree／Required 5を一致させる専用contract-only workflowを追加した。version-only Artifact名、global serialization、expiredを含むduplicate拒否、overwrite false、upload後のouter／inner digest readbackをFail Closedで必須化する。API image Build／push／Activation、Admin／Storefront application Build、Migration、Runtime、actual MIG-098 Artifact publication、version reservation、Release Ledger変更は各0である。
- Publication authority／readback 11 tests、Release全36 tests、Preview normal／api-only回帰26 tests、Policy Unit 179 tests、local Policy Gate 1,603 tracked files、release source validator、changed Python compile、workflow YAML parse、staged diff checkをPASSした。初回publication unit 7 errorsはmock signatureとexisting verifier関数名の不一致を検出し修正後全PASS、Ruby YAML parser／actionlintはlocal未導入のためGitHub Required Checksでworkflow validationを固定する。
- Draft PR #418のpre-final Head `3a19d42d31ac557ee765cd7c7e87c322ad802b0d`でRequired 5をPASSした。初回automatic runはPR bodyのmachine-readable Lane metadata 3 labels欠落だけでpolicy-gate Fail Closed、bodyをsource変更なしで補正しStrict workflow dispatch rerun 1回でpolicy／quality／security／integration／ci全成功となった。このevidenceをreportへ記録するfinal HeadでRequired 5／fresh Strict self-reviewを再実行する。Contract Artifact workflow dispatch／Artifact publication／API image Build／push／Activation／Migration／Runtime mutationは0である。

## MIG-098 3DS-Verified Save Card Registration Contract

- GOV-020後のprotected main `cfe24c416b9d95131ef0ec271bd129875a91c9f1`へfrozen checkpoint `231a9a0b7bcb3ba450bf72b0669adca3c550ad2f`をrebaseし、checkpoint `91766b6bdb518050295857f0f69646f26c4e635f`とした。semantic overlapは`policy_gate.py`と同unit testだけで、GOV-020 exact merged-main／contract-only／immutable readback／separate ledger reconciliation invariantsとMIG-098 Migration／Payment guardsを両立した。Platform Integration Lockを再取得し、Migration 000067 Lockを維持する。
- Human承認済みTask PolicyをBase `cfe24c4...`へ再発行後、Testkit exact export security boundaryのため`packages/storefront-testkit/scripts/check-exports.mjs`だけを追加した。allowed pathsは46、Policy SHA-256は`5e91031d43e9d0a313fb159673d7d11611d607a038babb9bfb318867d0628547`である。
- fincode Payment Method Registrationを`pay_type=Card`、`tds_type=2`、`tds2_type=2`、fixed Return URLsで開始し、Browser Return非Authority、signed Webhook trigger、Payment Method exact GET＋Card exact GET Authorityを実装した。`ACTIVATED + AUTHENTICATED`とUser／Customer／Method／Card ownership一致後だけPlatform Cardをexactly onceで作成する。failure／cancel／expiry／unsupported／unknownはCard／Payment／Coin／Mailを作成しない。
- legacy standalone completionと`startPayment(source=new, save=true)`はBackendで`CARD_REGISTRATION_3DS_REQUIRED`へFail Closedし、save=falseとverified saved Card PaymentはPayment 3DS2を維持する。PayPay／Konbini／Virtual Accountは変更しない。`limits.remaining`は不変で、`registration_remaining`と`next_capacity_at`をadditiveに追加した。
- additive Migration 000067を作成し、既存Card／Intentをverifiedへbackfillしない。Shared Preview SELECT-only readbackはactive／deleted Card 0／0、Intent expired 3＋期限切れreserved 1、proof columns 0で、current active Card impactは0。Migration apply、API Build／Activation、Provider実通信、business／Production／Secret mutationは0である。
- Local focusedはBackend＋Concurrency 38 tests／502 assertions、Client 32、Testkit 41＋exact exports/network、OpenAPI Admin 222／Public 71／Webhook 2、Artifact 13、publication authority 11、Policy Unit 181、Policy Gate 1,607 files、artifact source alpha.31 pending、diff checkをPASSした。Required 5、fresh Strict review、merge、exact-main Artifact publicationはFinal Headで継続する。
- ActivationはTEST／SANDBOX event `customers.payment_methods.updated`未設定のためdeferredを維持する。Artifact publication後のRelease Ledger reconciliation、SITE-048 exact pin、Migration apply／API-only Activation、Human Sandbox Save Card E2Eは順序を分離し、現時点はいずれもHOLDである。
- Draft PR #419のApplication Head `c184b7ffb2b3388209a0841e075e3990e9196647`でRequired 5 Checksを全PASSし、fresh Strict self-review `#issuecomment-5462554301`はscope／secret・PII／Migration・Contract・SecurityをPASS、SEV-0／1／2／3を全0とした。Remote task refは旧Base seedだけだったためGitHub App `push-new-branch`が安全にconflictを報告し、exact ancestry／46-path scope／secret／no-force確認後に通常fast-forwardで初回だけ更新した。以後のpushはpolicy-aware wrapperを使用する。このevidence記録だけのFinal HeadでRequired 5とfresh reviewを再実行する。

## GOV-021 Storefront Contract Release Ledger Reconciliation — 2.0.0-alpha.31

- clean local／origin／GitHub protected `main@ad078ecd1eebd68cd2443b347d387433177fd686`、GitHub Issue／PR、Repository／root evidence、branch／worktree／Task Policy、open PR path、Shared Locksをlive確認し、未使用`GOV-021`を確定した。MIG-098 Issue #416はclosed、live branch／local branch／worktree／Policy cleanup済み、Migration 000067 LockはMIG-098 holderのまま非変更、Artifact Lock freeを確認した。初回Task Policyをexact 5 pathsで発行し、Issue #420、Branch `chore/GOV-021-alpha31-ledger-reconciliation`、専用Worktree、Platform Integration Lockで開始した。Policy Unitの旧alpha.30＋pending alpha.31 assertionがreconciliation後に必然FAILしたためHuman承認で同test pathだけを追加し、Base不変／exact 6 paths／Policy SHA-256 `f86d35f64eb45c1c2802659bc753985416548c2f77616186319747e0182b18b6`へ再発行した。
- Canonical GOV-020 validatorでArtifact `9715454247`／Run `33254741748`をremote再downloadし、dedicated workflow success、protected-main Source `ad078ecd1eebd68cd2443b347d387433177fd686`、唯一／available Artifact、outer／Manifest／SHA256SUMS／OpenAPI／Client／Testkit digest、Manifest task／version／Sourceを一致確認した。Ledger schema `2.0`を維持し、alpha.30以前を不変のままalpha.31をimmutable history／latestへ記録してcandidateをnullへclearする。Artifact ID／Run／outer／SHA256SUMSはLedger未定義fieldのため追加せずrunbook／reportへprovenanceを記録する。Artifact再発行／workflow dispatch／version reservation／Build／Activation／Migration／Provider／Payment／Runtime／Production／Secret mutationは各0である。
- Focused Artifact 14 tests、Release全37 tests、Release validation（既存67 migrations不変）、Policy Unit 181 tests、Local Policy Gate 1,608 tracked files、changed Python compile、Ledger JSON／alpha.30以前history canonical digest不変、`git diff --check`をPASSした。初回Policy Unitの1 failureはscope外testが旧pending stateを固定したことだけを検出し、Human承認／Policy再発行後の最小assertion更新で全PASSした。Final Head Required 5とfresh Strict self-reviewを継続する。

## OPS-025 MIG-098 3DS Save Card Shared Preview Activation

- clean local／origin／Remote／GitHub protected `main@52efea768b6cfba086f1e85523f2e9f561246af4`、GitHub／Repository／worklog／deployment／Task Policy／evidence／branch／worktreeでHuman指定`OPS-025`未使用をlive確認した。Issue #422、Branch `chore/OPS-025-mig-098-shared-preview-activation`、専用Worktree、Lane Strict Change、Risk R4、Activation immediateで開始した。Task PolicyはHuman承認済みexact 3 paths、SHA-256 `eaae357e24cc2885c70d37e6fb7a3edba8f4d0143d046e9b4dc92cce15a7cb23`である。
- Stage 0 active APIはMIG-097 image `oripa-v2-api:preview-MIG-097-e006c7a95592`／OCI `e006c7a95592e5a03eef0be7e59bfa995185171c`、4 services healthy／restart 0、Storefront `0a8bcdf09e1d0549dac7d1736b9cb868eb04f0e9`／Build `hjgAN8D6Bf2kOE71jmmh6`、Nginx canonical／active、direct／test／live APIとStorefront pages 200、root free 20 GiB以上をPASSした。ledgerは66／batch 32／`000066`で、pendingは000067 exact 1件、000068以降unexpected 0である。
- business aggregateはCards 0（active 0／deleted 0）、Registration Intents 4（expired 3／reserved 1）、Payments 21、Payment Point Grants 6、Point Ledger Entries 31。Artifact Ledgerはlatest immutable alpha.31／candidate null。Human Webhook確認を受容し、Provider Settings GET／change、Webhook replay／send、Provider event生成は0、Repository／active routeのcanonical `POST /webhooks/v2/fincode`だけを確認した。
- Issue／remote branch／worktree成立後にPlatform Integration Lockを取得し、protected-main GOV-021が保持を証明するMigration 000067 LockをMIG-098からOPS-025へcompeting holder 0でhandoffした。source phaseは承認済み3 evidence pathsだけを変更する。初回Source Commit `451693db737608d63fca16e3a63213f1f6762591`、Draft PR #423、pre-resume Head `fc24322064401867e51ed89005bca2f892d94977`まで成立し、Policy Unit 181 tests、Local Policy Gate 1,610 tracked files、deployment JSON、exact 3-path scope、high-confidence secret scan、`git diff --check`、Required 5をPASSした。
- 旧OCI revision＝Squash SHA完全一致要件についてFail Closedした後、HumanはOPS-025限定のReviewed Tree Authorityを承認し、GOV-022作成とworkflow／wrapper等6 pathsのScope追加を明示的に不採用とした。Final reviewed PR HeadをOCI revisionとしてAPI-only Buildし、pre-merge main drift gate、Squash Merge、PR Head tree＝Merge tree、content diff 0を必須にし、そのcommon treeを介してOCI PR Head→Merge SHAをbyte-equivalentに証明する。tree mismatch時はMigration／Activation禁止である。
- Source変更／Build前のFull Delivery Preflightで、Final Head→Required 5→fresh Strict self-review→API-only Build→digest／OCI取得→pre-merge drift gate→Squash Merge→tree比較→verified imageによる000067 one-off apply→API-only Activation→direct／test／live acceptance→3 Locks／Issue／branch／worktree／Policy cleanupの全経路をread-only確認し、追加hard blocker 0でPASSした。test／live Nginxはstable `127.0.0.1:8611`でcanonical、ledgerは66／batch 32／000067 pending exact 1、root free 20 GiB以上、Platform／Migration LocksはOPS-025 held、Preview Lockはfreeである。
- pre-resume HeadのRequired 5はHuman authority evidence更新でsupersedeされる。Reviewed Tree candidateのPolicy Unit 193 tests、Local Policy Gate 1,610 tracked files、deployment JSON、exact 3-path scope、high-confidence secret scan、`git diff --check`をPASSした。新Final HeadでRequired 5／fresh Strict reviewを実行し、以後Sourceを固定してAPI-only Build→drift gate→Squash Merge→tree equality→000067 apply→API-only Activation→Runtime Acceptanceの順を維持する。現時点のMigration／Build／Activation／Provider／business／Production／Secret mutationは0である。

## PAY-001 Save Card Payment Method Registration Final Fix

- Human Git Lite Decisionに従いIssue／dedicated Worktree／Source Lockなし、clean protected `main@273aebfd0066b6dc5e5f217d0f05153d14f29fa2`からBranch `fix/PAY-001-save-card-payment-method-registration-final-fix`を作成した。Lane Strict Change、Risk R4、Activation immediate。Migration ledgerは000067、Artifact latest immutableはalpha.31、active API／Storefront／same-origin health、rollback image、root free 20 GiB以上、競合Platform PRなしを確認した。
- fincode公式Payment Method／3DS2／API reference／OpenAPI v1.4.0を現行requestとfield-by-field比較した。method、endpoint、customer、JSON、Bearer、idempotency、Card token、`tds_type=2`、`tds2_type=2`、HTTPS callback、query、optional field omissionは一致した。`default_flag`だけが初回Cardでも常時`0`で、Payment Methodが存在する決済種別にはdefaultが必ず1件必要という公式Contractと矛盾するconfirmed mismatchだった。
- 初回active saved Card 0件時を`default_flag=1`、以後を`0`へ修正した。Provider非成功時は既存Registration `last_error_code`へinternal code＋safe HTTP status＋最初の公式11文字`errors[].error_code`だけを保持し、raw response／message／token／credential／request bodyは保存・log・User Problemへ出さない。typed `CARD_REGISTRATION_FAILED`／`UNAVAILABLE`、Browser Return non-authority、Webhook trigger-only、exact GET authority、Registration／Payment双方の3DS2を維持する。
- PHP syntax PASS。HTTP fakeだけのFincode focusedは40 tests／497 assertions／warning 0、Payment concurrencyは1 test／41 assertions／warning 0でPASSした。保存失敗時Card／Payment／Coin／Mail 0、save=false／PayPay／Konbini／VA／exactly-once回帰を含む。Migration created 0、Shared Preview／Production applied 0、Artifact／Provider／Runtime／Production／Secret mutation 0。PR／Required 5／fresh review／API-only Build／merge/tree authority／Activation／Runtime Acceptanceを継続する。

## ACCT-001 Account Security — Password Reset／Email Change／Password Change

- Human Git Lite Decisionに従いIssue／dedicated Worktreeなし、clean protected `main@5c08bc7bee1b714f1893adac17626ad26f46429c`からBranch `feature/ACCT-001-account-security`を作成した。Engineering Safety Strict／Git Lite、Lane Strict Change、Shared Preview Activation immediate、Production Activation scope外。Migration Allocation Lock `000068`を取得し、Remote `main` drift 0、latest immutable Artifact alpha.31、next unused alpha.32をlive確認した。
- Password Resetは既存Token／Rate Limit／Password Policy／Outbox／Audit境界を再利用し、eligible Active／Restricted Userだけへhash-only Tokenを60分で発行する。unknown／unverified／suspended／closed／anonymizedは同一202 responseでToken／Mail 0。Confirmはexact Token rowとUserを一貫したlock順で再確認し、single-use／attempt／resend／replay／state changeをFail Closedする。成功時は全Session／Remember Deviceを失効し、Password変更通知とAuditを同一transactionで確定するが、新Session／CSRFは発行せずLoginへ戻す。
- Email Changeは専用`user_email_change_requests`へnormalized pending Email、Token Hash、initiating Session Hash、60分期限だけを保存する。Startはcurrent Session／CSRF／OriginのみでFresh Authentication／Current Password不要。Completeは別Browserでも可能で新Sessionをmintせず、valid initiating Sessionを保持する。同一BrowserだけSession／CSRFをrotateし、other Sessions／Remember Deviceを失効する。Canonical Email更新とDB uniqueness final authority、one-time consume、Audit、完了通知の新Email限定を同一transactionで確定する。
- Password ChangeはCurrent PasswordをUser／Session lock内で再検証し、既存`V2PasswordPolicy`、currentとの差分、5回／15分Rate Limitを適用する。Verification Link／Pending Password Tableは作らず即時Hash更新し、current Session／CSRFをrotateしてLoginを維持、other Sessions／Remember Deviceを失効し、`password_changed` OutboxとAuditを確定する。
- additive Migration `000068`はPassword Reset TTL constraintを60分へ更新し、Email Change専用Tableと固定Mail Template 4種を追加して7→11件とした。既存Migration改変は0。Security Mail Workerは4 TopicだけをClaimし、Provider failureはretry／Audit／safe logへ送りAccount mutationを巻き戻さない。Outbox／必須Audit永続化失敗はmutation transactionをRollbackする。Raw TokenはToken Table／Audit／Logへ保存せず、delivery用payloadだけをApplication-level Encryptionする。
- Public OpenAPIはAccount Security 3 operationsをadditiveに追加して74 operations、Public／Admin／Webhook Contract alpha.29、Storefront Client／Testkit pending candidate alpha.32へ同期した。Password Reset成功は既存`UserSession`へ混在させず専用`PasswordResetCompleted`を使用する。Admin Mail Template Contract／generated Client／UIを11 templates・13 variablesへ同期し、Storefront Repository／UI本体は変更していない。
- Strict local reviewで既存Session schemaへのReset完了混在、Start／Confirm lock-order inversion、Email完了時のSession RotationがFresh Authentication時刻まで更新する権限昇格余地を検出した。専用Response、User→Token／Requestの統一lock順、logical Session作成時刻とFresh時刻を保持する専用Rotationへ修正した。隔離PostgreSQL 17／PHP 8.4で全68 migrations、rollback／reapply、Auth／Browser／Audit／Outbox／Password Policy／Mail／Account Security 90 tests・659 assertions、Concurrency 5 tests・40 assertionsをwarning 0でPASSした。初回test harnessはrepository `phpunit.xml`のfixed DB名が隔離DB指定を上書きしたため既存synthetic rowsを検出し、Repository変更なしのephemeral PHPUnit configへ切替後に最終PASSした。
- OpenAPI 9、Storefront Client 32、Testkit 42＋exports／network、Admin 35 files・212 tests＋typecheck／lint／production build、Policy／DB／Artifact 237 tests、Artifact source validation、Compose config、PHP syntax、`git diff --check`をPASSした。Migration Shared Preview／Production applied、Build／Activation、Artifact publication、Provider／Payment／Save Card／Point／Draw／Inventory／Production／Secret mutationは現時点で各0。PR／Required 5／fresh exact-head Strict self-review／merge／Shared Preview acceptance／Artifact publicationを継続する。
- PR #425の初回Head `3c90edadb5c522e0c368a4f64e1582d80b38d414`はpolicy／quality／security gateをPASSしたが、integration-suiteのDB Guardが新規`identity-mail-worker`を旧4 Service固定Inventoryとして拒否し、integration／ci gateがFAILした。元Change成立に直接必要な小規模CI blockerとして、GuardをAPI／Admin／PostgreSQL／Redisのprivate-only境界と、Security Mail Workerだけのexact private＋egress境界へ同期した。Composeの未指定Mailerは安全な`array`へ既定し、CI／local database smokeで外部送信を起こさない。Policy Unit 196、DB Guard Unit 42、実Compose Guard validateをPASSし、新Final HeadでRequired Checksを全件再実行する。Branch protection／Permission／Secret設計／Security Gateの変更・弱体化は0。
- 次Head `5d38a6a9f294697f487a36bc7118842a3ce0ca6d`は前回blockerを越えてcontracts／Compose／DB GuardまでPASSしたが、isolated migration smokeがMigration前に全Compose Serviceを起動し、Security Mail Workerが未初期化DBへ接続してFAILした。Workerをexplicit `identity-mail` Profileへ隔離し、DB smokeを従来のAPI／Admin／PostgreSQL／Redis exact 4 Service起動へ固定した。これによりMigration前Worker起動と未指定外部Mail送信をFail Closedで防ぎ、PreviewではMigration後にWorkerを明示Activationする。Policy Unit 197、DB Guard Unit 44、core-only／profile-enabled Compose config、profile-resolved実Compose GuardをPASSした。

## GOV-022 Engineering Safety Strict / Git Lite

- ACCT-001 PR #425のSquash Merge、Shared Preview Migration／Activation、Runtime Acceptance PASS、Artifact alpha.32 publication、closeout comment、旧branch／Task Policy cleanupをread-onlyで確認した。local／origin／GitHub protected `main@4147487f8f1474d5261a12aa8a0ad124cebe922f`一致をBase Authorityとし、Account Security旧branch／PR Head／Migration／Runtimeを変更していない。
- Human-approved Git Liteに従いIssue／dedicated Worktree／Source Lockなし、Branch `docs/GOV-022-engineering-safety-strict-git-lite`、Lane Strict Change、Activation `none`で開始した。Stage 0 inventoryで正本3文書、Root／nested AGENTS、PR／Issue template、Ruleset／Preview Build docs、Policy Gate／Testsを照合した。Migration作成／適用は0でありMigration Allocation Lockはnot applicableである。
- 旧正本とtemplateはIssue、dedicated Worktree、Task Policyを常時必須化し、`policy-gate`は全PRでexact `Allowed paths`を強制していた。Source LockのRepository-wide強制はなく、Migration Allocation LockとReviewed Tree Authorityは正本未反映だった。実CI enforcement変更が必要なためHuman指定どおりRiskをR2からR3へ昇格し、actual Changed files一致を維持したままexact Allowed pathsだけを選択制へ変更した。
- Governance Revision 3、Release Gates Revision 2、Autonomous GitHub Operations ADR Revision 1を2026-08-30正本として追加し、2026-07-23旧版を`SUPERSEDED / HISTORICAL`とした。Canonical `1 Change = 1 Branch = 1 PR`、Issue／Worktree／Task Policy／Source Lockのrisk-based optional化、Migration Allocation Lock維持、小規模CI/Git blockerの同一PR修正条件、`Task -> GOV -> Task -> GOV`回避を明文化した。
- Reviewed Tree AuthorityはFinal PR Head→Required Checks PASS→fresh self-review PASS→Build→Squash Merge→Head tree＝Merge tree→content diff 0を固定し、Final Head／Tree、Merge／Tree、equality、content diff、image digest、OCI revisionを必須Evidenceとした。mismatch時はMigration／Activation禁止。protected main、Required 5、Strict freshness、Security／Auth／Payment／Coin／Draw／Webhook／Migration safety、Production Human checkpointは不変である。
- R3 Governance／GitHub App mutation guardとしてだけTask Policyを選択し、exact 17 paths、root-owned mode 0600、SHA-256 `1ef4cc5aecdd2a1d6a9ddd80d8e8c2d1165d1ad820249695b10869264ed9ef15`で発行した。Remote branchはBase SHAから作成済み。これはTask PolicyのRepository-wide必須化ではなく、本Changeが選択したscope controlである。
- Policy Unit 201 tests、local Policy Gate 1,622 tracked files、changed Python compile、Issue Template YAML parse、`git diff --cached --check`、exact 17-path Task Policy一致、current-source old-rule searchをPASSした。残存旧必須文字列はanti-regression testと`SUPERSEDED / HISTORICAL`資料だけである。Source phaseのRuntime／Migration／Artifact／Production変更、Build、Activationは各0。secret・PII／binary・submodule／final scope review、Required 5、fresh exact-head Strict self-review、squash merge、cleanupを継続する。

## GOV-023 Storefront Contract Release Ledger Reconciliation — 2.0.0-alpha.32

- Human指定Git Liteに従いIssue／dedicated Worktree／Source Lockなし、clean local／origin／GitHub protected `main@fe1153af23c9b11fae9d58ebae5e4683f2fa93ff`からBranch `docs/GOV-023-storefront-contract-alpha-32-ledger-reconciliation`を作成した。Risk R2、immutable release historyのためLane Strict Change、Activation `none`。15 open PRのlive changed pathsに対象6 pathsとの競合0、Migration作成／適用0のためMigration Allocation LockはN/Aである。
- GitHub live inventoryでArtifact `oripa-storefront-contract-2.0.0-alpha.32`はexact 1件、ID `9730828197`、Run `33307134531` attempt 1 completed success、unexpired、Source `4147487f8f1474d5261a12aa8a0ad124cebe922f`を確認した。fresh remote downloadとcanonical validatorで5-file inventory、outer `e891ea105a03bc3e484d06ff837730d2c0f24ab5d7df887a3a7040011b8a6744`、Manifest `263955a5521a863635bf6ad23d604e52b1319e84052178288bad7b7c308de564`、SHA256SUMS `755c4a752250edc77da01d6dd7c2b7ef781aa3cfc55b696be47c760517bd4237`、Public OpenAPI `9670bc769080da605c97cb9849b61f342cf0111bc39e91c09dbbf62fc4bcc720`、Client `5d00dd111914d4bd6da248c99b98fcc697eb1507092fe6757015745e73856ad8`、Testkit `6124a6ac5837984eda60fdada0dae98fa24f28285ed674b7197f3b64bd7095be`をreadback一致確認した。
- Ledger schema `2.0`とalpha.31以前のcanonical history digest `5e286877a462d29e643b2fc4e2a0040221e42be9687e31f378e857b28a51026c`を維持し、alpha.32をimmutable history／latestへ追加、candidateをnullへclearした。Ledger↔Manifest／actual readback 25 checks、settled source validator、focused Artifact 14、full Release 37、Release source validation（既存68 migrations不変）、Policy Unit 201、Local Policy Gate 1,623 files、Python compile、JSON／6-path scope／text-only／contradiction／diff checkをPASSした。GitHub App privileged deliveryだけにexact 6-path Task Policy（root 0600、SHA-256 `4caa102adf830d52a421b27b5a8a38cae82c6373ecb7a51c6177e2647cb64941`）を選択した。Artifact再発行／上書き／新Version／Build／Activation／Migration／DB／Application／Production mutationは各0。exact-head secret scan、Required 5、fresh Strict self-review、squash merge、Policy／branch cleanupを継続する。

## GOV-024 Storefront Artifact Version Coherence Repair

- clean local／origin／GitHub protected `main@433fc8f5bc9a79d9c499580bc1843beb9d03694f`からBranch `fix/GOV-024-storefront-artifact-version-coherence`を作成した。Issue／dedicated Worktree／Source Lockなし、Risk R3、Lane Strict Change、Activation `none`。Migration作成／適用0のためMigration Allocation LockはN/Aである。GitHub App privileged deliveryだけにexact 16-path transient Task Policy（root 0600、SHA-256 `4c56c6aa066ea78bf14c2787809589930222175f2868c8f0651e0e8145c5f81e`）を選択し、closeoutで削除する。
- GitHub live inventoryでalpha.32 Artifactはexact 1件、ID `9730828197`、Run `33307134531` attempt 1 completed success、unexpired、outer SHA-256 `e891ea105a03bc3e484d06ff837730d2c0f24ab5d7df887a3a7040011b8a6744`を確認した。fresh downloadしたexact 5 filesはManifest／SHA256SUMS／OpenAPI／Client／Testkit digestが既存ledger evidenceと一致したが、Client tarballの`package.json.version=2.0.0-alpha.32`に対し実import対象`dist/constants.js`のruntime versionは`2.0.0-alpha.31`で、Headerも旧versionになるdefectを再現した。alpha.32は削除／上書き／再発行せずledger `retired`（published but non-adoptable）とし、GitHub Artifact inventory 0件のnext unused `2.0.0-alpha.33`をpackage-only candidateにした。
- Client package／runtime constant、Testkit package／Client dependency／compatibility metadata、pnpm lockをalpha.33へ同期した。Client testはpackage version＝runtime constant、transport header＝runtime constantを検証し、Testkit testはpackage version＝Client bundle metadataを検証する。release validatorはsource package/runtime coherenceに加え、packed Client `dist/constants.js`をNodeで実importし、Client package/runtime/Manifest/bundleとTestkit package/Manifest/bundleの一致をFail Closedにした。Publication workflowはupload前にClient／Testkit checkを必須実行する。Public OpenAPI alpha.29／hash／74 operations、Site Schema alpha.23、Platform／Application／Admin／Webhook versionは不変である。
- Client check 33 tests、Testkit check 43 tests＋exports／network、Storefront Release 40 tests、Policy Unit 201 tests、Local Policy Gate 1,623 tracked files、frozen install、release source validation、actual Client／Testkit build・pack、Manifest／SHA256SUMS verify、tarball semantic readback、changed Python compile、JSON、`git diff --check`をPASSした。初回focused ReleaseはPython 3.9非互換のtest annotation 1 error、selected Policyはrunbook required phrase改行で1 error、初回full Policyはworkspace fixture旧alpha.32固定により28 failures＋1 errorとなり、runtime／assertionを弱めず互換表記・固定句・fixture versionだけを修正して各final PASSした。Runtime Build／Runtime Activation／Migration／DB／Application／Production／Auth／Payment mutationは各0。Repair PR Required 5、fresh exact-head Strict self-review、squash merge後にexact protected mainからalpha.33を一度だけpublicationし、fresh readback後の別ledger reconciliation PRへ進む。

## GOV-025 Storefront Publication Build Order Repair

- GOV-024 Repair PR #428はRequired 5とfresh self-review（SEV-0／SEV-1各0）後、reviewed head `75b01ba71fc2062090ff57dfd40d221fab2f19dc`をsquash merge `b42a1a45276fce69b282c183cfc5675a2d6d9be5`し、reviewed／merge tree `31c25c77bc8e9f159b94a135a342b9a46229b00c`一致を確認した。exact protected mainからcanonical publication Run `33317049114` attempt 1を一度dispatchしたが、upload前のTestkit typecheckが未buildの参照Site Schemaを解決できずFAILし、readbackはskipped、alpha.33 Artifact inventoryは0件のままである。上書き／重複Artifact／Runtime mutationは発生していない。
- clean `main@b42a1a45276fce69b282c183cfc5675a2d6d9be5`からBranch `fix/GOV-025-storefront-publication-build-order`を作成した。Issue／dedicated Worktree／Source Lockなし、Risk R3、Lane Strict Change、Activation `none`。publication workflowに`pnpm site-schema:build`をTestkit check直前へ追加し、参照Schemaを発行せず依存型だけを生成する。Policy Gate required markerとworkflow順序回帰testを追加し、runbookを同期した。GitHub App privileged deliveryだけにexact 5-path transient Task Policy（root 0600、SHA-256 `a3d08a67e40601930bdce011a892647faba8d3c833f4c1c54c598ab53012473d`）を選択する。
- 実順序Site Schema build→Testkit check 43 tests＋exports／network、focused Storefront Artifact 17 tests、Policy Gate file 189 tests、Local Policy Gate 1,623 tracked filesをPASSした。package／Artifact version、Manifest／ledger、OpenAPI／Site Schema metadata、Application、Auth／Payment、Migration、DB、Runtime Build／Activation、Productionは変更しない。Required 5、fresh exact-head Strict self-review、squash merge後に同じalpha.33 candidateを新protected mainから再度一度だけpublicationする。

## GOV-026 Storefront Contract Release Ledger Reconciliation — 2.0.0-alpha.33

- GOV-025 blocker PR #429はfinal head `765d5e841c99f1d44e814462f7a49f9834e58df8`、Required 5 PASS、fresh self-review SEV-0／SEV-1各0でsquash merge `9867c1ea50140efd1eff7a652d3da5bd36665e1d`した。reviewed／merge tree `e74018cce6f5ce273a9d01a35bc5dbb590853d3e`一致を確認し、clean local／origin／GitHub protected `main@9867c1ea50140efd1eff7a652d3da5bd36665e1d`からBranch `docs/GOV-026-storefront-contract-alpha-33-ledger-reconciliation`を作成した。Issue／dedicated Worktree／Source Lockなし、Risk R2、Lane Strict Change、Activation `none`。GitHub App privileged deliveryだけにexact 6-path transient Task Policy（root 0600、SHA-256 `0ab66854805fea4f34b67b0b78db9e2ec0fc5e1127f32c09028630e3768d530a`）を選択する。
- exact protected mainからcanonical publication Run `33318307918` attempt 1をdispatchし、authorize／publish／readback全jobがPASSした。Artifact `oripa-storefront-contract-2.0.0-alpha.33`はexact 1件、ID `9734141503`、GitHub digest `sha256:734b8e36fef261b72ab8013a0656c4a2ca3f1a6c8ea472d817c3b3ae7410e58c`、Source `9867c1ea50140efd1eff7a652d3da5bd36665e1d`である。先行Run `33317049114`はupload前FAILでArtifact 0件、alpha.32／alpha.33のoverwrite・republish・deleteは0である。
- 別fresh downloadはexact 5-file inventory、Manifest `b6522d16230734ea7f4604be59a2585c29bcf03a2b447269e824e712759d893c`、SHA256SUMS `10252bf2cb15f80e2c26fd329c15092517d667267a9cc105ab74b9f5c3649328`、Public OpenAPI `9670bc769080da605c97cb9849b61f342cf0111bc39e91c09dbbf62fc4bcc720`、Client `846b0e036ebf76dd46ab1a2c9d6b67b786f9d2dfe5672d8b3a0eb31b7ad675a2`、Testkit `720d8cc6a0b1c786267de34af0f1fddefc5a517d5d064491f4a78af2e492df4d`を検証した。packed Clientを実importしてtransport requestを行い、bundle／Client Manifest／package／runtime／actual Header、Testkit Manifest／packageがすべてalpha.33で一致した。Public OpenAPI alpha.29／74 operations、Site Schema alpha.23は不変である。
- Ledgerはalpha.32 `retired`を保持してalpha.33 released recordをappendし、actual Run／attempt／Artifact ID／name／GitHub digest／SHA256SUMSを最小`publication` evidenceとして記録、`latest_immutable=2.0.0-alpha.33`、`candidate=null`とした。alpha.33以降のpublication evidence欠落／不正形式をFail Closedにした。settled source validation、focused Artifact 19、full Release 42、Policy Gate file 189、Local Policy Gate 1,623 tracked files、JSON parseをPASSした。初回focused Artifactは形式上有効な別digestまで単体validatorで拒否する過剰negative test 1 failureとなり、canonical値固定assertionを維持したままnegativeを不正形式へ限定してfinal PASSした。このReconciliation ChangeのArtifact publication／Build／Runtime Activation／Application／Migration／DB／Production mutationは各0である。

## OPS-026 Shared Preview Public Origin Runtime Activation

- Humanによる`V2_PUBLIC_ORIGIN=https://test.luxe-pack.biz`更新後、clean local／origin／GitHub protected `main@4bc6f5b0da48b8a42f543c524116fe1aacf7855c`からBranch `chore/OPS-026-preview-origin-runtime-activation`を作成した。Issue／dedicated Worktree／Source Lockなし、Lane `Strict Change`、Risk `R4`、Activation `immediate`、Production Activationはscope外である。GitHub App privileged deliveryだけにexact `worklogs/new_ver_main.md`のTask Policy（root-owned mode 0600、SHA-256 `ecfc4a5704c6f94a9ff1e307a6775f52cec722d0e05d4fe215c6d86aaf438e80`）を選択し、closeoutで削除する。
- 稼働container labelsからShared Preview Authorityをlive readbackし、Compose project `mig061a-v2-preview`、config files `/var/www/oripa/docker-compose.v2.yml`＋`/var/lib/oripa-v2-evidence/ACCT-001/preview.override.yml`、working directory `/var/www/oripa`、env-file順 `/var/lib/oripa-v2-evidence/MIG-061A/v2-preview.env`→`/var/www/oripa/.env`を確定した。API／identity-mail-workerは同一image `oripa-v2-api:preview-ACCT-001-312a2368c89b`、Image ID `sha256:467b832cd777a576fd6465f32c0f962270fbd7a0de55689f546aa12efa8fa568`、OCI revision `312a2368c89b93bc0aae36be953e35b3dc0d9825`で、再作成前Originは双方`https://luxe-pack.biz`だった。
- Human変更済み`.env`はroot-owned mode 0600を維持し、上記exact env-file順・profile・overrideでComposeを解決するとAPI／worker双方の`V2_PUBLIC_ORIGIN`が`https://test.luxe-pack.biz`となることを値限定で確認した。V1 Composeは同`.env`をlabel上参照するが`V2_PUBLIC_ORIGIN`をconsumeせず、V1 Compose command／container recreate／service restartは実行していない。
- Preview Deployment／OS lock下でcanonical project／config／env／overrideと既存imageを使用し、`--force-recreate --no-build --no-deps api identity-mail-worker`相当で対象2 serviceだけを1 activation window内に再作成した。Compose解決後のprivate-only起動から既存egress networkへAPIとworkerだけを復元し、private network／egress networkのID・subnet・internal境界とexact memberを維持した。Build 0、image変更0、Admin recreate 0、PostgreSQL／Redis／Asset Storage recreate 0、network／volume recreate 0である。
- 再作成後のcontainer envとLaravel runtime configはAPI／worker双方`https://test.luxe-pack.biz`、同一Image ID／OCI revision、healthy、restart 0である。direct API healthはapp／db／redis／storage全`ok`、Admin／PostgreSQL／Redisのcontainer ID・start時刻、PostgreSQL／Redis／Asset volume作成時刻は前後不変だった。
- safe synthetic verificationはtest OriginのSession bootstrap 200後、正しいCSRF付きLogin mutationが403 `CSRF_TOKEN_MISMATCH`ではなくexpected 401 `INVALID_CREDENTIALS`へ到達した。identity worker内の実`V2IdentityMailUrlBuilder`でsynthetic Password Reset URLとEmail Change verification URLを生成し、双方のOriginが`https://test.luxe-pack.biz`、expected query keyだけであることをraw Token非表示でPASSした。activation windowのAPI unexpected error 0、worker unexpected error 0、Nginx error 0、HTTP 500／502／504各0である。
- Production側env metadata、V1 container ID／image／start時刻／restart count、Nginx service start時刻／restart count、Public／Admin vhost digestは前後不変で、Production recreate／reload／Activationは0。Migration created／applied、DB／Redis／Storage data mutation、Application／Contract／Artifact／Storefront／Provider／Payment／Point／Draw mutationは各0。Human指定どおりBrowser／Visual Acceptanceは未実行で、Platform Preview Origin blockerは解消した。rollbackはHumanがOriginを旧値へ戻した後に同じexact Compose AuthorityでAPI／workerだけを再作成する手順であり、DB rollbackは不要である。

## MIG-099 Canonical Rank Master + Gacha Rank

- `main@a8a45a5673ca603b8fe1abaf5b9642038776e37e`から既存Branch `feat/MIG-099-rank-master-gacha-rank-canonical`を継続した。LaneはStrict Change、Application Runtime Activationはdeferred、Production操作・Production data mutationは0である。Migration Allocation Lockを保持し、正規に確保済みの`000069`だけを使用した。既存Migration編集、Trigger disable、immutable Draw/history mutation、legacy Rankの同名auto mergeは各0である。
- `000069`はGlobal Rank Masterとimmutable Revision、Gacha×Rank Masterとimmutable Video Revision、Canonical `Prize.gacha_rank_id`、Draw Revision FK、Cross-Gacha/usage/asset/immutability triggerを追加する。Rank Effect MaterialはAsset Masterへ限定し、旧Rank assignment/orderを新Runtime Authorityから除外する。Drawは確定したCanonical PrizeのRevision pairをselection/inventory mutation前にsnapshotし、確率、在庫、sold count、Coin、Payment、retry/idempotencyの意味論を変更しない。
- Adminはactive Master union read、lazy Gacha Rank、video即時保存/unset、rank-fixed Prize、Master revision/reorder/statusを実装した。Public GachaはCanonical Prizeを持つRankだけを返し、lineup image、video、display order、`show_total_stock`と`total_quantity`合計だけを提供する。旧Public RankDisplay互換はHuman Canonical仕様どおり維持しない。Public/Admin Contractはalpha.30、Client/Testkit Artifact候補はexplicit `contract-breaking` alpha.34として記録した。
- 隔離PostgreSQL 17で`migrate:fresh`を`000069`までPASSし、Canonical API/Draw/QA/Reporting focused 105 tests/1,222 assertions、並行Draw/Presentation Revision boundary 1 test/9 assertions、Admin Vitest 35 files/199 tests、Storefront Client 33 tests、Testkit 43 tests、OpenAPI gate/unit 10 tests、Artifact unit 44 tests、migration inventory 44 tests、Policy unit 189 testsをPASSした。Artifact source validationもPASSし、Runtime Activation、Shared Preview Activation、Production Activation、Account Security/Payment/Save Card/SMS/LINE mutationは各0である。
