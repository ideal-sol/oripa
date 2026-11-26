# オリポケ / オリパV2 — NEW Production 標準反映手順書（Canonical Release Runbook）

**v1.1（制定 2026-10-20、正式採用は本改訂のmainへのMerge時）**。対象はPlatformおよび承認済みStorefront。正式採用、限定経路の実装・配備、個別Releaseの実行承認は別の決定である。

## 1. 担当交代時の起点

ChatGPT本番反映担当と以下の各Codexは、担当Repositoryの最新mainのAGENTS.md、Platform最新mainの本書、現行Governance・Release Gate、最新の本番状態Handoffを読む。Handoffの実測状態と今回の差分から下表の適用行を選び、最後に完了した工程の次から進める。チャット履歴や過去のGOを権限の根拠にしない。

| 担当 | 作業範囲 |
|---|---|
| OLD・Platform Source Codex | Platform RepositoryのSource、Authority、PR、CI、Mergeを担当し、既存GitHub Actionsで正式Artifactを発行する。 |
| OLD・Storefront Source Codex | 担当Site RepositoryのStorefront Source、Authority、PR、CI、Mergeを担当し、既存GitHub Actionsで正式Artifactを発行する。 |
| NEW本番Codex | 正式Artifactを取得・検証し、非稼働Stage、Readiness、Drain、Writer Fence、限定Migration、Runtime切替、Health、Handoffを担当する。NEWサーバーでのSource改修・PR・Merge・local Buildは禁止。 |
| ChatGPT本番反映担当 | 本書に沿って工程ごとの担当を選び、標準指示を出す。進捗と証跡を引き継ぎ、Human判断が必要な事項をまとめる。 |

通常Human作業は、最終Readiness・一括GO、必要時のALBメンテナンス切替、必要時のDB Snapshot取得、ブラウザ確認・ALB解除の4項目。Codexが準備とその他の通常操作を担当する。秘密値の新規登録・交換・注入、DB Restore、結果不明の決済処理、重大な金融・法務・会計・provider判断、初回商用GOは既存のHuman権限に従う。例外が必要なら停止前に一括GOへ集約する。

## 2. 共通フローと工程別の指示

各行の「標準指示」をその工程の指示本文として使う。工程1で対象と依存関係を確定し、以降は対象ごとに直前の適用行の完了と当該行の開始条件を確認する。PlatformとStorefrontの独立したSource・Authority・Artifact作業は並行できる。NEWの非稼働Stageも対象の正式Artifactと依存条件が揃ったものから並行できる。全対象のStage・Readinessを工程5の前に集約する。完了欄を満たせば次の適用行へ進む。一括GO後はHumanの担当地点と実障害だけで待つ。省略は工程1で根拠をHandoffへ記録する。

