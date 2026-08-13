# MIG-062J 残口数未満時の端数Draw対応

## Task

- Issue: #249
- PR: #250
- Base: `main@38c10edcabed971cd5a18cfe43c3b95e87c2d1b9`
- Branch: `feat/MIG-062J-partial-remaining-draw`
- Risk: R4
- Task Policy SHA-256: `8fef3c6d77498facc58c3cb01d25b8177303b2d9125a67f905ca6da5e5747eae`
- Final Head／Squash Commit: Closeout時に確定

## Presentation／Contract

- Public `allowed_draw_counts`はGacha設定、販売状態、Eligibility、JST日次上限、既存Draw条件を維持し、残口数だけを理由に設定Countを除外しない。
- Public Drawは`requested_count`へ設定Countを保持し、`executed_count`を1からrequested countまでの整数として返す。Public／Admin OpenAPIとGenerated Contractを同期した。
- Storefront Client、Site Schema、Storefront Testkitを`2.0.0-alpha.10`へ更新する。Testkitは「設定1000・残900・実行900・sold out・Replay 900」を型付きFixtureとして提供する。
- `alpha.10` ArtifactはApplication Head `ed57eca709c9a49fc5bb5ffa9903a84573052077`から`/var/lib/oripa-v2-evidence/MIG-062J/artifacts/2.0.0-alpha.10/`へ新規生成した。既存Versionは変更していない。
- Artifact SHA-256: Client `2cbee027ba6224af791c47cdb6f8d1dc3b9cd5feb6890d2c568a65f4ddead91d`、Testkit `d64ca49843394c6f6147079f7dc6e9646b8e72dfadd845dfac096a6dff821386`、Site Schema `b201f79f2d784e2980fb1c89e5e8d71c72c5d30786dff2721681312343580f45`、Public OpenAPI `e84d9f59c6e1daa9c4611e72bb588681c89354ee1eccef77dc42ccb15555c811`、Manifest `693010eefd27ba7d45886a44f6370836dadf7afeaa649dc3110be547072fc9fc`。

## Transaction／Idempotency

- Gacha／Draw StateをLockした後の`total_count - sold_count`をCanonical remainingとし、通常Drawだけ`min(requested_count, remaining)`で実行数を確定する。
- Point消費、Result、User Prize、Point Back、Inventory、`sold_count`、Draw履歴、Audit、Outboxを`executed_count`へ揃えて同一Transactionで確定する。
- Idempotency Replayは初回のrequested／executed countとResponseを返し、Point、在庫、結果を再Mutationしない。
- Daily Limitはrequested countへ既存判定を適用し、残口数以外の理由では端数実行しない。QA Drawの既存exact count動作も維持する。

## Verification／Preview

- Migration `000046`はcompleted Draw Requestへ`0 < executed_count <= requested_count`を許可し、rollbackで既存の完全一致Constraintへ戻す。Task DBのfresh／rollback／reapplyはPASSした。
- Backend対象はDraw 19 tests／178 assertions、Presentation 11 tests／97 assertions、Concurrency 1 test／11 assertionsがPASSした。
- OpenAPI bundle/check、Generated Client同期、Storefront Client、Site Schema、TestkitのTypecheck／Lint／Build、Policy Unitを対象検証する。共有依存環境の欠損とGitHub CI結果は最終記録で区別する。
- Quality Gateで検出した「数値Enumから全旧値を包含する整数Rangeへの拡張」を、OpenAPI Breaking Checkerで非Breakingと判定する対象補正を追加した。旧値を外すRangeおよび文字列Enum削除は引き続き拒否する。
- Fresh Install環境のTestkit固定Export Surface検査へ、正式追加した`PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE`を登録した。
- 独立したTestkit Export GateのRoot／Fixture Entry Pointにも同FixtureをExact登録した。
- Numeric enumから全旧値を包含するinteger rangeへの非Breaking拡張を対象Unit 7件で確認し、文字列Enum削除および旧値を外すRangeは引き続きFail Closedとした。
- Artifactの`SHA256SUMS`4件、Manifest整合、Repository Workspace外のpnpm clean installとRuntime importはPASSした。
- Previewは検証済みGitHub-hosted amd64 Imageだけを使用し、Host Buildは行わない。Migration `000046`のみをSafety Guard後に適用する。
- 全Suite、Storefront Repository、V1、Nginx、Point／Payment仕様、Draw Algorithmは変更しない。G4／G5はNOT COMPLETEを維持する。
