# MIG-062M QAテストユーザー抽選UI統合

## Task

- Issue: #255
- PR: #256
- Base: `main@608d59a18215bf16109d7a9765c18f82940f6bde`
- Branch: `feat/MIG-062M-qa-test-user-draw-integration`
- Risk: R4
- Task Policy SHA-256: `fabfc8911c1392e88b05b0801827edd16fa16356ef7604f9e76c6073c59b71c8`
- Policy補正: 無期限化に伴う既存QA管理UI変更用の
  `apps/admin/src/components/qa/qa-management-workspace.tsx`だけをAtomic追加し、
  その他Field不変を機械確認した。

## Domain

- `qa_test_user_modes`を重複BooleanなしのCanonical Test User flagとして維持し、`ends_at` nullableで手動OFFまで無期限にした。既存の期限切れModeはMigration時にdisabledへ移し、再有効化しない。
- `qa_gacha_guarantee_assignments`で`User x Gacha x stable Prize`を保持する。User／Gachaは変更不可、UserとGachaは一意、Prize ownershipはDB TriggerとServiceでCross-Gachaを拒否する。
- ActiveかつTest User ONのUserと、Canonical Published Versionで抽選可能かつ在庫のある同一Gacha PrizeだけをAdmin候補に返す。Published relationで解決不能な設定は`PUBLISHED_PRIZE_UNAVAILABLE`として表示し、DrawはFail Closedする。
- 既存Consumable QA Plan／Assignment／Resolver／Execution／Auditを維持し、Persistent guaranteeと同一User／Gachaで同時Activeにできない。独立Draw Engineは追加していない。

## Draw

- Persistent guaranteeがあるRequestだけ、Result先頭1件を指定Prizeとして保証し、残りを既存Probability／Inventoryで通常抽選する。設定なしまたはTest User OFFでは通常Drawとなる。
- Point、Inventory、`won_count`、`sold_count`、Draw Result、User Prize、Point Back、履歴、Audit、Outbox、Idempotencyは既存`V2DrawService`の同一Transactionを使う。
- 1／5／10／100／1000回で保証枠は最大1件。MIG-062Jのrequested 1000／remaining 900ではexecuted 900、保証1＋通常899として整合する。
- Replayは初回Responseと保証結果を再利用する。Concurrent Drawは既存Lock後の在庫を正本とし、保証在庫不足時は部分MutationなしでRollbackする。

## Admin／Contract

- User詳細へテストユーザーON／OFF、無期限状態、理由、最終更新を追加した。既存`qa.draw.manage`、Fresh MFA、OCC、Idempotency、Auditを再利用する。
- Gacha詳細へ複数Test Userの保証Prize設定、更新、解除、不整合表示を追加した。長いPublic IDを含むMobile formの横溢れを防止した。
- Admin OpenAPI／Generated ClientへUser mode取得とGacha guarantee管理を同期した。
- 既存Admin Contract互換のため旧`starts_at`／`ends_at`入力はdeprecated optionalとして受理して無視し、旧Mode Responseの非nullable `ends_at`には無期限を表す互換日時を返す。DB／DomainのCanonical `ends_at`は`null`のまま維持する。
- Public Draw Response Shapeは通常Resultのまま変更なし。Public OpenAPI、Storefront Client、Site Schema、Testkit、Storefront Artifactは更新しない。

## Verification

- Migration `000049`: Task DB fresh、rollback、reapply、既存期限Mode移行、DB Guard PASS。
- Backend: 対象62 tests／594 assertions PASS。1000連、同時10 Request、負残高0、在庫超過0。
- Admin: Unit 33 tests PASS、対象Browser 8 tests PASS、Typecheck／Lint／Production Build PASS。
- Contract: Admin OpenAPI 209 operations bundle PASS、Generated check PASS、Public差分0。
- Admin Contract compatibility: OpenAPI Breaking Check PASS、対象7 tests／80 assertions PASS。
- Policy Unit 124 tests、Policy Gate、Quality Gate、PHP syntax、`git diff --check` PASS。

## Preview／Closeout

- GitHub Required Checks成功後、exact PR HeadのGitHub-hosted amd64 API／Admin Imageだけを検証・loadし、DB Safety Guard後にMigration `000049`だけを適用する。
- Admin User ON、Gacha guarantee、通常Public Draw、保証1件＋通常抽選、Point／Inventory／User Prize／履歴、Replay、OFF後通常Drawを最小Synthetic dataで確認する。
- Nginx、V1、Storefront Repository、Payment、Public contractは変更しない。G4／G5はNOT COMPLETEを維持する。
