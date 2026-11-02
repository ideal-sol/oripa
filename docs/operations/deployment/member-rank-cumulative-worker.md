# Cumulative member rank rollout and Worker handoff

Status: Source preparation for MEMBERRANK-CUMULATIVE-20261102, Strict Change,
R3, Activation deferred. Human approved the accounting decisions on2026-11-02.
This Change authorizes implementation, synthetic verification and a Draft PR
only. Ready/Merge, OLD Test/Production activation, operational migrations,
backfill application, Worker launch and artifact publication require separate
explicit Human authorization. None is performed by this document or startup.

## Approved accounting

Points are the signed sum of original monthly buckets, the single current
transfer allowance and net monthly compensation, floored once at zero. Negative
adjustment months are valid; immutable originals and deferred entry/total
equality remain. Rank points are separate from wallet coins. Existing paid/free
lot accounting, purchase-bonus cancellation, prelaunch exclusion and test-payment
exclusion remain. No automatic event, read, refund, transfer edit or old Worker
lowers a rank. Only an explicit authorized manual rank selection can lower it.

Purchase, positive manual points, transfer editing and monthly events may
promote. Merely reading changed thresholds never promotes. Purchase bonuses use
the pre-purchase rank. Lifetime member/rank achievements and immutable exclusion
history prevent promotion-bonus reissue, including historically recovered
bonuses. Transfer ranks and lower ranks stay excluded even after reverting the
allowance to none. Manual demotion permits re-promotion; a first newly reached,
ungranted, nonexcluded upper rank earns its normal first bonus.

Only a line increase creates monthly compensation. For current cumulative P
and old/new current-rank lines L0/L1, grant
`max(0,L1-P) - max(0,L0-P)` if L1>L0. Existing refund/support shortages remain.
A purchase alone never cancels compensation. A line decrease cancels
`min(worker_balance,L0-L1,max(0,P-L1))`, through Worker entries only. Purchase,
manual and transfer points cannot be canceled by this procedure. The resulting
compensation participates in promotion. Example:120000 points,
Gold100000→150000 grants30000;150000→70000 cancels30000. An unchanged line
does not mutate compensation.

## Ordered activation (not executed)

1. Obtain Source Merge and separate site Runtime/Migration approvals. Complete
   backup/restore and compatibility gates. Use an approved merged image/digest,
   never a PR verification image or runtime-host Build.
2. Stop/disable the old `v2:member-rank:work` supervisor/timer using the approved
   operational procedure before switching Source. Retain its Source,
   `member_rank_monthly_jobs` and judgments. New Source makes accidental old
   invocation a zero-user operation, not an alias to the new Worker.
3. Apply forward migration000104 through the approved operational procedure.
   It adds transfer allowance/history, cumulative events, compensation entries
   and promotion jobs; extends promotion/achievement origins; retires monthly
   nonnegative and maintain-vs-reach constraints. Immutable history, signed
   entry equality, actor/revision and matching-origin guards remain.
4. Privately review `php artisan v2:member-rank:backfill-transfers` (default
   dry-run, no writes). Output contains member identifiers: do not copy it into
   public records. Separately authorize `--apply`. Active, unfrozen legacy
   `transferred_at` users without allowance/history receive one deficit
   allowance, no coins and no rank change. Replays skip corrected users; edits
   are never overwritten. Existing feature activation/prelaunch gates remain.
5. Coordinate the breaking Public contract with the separate Site Change.
   Client/Testkit alpha.49 and Public/Admin/Webhook contract alpha.45 are pending
   candidates, not published artifacts. alpha.48 and all immutable release
   history remain unchanged. Do not serve the new contract to an incompatible
   Site merely because Platform tests pass. Storefront UI belongs to its own
   repository and is outside this Change.
6. Separately authorize the promotion Worker to discover each month at00:00
   JST on the first day. The continuous command is
   `php artisan v2:member-rank:promote --worker=member-rank-01 --limit=100 --loop`.
   Bind only the site's approved image, private network, environment and writable
   storage. This Change installs no supervisor, timer, Scheduler or environment.
   First invocation initializes the current month; missed months after that are
   replayed in order. Initialize during the approved rollout month before the
   required December boundary. First launch does not reconstruct an uninitialized
   historical period silently.

## Progress, failure and audit

`member_rank_promotion_jobs` records month, status, cursor, processed count,
failures, lease owner/expiry, heartbeat and sanitized error. One owner processes
a month; each member commits separately with the user/state locks shared by
purchase, refund, transfer, manual operations and closure. Inactive/frozen
members are excluded. A unique user/month event prevents replayed compensation
and grants. SIGTERM/SIGINT stop between users and release the lease. Forced
termination leaves a60-second lease. Three consecutive failures stop the job;
later months wait behind unfinished earlier months. Review failed status before
a separately approved repair; never delete events or reset a completed job.

Admin summary shows current-month remaining/provisional state and last completed
month. Inspect jobs too for an older blocked month. Logical month/settings stay
on an event; late processing uses actual event/bonus timestamps, avoiding
backdated free-point expiry. Reads keep the stored rank while work is pending.
Feature OFF yields beginner projection and no Worker writes.

Immutable compensation entries link to monthly events/settings and have a
nonnegative Worker-only running balance. Audit includes delta and before/after
balance. A transfer allowance must match the latest consecutive immutable
history revision, with actor, reason and before/after values. Admin exposes
components and history. Public exposes only the latest3 purchases; total includes
every signed component and need not equal those3 rows.

Refund preparation has no rank selection. Completion and manual chargeback
offer unchanged(default) plus all7 ranks only below the current line. An explicit
rank requires both payment and member-rank management permission. Preview tokens
reject stale points/rank/settings. Payment, manual rank change, idempotency and
audit commit together; the selection grants no bonus. Shared request ID and
`payment-rank:<adjustment UUID>` idempotency origin connect records. Provider
events never choose a manual rank. Discrepancies are Owner-only and active-only.

## Rollback

Before activation, revert unactivated Source through a reviewed Change. After
000104/cumulative records exist, retain schema/history and use forward fixes.
Do not run down, delete history, rewind release metadata or run an old monthly
downgrade application over cumulative data. A separately approved incident may
stop the new Worker during investigation; immediate purchase promotions remain.
Do not toggle an activated feature OFF as a rollback shortcut.

Image/runtime authorities remain
[preview-image-build.md](preview-image-build.md) and
[platform-production-arm64-runtime.md](platform-production-arm64-runtime.md).
