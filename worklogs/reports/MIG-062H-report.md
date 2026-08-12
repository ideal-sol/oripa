# MIG-062H ガチャ登録／編集・初回ユーザー条件修正

## Task

- Issue: #245
- PR: 作成後に確定
- Base: `main@27a19604d87513fc9a66088588bd2697bfeadba5`
- Branch: `feat/MIG-062H-gacha-registration-eligibility`
- Risk: R4
- Task Policy SHA-256: `956574d49238251cc0ccd4c21adeb7193c0584b61bb84c17e1edf33840e6581a`（初版 `f71fe083ef546950ffbd7ee428bcdd743ce1e64987f52264c6727b228b73fb1a`）
- Application Head／Final Head／Squash Commit: Commit、Checks、Merge後に確定

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

- Task DBでMigration fresh、rollback、reapply、Default、Check Constraintを確認した。
- Backend対象TestはMaster編集5件／34 assertions、予約公開変更5件／99 assertions、Presentation 8件／86 assertions、初回Draw境界2件／4 assertions、Catalog基盤13件／118 assertionsがPASSした。部分Test ContainerのRepository外Path検査だけWarningであり、Assertion失敗は0件。
- Policy Unit 119件、Local Policy Gate、PHP構文、JSON構文がPASSした。
- Admin Unit／Typecheck／Lint／Production Build、OpenAPI lint／bundle、Required ChecksはGitHub-hosted CIで実施する。Production Host Buildは行わない。
- 全SuiteはTask対象外のため実行しない。

## Preview／残課題

- PreviewはRequired Checks後、GitHub-hosted amd64 Build Artifactを検証してAPI／AdminをHost Buildなしで更新し、Migration `000044`だけをSafety Guard後に適用する。
- Preview結果、Image、Smokeは反映後にRepository外Evidenceと本Reportへ追記する。
- G4／G5はNOT COMPLETEを維持する。