| 工程・担当 | 開始条件 | 標準指示（既存経路で行う操作） | 完了・次へ渡す証跡 | HOLD条件 |
|---|---|---|---|---|
| 1 対象確定・Codex | 最新Handoffと反映依頼あり | 差分、依存、契約互換性から更新・据置サービス、ENVキー名、機能状態、適用工程と復旧点を固定する | Scope、パターン、受入結果・条件 | 稼働状態・範囲・復旧点不明 |
| 2 Production Authority・各OLD Source Codex | Scope確定 | 承認済みSource／Tree、契約来歴、Workflow Authorityを区別し、正式Authorityの保護PR経路を使う | Sourceの承認、Tree一致、下記のRepository別Authority検証証跡 | 当該経路の必須証跡不足・不成功、Authority移動・Tree不一致 |
| 3 正式Artifact・各OLD Source Codex | 対象Repositoryの工程2成立、発行権限あり | 一致する有効な正式Artifactを再利用。欠ける対象だけ既存GitHub Actionsの正式BuildをDispatchしてVerifyする | Source・Authorityに結び付くArtifact ID、digest、Image／Build ID、対象アーキテクチャ、必須Security結果 | PR検証用のみ、digest不一致、Security Gate未成立 |
| 4 非稼働Stage・Codex | 検証済みArtifactあり | 対象のみ既存経路で非稼働Stage。現行版・復旧資産、対象別Health、旧状態との互換、必要なFence・実行器版のOLD Test証跡を照合する | Stage同一性と最終Readiness、Human作業・例外・予想停止範囲を一括提示 | 経路未配備、権限不足、未検証の操作、必要な資産不足 |
| 5 一括GO・ALB切替・Human | 工程4完了 | 固定した反映内容・Migration・例外を一括承認し、停止対象ならALBをメンテナンスへ切り替える | 対象に結び付くGO、必要時はメンテナンス応答確認 | 未承認・対象変更・流入遮断失敗 |
| 6 金融Drain・Writer Fence・Codex | GO、必要な流入遮断成立 | 対象Timerの新規起動を抑止し、処理中の決済・送信・PutをDrain。内部・外部Writerの停止と再起動抑止を既存経路で確認する | 対象処理の確定・未確定数、Writer停止状態、復帰対象 | 未確定の外部結果、書込継続、Fence喪失 |
| 7 DB Snapshot・Human | 必要なDrainとFence成立 | Humanが対象DBの新規Manual Snapshotを取得。Codexは限定状態照会またはHuman取得証跡で対象・取得時点・availableを照合する | Fence後の該当Snapshot available | 別DB、未完了、取得後の書込 |
| 8 固定Migration・Codex | 個別Releaseの一括GO、工程6・7成立、限定経路導入済み | §4の照合後、対象ファイルだけを指定順で適用し、各結果と終了状態を確認する | 承認済みLedger遷移、Schema・データ整合性、完了記録 | 差異・例外・状態不明で即停止。自動再試行禁止 |
| 9 Runtime切替・Codex | Stage・GO成立、適用対象なら工程8完了 | Stage済みのAPI→Health→依存サービス・Storefront→Worker／Timerの順で対象だけ切替。承認済み機能状態・起動状態へ戻す | 各版、必要なAPI・DB・Redis・Storage Health、Worker／Timer復帰確認 | 対象違い、継続的Health異常、復帰不能 |
| 10 ブラウザ確認・ALB解除・Human | 技術Health成立 | 対象画面・必要なログイン操作を確認し、使用したALBメンテナンスを解除する | Human受入と通常流入復帰、または適用外理由 | 受入失敗・流入復帰失敗 |
| 11 最終確認・Handoff更新・Codex | 対象受入・流入復帰 | 通常経路のHealth、実際の稼働版・Ledger・機能状態と残課題を確認し、既存の本番状態Handoffを更新する | §6形式の完了報告、証跡、復旧資産、次担当の開始点 | 実測不一致は未完了として引き継ぐ |

**工程2から3への移行条件**。各Repositoryの正式Workflowが要求するAuthorityをDispatch前に照合し、対象SHAと証跡を引き渡す。

- Platformは、現行protected mainのWorkflow Authority SHAでRequired 5 checksがすべて成功し、固定Runtime SourceのReviewed HEADでもRequired Checksが成功していることを確認する。
- Storefrontは既存の `production-provenance` に従い、固定SourceのReviewed HEADとTree・Required Checksを照合する。現行mainに対象Checkが1件でもあれば、同じSHAでRequired Checksがすべて成功するまで待つ。対象Checkが0件の場合だけ、同経路が特定する正式なMerge済みPRのReviewed HEADが現行mainと同一Treeで、Required Checks成功済みであることを確認し、その証跡を再利用する。この場合はmain CIを追加実行しない。

工程6の判定単位は対象サービスの仕事と書込能力。Mail／SMSのアイドルポーリングだけで金融処理中とは判定しない。freeze前後でPID一致を求めず、対象unit・containerの停止、新規処理の抑止、処理中件数と外部結果を照合する。不明分を消すための再送・削除は禁止。

## 3. パターンと省略条件

全パターンで工程1～5のScope・Authority・正式Artifact・非稼働Stage・一括GO、工程9の対象Health、工程11を適用する。Stageは同一性が有効なら既存証跡を使う。互換性と既存切替経路から無停止と判断できる場合はALB操作を省略する。停止が必要なら工程5・10でHumanが切替・解除する。DB変更を伴わないときは新規SnapshotとMigrationを省略できる。現行Release Gateのbackup／restore証跡は維持する。

