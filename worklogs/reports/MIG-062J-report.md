# MIG-062J 残口数未満時の端数Draw対応

## Task

- Issue: #249
- PR: #250
- Base: `main@38c10edcabed971cd5a18cfe43c3b95e87c2d1b9`
- Branch: `feat/MIG-062J-partial-remaining-draw`
- Risk: R4
- Task Policy SHA-256: `72095b56fc0c6a90069fbbb789d158f3d7679c11c44fe07c60238c89c6402e1a`
- Final Head／Squash Commit: Closeout時に確定

## Presentation／Contract

- Public `allowed_draw_counts`はGacha設定、販売状態、Eligibility、JST日次上限、既存Draw条件を維持し、残口数だけを理由に設定Countを除外しない。
- Public Drawは`requested_count`へ設定Countを保持し、`executed_count`を1からrequested countまでの整数として返す。Public／Admin OpenAPIとGenerated Contractを同期した。
- Storefront Client、Site Schema、Storefront Testkitを`2.0.0-alpha.10`へ更新する。Testkitは「設定1000・残900・実行900・sold out・Replay 900」を型付きFixtureとして提供する。

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
- Previewは検証済みGitHub-hosted amd64 Imageだけを使用し、Host Buildは行わない。Migration `000046`のみをSafety Guard後に適用する。
- 全Suite、Storefront Repository、V1、Nginx、Point／Payment仕様、Draw Algorithmは変更しない。G4／G5はNOT COMPLETEを維持する。
