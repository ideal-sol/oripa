# V2 External Identity／Google OIDC／LINE Login v2.1

## Scope

MIG-058AはV2 User Realm向けの共通External Identity基盤とGoogle OIDCを
提供する。MIG-058Bは同じTransaction、Account、Session、Audit、Outbox境界を
再利用し、LINE Login v2.1を追加する。Admin Social Login、UI、実Provider
Account E2E、Provider Tokenの長期保存、Production Deploymentは対象外である。

## Authority

- Google OpenID Connect:
  <https://developers.google.com/identity/openid-connect/openid-connect>
- Google ID Token validation:
  <https://developers.google.com/identity/sign-in/web/backend-auth>
- OAuth 2.0 Web Server flow:
  <https://developers.google.com/identity/protocols/oauth2/web-server>
- LINE Login v2.1 API:
  <https://developers.line.biz/en/reference/line-login/>
- LINE Login PKCE:
  <https://developers.line.biz/en/docs/line-login/integrate-pkce/>
- LINE Login web integration:
  <https://developers.line.biz/en/docs/line-login/integrate-line-login/>

Googleの固定Issuerは`https://accounts.google.com`、固定Token Endpointは
`https://oauth2.googleapis.com/token`、固定JWKS Endpointは
`https://www.googleapis.com/oauth2/v3/certs`である。Provider応答のURLを
動的な通信先として採用しない。

LINEの固定Issuerは`https://access.line.me`、Authorization Endpointは
`https://access.line.me/oauth2/v2.1/authorize`、Token Endpointは
`https://api.line.me/oauth2/v2.1/token`、ID Token Verify Endpointは
`https://api.line.me/oauth2/v2.1/verify`である。任意URL、Discovery URL、
Provider応答のURLを通信先として採用しない。

## V1 Characterization

V1のGoogle LoginはCache StateとAuthorization Code Exchange後のUserInfoを
用い、Provider Subject、Email、Name、Avatar、Raw Profileを保存していた。
Nonce、PKCE、ID Token署名検証、明示Link／Unlinkは存在しなかった。
既存Social SubjectはLoginし、Verified Email衝突は拒否していた。

V2は業務上の「既存Subject Login」「Verified Emailが必要」「Email衝突拒否」
だけをCharacterizationとして維持する。V1のToken処理、Email識別、
Profile保存、Bearer Token Sessionは移植しない。

V1固定SourceにはLINE Login実装が存在しない。MIG-058BはLINE公式仕様と
既存External Identity共通境界を正本とし、V1由来の架空互換を追加しない。

## Protocol Boundary

- Server-side Authorization Code Flow
- CSPRNG State／Nonce／PKCE Verifier
- PKCE S256
- Browser Transaction CookieとUser SessionへのBinding
- Transactionは10分、1回限り
- ID TokenはRS256のみ
- Issuer、Audience、任意の`azp`、`exp`、`iat`、Nonce、Subject、
  Verified EmailをServer時刻で検証
- Unknown `kid`では固定JWKSを1回更新してからFail Closed
- Raw Code、Token、State、Nonce、Verifier、Subjectを永続化しない

LINEは固定Verify Endpointの応答についてIssuer、Audience、`exp`、`iat`、
Nonce、Subjectと型を検証する。Provider Timeout、429、5xx、Verify拒否は
Fail Closedとし、曖昧なAuthorization Code交換を無制限に再試行しない。

Repository外Environmentには次の値を設定する。値そのものを
`.env.example`、Log、Worklog、GitHubへ記録しない。

- `V2_GOOGLE_OIDC_CLIENT_ID`
- `V2_GOOGLE_OIDC_CLIENT_SECRET`
- `V2_GOOGLE_OIDC_REDIRECT_URI`
- `LINE_LOGIN_CHANNEL_ID`
- `LINE_LOGIN_CHANNEL_SECRET`
- `V2_LINE_LOGIN_REDIRECT_URI`
- `V2_LINE_LOGIN_EMAIL_SCOPE_ENABLED`
- `LINE_MESSAGING_CHANNEL_SECRET`
- `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN`

