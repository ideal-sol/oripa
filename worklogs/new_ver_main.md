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
