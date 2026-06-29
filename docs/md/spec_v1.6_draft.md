# Luxe-pack Specification v1.6 Draft

This document records approved or proposed changes after `docs/md/spec_v1.5.1.md`.

Specification priority is:

1. Latest explicit human decision
2. `docs/md/spec_v1.5.1.md`
3. `docs/md/spec_v1.6_draft.md`
4. `docs/md/spec_v1.5.md`
5. `docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md`
6. `docs/md/spec_v1.4.md`
7. `AGENTS.md`
8. `TASK_BOARD.md`
9. `docs/SHARED_CONTEXT.md`
10. `docs/md/all_check.md`

## Additional Specifications

### v1.6-DRAFT-001: Gacha Category Description Column

Specification name: ガチャカテゴリ説明カラム追加

Formal specification:

- Add `gacha_categories.description`.
- Type: nullable `text`.
- Maximum length: 2,000 characters.
- Use in admin category registration and category editing.
- The admin category list may receive `description` through API data, but the description column is hidden by the latest explicit human decision.
- Return `description` from the admin API.
- Include `description` in public API `gacha.category.description`.
- Do not display category descriptions on user-facing pages at this stage.
- Showing category descriptions on user-facing pages is a separate future task.
- Only enter content that may safely be shown to users.
- Do not enter internal notes, supplier information, cost information, management-only notes, or personal information in `description`.

Implementation status:

- Migration applied.
- Admin API implemented.
- Admin screen implemented.
- Public API implemented.
- User-facing screen display not implemented.
- Target tests passed.

Notes:

- The current explicit human decision after implementation is that the category list should not display the description column.
- Registration and editing screens still use the description field.

### v1.6-DRAFT-002: Daily Point Balance Snapshots

Specification name: 日次残高スナップショット

Current status:

- `point_balance_snapshots` table and Model exist.
- Service, Command, Scheduler, and tests are not implemented or not yet confirmed.
- This is the next pre-release critical backend task.

Formal requirement:

- Store daily unused point balances separated by paid and free points.
- Use the stored daily balances as the basis for funds settlement law support and future reporting.
- Preserve the ability to identify reporting date balances such as March 31 and September 30.
- Keep implementation in Laravel; do not calculate or persist this logic in Next.js.

Implementation scope for next task:

- Add a domain Service that calculates daily paid/free unused balances.
- Add an Artisan Command to create snapshots for a target date.
- Register the Command in Scheduler for daily execution.
- Add tests for paid/free aggregation, idempotency, reruns for the same date, and basic exclusion rules if applicable.
- Confirm behavior for withdrawn/suspended users and expired free lots during implementation.

Release status:

- Pre-release critical feature.
- Not completed in this draft.
