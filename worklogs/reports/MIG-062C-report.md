# MIG-062C Browser-safe Draw Mutation／Typed Draw Error Contract

## Task

- Issue: #231
- PR: #232
- Base: `8b8e50dd571c360edb0c37bbc4668b11bea15080`
- Application／Artifact Source Commit: `41758bb26523a45b990b82564bc450bf6e470107`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `1641af606c8753739b7dca782d336e326afb83809d0ae5b98bb26d29dbd1322f`
- Risk／Verification: R3／`TARGETED_DRAW_CONTRACT`

## Browser-safe Draw Contract

- `createBrowserStorefrontDrawClient`を追加し、既存Browser Auth Clientと同じCSRF Initializer、Cookie reader、`credentials: include` Transportを再利用した。CallerはCookie名、Cookie解析、XSRF Header、Tokenを扱わない。
- Browser向け`createDraw`はCallerが`idempotency_key`を明示する。同じMutation RetryはTransportが同じKeyを維持し、別内容で同じKeyを使った場合はBackendの既存Conflictを返す。
- 後方互換のため低レベル`createStorefrontDrawClient`と既存`CreateDrawOptions.csrf_token`は変更していない。Draw Transaction、Point、Inventory、Eligibility、Daily Limit、Lock、Business Ruleには変更がない。

## Error／Result Contract

- Public OpenAPIへBackendで実在する12 Codeだけを`DrawProblemCode`として同期した: `AUTHENTICATION_REQUIRED`、`CSRF_TOKEN_MISMATCH`、`DAILY_DRAW_LIMIT_EXCEEDED`、`DRAW_COUNT_INSUFFICIENT`、`GACHA_AUDIENCE_NOT_ELIGIBLE`、`GACHA_NOT_DRAWABLE`、`GACHA_SALES_PAUSED`、`IDEMPOTENCY_KEY_REUSED`、`IDEMPOTENCY_REQUEST_IN_PROGRESS`、`INSUFFICIENT_POINTS`、`INVALID_DRAW_REQUEST`、`RATE_LIMITED`。
- ClientはGenerated Typeと`isDrawProblemError`で既知Codeを判別する。未知Codeはdetail文字列を解析せず、汎用`ApiProblemError`として安全に処理する。内部Exception文言は公開しない。
- Draw成功Responseの`id`はPublic Draw Request IDで、既存`getDrawRequest(id)`へ接続する。Result GET前後で`draw_requests`／`draw_results`件数が不変であり、Reload後の再取得がDrawを再実行しないことを対象Testで確認した。

## Verification／Artifact

- Backend対象4 tests／34 assertions、Public OpenAPI 48 operations、Storefront Client 21 tests、Site Schema 10 tests、Storefront Testkit 25 testsがPASSした。Browser CSRF初期化、同一Key Retry、CSRF Problem、既知／未知Draw Error、Result再取得、既存Auth／Gacha Presentation Contractを確認した。
- Frozen Install、OpenAPI生成同期、Client／Testkit Typecheck・Lint・Build、Policy Unit対象3件、Local Policy／Quality Gate、`git diff --check`がPASSした。全Suiteは実行していない。
- Artifact Version: `2.0.0-alpha.5`。既存`2.0.0-alpha.4`は変更せず、`/var/lib/oripa-v2-evidence/MIG-062C/artifacts/2.0.0-alpha.5/`へ新規生成した。
- Manifest: `artifact-manifest.json`／`52c442da31b93977158bbe43c867b7b49b4c0bcd35a36534ae1717c92c47d564`。
- Client: `oripa-storefront-client-2.0.0-alpha.5.tgz`／`fd40bc2fd357dadb2a2f2f1d1f1ef6854b00a495c30e4c0760b1c35949442d50`。
- Testkit: `oripa-storefront-testkit-2.0.0-alpha.5.tgz`／`266e3634bdb107363a30cdd00981df53ae69388a9375a26fda2f2f118dc27901`。
- Site Schema: `oripa-site-schema-2.0.0-alpha.5.tgz`／`56a6138e0f8be99816d9d13277a3da79b81e383b15327dd9d73d83841dcb715e`。
- Public OpenAPI: `public.openapi.json`／`3b479426c9a5f70c4123cbeef29dba49de31a6b3d13c50e8cd010b1910301542`。`SHA256SUMS` 4件、Manifest内部整合、Workspace外Clean Install／Frozen Install／ESM ImportがPASSした。
- Runtime、Preview、DB、Migration、Nginx、V1、Storefront Repositoryは変更していない。
- SITE-005はCloseout後、`.5`の3 PackageとPublic OpenAPIを固定導入し、Browser FacadeとTyped Guardを使用する条件で再開可能である。
- 残課題: Backendが将来新しいDraw拒否Codeを追加する場合は、Public OpenAPIとGenerated Packageを同時更新する。未知Codeはそれまで汎用Errorとして扱う。