| パターン | 切替・確認対象 | Drain・Fenceと受入 |
|---|---|---|
| API-only、Migrationなし | APIだけ切替、該当API・依存Health | Writer・金融処理に影響するときだけ工程6。画面影響があれば工程10 |
| Admin-only | Adminだけ切替、Admin Health | API契約が互換ならDB工程・Writer停止を省略。Humanが対象Admin画面を確認 |
| Storefront-only | 承認済みSiteのBuildとAPI契約を照合しStorefrontだけ切替 | 互換ならDB工程・Writer停止を省略。Humanが対象Storefrontを確認 |
| Migrationなしの複数サービス | 依存順に対象だけ切替・Health | 影響するWriterだけDrain。対象画面を確認 |
| Migrationありの一括反映 | 工程1～11。単一サービスでもMigrationがあれば同じDB条件 | 金融Drain・全該当Writer Fence、Snapshot available、前後照合が必須 |
| Worker／Timerのみ | 変更対象の起動抑止・Drain・切替・復帰、次回起動状態を確認 | 外部送信の結果不明時はHOLD。DB変更・画面影響・流入遮断がなければ工程7・8・10を省略 |

## 4. Migration専用経路と機密境界

一括GOは対象Site、Release、正式Source／Tree、Image digest、MigrationファイルごとのSHA256・順序、期待する前後Ledgerと検査項目に結び付ける。実行直前に限定経路内でImage内のファイルと固定値、実DBのLedger・必要Schema・データ整合性、SnapshotとFenceの有効性を照合する。単一起動に限定し、各ファイルの成功を確認して次へ進む。適用後も同じ検査項目を照合する。MigrationごとのGO・Human入力は不要。

既存DB接続情報・サービス固有ENVは実行環境内だけで利用する。Codexによる秘密値・接続情報の取得、閲覧、表示、コピー、記録は禁止。任意SQL・任意コマンド、汎用root、Docker制御、全ENV参照、全件migrate、Ledger書換え、自動rollbackを許可しない。結果出力は工程、対象ファイル識別子、結果区分、許可リストのエラー種別・SQLSTATE、合否と必要な集計だけ。生ログ、例外本文、SQL・パラメータ、会員情報を返さない。

異常時は後続処理を止め、試行記録とFenceを保持する。状態不明・一部適用時は限定照会でLedgerと事後条件を照合し、確定済み工程を再実行しない。復旧判断が必要ならHumanへ引き継ぐ。Snapshotは外部決済・送信を復元しない。DB Restoreと隔離Restore Testの要否は既存Gateと当該ReleaseのHuman判断に従う。

## 5. 共通実行器の採用条件

正式Artifactの発行・検証は既存の[Platform ARM64経路](platform-production-arm64-runtime.md)と承認済みSite側経路を使う。11月25日のOne-shotは適用実績があるが、Creator／Readerの内部例外とHuman専用起動があった。成功したStage・切替・One-shotの操作を再利用する。Codex専用入口の配備・権限は本改訂では付与しない。

最小実装案は、既存One-shotを呼ぶ固定の入口と結果照会をRepositoryで版管理すること。ホスト側の信頼済み設定が承認済みReleaseとdigestを解決し、呼出者にはそのReleaseの実行・状態照会だけを許可する。呼出者がImage、ファイルパス、ENV、SQL、引数、実行器や承認情報を差し替えられないようにする。同時・重複起動を排除し、開始記録を永続化して切断後も状態を照会できるようにする。既存のStage・Health経路は維持する。

別GOで実装したら、OLD Test専用のDB・ENVと合成データで、同じ実行器版・呼出権限・実際の起動／照会経路を通す。正常系はStageからDrain、Fence、Snapshot条件、順次適用、Ledger・Schema・整合性、復帰まで確認。異常系はアイドルポーリング・PID交代、処理中Writer、誤digest・順序、Snapshot不足、二重起動、Creator／Reader例外、途中切断を再現し、拒否・安全な診断・再適用防止を確認する。模擬秘密値が出力に現れないことも確認する。結果を版・入力・権限条件に紐付け、NEWへの限定配備・適合確認まで停止前に済ませる。

リリースごとに変えるのはScope・Authority・Artifact・Migration・ENV対象キー・運用条件・個別承認だけ。実行器本体・権限境界・基本順序を都度変更せず、専用Creator／Reader／Starter／Observer／Validatorを原則新設しない。メンテナンス中の開発・再封印は原則禁止。実障害は安全に停止・復旧し、修正を要する版は別途OLD Testで検証する。

