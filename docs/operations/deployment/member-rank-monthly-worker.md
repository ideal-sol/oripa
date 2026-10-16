# Member rank monthly worker launch preparation

Status: source preparation only, disabled by default. No service is installed or
enabled by this document, Composer, API startup, Compose, or Scheduler. M3 alone
does not authorize source merge, operational migration, feature ON or a worker.
Initial activation belongs to the Final Activation Readiness Gate after all
member-rank phases. M9 is manual refund/chargeback recording.

## Invocation and supervision

The API image contains this standalone command:

```text
php artisan v2:member-rank:work --worker=member-rank-01 --limit=100 --loop
```

Without `--loop`, at most `--limit` users (1–500) are visited. With `--loop`, the
command discovers the current JST month on every batch and sleeps five seconds
when idle; it needs no Laravel Scheduler. SIGTERM/SIGINT stop between users,
release the lease and exit. A forced stop leaves a 60-second lease; another
invocation resumes the committed cursor. Three consecutive failed attempts or
expired leases mark the job failed. Success resets consecutive failures.
Exceptions are stored as fixed error codes, never exception messages or user
data. Inspect failed jobs before any separately approved repair; do not delete
history, reset rank state or re-enable a completed monthly job.

Preview uses the canonical AMD64 image and verification procedure in
[preview-image-build.md](preview-image-build.md). Production uses the canonical
native ARM64 candidate and approved Runtime Source in
[platform-production-arm64-runtime.md](platform-production-arm64-runtime.md).
The current approved Production source predates M3: a future approved-source
update, merged M3 image, migration/backup gate and separate Human Runtime GO are
required. Never use a PR verification image as Runtime Authority or build on a
runtime host. `docker-compose.v2.yml` remains development-only.

After those approvals, the operator binds the following **inactive supervisor
template** to the site's existing approved API environment file and private DB
network. Record the exact image reference and OCI digest, source/tree, worker
identity, network and environment-file identity in that activation's evidence.
No HTTP port, provider egress, mail worker, API restart or Scheduler is required.
The environment file is read by Docker and its values must not be printed.

```ini
[Unit]
Description=Oripa member rank monthly worker
After=docker.service
Requires=docker.service
ConditionPathExists=/etc/oripa/member-rank-worker.approved

[Service]
Type=simple
EnvironmentFile=/etc/oripa/member-rank-worker-launch.conf
ExecStart=/usr/bin/docker run --rm --name oripa-member-rank-worker --pull=never --network=${RANK_PRIVATE_NETWORK} --env-file=${RANK_API_ENV_FILE} --cap-drop=ALL --security-opt=no-new-privileges --user=www-data --entrypoint=php ${RANK_APPROVED_API_IMAGE} artisan v2:member-rank:work --worker=member-rank-01 --limit=100 --loop
ExecStop=/usr/bin/docker stop --time=75 oripa-member-rank-worker
TimeoutStopSec=90
Restart=on-failure
RestartSec=10
```

There is deliberately no `[Install]` target. The approval marker and launch
configuration do not exist as part of M3. Operator review must validate the
image's writable storage/cache layout and runtime dependency access against
the deployed API before installing and explicitly starting this unit. Do not
copy another site's environment. Stopping this worker is an incident action,
not a feature OFF switch; read projection and live judgment remain effective.

## Database observation and handoff

`member_rank_monthly_jobs` exposes month, status, cursor, processed count,
consecutive failures, owner token, lease expiry, heartbeat, next attempt and
sanitized last error. One owner serializes each month; multiple processes are
safe, and each user commits independently. A stale owner's updates require its
exact claim token and an unexpired lease. Existing completed jobs are unchanged
on replay. Missing skipped months are caught up per user in month order.

Internal `V2MemberRankMonthlyWorker::progress()` returns remaining bronze-or-higher
states, provisional status, current job and last completed month for future M7.
It is not a new Admin API. M7 must retain the provisional annotation until
remaining is zero, including failed/expired jobs. Read observation must not
start or repair work.

Activation orchestration must finish same-month paid-grant backfill before
publishing enabled state or starting workers. An unaccounted paid grant behind
a materialized baseline fails closed; this is not a historical reranking API.
Feature OFF yields beginner projection and zero rank/job writes.

Rollback retains migration 000090 and all history. Stop only the approved
worker under incident authority; corrections use reviewed forward migrations.
Never run `down`, delete monthly history, rewind a cursor, or switch a started
member-rank feature OFF.
