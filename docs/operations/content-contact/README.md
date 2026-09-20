# V2 Content／Contact Boundary

## Responsibility

MIG-056は、Banner、Notice、Static Page、Contact InquiryのV2 DB、Public／Admin
Contract、Domain Service、Audit／Outbox境界を所有する。UI、通知Transport、
Production Deployment、V1 Data Importは所有しない。

## V1 Characterization

- Bannerは表示順、Active状態、画像、Linkを持つ。V2では期間付きPublished Versionと
  Public Assetで同じ表示結果を表現する。
- Noticeは公開日時以前のPublished Recordを新しい順に一覧・詳細表示し、重要表示を
  区別する。V2ではDraft／Published／Archivedと開始・終了期間を明示する。
- Static PageはSlug、Title、本文、公開状態を持つ。`terms`、`privacy`、
  `commercial-law`、`point-terms`はLegal Pageとして扱う。
- Contactは匿名・Login済みの双方を受け付け、受付番号と
  `new`／`replied`／`closed`を持つ。V2では`in_progress`を明示し、返信依頼を
  Outboxへ保存する。
- V1の同期Mail／Discord通知、内部Table名、内部ID、未Sanitize HTML、
  Test問い合わせDataはV2 Contractへ移植しない。

## Content Version

Content MasterとVersionを分離する。Published VersionはDB TriggerでUpdate／Deleteを
拒否し、変更は新しいDraft Versionを作成してPublishする。公開開始以下かつ公開終了が
NULLまたは現在より後のPublished VersionだけをPublic APIへ返す。BannerはPublicな
Image Assetを必須とし、NoticeはPublic-safeなThumbnailを任意で返す。
Published VersionのAsset RelationもDB Triggerで変更を拒否し、Storage Identifierは
公開しない。

Adminは現行Session／Permission／Origin／CSRF境界を使用する。廃止済みの
Fresh Authentication、Password fallback、業務Limiterは再導入しない。

## HTML Security

本文はServer-side DOM ParserでAllowlist Sanitizationする。段落、見出し、強調、
List、Link、Tableを許可し、Script、Inline Event、Style、`javascript:` URL、
iframe、embed、object、form、SVG、MathMLを除去する。保存時とPublic Response時の
両方で同じSanitizerを適用する。CSPへ外部Domainは追加しない。

## Contact PII

Name、Email、Phone、Subject、Body、Internal Note、Reply本文はApplication-level
Encryptionで保存する。Email検索・Rate LimitにはRepository外KeyによるHMAC相関値だけを
使用する。Full PIIをLog、Error、Audit、Outbox Payloadへ保存しない。Contactは物理削除
せず、初期Retentionは受付から365日とする。

Status History、Internal Note、Reply RequestはAppend-onlyである。User入力とAdmin
Internal Noteは別Tableに保存する。返信依頼の受付時点では通知完了とみなさず、
`new`から`in_progress`へだけ遷移し、`replied`は明示的なStatus更新で確定する。
Public ContactはUser認証とIdempotency-Keyを必須とする。`contact.submit` scopeの
User別Idempotency Recordを同一Transactionで保存し、同じKey／入力の再試行は
元の受付結果を返す。同じKeyで異なる入力は409、別Keyの明示的投稿は別操作とする。

任意の`inquiry_id`はContact Public IDである。本人所有の有効IDならrow lock後に
`contact_user_messages`へ暗号化本文をappendし、全状態から`in_progress`へ戻す。
初回本文・Owner・Public ID・受付日時は不変。Status History／Auditも同じTransactionで
記録する。不在／他人所有IDは同じ新規受付経路へ進み、他人のContactへ書き込まない。
Malformed ID、認証・DB・Transaction障害を新規受付へfallbackしない。
新TableはUpdate／Delete／Truncate拒否TriggerでAppend-onlyを保証する。

## Anti-spam