### 限定Migration入口のSourceと実証

工程8の共通入口は [production-migrations](../../../scripts/ops/production-migrations) の `run RELEASE_ID`、`status RELEASE_ID`、明示復旧用の `resume RELEASE_ID`。Stage・切替・復帰は上表の既存経路で行い、Migrationなしの場合はこの入口を使わない。配備時は呼出者にこの入口だけを許可し、実行ファイル、Python、設定、証跡DB、ホストアダプターとその依存物は呼出者が変更できない所有・権限にする。呼出者にENV指定、インタープリター、Dockerソケット、DB接続を渡さない。

- 非稼働Stageの既存証跡から、Source・Tree・Authority、Artifact・Image・Build、対象サービス、運用条件とアダプターのSHA256、順序付きMigration名・SHA256・前後Ledger件数とdigestを解決する。Ledgerは順序付きMigration名（拡張子なし）のUTF-8 JSON配列を空白なしで符号化し、SHA256を取る。
- 限定入口は、ホスト側で初回登録した証跡発行元と項目対応（`evidence-sources.json`）を使い、既存Stage・一括GO・Snapshotの保護済み証跡を読む。GOは認証済みHumanによる対象Site・Release・本番反映の承認、発行時刻・有効期限、Stage原本のSHA256を含む。Snapshotは同じStage・対象DB・連続Fenceに結び付いた手動取得記録と、現在availableである実測を照合する。証跡を自動発行する上流とその認証・所有境界を実証時に確認する。呼出者による生成・差替えは禁止。通常4工程で必要項目が発行されない環境は接続未成立としてHOLDする。
- 固定アダプターとのやり取りはホスト内のJSON標準入力／出力に限る。[共通制御Source](../../../scripts/ops/production_migrations.py) の `Host.call` と `verify_reader` が入出力仕様である。アダプターは各呼出しでRelease・plan digest・phase・index・nonceを照合して応答に結合する。`fence` は最新の金融Drain、対象DB、連続Fenceと同じSnapshotがavailableである証跡を取得する。`create` はこのFenceを再確認し、成功版Creatorの固定Image・設定照合と未起動Runner作成を行う。既存Runnerとの衝突・作成状態不明なら停止する。
- `pre`／`post` は修正版Readerの作成・起動・終了とObserverによる読取り専用トランザクションのrollback、Image内ファイルのhash、Ledger・Schema・履歴・金融検査を含む。`start` はStarterの責任で**起動直前**に同じGO・Fence・Snapshot、pre receiptのhashと鮮度、実測RunnerのID・Image・revision・コマンド・Mount・未起動状態を再照合し、排他的intent後に1件だけ起動する。postは同じRunnerの単一起動・終了0とpreへの結合を証明する。生ログ、ENV、照会結果はアダプター内部に留める。運用条件packと私有ヘルパーの同一性をアダプター側でも検証する。
- [共通ホストアダプター](../../../scripts/ops/production_migration_adapter.py) は全Releaseで同じ実装とする。固定Engine APIで未起動Runnerを作り、IDを永続化する。起動は検証済みIDへの1回に限る。ホスト専用の `conditions.json` は対象DB・architecture、封印済み `measurement.py` と読取り専用照会packのSHA256を持つ。非公開部分の契約は `measure`（実測Fence・金融Drain・Snapshot）、`runner_template`／`check_parity`（元の設定と実体の完全照合）、`reader_spec`／`read`／`evaluate`（承認済み照会、rollback済み観測、Ledger・Schema等の判定）、`runner_history`／`quiescent`（実起動回数と未処理要求の有無）。照会時の `read` はRunnerを作成・起動しない。新しい非対話入口へは成功版の検証処理を接続し、既存のHuman専用入口の認可・TTY検査を維持する。
- 切断・HOLD後の入口はまず `status RELEASE_ID`。最終phase・index・post確定件数を保持し、最初の未確定ファイルだけを限定照会する。アダプター応答は出力途中・終了待ちも含めて照会5秒、その他300秒が上限。期限超過は照会ならUNKNOWN、実行ならHOLDとし、Runner・DB・実行中アダプターには終了命令を送らない。この上限はDBの実行時間制限ではない。
- 復旧は同じReleaseの `resume RELEASE_ID` で明示する。`run` の再呼出しは既存記録を返す。排他ロックを実行アダプターにも継承し、前の呼出しが残る間は復旧を開始しない。アダプターは非同期依頼の行方、Runner ID、元の連続Fence・Snapshotと最新のDB実測を照合する。`settled` は先行する作成・起動要求が後から発効しないことまで確認できた場合だけ真とする。

