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

Legal PageのPublish、Published Pageの置換、Archiveには共通Admin Fresh MFA
5分境界を適用する。通常のReadとDraft編集にはFresh MFAを要求しない。

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
Contact送信はIdempotency Resourceを持たず、同じ入力も別の受付番号として受理する。
重複抑止はIP／Email Rate Limitを境界とする。

## Anti-spam

Contact MutationはCSRF、Exact Origin、JSON Content-Type、Honeypot、Field Length、
20,000 byte本文上限、Unicode NFC正規化を検査する。IPは5回／1時間、Emailは3回／1時間を
HMAC相関Keyで制限する。Limiter障害時はFail Closedとし、429では`Retry-After`を返す。

## Audit And Outbox

Content作成・Version作成・Publish・Unpublish・Archive、Contact受付・閲覧・状態変更・
Internal Note・返信依頼・Rate Limit・Validation拒否をAppend-only Auditへ記録する。
Contact受付と受付確認／管理者通知Outboxは同一Transactionで確定する。実Mail、SMS、
Discord送信は後続Taskまで実装しない。

## Import

MIG-070～072はCategory依存を持たないContent Masterを、Asset公開状態確認後に
Master、Version、Asset Relationの順でImportできる。Contact InquiryはImportしない。
V2 Production開始時のContact件数は0件とする。

## Operations

V2 Migrationは`apps/api/database/migrations-v2`だけに追加し、
`scripts/db/v2_database.py`のGuard経由で実行する。V1 Migration、V1 Runtime、
V1本番DB、Nginx、Domain、TLSは変更しない。