Contact MutationはCSRF、Exact Origin、JSON Content-Type、Honeypot、Field Length、
20,000 byte本文上限、Unicode NFC正規化を検査する。IPは5回／1時間、Emailは3回／1時間を
HMAC相関Keyで制限する。Limiter障害時はFail Closedとし、429では`Retry-After`を返す。

## Audit And Outbox

Content作成・Version作成・Publish・Unpublish・Archive、Contact受付・閲覧・状態変更・
Internal Note・返信依頼・Rate Limit・Validation拒否をAppend-only Auditへ記録する。
Contact受付と受付確認／管理者通知Outboxは同一Transactionで確定する。
既存受付メール`contact_received`のMail Delivery経路は返信Consumerから独立している。

## Contact Reply Mail Cutover (CONTACT-20260920)

- 新規Admin返信保存は既存Reply Request／Outbox／Idempotency／Audit Transactionを
  維持し、topic `contact.notification`、event `contact.reply.email.requested`を作る。
  旧`contact.reply.requested`、Receipt、Admin Notificationは新Workerのclaim対象外。
  過去のpending行は件数・日時に関係なく不変とし、更新・削除・再送しない。
- `v2:contact:work-reply-mail-outbox --worker=<unique-worker> --limit=10`が専用entrypoint。
  Source提供のみで、既存Identity Worker／Schedulerへの登録やRuntime activationはしない。
  Shared Test／Production実行には別のHuman指示が必要。
- Consumer実行時のUser登録Emailをrecipientに使う。Contact入力Emailは使わない。
  Ownerがない旧匿名Contact等はfail closed。現在のdisplay_name、verifiedかつ
  revokedでないUser電話、削除されていない最新登録住所を使い、欠損は空文字とする。
- 返信Composerは`full_name / phone_number / email / address / inquiry_url`のみを
  一回のplain-text置換で解決する。URLは`v2_identity.origins.user`と既存URL Builderから
  `/contact?inquiry_id=<Public ID>`を作り、Production originをhardcodeしない。
- 解決済み本文を`reply_content`として`contact_reply`テンプレートへ渡す。
  outer rendererでHTML escapeし、reply_contentだけ改行をbrへ変換する。User値／Admin
  入力を再評価せず、既存Sanitizerを前後に維持する。任意HTML／Blade／PHP評価はない。
- テンプレート表示名「お問い合わせ返信」、初期件名「お問い合わせへのご返信」。
  本文はfull_name宛名、受付への謝辞、reply_content、再問い合わせ案内。既存設定から
  件名・本文の編集／Previewが可能。追加catalog変数はreply_contentのみ。
- Mail送信は最大1回のTransport呼び出し。通常例外はfailed、Transport到達後の例外は
  `contact_reply_delivery_uncertain`としてfailed。自動retryは行わない。期限切れleaseの
  再claim（attempts > 1）は送信せずfailedにする。記録前のCrashは送信済みか不明なため
  Exactly Onceは保証しない。Pre-send crashも未送信のまま停止し得る安全側の設計。
  将来の個別再送／Provider照合は別途判断し、既存Outboxを自動変更しない。
- Timestampは`V2DatabaseTimestamp`と既存UTC persistence規約に従う。
  Migration 000076は専用履歴Tableと固定Template行を追加する。
  有効履歴／新Outboxがある場合downは拒否する。通常rollbackはWorker停止とSourceの
  切戻しを先に検討し、履歴を削除するrollbackを行わない。

## Import

MIG-070～072はCategory依存を持たないContent Masterを、Asset公開状態確認後に
Master、Version、Asset Relationの順でImportできる。Contact InquiryはImportしない。
V2 Production開始時のContact件数は0件とする。

## Operations

V2 Migrationは`apps/api/database/migrations-v2`だけに追加し、
`scripts/db/v2_database.py`のGuard経由で実行する。V1 Migration、V1 Runtime、
V1本番DB、Nginx、Domain、TLSは変更しない。
