# MIG-061U Storefront認証Public Contract／Client Completion

## Task

- Issue: #210
- PR: TBD
- Base: `e1cc759ab1f7a8b38989d29d331c70ac25e1da6b`
- Final Head: TBD
- Squash Commit: TBD
- Policy SHA-256 (initial): `24fb4b526205b4bad11eb0a0d7d4dc513a617b86bc79e4ca1d93f54f75d2e6fd`
- Policy SHA-256 (current): `214ac77e0e9fac90f8d466683a5cbe30b25ea5c6b4d5c0c81cfb673aacb462e8`
- Risk／Verification: R4／`TARGETED-AUTH-CONTRACT`

## Characterization

- 既存Routeは登録、Login、Logout、Session、Email Verification再送／完了の6件をすべて提供済みである。
- `GET /api/v2/auth/session`がUser RealmのCanonical CSRF初期化Endpointである。
- Session Cookieは`__Host-oripa_user_session`、CSRF Cookieは`__Host-oripa_user_xsrf`。Secure、Host-only、Path `/`、SameSite Laxで、SessionのみHttpOnlyである。
- Unsafe JSON RequestはExact Origin、CSRF Cookie／Header一致、Cross-site拒否を要求し、CORSは使用しない。Session idle 60分、absolute 24時間、Verification Token TTL 60分である。
- 既存Backend Service、Hash-only Session／Verification Token、Rate Limit、Security Event基盤を再利用した。DB Schema変更はない。

## Contract／Client

- Public OpenAPI: 6 Auth EndpointのStatus／RFC 9457 Error Codeを型付きで明記する。
- Client: Browser CSRF初期化、register、login、logout、getCurrentSession、resendEmailVerification、completeEmailVerificationを提供する。
- Error Contract: `INVALID_CREDENTIALS`、`EMAIL_VERIFICATION_REQUIRED`、`INVALID_REQUEST`、`CSRF_TOKEN_MISMATCH`、`SESSION_EXPIRED`、`INVALID_VERIFICATION_LINK`、`VERIFICATION_LINK_EXPIRED`、`EMAIL_ALREADY_CLAIMED`、`RATE_LIMITED`、`AUTH_SERVICE_UNAVAILABLE`、`INVALID_REDIRECT`、`UNSUPPORTED_MEDIA_TYPE`。
- Testkit: Credentialを含まないAuth Session／Registration Fixtureを追加する。

## Artifact

- Version: `2.0.0-alpha.1`
- Placement: `/var/lib/oripa-v2-evidence/MIG-061U/artifacts/`
- Source Commit／Artifact SHA-256／Public OpenAPI SHA-256: Verification後に確定する。
- Compatibility: Node 22.22.3、pnpm 10.12.1、Browser Cookie Session、Public API family 2。既存Client APIへadditiveなNon-breaking変更とする。

## Verification／Preview

- Targeted Test: Backend Auth／Browser Security 18件・101 assertions、Public OpenAPI lint／bundle／Breaking Check、Generated Type同期、Client 17件、Testkit 23件、Typecheck／Lint／Build、Policy／Quality Gate、`git diff --check`はすべてPASS。
- Artifact Clean Install／Import: TBD
- Preview: Backend差分があるためV2 APIのみ対象。結果は反映後に記録する。
- Storefront導入: Artifact tarballとchecksumを固定し、同一Origin reverse proxy下で`base_url: "/api/v2"`、`credentials: include`をClientへ委任する。

## Remaining／Elapsed

- Remaining: Storefront Repositoryへの導入とProduction Storefront切替は別Task。
- Elapsed: TBD
