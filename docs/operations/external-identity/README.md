# V2 External Identity／Google OIDC

## Scope

MIG-058AはV2 User Realm向けの共通External Identity基盤とGoogle OIDCを
提供する。LINE Login、Admin Social Login、UI、実Google Account E2E、
Provider Tokenの長期保存、Production Deploymentは対象外である。

## Authority

- Google OpenID Connect:
  <https://developers.google.com/identity/openid-connect/openid-connect>
- Google ID Token validation:
  <https://developers.google.com/identity/sign-in/web/backend-auth>
- OAuth 2.0 Web Server flow:
  <https://developers.google.com/identity/protocols/oauth2/web-server>

Googleの固定Issuerは`https://accounts.google.com`、固定Token Endpointは
`https://oauth2.googleapis.com/token`、固定JWKS Endpointは
`https://www.googleapis.com/oauth2/v3/certs`である。Provider応答のURLを
動的な通信先として採用しない。

## V1 Characterization

V1のGoogle LoginはCache StateとAuthorization Code Exchange後のUserInfoを
用い、Provider Subject、Email、Name、Avatar、Raw Profileを保存していた。
Nonce、PKCE、ID Token署名検証、明示Link／Unlinkは存在しなかった。
既存Social SubjectはLoginし、Verified Email衝突は拒否していた。

V2は業務上の「既存Subject Login」「Verified Emailが必要」「Email衝突拒否」
だけをCharacterizationとして維持する。V1のToken処理、Email識別、
Profile保存、Bearer Token Sessionは移植しない。

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

Repository外Environmentには次の値を設定する。値そのものを
`.env.example`、Log、Worklog、GitHubへ記録しない。

- `V2_GOOGLE_OIDC_CLIENT_ID`
- `V2_GOOGLE_OIDC_CLIENT_SECRET`
- `V2_GOOGLE_OIDC_REDIRECT_URI`

未設定、HTTP Redirect URI、Provider通信障害、JWKS障害はFail Closedである。

## Account Boundary

Identity Keyは`provider + issuer + HMAC(subject)`であり、Emailではない。
Verified Emailが既存Verified Userと一致しても自動Linkしない。認証済みUserが
新しいGoogle認証を完了した場合だけLinkする。Google由来新規Userは
`password_login_enabled=false`とし、Password Reset成功時だけ有効化する。

UnlinkはServer DB時刻による5分以内のRecent User Authenticationを要求する。
Password Login無効UserはGoogle Reauthenticationを使用する。最後の利用可能な
Credentialは削除せず、Unlink時はRemember Deviceと他Sessionを失効し、
現在SessionとCSRFをRotationする。

## Transaction／Retention

Identity、User、Session、Audit、OutboxはDomain確定Transaction内で保存する。
Provider HTTPはDB Transaction外で実行する。Transaction、Account Historyは
削除せず、DB TriggerでIdentity変更、Replay、履歴Update／Delete／Truncateを
拒否する。期限切れTransactionの将来Cleanupは状態を`expired`へ遷移する
限定Commandで行い、物理削除しない。

## Public Safety

Public ContractへProvider Subject、Token、Transaction内部ID、Secret、Full
Emailを公開しない。Audit／OutboxにもRaw Protocol値を保存せず、Provider名、
User Public ID、Purpose、Reason Codeだけを記録する。
