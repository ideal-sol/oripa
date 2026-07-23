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

- Implementation Commitは`be58b9ab21cbaf17cb3634ae127c3c2e491b6427`で、ParentはBase SHA `0efd04ec8283ef8a084b6b7d7eddbfcea2d1bd4d`である。
- GitHub App WrapperでRemote Task BranchへFast-forward Pushした。Direct main Push、Force Push、Archive Ref変更は行っていない。
- PRは`#56` (`https://github.com/ideal-sol/oripa/pull/56`)、Authorは`ideal-sol-oripa-codex[bot]`、Draft、Baseは`main`である。
- PR本文へ23 Changed Fileを完全列挙し、Contract Skeleton、共通Component、Lint／Bundle／Breaking Change、CI／Policy、Worklogを分離して記録した。
- 本追記を含むFinal HeadでRequired 5 Check、Available CodeQL／Dependency Review、固定Head Self-review、SEV-0／SEV-1なし、Merge Conflictなしを確認して自律Squash Mergeする。
