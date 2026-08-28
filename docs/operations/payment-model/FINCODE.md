# fincode Payment Backend Canonical Specification

## Status And Authority

MIG-079で確定したOripa V2のfincode Backend正本である。適用LaneはStrict Change、Riskは
R4、Application Runtime Activationはdeferredである。fincode Sandbox／Productionの
credential設定、Webhook登録、実決済、Production Enableは行わない。

Provider parameter、status、event、3D Secure、Redirect仕様は常にfincode公式の
[Docs](https://docs.fincode.jp/)、[API reference](https://docs.fincode.jp/api)、公式
[OpenAPI](https://github.com/fincode-byGMO/fincode-mcp/blob/main/packages/fincode-mcp-docs/fincode-openapi.yml)
を優先し、Repository実装と不一致ならFail Closedする。

## Canonical Payment Grant

購入後の実績表示は`getPayment(paymentId)`の`Payment.grant`を正本とし、次の4fieldを返す。

```text
paid_points
bonus_points
limited_bonus_points
total_points
```

`paid_points`はPayment作成時にsnapshotされ成功時に実際に付与された有償コイン、
`bonus_points`は期間限定分を含まない通常無償ボーナス、`limited_bonus_points`は成功時刻と
Payment固有Campaign snapshotから確定して`payments.limited_bonus_point_amount`へ保存された
期間限定ボーナス（適用なしは0）である。`total_points`は3fieldのCanonical合計である。

成功済みPaymentの実績は`payment_point_grants`、Point Operation／Lotsとの既存transaction境界で
確定し、現在のPointProduct、Campaign、現在時刻から再計算しない。Campaign終了・変更や
PointProductの変更可能field更新後も同じ値を返す。購入前表示は引き続き現在のPointProduct
Contractを使用し、購入後のHistorical Authorityと混同しない。

## Supported Methods

Canonical Providerは`fincode`、初期対応は`credit_card`、`paypay`、`konbini`、
`virtual_account`である。Apple Pay、Google Pay、Refund、Chargebackは対象外である。
PayPay、Konbini、Virtual Accountはfincode Redirect Paymentを使用し、Userが支払操作を
確定した時だけ`POST /v1/sessions`を呼ぶ。

## Canonical Browser Return

4方式共通のnormal returnは次である。

```text
/points/purchase/thanks?pid={Payment.id}
```

failure／cancel returnは次である。

```text
/points/purchase/{PointProduct.id}?pid={Payment.id}
```

`pid`はCanonical Public Opaque `Payment.id`、path segmentはPayment作成時の
`point_product_id`と同一のCanonical Public Opaque `PointProduct.id`である。Storefrontの
実Route `/points/purchase/[productId]`が要求するIDもこの`PointProduct.id`であり、
`production_id`等の新規IDは設けない。`point_product_id`は購入対象を選ぶCanonical Request
fieldとしてだけ受け付け、Return先を別値へ差し替える入力として扱わない。

Return URLはPlatformがallowlisted canonical Platform／Storefront origin、固定path、作成済み
PaymentとそのPointProductからだけ生成する。Storefront Requestの`return_url`、`success_url`、
`failure_url`、`cancel_url`、`pid`、`payment_id`、`product_id`、`production_id`は拒否し、
Open Redirectを作らない。URLにはPublic Payment／Product ID以外のProvider ID、DB ID、User ID、
PII、Secret、access ID、token、credential、raw statusを含めない。

fincode公式OpenAPIのreadbackでは、Card 3DSの`return_url`／`return_url_on_failure`と、PayPay、
Konbini、Virtual Accountで使用する`POST /v1/sessions`の`success_url`／`cancel_url`はBrowserを
HTTP POSTで遷移させ、URL上限は256文字である。query付きURLを禁止する記載はない。このため
Provider POSTはPublic APIのnon-mutating Return Handlerで受け、POST bodyとProvider raw値を
無視してPayment／PointProductのCanonical mappingを再解決し、HTTP 303で上記Storefront GETへ
正規化する。未知・malformedな`pid`またはmapping不成立時は`/points`へ戻す。HandlerはPayment
status、Coin、User business stateを変更せず、Webhookを代替しない。

公式OpenAPIにはper-request Return URLに対するDashboard domain allowlist要件は記載されて
いない。後続Sandbox Integrationでは、HTTPSで外部到達可能なPlatform callback originと
configured Storefront originを確認し、Provider側に別途domain登録が要求される場合だけ登録する。
本TaskではSandbox／Provider設定を変更しない。

Browserのnormal／failure／cancel routeはnavigation hintであり、Payment status Authorityでは
ない。Storefrontはどちらから戻っても`pid`を即時`getPayment(pid)`へ渡し、Platformが
Authenticated UserとPayment ownershipを検証したCanonical `Payment.status`を優先する。
failure routeでも`succeeded`なら正常完了へ進み、`failed`／`canceled`／`expired`なら商品購入
ページで非成功表示を行う。`created`／`requires_action`／`processing`なら確定済みpolling境界へ
入る。missing、malformed、unknown、other-user、ownership不成立は存在差を開示しない共通Errorと
し、Storefrontは「決済情報を確認できませんでした」相当と`/points`導線を表示する。

CardとPayPayはReturn直後に`getPayment(pid)`を1回実行し、`created`、`requires_action`、
`processing`の間だけ推奨2秒間隔、最大30秒で自動pollしてよい。`succeeded`、`failed`、
`canceled`、`expired`を取得したら即時停止する。30秒後も未確定なら自動pollを停止し、無限
loadingではなく確認遅延を示す安全な待機状態へ移る。429では`Retry-After` headerまたは
Problem Detailsの`retry_after_seconds`があれば2秒より優先し、指定前に再pollしない。現行
OpenAPIの分類は`authenticated-read`で、RepositoryにgetPayment専用の数値上限は定義されて
いないため数値を推測しない。Client transport retryはGETのNetwork Errorと502／503／504に
限定され、429は自動retryしない。Storefront Payment pollingとは別責任である。

Konbiniは支払情報発行、Virtual Accountは振込先発行が成功したnormal returnでは通常
`processing`／未払いであり、failureとして扱わず入金後だけCoinを付与する。thanks page reloadでも
`getPayment(pid)`で状態を再取得し、期限内未払いならUser action時に
`resumeUnpaidPayment(pid)`を呼ぶ。`getPayment().next_action.url`をdurable resume URLとして
依存しない。resumeと`view=unpaid`は、Konbini／Virtual Accountの
`requires_action + UNPROCESSED`または`processing + AWAITING_CUSTOMER_PAYMENT`だけを対象とする。
ownership、fincode provider、方式、期限、復号可能な保存済みfincode HTTPS redirectを共通で
検証し、暗号化保存済みの既存URLを返すだけで、新規Payment、fincode Session、Konbini支払情報、
Virtual Accountを作らずProvider APIも呼ばない。Card／PayPay、terminal、期限切れ、other-user、
redirectなし／復号不可／authority不正、invalid Paymentは一覧へ出さずresumeできない。

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

Card UI表示は`GET /api/v2/me/payment-card-ui-bootstrap`を正本とする。Authenticated Userだけが
取得でき、responseは`provider=fincode`、Public API Key、`is_live_mode`の3fieldだけである。
Payment、Provider Session、Registration Intent、fincode Customer／Card、Coinを作成せず、
Provider通信、CSRF mutation semantics、Idempotency-Keyを使用しない。Payment無効化、Public Key
未設定、Secret／Webhook readiness不足、test／production keyとendpointまたはApplication environmentの
不整合は空値で継続せずCanonical Problem DetailsでFail Closedする。

StorefrontのCard UI表示は次の順序である。

```text
Credit Card選択
→ getPaymentCardUiBootstrap()
→ initFincode(public_api_key, is_live_mode)
→ FincodeUI create / mount
→ mount成功
→ 購入操作可能
```

Storefrontは`ui.getFormData()`でPAN／CVCを取得・監視せず、undocumented iframe event／messageを
使用しない。Card入力validationは公式fincode SDKのsubmit時validationへ委任する。Card入力中に
別Payment Methodへ切替またはページ離脱する場合はCard UIをdestroy／unmountして入力を破棄し、
Backend mutationとcleanup APIは0とする。再選択時は空のCard UIをmountする。

保存せず購入はBootstrap／mount後の購入操作で初めて
`startPayment(source=new, save=false)`を呼び、`Payment.next_action`からfincode
`executePayment`／3DS2へ進む。保存して購入は購入操作時に
`createPaymentCardRegistrationIntent()`、fincode `registerCard()`、返されたProvider Card IDを使う
`startPayment(source=new, save=true, registration_intent_id, provider_card_id)`の順であり、Platformが
同じ購入Flow内でregistrationをcompleteしてPayment／3DS2へ進む。購入Flowは
`completePaymentCardRegistration()`を別途呼ばない。同operationは購入を伴わない独立Card登録Flowだけを
担当する。

Bootstrapとnew-card `Payment.next_action`は同じvalidated configuration authorityからPublic Keyと
`is_live_mode`を返す。Storefrontは両方の完全一致を確認でき、不一致時にenvironmentを推測・補正せず
Payment実行を停止する。

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

Public APIはPayment開始、Platform生成ReturnのPOST→303正規化、状態参照、成功／未払い履歴、既存未払いRedirect再開、カード一覧・
削除・登録Intent・登録完了を提供する。成功履歴は`succeeded`だけ、未払い履歴は期限内かつ
復号可能な保存済みfincode HTTPS redirectを持つKonbini／Virtual Accountの
`requires_action + UNPROCESSED`または`processing + AWAITING_CUSTOMER_PAYMENT`だけを返す。
Credit Card／PayPay、expired、failed、canceled、succeededはStorefront未払い履歴に出さない。
`getPayment`はCanonical state／presentationのreadでありProvider
再照会、Session作成、Coin Grant、Webhook代替を行わない。未払い再開は暗号化保存した既存
Redirect URLを返し、新規PaymentやProvider Sessionを作らない。API正本は`openapi/public/openapi.yaml`、薄いClientは
`packages/storefront-client/src/payments.ts`である。

## Admin Contract

Admin APIは全User Payment一覧とUser Detail Payment一覧を提供し、全Canonical状態を対象に
status、payment method、全体一覧ではUser filter、cursor paginationを提供する。既存Admin
Session、MFA realm、`reporting.financial.read` permission、Problem Details、Auditを再利用する。
Admin UIは実装しない。API正本は`openapi/admin/openapi.yaml`である。

## Contract Artifact

Canonical publicationは`docs/operations/releases/storefront-contract-artifact.md`の
additive-contract手順に従う。既存immutable `2.0.0-alpha.25`は変更せず、Public Contract、
Storefront Client、Testkitのbyte変更をimmutable `2.0.0-alpha.26`としてSource Commit
`2dd1c7dbcf83b78f5d07fe3d965f9982d1f2fd05`から正式発行した。Manifest SHA-256は
`05ad837c3f4ebbf5875e4aed846d28df750c366cc5f4e3d8589799deea659e2e`、Clientは
`80ebe7172fd4ca86fbcebe545cb2b18dfe4c3a76ccf9ad770b5291dd82225b3d`、Testkitは
`ca62c03bddc6a3a263f5853f512f0b085756744c23ab563365b0e6d7c8e53fde`、Public OpenAPIは
`888df7d36606aa05b599859ae27a0cd4343123d46cbbe9f4355f2f9fd649a6e5`である。
Public OpenAPI Contract versionは`2.0.0-alpha.25`、Admin／Webhook Contractは既存versionを
維持する。Platform、Application、Site Schemaも独立Versionのままである。

## Activation And Deferred Work

`FINCODE_PAYMENT_ENABLED=false`が既定であり、Activation前はProvider通信をFail Closedする。
Sandbox credentialを用いた外部実通信、Webhook登録、Shared Preview Activation、Storefront
Payment UI、Admin Payment History UI、Refund／Chargeback、Production Enable／Commercial
Gateは後続Taskで行う。Mock ProviderをProductionで有効化しない。