未設定、HTTP Redirect URI、Provider通信障害、JWKS障害はFail Closedである。

## Account Boundary

Identity Keyは`provider + issuer + HMAC(subject)`であり、Emailではない。
Verified Emailが既存Verified Userと一致しても自動Linkしない。認証済みUserが
新しいProvider認証を完了した場合だけLinkする。Provider由来新規Userは
`password_login_enabled=false`とし、Password Reset成功時だけ有効化する。

UnlinkはServer DB時刻による5分以内のRecent User Authenticationを要求する。
Password Login無効Userは残るGoogleまたはLINE Identityによる
Reauthenticationを使用する。最後の利用可能なCredentialは削除せず、
Unlink時はRemember Deviceと他Sessionを失効し、現在SessionとCSRFを
Rotationする。

LINEの基本Scopeは`openid profile`である。`email`はLINE Developers Consoleで
取得許可済みかつRepository外設定が明示的に有効な場合だけ要求する。既存LINE
IdentityはEmail ClaimなしでもSubjectでLoginでき、認証済みUserへのLinkも
Emailを要求しない。新規User作成だけはEmail Claimを必須とし、存在しない場合は
Active Userや架空Emailを作成せず
`EXTERNAL_IDENTITY_EMAIL_COMPLETION_REQUIRED`境界を返す。

## Messaging Follow Boundary

Messaging Webhookは固定Route `POST /webhooks/v2/line`で受け付け、生Request
Bodyと`LINE_MESSAGING_CHANNEL_SECRET`で署名を検証してからJSONを解釈する。
処理対象は`follow`／`unfollow`だけであり、Message本文は受信・保存しない。
`webhookEventId`とLINE SubjectはHMAC相関値として保存し、Raw User ID、
Reply Token、Channel Access Tokenは永続化・Log・Auditしない。

Login済みFollowではFriendship、任意のfree Point Reward、Audit、Outboxを
同一DB Transactionで確定する。Login前FollowはPendingとして保存し、後続の
LINE Login／Link成功Transactionで一度だけClaimする。Pointまたは設定確定に
失敗した場合はReplyを送らない。

Follow Transaction成功後だけ、固定Reply Endpointへ
`LINE_MESSAGING_CHANNEL_ACCESS_TOKEN`のBearer認証で1 Text Messageを送る。
Reply EventはDB状態で一度だけClaimし、Redeliveryや並行処理で同じReply Tokenを
再利用しない。Replyの429、5xx、TimeoutはPoint／FriendshipをRollbackせず、
Redaction済みFailure CodeをAuditする。Push、Broadcast、Multicast、
NarrowcastへのFallbackは行わない。

## Transaction／Retention

Identity、User、Session、Audit、OutboxはDomain確定Transaction内で保存する。
Provider HTTPはDB Transaction外で実行する。Transaction、Account Historyは
削除せず、DB TriggerでIdentity変更、Replay、履歴Update／Delete／Truncateを
拒否する。期限切れTransactionの将来Cleanupは状態を`expired`へ遷移する
限定Commandで行い、物理削除しない。

## Public Safety

### O1 encrypted LINE subject exception

The Human-approved [LINE subject ADR](../../architecture/ADR_LINE_SUBJECT_VAULT_2026-11-05.md)
permits `subject_ciphertext` only in LINE identity, friendship and pending rows.
`V2LineSubjectVault` verifies current/previous HMAC candidates before one-time
encrypted backfill and centralizes decryption. Raw IDs, ciphertext and availability
flags are never returned by Public/Admin APIs or recorded in logs, Audit or Outbox.
Signed Webhook events may backfill an existing matching row using `source.userId`;
message bodies remain unprocessed. No delivery caller is introduced in this phase.
The general prohibition on provider-token persistence remains unchanged.