| 未確定ファイルの照会結果 | 正式な復旧経路 |
|---|---|
| NOT_STARTED（未実行） | 同じRunnerが未起動・起動intentなし、前Ledger・Schema・整合性が一致すれば、新しいpre照合から再開する。Creator直後なら元のRunner集合全体を検証して引き継ぐ。Runnerがなく作成・起動intentも保留要求も存在しないことを証明できた場合だけ作成に進む。 |
| RUNNING（実行中） | Fenceを保持し、後でstatusを再照会する。再起動・強制終了はしない。 |
| APPLIED（適用済み） | 保存したpreに対応する同じRunnerの単一起動・終了0、実ファイル、後Ledger・Schema・履歴・金融整合性を含む新鮮なpost証跡が揃った場合のみ、resumeでpostを追記して次の未確定ファイルへ進む。 |
| UNKNOWN（不明・失敗・照合不能） | 同じ試行とFenceを保全してHOLD。終了表示単独・部分適用・元証跡不足はこの区分。限定照会で解消できなければHumanの個別復旧判断へ引き継ぐ。 |

resumeは有効なGO・同じplanとアダプターを再照合し、従来のINTENT／HOLD・理由・復旧判断を追記してから進む。以前のpre／postは保持する。証跡DBの削除、状態の強制書換え、別Release IDによる回避を禁止する。

OLD Test実証は別GOで、通常のoldtest-deployから独立した専用DB・合成データ・試験用権限で行う。実証対象はこのPRの固定HEAD／Tree、入口・共通制御・共通ホストアダプターのSHA256、対応する非公開計測Source・条件と照会packのSHA256を一組で封印する。専用呼出者で同じ `run`／`status`／`resume` を使用し、§5の正常・異常系、並行起動・切断後の再呼出し、呼出者からの設定変更拒否・秘密値非出力まで確認する。資料中の伏せ字版を実行せず、ホスト側の接続と実証の完了をStage前に記録する。

## 6. 指示・引継ぎ・完了報告

指示形式は「Release ID／Runbook版／工程番号・担当／対象と固定入力・既存証跡／工程表の標準指示／完了・HOLD条件／次の適用工程」。担当交代時にも同じ形式を使い、チャット独自のGate・追加GO・全件再テスト・再Build・再Stage・順序変更を加えない。

既存Handoffに次を記録する。公開記録は公開可能な識別子と集計に限り、非公開の運用詳細は既存の限定保管先に置く。

```text
Release ID / Runbook版 / 適用パターン / 現在・次の工程 / 担当
Platform・SiteのSource / Tree / Workflow Authority / Contract
Artifact ID / Image digest / Build ID / 実行器版・OLD Test証跡
更新・据置サービス / Worker・Timerの元状態と復帰状態 / Feature ON・OFF
ENV変更キー名のみ / 運用条件 / 個別承認の対象・期限
Migration NONE または順序付きファイル・SHA256・期待／実測Ledger・検査結果
ALB / Drain・Fence / Snapshot available / 一括GOの範囲と証跡
各工程番号 / PASS・HOLD・未実施・適用外と理由 / 実施者・時刻・証跡
最終稼働版・Health / Human受入・流入復帰 / 所要時間 / 復旧資産・残課題・次の操作
```

標準からの逸脱は理由・必要性・影響範囲、代替手順、Human承認と有効範囲をHandoffに明記する。恒久変更は別途Human決定と本書の改訂を要する。11月25日のPaidy残存5件の個別判断とSecurity限定受容は当該Release限り。過去のGO、例外、SourceやSnapshot識別子を次回に転用しない。Schema適用後の機能単体有効化でMigrationを省略した実績は、初回機能反映の省略根拠にしない。
