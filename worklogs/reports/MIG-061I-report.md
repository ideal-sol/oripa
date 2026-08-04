# MIG-061I Gacha Core Management Report

## Task

- Issue／PR: `#184`／`#185`
- Base: `main@b41f9e9ec460ba1c8413b2bdbc46b11ebde277c8`
- Branch: `feat/MIG-061I-gacha-core-management`
- Risk／Verification: `R3`／`FAST-TRACK-FEATURE`
- Policy SHA-256: `238c81545492ea470589e9bd3c8c364d93b07249953a6b1522d1d180e27e1ccc`
- Final Head／Squash Commit: CloseoutのGitHub merge結果を正本とする。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061I/`

## Implementation

- V1からCategory／Tagの項目順、状態、登録／編集導線を移植し、既存V2 Category／Tag CRUDへ接続した。
- V2ではGacha Masterと初期Draft Versionを1 Transactionで作成する
  `POST /admin/api/v2/catalog/gachas/core`を追加した。Public ID、既存Catalog Permission、Idempotency、
  Audit／Outbox、RFC 9457、`private, no-store`を既存規約どおり再利用する。
- Gacha一覧をID、名称、Thumbnail、消費Point、公開状態、履歴、詳細の順へ変更し、詳細画面に
  Core項目と利益Simulation／商品設計Plannerへの導線を追加した。履歴、Simulation、PlannerはScaffoldのみ。
- 登録FormはThumbnail、Title、Category、Tag、消費Point、総口数、日次上限、Draft状態、会員区分、
  開始／終了日時、説明、注意事項を扱い、作成後に詳細へ遷移する。公開操作やRank／Prize操作は追加していない。

## Migration

- `2026_08_19_000032_add_v2_gacha_core_management_fields.php`でDraft Versionへ
  `daily_draw_limit`（default 0）と`audience_code`（default `all_users`）をForward-safeに追加した。
- 会員区分は`all_users`、`first_time_users`、`line_users`のCodeで保存する。Draw時の対象判定とJST日次上限判定は
  MIG-061Jへ延期し、Published Versionの直接変更は行わない。

## Verification

- Task DB `oripa_v2_mig061i`／Marker `MIG-061I`でDB Target Safety Guard、Fresh 32件、最新Migrationの
  Rollback／Reapply、Backend対象24 tests／413 assertionsがPASSした。
- Admin Unit 33件、Desktop／Mobile Browser対象3件、Typecheck、Lint、Production BuildがPASSした。
- Admin OpenAPI Lint／Bundle／Base Breaking、Generated Client差分0、Policy Unit 96件、Local Policy Gate、
  `git diff --check`がPASSした。
- Full V2 Suite、Full Guard、Backup／Restore、Draw負荷、V1全回帰、全Admin E2E、全Local Security Gateは
  FAST-TRACK-FEATUREの明示範囲外として実行していない。GitHub Required ChecksはCloseoutで確定する。

## Preview／Remaining

- Application Head `bfc47d8276c50b1c5fcb8167317f08a2412d809c`から、Preview API Imageを
  `sha256:d3d2b2b8...`から`sha256:42d971aa...`、Admin Imageを`sha256:d5c38bb3...`から
  `sha256:fb9d2497...`へ更新した。旧ImageはRollback用に保持する。
- DB Target Safety Guardで`oripa_v2_mig061a`、Marker `mig061a`、Purpose `v2-persistent`を確認し、
  既存Dataを保持したままMigration 000032だけを30.23msで適用した。Migration集合は31件から32件となった。
- Container名、Network、固定IP、Loopback Port、Restart Policy、Environment Key集合は切替前後一致した。
  PostgreSQL／Redis Container IDとNginx 4設定Checksum、V1は変更していない。
- Owner Login、Gacha Empty State、登録Form、Category／Tag、履歴／Simulation／Planner Scaffold、Mobileを確認した。
  Console Critical Error、Page Error、HTTP 500／502／504は0。Synthetic Gachaは投入していない。
- Rank／Prize／実History、Simulation Algorithm、商品設計、会員区分と日次上限のDraw Enforcementは対象外。
- GitHub Required Checks、Fresh Self-review、Squash Commit、Task Resource CleanupはCloseoutで確定する。
- Gate G4／G5は`NOT COMPLETE`を維持し、MIG-061Jは開始しない。

## Security Blocker

- GitHub CodeQL 2件、Dependency Review、最新Policy Gate、Quality GateはPASSした。Security Gateは
  2026-08-04時点のAdvisory DBとSEC-005 Baselineの不一致でFailし、集約`ci-gate`もFailした。
- Composerは既存10件に加え、Guzzle High `PKSA-gcrk-3vtt-1r14`（`CVE-2026-69246`）とMedium
  `PKSA-cnw1-2ytm-cgr8`（`CVE-2026-69245`）を検出した。
- Workspace pnpmはHigh 2件（`brace-expansion`、`fast-uri`）とModerate 1件（`postcss`）、Legacy pnpmは
  High 1件（`brace-expansion`）とModerate 1件（`postcss`）を検出した。Criticalは0件。
- これらはMIG-061I開始後に外部Advisory DBへ追加されたDependency Findingであり、Application／Lockfile差分に
  起因しない。Baseline追加／期限延長やDependency更新はTask Policy外のため実施していない。
- Required Checks成功条件が未成立のためPR `#185`とIssue `#184`はOpen、Branch／Worktree／Task DBは保持する。
  Previewは正常稼働を維持し、別Security Task完了後に同じMIG-061Iを再開する必要がある。

## Time

- 時間を要した作業は既存Publish／Version状態のCharacterization、Task DBのDocker Compose／Engine互換回避、
  Browser Fixtureの既存Dashboard／User API境界の決定化である。Host Toolchain、Assertion、Timeoutは変更していない。
- Weekly limitの開始前／終了後値は利用可能なLocal Evidenceがないため記録していない。