`V2_LINE_FRIENDSHIP_CHECK_ENABLED=false` is the default. When enabled, successful
LINE login/link/reauthentication uses the ephemeral access token against the fixed
friendship endpoint with a three-second timeout, outside the identity transaction.
`V2_LINE_LOGIN_BOT_PROMPT` accepts only none/normal/aggressive, default none.
Provider setup, reward amount/expiry/URL and operational enablement remain Human.

### Storefront handoff (Source only)

Use the existing Client `startLineLink` / `startLineReauthentication` with
`return_path=/mypage/line`. A session-bound browser callback returns HTTP303 to
that exact path; the existing authenticated friend-state Client read refreshes
the presentation and consumes `callback_result` once (server-side TTL120s).
Keep the JSON callback for existing API clients. No provider values belong in
Storefront URLs, browser storage or notices.

| Result code | One-time message |
| --- | --- |
| `friendship_check_failed` | 友だち追加状態を確認できませんでした。もう一度お試しください。 |
| `reward_apply_failed` | LINE特典を反映できませんでした。もう一度お試しください。 |
| `line_auth_failed` | LINE認証を完了できませんでした。もう一度お試しください。 |

Use Platform `secondary_action` to show 「友だち追加を確認する」. For an unconfirmed
linked account this is independent of reward enablement; for a confirmed account
it requires an enabled, unclaimed reward. A claimed/confirmed account has no
permanent recheck action (decision14 deferred). No periodic sync or draw-time
reauthentication is introduced. Webhooks directed only to another system can
therefore delay detection of an unfollow.

The menu is 「LINE連携」 with 「LINE連携・友だち追加・特典を確認する」. For granted
rewards show 「友だち追加特典は受け取り済みです」 without an old amount. Otherwise use
current `reward.point_amount` as N in 「条件を満たした方にNコインをプレゼントします」;
this is not an unconditional entitlement. Confirmed, enabled and unclaimed uses
「LINE連携・友だち追加が完了しています。条件を満たした方はLINE特典を受け取れます。」.
500 is an intended Human Admin setting, not a Source default.

Source integration approval (2026-11-10): formal Paidy alpha.51 is verified.
LINE Client/Testkit alpha.52 is its pending additive successor: Public alpha.48,
Admin/Webhook alpha.47. Immutable releases through51 remain unchanged.
Publication, Stable Tags and Runtime activation belong to separate release work.

### OLD Test handoff: API and Admin

Build and deploy both API and Admin from the approved merged Source/artifact.
Integrated Source includes merged Admin UI PR663 (ADMIN-UI-001–016 and018),
mail template management and phone_changed/account_closure_payment/
account_closure_code editing. Admin build, deployment and actual screen checks
are required. The prior Paidy API-only authority does not authorize this new
integrated release; the release owner prepares the matching artifact/authority,
retaining reviewed-tree provenance. Client/Testkit52 publication and Site
adoption require separate gates.

Before operational migration107: backup, inspect the actual migration ledger,
then apply pending migrations through the Canonical release procedure.107 is
forward-only; retain encrypted identities, histories and immutable triggers.
Do not use down() for rollback. Revert Runtime Source through an approved plan
while retaining additive schema. This Source integration applies no migration.

Confirm approved LINE Provider/official-account association, secrets and callback
configuration, reward enablement/500 coins/expiry/friend URL, bot_prompt and the
optional friendship-check flag (Source default OFF). No operational value is
changed by this PR. Verify actual browser callback/session safety, friend/unfollow
ordering, reward eligibility/idempotency and retry after point failure. Verify
Admin template editing permissions and the three templates above. Real provider,
Browser/E2E and deployed screen acceptance remain the release owner's checks.
Decision14 remains deferred. Paidy deployment precedes LINE deployment.


Public ContractへProvider Subject、Token、Transaction内部ID、Secret、Full
Emailを公開しない。Audit／OutboxにもRaw Protocol値を保存せず、Provider名、
User Public ID、Purpose、Reason Codeだけを記録する。
