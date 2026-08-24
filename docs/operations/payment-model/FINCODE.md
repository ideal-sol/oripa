# fincode Payment Backend Canonical Specification

## Status And Authority

MIG-079で確定したOripa V2のfincode Backend正本である。適用LaneはStrict Change、Riskは
R4、Application Runtime Activationはdeferredである。fincode Sandbox／Productionの
credential設定、Webhook登録、実決済、Production Enableは行わない。

Provider parameter、status、event、3D Secure、Redirect仕様は常にfincode公式の
[Docs](https://docs.fincode.jp/)、[API reference](https://docs.fincode.jp/api)、公式
[OpenAPI](https://github.com/fincode-byGMO/fincode-mcp/blob/main/packages/fincode-mcp-docs/fincode-openapi.yml)
を優先し、Repository実装と不一致ならFail Closedする。

## Supported Methods

Canonical Providerは`fincode`、初期対応は`credit_card`、`paypay`、`konbini`、
`virtual_account`である。Apple Pay、Google Pay、Refund、Chargebackは対象外である。
PayPay、Konbini、Virtual Accountはfincode Redirect Paymentを使用し、Userが支払操作を
確定した時だけ`POST /v1/sessions`を呼ぶ。

正常Returnはconfigured Storefront originの`/points/purchase/thanks`、Cancelは固定
`https://luxe-pack.biz/points`とする。RequestからReturn URLを受け取らずOpen Redirectを
作らない。Browser Returnとサンクスページ到達はPayment Success Authorityではない。

## Card Boundary

PAN、CVC、生カード入力、TokenをLaravelへ送信・保存・Loggingしない。カード情報の
Canonical holderはfincodeである。Platformが保存するのはUserとfincode Customer／Card
reference、brand、last4、YYMMから検証したexpiry、last-used時刻だけである。

Userごとの登録カードは最大3枚である。User row lockと有効な登録Intent予約数を含む
判定により並行Requestでも4枚目を成立させない。利用・削除・登録完了ではPlatform側の
User、Customer、Card所有関係とfincode取得結果のCustomer／Card referenceを照合する。
期限切れカードは一覧表示と削除を許可し、決済利用を拒否する。表示順はlast-used降順で、
メインカードというPlatform機能は持たない。

新規カードはfincode UI Component／Public APIからProviderへ直接送る。Backend Contractは
今回限りのカード、保存して購入、登録済みカードを区別する。登録Intentはfincode Customer
reference、Public API Key、`tds_type=2`だけを返し、Secret API Keyは返さない。

## Mandatory 3D Secure

全Credit Card Paymentは登録時の`POST /v1/payments`へ`tds_type=2`かつ`tds2_type=2`
を指定する。`tds2_type=2`は非対応カードを認証なしで継続せずError終了する境界である。
新規、保存する新規、保存しない新規、登録済みカードに迂回Pathを設けない。

Challenge／Frictionless後もBrowser Returnでは成功にせず、署名検証済みWebhookを契機に
Secret APIで同一orderを照会し、Canonical statusが`CAPTURED`である場合だけ成功させる。
failure、reject、cancel、abandonment、timeout、unknownは成功にしない。

## Status And Expiry

Platform状態は`created`、`requires_action`、`processing`、`succeeded`、`failed`、
`canceled`、`expired`を使う。主なProvider mappingは次のとおりである。

| fincode | Platform |
| --- | --- |
| `UNPROCESSED`、`CHECKED`、`AUTHORIZED`、`AUTHENTICATED` | `requires_action` |
| `AWAITING_CUSTOMER_PAYMENT`、`AWAITING_PAYMENT_APPROVAL` | `processing` |
| `CAPTURED` | `succeeded` |
| `CANCELED` | `canceled` |
| `EXPIRED` | `expired` |
| `FAILED` | `failed` |

未知statusは推測せずRejectする。KonbiniとVirtual Accountの`payment_term_day`は公式仕様で
指定可能な`3`日固定である。Userごとの有効な未払いKonbiniは最大1件とし、User row lockで
並行作成を防ぐ。terminal化または期限経過後は新規作成できる。Virtual Accountの未払い数は
制限せず、同一購入操作はPlatformとProvider双方のIdempotencyで重複生成を防ぐ。

## Canonical Success And Exactly Once

成功順序は必ず次である。

```text
valid Fincode-Signature
→ Provider reference / method ownership照合
→ fincode Secret APIによるserver-side status / amount照合
→ CAPTUREDのCanonical確定
→ Platform Payment succeeded
→ paid/free Coin Grant確定
```

Provider Event、Payment、Wallet、Point Lot／Ledgerを既存Lock順で処理し、Paymentと
`payment_point_grants`の一対一制約を使用する。成功遷移、Limited Bonus判定、paid/free Lot、
Wallet、Ledger、Audit、Outbox、Mail scheduleを同じDB Transactionで確定する。同じPaymentを
Webhook、replay、遅延、out-of-order、並行delivery、API応答と競合してN回処理してもGrantは
1回である。Mail failureはPayment／GrantをRollbackせず、既存delivery key
`coin.purchase.completed:{payment_public_id}`で重複送信を防ぐ。

Webhook authenticityはfincode Webhook設定に登録したsignatureと受信
`Fincode-Signature`をconstant-time比較する。Payload全文とsignatureはApplication logへ
出さない。検証済みPayloadは既存Provider Event境界で暗号化し、event ID／payload hashで
duplicateとreplayを検出する。未知eventは副作用なしで受信済み応答し、不正event、参照不一致、
amount不一致、未知statusはFail Closedしてfincode retry対象にする。

Konbini／Virtual AccountがPlatform上の期限へ到達した場合は、Schedulerが認証済みProvider
status APIを再照会し、Webhook遅延または期限通知の取りこぼしを補完する。再照会結果も同じ
verified event／transaction／exactly-once境界へ入り、Local clockだけで`expired`や
`succeeded`へ変更しない。

Provider mutationのUUID v4 `idempotent_key`はDBへ永続化する。通信結果不明時の再送は公式の
30分有効期間内に同一Key・同一Requestだけを許可し、29分経過後は自動再作成せず
reconciliation requiredで停止する。

## Public Contract

Public APIはPayment開始、状態参照、成功／未払い履歴、既存未払いRedirect再開、カード一覧・
削除・登録Intent・登録完了を提供する。成功履歴は`succeeded`だけ、未払い履歴は期限内かつ
`AWAITING_CUSTOMER_PAYMENT`のKonbini／Virtual Accountだけを返す。expired、failed、canceledは
Storefront履歴に出さない。未払い再開は暗号化保存した既存Redirect URLを返し、新規Paymentを
作らない。API正本は`openapi/public/openapi.yaml`、薄いClientは
`packages/storefront-client/src/payments.ts`である。

## Admin Contract

Admin APIは全User Payment一覧とUser Detail Payment一覧を提供し、全Canonical状態を対象に
status、payment method、全体一覧ではUser filter、cursor paginationを提供する。既存Admin
Session、MFA realm、`reporting.financial.read` permission、Problem Details、Auditを再利用する。
Admin UIは実装しない。API正本は`openapi/admin/openapi.yaml`である。

## Contract Artifact

Canonical publicationは`docs/operations/releases/storefront-contract-artifact.md`の
additive-contract手順に従う。MIG-079はPublic、Admin、Webhook、Storefront Client、
Storefront Testkitを`2.0.0-alpha.24`へ進め、Platform、Application、Site Schemaは
独立Versionのまま維持する。既存immutable releaseのdigestやsource treeは変更しない。

## Activation And Deferred Work

`FINCODE_PAYMENT_ENABLED=false`が既定であり、Activation前はProvider通信をFail Closedする。
Sandbox credentialを用いた外部実通信、Webhook登録、Shared Preview Activation、Storefront
Payment UI、Admin Payment History UI、Refund／Chargeback、Production Enable／Commercial
Gateは後続Taskで行う。Mock ProviderをProductionで有効化しない。
