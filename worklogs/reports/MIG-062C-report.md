# MIG-062C Browser-safe Draw Mutation／Typed Draw Error Contract

## Task

- Issue: #231
- PR: #232
- Base: `8b8e50dd571c360edb0c37bbc4668b11bea15080`
- Application／Artifact Source Commit: `fedc176f06518edcf9dd57c0387a6d03eee7471b`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256 (initial): `b3e054121fd8ef392e759149326c2e4839080b0fa2459dbfbd03acd1d6a2b424`
- Task Policy SHA-256 (current): `1641af606c8753739b7dca782d336e326afb83809d0ae5b98bb26d29dbd1322f`
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
- 初回GitHub Policy GateはPR本文のCanonical見出し不足を検出した。Application／Gate条件は変更せず、必須見出しと実Diff／Allowed Paths各37件が完全一致する本文へ補正した。
- Fresh Self-reviewで`.5` Candidateの`DrawProblemResponse.oneOf`が既知Errorに対して両Branchへ一致する契約上の曖昧さを検出した。`.5`は上書きせず未採用とし、`anyOf`へ補正した未使用Version `.6`を生成した。
- Artifact Version: `2.0.0-alpha.6`。既存`.4`／未採用`.5`は変更せず、`/var/lib/oripa-v2-evidence/MIG-062C/artifacts/2.0.0-alpha.6/`へ新規生成した。
- Manifest: `artifact-manifest.json`／`5067a8771155f72cabd5dc9348977c9318c8be7b7413f3481388a67c2425d313`。
- Client: `oripa-storefront-client-2.0.0-alpha.6.tgz`／`e41b0e0af4fe0da09eff57f53a74dbc981e0bbd138d7bb3cc302325f05a4b319`。
- Testkit: `oripa-storefront-testkit-2.0.0-alpha.6.tgz`／`1519547ba9677eb716051761458d3f40c47d6eb9662afbfecbfee028af78f358`。
- Site Schema: `oripa-site-schema-2.0.0-alpha.6.tgz`／`7c0e76057b2d78f239c785d6768a7962407bbf2f2ac400f8a374e9a24f8a664e`。
- Public OpenAPI: `public.openapi.json`／`6f4fc425718a57237fa89c0f6c75b196c0bf287022ce117dd916dd9b2cf457a1`。`SHA256SUMS` 4件、Manifest内部整合、Workspace外Clean Install／Frozen Install／ESM ImportがPASSした。
- Runtime、Preview、DB、Migration、Nginx、V1、Storefront Repositoryは変更していない。
- SITE-005はCloseout後、`.6`の3 PackageとPublic OpenAPIを固定導入し、Browser FacadeとTyped Guardを使用する条件で再開可能である。
- 残課題: Backendが将来新しいDraw拒否Codeを追加する場合は、Public OpenAPIとGenerated Packageを同時更新する。未知Codeはそれまで汎用Errorとして扱う。
