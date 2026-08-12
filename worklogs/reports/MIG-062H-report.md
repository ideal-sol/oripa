# MIG-062H ガチャ登録／編集・初回ユーザー条件修正

## Task

- Issue: #245
- PR: #246
- Base: `main@27a19604d87513fc9a66088588bd2697bfeadba5`
- Branch: `feat/MIG-062H-gacha-registration-eligibility`
- Risk: R3
- Task Policy SHA-256: `1a7172163be4ff81acf48798ea46dd528e8007013c4dde86c4e5a60bb09c8cf9`（初版 `f71fe083ef546950ffbd7ee428bcdd743ce1e64987f52264c6727b228b73fb1a`。Admin Contract generator 1 PathをAtomic追加）
- Application Head: `0a2f7db58e8ccc886cb039401b4a69adb203ada6`
- Final Head／Squash Commit: Closeout時に確定

## Schema／Eligibility

- Migration `000044`でGacha Versionへ`first_time_eligible_days`を追加し、Default 7、1以上のDB制約を設定した。既存VersionはDefault 7で移行する。
- Gacha Masterへ`management_status`を追加し、`draft`、`scheduled`、`published`、`sales_paused`、`unpublished`のDB制約を設定した。既存公開Pointer、販売停止、非公開状態から安全にBackfillする。
- `first_time_users`はUser `created_at`から設定日数 x 24時間以内をBackendで判定する。開始境界と終了境界を含み、通常Draw、QA Draw、失敗／Replayを含むDraw履歴は参照しない。

## Admin／Publish

- 新規登録とMaster編集の共通Formへ、初回ユーザー選択時だけ日数入力を表示する。Default 7、1以上の整数Validation、保存後再取得へ接続した。
- Master編集では既存Publish Permission／Fresh Authenticationを再利用し、管理状態を変更可能にした。Published Versionを直接更新せず、Draftと既存Publish／Pause／Unpublish Preflightを使用する。
- 予約公開は`management_status=scheduled`と開始日時を保持し、DB状態を書き換えるWorkerをno-op化した。Public Presentationは既存の現在時刻、期間、在庫条件から`coming_soon`／`on_sale`／`sold_out`／`ended`を都度評価する。
- ガチャ詳細は景品一覧と同じContent幅へ拡張し、Desktop 2列、狭いViewport 1列、長文折返しを維持する。

## Verification

- Task DBでMigration fresh、rollback、reapply、Default、Check Constraintを確認した。既存の通常／Archived Gachaを含むBackfillでも、非Archived行だけRevisionを1増加し、Archived行と保護Triggerを維持することを確認した。
- Backend対象TestはMaster編集5件／34 assertions、予約公開変更5件／99 assertions、Presentation 8件／86 assertions、初回Draw境界2件／4 assertions、Catalog基盤13件／118 assertionsがPASSした。部分Test ContainerのRepository外Path検査だけWarningであり、Assertion失敗は0件。
- Policy Unit 119件、Local Policy Gate、PHP構文、JSON構文がPASSした。
- Admin Unit／Typecheck／Lint／Production Build、OpenAPI lint／bundle、Policy／Quality／Security／Integration／CI GateはGitHub-hosted CIでPASSした。Production Host Buildは行っていない。
- 全SuiteはTask対象外のため実行しない。

## Preview／残課題

- Preview Image Build Workflow Run `31590083432`でApplication HeadのAPI／Admin `linux/amd64` Imageを生成した。外側Artifact digest、内部SHA-256、Image ID、Architecture、OCI revisionを検証し、HostではBuildせず`docker image load`した。
- DB Target Safety Guardで`oripa_v2_mig061a`／`v2-persistent`を確認し、Migration `000044`だけを適用した。既存7 Versionは7日、管理状態はpublished 5件／draft 2件へ移行し、保護Triggerはenabledを維持した。
- API／Adminだけを`--no-build --no-deps`で更新した。両Containerはhealthy、固定IP／Network／Restart Policyを維持した。
- Password-only Login、Session、Gacha一覧／詳細、Master編集Route、Public Catalog、動的Sale State、Desktop／Mobile CSSをSmokeした。HTTP 500／502／504とCritical Errorは確認されなかった。
- Nginx、V1、Storefront Repository、Payment／Point、既存Preview Dataは変更していない。
- G4／G5はNOT COMPLETEを維持する。
