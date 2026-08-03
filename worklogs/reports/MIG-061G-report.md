# MIG-061G Admin User List／Detail／Gacha History Report

## Task

- Task ID: `MIG-061G`
- Risk／Profile: `R3`／`DATA-R3-TARGETED`
- Base: `main@35ba11a1762a574ad1cf7528e825d92a2ed69a2c`
- Branch: `feat/MIG-061G-admin-user-management-read-model`
- Issue／PR: `#180`／`#181`
- Task Policy SHA-256:
  - Initial: `ad1a7a279def2d189ecc818fc42202ba06f02f00cdfc63b782ed457c7b72fa10`
  - Phase 0 corrected: `e94dbac9d014fff7293f73e024617a5a2640dfa894bdf0dc2e722922af634c2b`
  - Final corrected: `210e46d87242ab8bf31ab8a323de9b98981a06ef0a891246cffbdbe638aedf9e`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061G/`
- Application／Image Source Head: `c269b5b4202e358d9cf2bc11101d27175e3efdb8`
- Final Head／Squash Commit: CloseoutのGitHub merge結果を正本とする。

## Policy補正

- 既存Allowed Pathsを維持し、次の5 Pathだけを完全一致で追加した。
  - `apps/api/app/Models/V2/User.php`
  - `apps/api/database/migrations-v2/2026_08_18_000031_add_display_name_to_v2_users.php`
  - `apps/api/tests/V2/IdentitySchemaTest.php`
  - `apps/admin/src/lib/permissions/admin-navigation.ts`
  - `apps/admin/test/permissions-navigation.test.tsx`
- Wildcard、Directory単位、中央Permission Matrix、`/users/history`、V1、Storefront、Payment、
  Nginx／TLS／DNSは追加していない。
- GitHub全Admin Unitで旧Owner-only期待を持つ既存Test 2件が検出されたため、
  `apps/admin/test/admin-navigation-hierarchy.test.tsx`と`apps/admin/test/security-shell.test.tsx`だけを
  追加でAtomic許可した。他Fieldと既存Allowed Pathsは不変である。

## Characterization

- V1 `users.name`はLaravel既定長255の必須文字列で、追加の正規化Constraintはない。
  最新の人間決定に従い、V2では既存Userを破壊しないnullable `display_name`として移植する。
- V2のPassword登録とGoogle／LINE外部Identity作成には表示名入力Contractが存在しない。
  EmailやProvider情報から推測せず、User作成Serviceへ変更を加えない。
- Walletの`paid_balance`／`free_balance`がCurrent Canonical Balanceであり、合計残高はBackendで
  両者を整数加算する。Reserved残高は別Columnで、表示残高から再計算または控除しない。
- V1の「ユーザー保有景品」は`user_prizes`をUserで絞り、Statusで除外せず新しい順に表示する。
  このためV2の「ユーザーガチャ履歴」も現在状態に限定せず、過去を含む取得景品履歴を表す。
- V1詳細の状態変更、ポイント調整、QA操作、配送／景品操作はMutationであり本Taskへ移植しない。
  ポイント調整は後続Task候補として記録する。

## 実装

- Forward-safe Migration `000031`で`users.display_name varchar(255) null`を追加した。既存Userの
  Backfill、Emailからの推測、既存User作成／Import Contractの変更は行っていない。
- Read-only Admin APIとして`GET /users`、`GET /users/{user_public_id}`、
  `GET /users/{user_public_id}/gacha-history`を追加した。Public ID、`private, no-store`、Request ID、
  RFC 9457、Stable Sort、既存Opaque Cursorを維持し、内部DB IDやSecretを返さない。
- Owner／Admin／Operatorを同一の有効Admin Session境界で許可した。専用Permission、Role名分岐、
  Fresh MFA、Mutation、Outboxは追加していない。Read Auditだけを既存Append-only Auditへ記録する。
- 一覧列は`ID`、`ユーザー名`、`状態`、`合計残高`、`有償P`、`無償P`、`登録日`、`詳細`の順で固定した。
  Wallet未作成と残高0を区別し、Frontendでは合計を計算しない。
- 詳細はV2に実在する基本情報、Email確認、状態、登録／更新日時、Wallet残高だけを表示する。
  V1のユーザー保有景品を重複表示せず、別ページへの導線を置いた。
- ガチャ履歴はV1の意味を維持し、Statusを限定しない過去を含む取得景品履歴を新しい順に表示する。
  Draw Result、Gacha Version、Prize、RankはPublic IDだけを返す。
- `/users/history`のScaffold、Point／User／Prize／Shipping Mutation、中央Permission Matrixは変更していない。

## 残高／時刻

- 有償P／無償PはCanonical Walletの`paid_balance`／`free_balance`。合計残高はBackendで整数加算する。
  Reservedは既存どおり別管理し、表示上の独自控除はしない。Wallet制約により負残高は許可されない。
- API TimestampはUTC ISO 8601、Admin表示は`Asia/Tokyo`。表示名未設定はEmail代用せず「未設定」とする。

## Test／性能

- Task専用DB `oripa_v2_mig061g`、Marker `MIG-061G`、Purpose `v2-task-ephemeral`でMigration Fresh、
  Rollback／Reapply、既存User保持を確認した。
- Backend Domain／HTTP／Schemaは12 tests／186 assertions PASS。Owner／Admin／Operator 200、未認証401、
  無効Admin拒否、404、残高境界、表示名NULL、Cursor、Public ID、不要PII非露出を確認した。
- 性能Fixture 100 Userで一覧は6 Query、323.51ms。行数依存N+1はない。
- Admin対象Unit／Componentは3 files／25 tests、全Admin Unitは16 files／86 tests、対象Browser E2Eは
  Desktop／Mobile 2件PASS。
  一覧列順、未設定表示、詳細非重複、別履歴Route、Cursor追読、Operator表示、Keyboard Scrollを確認した。
- OpenAPI Lint／Bundle／Breaking、Admin生成差分0、Typecheck、Lint、Production Build、PHP構文、
  Policy Unit 94件、Policy／Quality／Security Gate、`git diff --check`がPASSした。
- SecurityはWorkspace／Legacy pnpm Finding 0、Composer既存Baseline 10、期限`2026-08-07`、
  Secret／PII Candidate 0。Baselineの追加・延長はない。
- Profile指示どおりPersistent／Ephemeral Full Guard、Backup／Restore、Draw負荷、V1全回帰、
  Storefront Test、全Admin Browser E2Eは重複実行していない。

## Preview反映

- DB Target Safety Guardで`oripa_v2_mig061a`、Marker `mig061a`、Purpose `v2-persistent`、旧30件集合を
  確認後、Migration `000031`だけを適用し、31件集合を再確認した。`migrate:fresh`は実行していない。
- API Imageを`sha256:0bbb6c3b...`から`sha256:562e666f...`、Admin Imageを
  `sha256:f348dbb2...`から`sha256:9d3c0e70...`へ更新した。OCI RevisionはApplication Headと一致する。
- Container名、Network、固定IP `192.168.61.10/11`、Port Binding `8611/3611`、Restart Policy、
  Environment Key集合を維持した。PostgreSQL／Redis Container IDとNginx設定Checksumは前後一致する。
- 実DomainでPassword Login、Dashboard Route、新`/users`、実Data 0件のEmpty Stateを確認した。
  Preview DBへ架空Userを投入していないため、詳細／ガチャ履歴はTask DBと対象E2EのEvidenceを正本とする。
- API／Admin Container内Health 200、Console Critical Error 0、HTTP 500／502／504 0、
  `luxe-pack.biz` HTTP 200を確認した。V1、Nginx／TLS／DNS、Payment Providerは非変更。
- Host LoopbackはPort Binding設定を維持するがHostから直接到達せず、切替前測定がないため環境特性として記録した。
  DomainとContainer内Healthは正常で、Rollbackは不要。切替前Imageは削除していない。

## 後続候補／時間を要した作業

- V1詳細のポイント調整配置はCharacterizationしたが、Owner／Admin MutationとOperator拒否を伴うため、
  後続の専用Mutation Taskへ分離する。
- Policy補正では既存Allowed Pathsを保持しつつ、実在する5 PathだけをAtomic再発行した。
  実装後は新規Backend／Admin 13 Pathを中央Policy Gateへ完全一致登録し、Migration完全一致集合にも000031を追加した。
- Task API ContainerのPHP構文PathとReact Effect Lint、PlaywrightのAccessible Name部分一致を個別に修正した。
  Assertion、Timeout、Security Gateは緩和していない。
- GitHub IntegrationのV1-only Schemaでは新V2 Unit 2件が新規Failureになったため、既存Reporting Unitと
  同じSchema存在Guardを追加した。Task V2 DBでは2 tests／23 assertionsを再確認した。
- GitHub Qualityでは旧Sidebar Test 2件が`users-list`をOwner-onlyと期待していたため、最新の
  全管理者Read方針へ更新し、未実装`users-history`のOwner-only境界は維持した。全Admin Unitを再実行した。
- GitHub補正後に`c269b5b4202e358d9cf2bc11101d27175e3efdb8`との差分をApplication、Contract、
  Migration Pathへ限定して機械確認し、差分0を確認した。補正はTest／Worklog／Policyだけのため、
  同じApplication Headで成功済みのPreview Image、Migration、Browser Evidenceを再利用し、再Deploymentは行っていない。
- Preview Migrationの最初のOne-off Compose実行は固定IP衝突でContainer起動前に失敗した。
  稼働API EnvironmentをRepository外0600 Fileへ値を表示せず抽出し、固定IPなしの一時Containerで安全に再実行した。

## Gate

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- `/users/history`、ポイント調整Mutation、MIG-061H以降は開始しない。
