# B2クラウド配送CSV — Source Draft handoff

Change: SHIP-B2CSV-20261031 / Strict Change / R3 / Runtime Activation deferred.
Authority: Human `SHIP-B2CSV.md`（追加補足を含む）と仕様書v1.1 B.1–B.8。

## 現在の受入状態

`B2_OFFICIAL_HEADER_PENDING`。Source実装と自動テストの検証範囲を示す文書であり、
正式Merge・OLD Test・Productionへの反映承認ではない。
公式97列CSVヘッダー完全一致、ログイン後Browser、実際のB2取込は未実施。
正式ヘッダー確認とHuman AcceptanceまでMergeは保留する。

ヘッダーは `apps/api/config/v2_b2_shipping.php` の `headers` 一か所に集約。
Human指定の既知列名を優先し、他列は
[ヤマト運輸の入出力レイアウトPDF](https://bmypage.kuronekoyamato.co.jp/bmypage/pdf/new_exchange1.pdf)
のNo.1–97項目名を参照した暫定ラベルである。
PDF項目名称を実CSVの正式ヘッダーと同一とは扱わない。
後日のラベル差替は位置マッピングを変更せず、97列を維持して再テストする。

## 出力と警告

配送一覧で1〜500配送を選択し、既存CSVボタンの右の「B2クラウド形式でCSV出力」を押す。
`POST /admin/api/v2/shipping-requests/export-b2` は既存のAdminセッション・MFA・CSRFと
`shipping.request.manage` 権限を使用する。配送状態は変更しない。
既存9列UTF-8 BOM CSVのAPI、ファイル名、データ形式は維持する。

配送Public IDを重複排除し、1配送1行、97列、CP932（Windows-31J）、BOMなし、CRLF。
ファイル名は `b2cloud-shipping-YYYYMMDD-HHmm.csv`（JST）。
品名1は固定設定値、品名2は景品点数。実際のカード名や高額景品名を出さない。

Adminは `Accept: application/json` で、同じ生成結果の `csv_base64`、`filename`、
`warning_count`、`warnings` を一度に取得し、バイナリBlobとしてダウンロードする。
CSVと警告の取得時差を避け、500件すべての警告をHTTP本文で返す。警告詳細のヘッダー格納、
一時ファイル、ジョブ、DBへのCSV保存は行わない。`Accept: text/csv` はCSVのみ返す。
どちらも設定検証・ID検証・全件生成を終えてから応答する。
レスポンスは配送先情報を含むため保存・ログ出力しない。

警告一覧は配送IDとコードのみ表示。件数は警告を持つユニーク配送ID数。
`ADDRESS_TOO_LONG`、`BUILDING_TOO_LONG`、`NAME_TOO_LONG`、`PHONE_INVALID`、
`UNSUPPORTED_CHARACTER` を同一配送についてもすべて保持する。
氏名・住所は切り詰めない。住所の空白は数字間を除いて除去し、長すぎて建物欄が空の場合のみ
元streetの最後の空白で分割を試し、両欄が上限内の場合に適用する。
長さは実際のCP932出力で全角2・半角1として換算する。α・①等も2として数え、
UTF-8バイト長やUnicodeの曖昧幅を使わない。
髙・①等のCP932拡張文字は保持して警告し、非対応文字は〓に置換して警告する。
電話番号は先頭+81だけを国内0に変換し、ハイフンにアポストロフィーを付けない。
監査 `shipping.b2_csv_exported` は配送件数・警告配送件数と既存の監査属性のみ記録する。

## Humanが設定するENV

以下はキーと既定値のみ。実設定は別途承認された反映でHumanが行う。
実値をPR、Issue、Worklog、ログ、チャットへ記載しない。

| キー | 既定値・制約 |
| --- | --- |
| V2_B2_INVOICE_TYPE | `0`。PDF列挙の0/2/3/4/5/6/7/8/9/A |
| V2_B2_SENDER_NAME | 空、必須。半角換算32文字以内 |
| V2_B2_SENDER_PHONE | 空、必須。半角数字と区切りハイフン、15文字以内 |
| V2_B2_SENDER_POSTAL_CODE | 空、必須。半角7桁または123-4567形式 |
| V2_B2_SENDER_ADDRESS | 空、必須。半角換算64文字以内 |
| V2_B2_SENDER_BUILDING | 空、任意。半角換算32文字以内 |
| V2_B2_ITEM_NAME | `トレーディングカード(置き配禁止)`。半角換算50文字以内 |
| V2_B2_BILLING_CUSTOMER_CODE | 空、必須。半角数字9〜12桁 |
| V2_B2_BILLING_CLASS_CODE | 空、任意。指定時は半角数字3桁 |
| V2_B2_FREIGHT_NUMBER | 空、必須。半角数字2桁 |

設定不足・不正は503 `B2_EXPORT_NOT_CONFIGURED`。Admin表示は
「B2クラウドの出力設定が未設定です」。実際の値や不足キーを応答へ出さない。
依頼主や品名の文字種・長さ不正も設定エラーとして処理する。
送り状種類別に必要な追加列の自動補完は実装しないため、Humanは利用する種類と設定で
取込受入を行う。Migration・依存追加・テンプレートデータ変更はない。

## 後日のHuman Acceptance

OLD Testへの別途承認された反映とテスト用設定後に実施する。現時点は全項目未実施。

1. Adminへログインし、配送一覧で架空のテスト配送2件を選択する。
2. B2ボタンでダウンロードし、必要な警告件数・配送ID・コードを確認する。
3. 正式97列ヘッダーと照合する。現在のラベルは暫定である。
4. B2クラウドの基本レイアウトでCSVを取り込む。**取込開始行は2行目**にし、ヘッダーをデータとして扱わない。
5. 文字化け、列ずれ、配送先、固定品名、個数、請求先対応を確認する。
6. 出力前後の配送状態が変わらないこと、既存CSVも従来どおり利用できることを確認する。

Source rollbackは当該Source差分のrevertで行う。運用環境のENV変更・撤去は別途承認と
既存リリースゲートに従う。本タスクではRuntime変更、実配送、実メールは行わない。
