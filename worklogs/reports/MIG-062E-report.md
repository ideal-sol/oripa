# MIG-062E Browser-safe Prize Fulfillment Mutation Contract

## Task

- Issue: #235
- PR: Draft作成前
- Base: `f08ff71edcd8c0cbbcf0fff83c2a69e753f012b6`
- Application／Artifact Source Commit: 確定前
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

- Backend対象7 tests／90 assertions、Public OpenAPI 48 operations、Storefront Client 24 tests、Site Schema 10 tests、Storefront Testkit 26 testsがPASSした。
- OpenAPI生成同期／Breaking Check、Frozen Install、Client／Schema／Testkit Typecheck・Lint・Build、Policy Unit／Gate、`git diff --check`を対象範囲で確認した。全Suiteは実行していない。
- Artifact Versionは既存Release順の未使用`2.0.0-alpha.7`。既存`2.0.0-alpha.6`は再生成・変更しない。Source Commit、Manifest、各SHA-256、Workspace外Clean Install結果はArtifact生成後に確定する。
- Runtime、Preview、DB、Migration、Nginx、V1、Storefront Repositoryは変更していない。
- SITE-012はCloseout後、alpha.7 Artifactを固定導入しBrowser ClientのRetry／Reconciliation Contractへ従う条件で再開可能となる。
- 残課題: 配送先update／deleteは自動Retry不可であり、通信結果不明時はRead Methodによる照合が必要。Backendに新しいFulfillment拒否Codeを追加する場合はPublic OpenAPIとPackageを同時更新する。
