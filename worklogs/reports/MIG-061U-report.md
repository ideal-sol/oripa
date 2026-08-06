# MIG-061U Storefront認証Public Contract／Client Completion

## Task

- Issue: #210
- PR: #211
- Base: `e1cc759ab1f7a8b38989d29d331c70ac25e1da6b`
- Application／Artifact Source Head: `76d8161de759d8969e74543f6d79b5f5b17cee1d`
- Final Head／Squash Commit: GitHub Closeout結果として完了報告へ記録する。
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
- Source Commit: `76d8161de759d8969e74543f6d79b5f5b17cee1d`
- `@oripa/storefront-client`: `6fa0ccbf612c1c870bdf76e1222cef7498b36693f7da4d0cf53481aedcd3190e`
- `@oripa/storefront-testkit`: `a53f248aaa2b7d00ead43aaa5f5b739fc45f52db4a1903aa43210d115671bafe`
- Support `@oripa/site-schema`: `8111b15b7540e7932a2bf29a51e1116771688ec5fb6395050a1135ae7956d035`
- Public OpenAPI: `71e8b5ab885a1e04fa64ecff0b4365be6552424e2dd625a8ddadbf60f66e6005`
- Artifact Manifest: `19afdd410b676b6703b61bc7e20d09834bc9d67f412742a7f942e23af6ca8708`
- Compatibility: Node 22.22.3、pnpm 10.12.1、Browser Cookie Session、Public API family 2。既存Client APIへadditiveなNon-breaking変更とする。

## Verification／Preview

- Targeted Test: Backend Auth／Browser Security 18件・101 assertions、Public OpenAPI lint／bundle／Breaking Check、Generated Type同期、Client 17件、Testkit 23件、Typecheck／Lint／Build、Policy／Quality Gate、`git diff --check`はすべてPASS。
- GitHub Policy Gateの初回実行でPR必須見出し、再実行でChanged filesの箇条書き形式不足を検出した。Application／Gate条件を変更せず、PR本文をCanonical metadataと25 Pathの完全一致箇条書きへ補正した。
- Artifact Clean Install／Import: 3 tarballのChecksum、Manifest、外部一時ProjectへのClean Install、ESM ImportがPASSした。Packed package内のWorkspace／`file:`／Repository Path参照は0。未公開Private packageのため、Consumerは3 tarballを依存へ固定し、Testkitの同Version依存を`pnpm.overrides`で同梱tarballへ解決する。
- Preview: APIのみ`sha256:ed95e50b60a8a1d0e9543e59dff6f8f4b193e1980ed5874bbb2ad3f0201b4fe9`から`sha256:8d2f0592ad1e1dcefe9128691947d271f0faeb2ed7d3320ad5c2af5fce58311d`へ更新した。Health 200、匿名Session 200、CSRF Cookie、`private, no-store`、API Version 2を確認し、Critical／500／502／504 Logは0。
- Preview制約: 現行PreviewにはPublic APIの外部Nginx Routeと`V2_PUBLIC_ORIGIN`がないため、MutationはFail Closedの`AUTH_SERVICE_UNAVAILABLE` 503となる。Nginx／Originを無断変更せず、登録、Login、Logout、Email Verificationの完全FlowはTask DB対象Testを正本とした。
- Storefront導入: Artifact tarballとchecksumを固定し、Site側で3 Private packageを同梱tarballへ解決する。同一Origin reverse proxy確立後に`base_url: "/api/v2"`と`credentials: include`をClientへ委任し、Site側でCookie名／CSRF Headerを再定義しない。
- 非変更: Admin Image、Migration 37件、既存DB Data、PostgreSQL／Redis、Nginx、V1、Storefront Repository、Payment Provider。

## Remaining／Elapsed

- Remaining: Storefront RepositoryへのArtifact導入、Public API同一Origin Proxy／`V2_PUBLIC_ORIGIN`設定、Production Storefront切替は別Task。
- Elapsed: 約36分。主な所要作業は既存認証境界Characterization、Target DB認証Test、Private packageのWorkspace外解決、Preview Origin境界確認。
