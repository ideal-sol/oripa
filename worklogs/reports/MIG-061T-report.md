# MIG-061T Report

## Issue／PR／Commit

- Issue: #208
- PR: #209
- Base: `149b5d3d882de60e0e67366d4968c5f4bae09010`
- API Application Head: `ef258879433d24bc2eefceedfcb615d3a9634630`
- Admin Application Head: `0c0de98e4c2c4afbe63e80de3ad282d95148a269`
- Final Head／Squash Commit: GitHub Closeout結果として完了報告へ記録
- Task Policy SHA-256: `f0e45473918abc741cc58de93ff626e7c34a08d3606b61581f0be98ad95be5fc`

## V1 Characterization

- V1の紹介設定は、付与ポイント、無償ポイント有効期限、有効／無効の順で単一設定を保存し、紹介成立時の設定値を紹介RecordへSnapshotする。設定変更は過去の紹介／Point Ledgerへ遡及せず、将来成立分だけへ適用する。
- V1の付与先は紹介者のみで、紹介されたユーザーのSMS認証完了時に一度だけ無償ポイントを付与する。最新の人間決定を優先し、V2では紹介者と紹介されたユーザーの値を独立設定に拡張した。
- 重複紹介は紹介されたユーザー単位で拒否し、付与はPoint Operationの一意Business Keyで重複を防ぐ。V1 API／V1 DBは参照しない。

## 実装

- 管理画面は、有効／無効、紹介者ポイント、紹介されたユーザーポイント、有効期限、付与条件、付与タイミング、Revision、更新日時を表示する。Owner／Adminは更新可能、OperatorはRead-onlyで、保存後にCanonical設定を再取得する。
- Admin APIは`GET／PUT /admin/api/v2/settings/referral-points`。`referral.settings.read`と`referral.settings.manage`を分離し、Fresh MFA、Revision OCC、Idempotency、Critical Mutation Rate Limit、Audit／Outbox、RFC 9457、`private, no-store`を適用した。
- Migration `000037`はSingleton設定、紹介Snapshot、User紹介Codeを追加する。初期値は有効、双方0 Point、有効期限180日。既存Userを保持してCodeをBackfillし、設定Singleton、値域、紹介者重複、状態遷移をDB Constraintで保護する。
- 紹介者／紹介されたユーザーの双方へ、SMS認証完了時に無償ポイントを付与する。設定保存では残高を変更せず、成立時Snapshotを使うため過去Record／Ledgerは変更しない。Wallet、Lot、Operation、Ledger、Auditを同一Transaction内で確定し、双方のLock順をUser ID昇順へ固定する。

## Verification／Preview

- Task専用DB `oripa_v2_mig061t`でTarget Safety Guard、Migration fresh、最新Migration rollback／reapplyがPASSした。
- Backend対象8 tests／119 assertions、Admin Unit 2 files／10 tests、Desktop／Mobile Browser 2 tests、OpenAPI lint／bundle、Generated Client、Typecheck、Lint、Production Build、Policy／Quality Gate、`git diff --check`がPASSした。
- GitHub Policy Gateが、Migration `000037`をIdentity境界の一時RepositoryへコピーしていないPolicy Unit Fixture不整合を検出した。Gate条件は変更せず、許可済みTestへ当該Migration Pathを完全一致で1件登録し、Policy Unit 108 tests、Local Policy／Quality Gateを再確認した。
- GitHub Integration Gateが、設定値Constraintの`BETWEEN`がPostgreSQL Backup-Restore時に等価だが異なる括弧へ正規化される差分を検出した。意味を変えず明示的な`>=`／`<=`へ変更し、Task DB fresh、対象4 tests／58 assertions、Backup-Restore Schema完全一致（diff 0）を確認した。
- Setting取得／更新、Owner／Admin／Operator境界、0／最大値／不正値、Revision競合、Idempotency Replay／異内容拒否、Audit／Outbox、設定保存時の残高／Ledger不変、未来成立分Snapshot、双方への一度だけ付与、有効期限、無効時取消を確認した。
- Preview DBへMigration `000037`だけを適用し、Migration集合37件とTarget Safety Guard PASSを確認した。設定初期値は有効、紹介者0、紹介されたユーザー0、有効期限180日である。
- Preview APIを最終`sha256:ed95e50b60a8a1d0e9543e59dff6f8f4b193e1980ed5874bbb2ad3f0201b4fe9`、Adminを`sha256:3b5f759be2c79935076c7e0ad2d20ad17d5897274f7ec197d7de850635882e0f`へ更新した。旧API／Admin ImageはRollback用に保持した。
- Owner Login後に設定を一時変更し、保存後再取得を確認して元の値へ復元した。Revisionは1から3へ進んだが、Wallet合計（有償0／無償7）とPoint Ledger 1件は前後一致し、設定保存だけでは残高／Ledgerを変更しない。
- Desktop／Mobile表示、API／Admin Health、未認証API 401、Console／Page Error、HTTP 500／502／504 0を確認した。Nginx checksum `9832e492f8995db08a45d72f22566d09111d44539524b6509a79b986909f7347`、V1 HTTP 200、Storefront、Payment Providerは非変更である。
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061T/`
- 残課題: Publicな紹介Code受付／登録UIはStorefront対象のため本Taskでは追加していない。
- 主な所要作業: V1設定SnapshotとSMS認証付与条件のCharacterization、V2 Referral foundation不在の確認、Point Transaction／Idempotency境界、Task DB Migration検証。
- Gate G4／G5は`NOT COMPLETE`を維持する。
