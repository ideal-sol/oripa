# MIG-062E Browser-safe Prize Fulfillment Mutation Contract

## Task

- Issue: #235
- PR: #236
- Base: `f08ff71edcd8c0cbbcf0fff83c2a69e753f012b6`
- Application／Artifact Source Commit: `5c9053ca2434847032a51f8b4f09dd25c8ef8535`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `4d9b8b0779fdf3b078c572d5a24049145c77cfad21280d4d41c7909baffdeddf`
- Risk／Verification: R4／`TARGETED_FULFILLMENT_CONTRACT`

## Browser-safe Contract

- `createBrowserStorefrontPrizeShippingClient`を追加し、MIG-062Cと同じBrowser Transport、CSRF Initializer、Cookie Reader、`credentials: include`を再利用した。CallerはCookie名、Cookie解析、XSRF Header、CSRF Tokenを扱わない。
- 景品Point交換、配送先作成、配送依頼作成はCaller指定の`Idempotency-Key`を必須とし、通信再試行時も同じKeyを使う。既存の景品交換／配送依頼Transaction、Lock、Eligibility、Payment Hold、Prize Stateは変更していない。
- 配送先作成へ既存Idempotency基盤を追加した。同じKey＋同じRequestはCanonical Replay、同じKey＋異なるRequestはConflictとなり、Address／Auditを二重作成しない。既存低レベルAPI Callerとの後方互換のためHTTP Header自体は任意、Browser Client境界では必須とした。
- 配送先更新／削除は自動再送を禁止し、通信結果不明時は`getShippingAddress`または`listShippingAddresses`で状態を照合してから利用者判断で次の操作を行う。成功後はShipping／Prize／Addressの既存Read MethodでCanonical状態を再取得できる。

## Error Contract

- Public OpenAPIへBackendで実在するFulfillment Problem Codeだけを同期した。認証、CSRF、Validation、Idempotency Conflict／In Progress、Concurrent Retry Exhaustion、Payment Hold、Action denied、Address／Shipping Request not found、PII保護、Rate Limitを型安全に分類できる。
- `isFulfillmentProblemError`はGenerated Codeだけを判定する。実在しないstale Codeは新設せず、未知Codeはdetail文字列を解析しない汎用`ApiProblemError`として扱う。
- User固有Mutation／Readの既存`private, no-store`、Problem Details、Public ID境界を維持し、内部DB ID／Storage Path／Secretは公開しない。

## Verification／Artifact

- Backend対象7 tests／92 assertions、Public OpenAPI 48 operations、Storefront Client 24 tests、Site Schema 10 tests、Storefront Testkit 26 testsがPASSした。Idempotency Recordへ配送先PIIを保存せず、Public IDだけを保持してReplay時に暗号化Addressを再取得することを確認した。
- OpenAPI生成同期／Breaking Check、Frozen Install、Client／Schema／Testkit Typecheck・Lint・Build、Policy Unit／Gate、Quality／Security Gate、`git diff --check`を対象範囲で確認した。Composer／Root／Legacy Auditは0件、Secret／PII Candidateは0件。全Suiteは実行していない。
- Fresh reviewでalpha.7 SourceのAddress Idempotency ResponseへPIIを複製する問題を検出した。alpha.7は未採用のまま上書きせず保持し、PII補正後の未使用`2.0.0-alpha.8`を最終Artifactとして新規生成する。
- 既存`2.0.0-alpha.6`は前後Checksum一致を確認し、再生成・変更していない。`/var/lib/oripa-v2-evidence/MIG-062E/artifacts/2.0.0-alpha.8/`へalpha.8を新規生成した。
- Manifest: `artifact-manifest.json`／`4d2808856624c02d139beaeeed5a2fadf9a86a3e8f949a34dc2039d36bc4d99a`。
- Client: `oripa-storefront-client-2.0.0-alpha.8.tgz`／`4dbc0daebb292b7474fafab0fb7a917d4923e194de54ee5074a35eb7a0573ab1`。
- Testkit: `oripa-storefront-testkit-2.0.0-alpha.8.tgz`／`3358e353cf091b9cc9609348e750ac989452d75ec5331c439e8981b4bfc9b660`。
- Site Schema: `oripa-site-schema-2.0.0-alpha.8.tgz`／`d689306b45e12bcb218546185924aeb3af885c2159e95b1b40f5f053f601f9ab`。
- Public OpenAPI: `public.openapi.json`／`210692ca1fa89c7ae28fc942c07d2b740eac7e2230d6b8c255570ac6bc16d568`。`SHA256SUMS` 4件、Manifest内部整合、Workspace外Clean／Frozen Install／ESM ImportがPASSした。
- Runtime、Preview、DB、Migration、Nginx、V1、Storefront Repositoryは変更していない。
- SITE-012はCloseout後、alpha.8 Artifactを固定導入しBrowser ClientのRetry／Reconciliation Contractへ従う条件で再開可能となる。
- 残課題: 配送先update／deleteは自動Retry不可であり、通信結果不明時はRead Methodによる照合が必要。Backendに新しいFulfillment拒否Codeを追加する場合はPublic OpenAPIとPackageを同時更新する。
