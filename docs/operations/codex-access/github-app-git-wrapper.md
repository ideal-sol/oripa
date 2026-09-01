# GitHub App Git Wrapper Repository Authority

## Status and Scope

This runbook is the repository authority for the Platform Git delivery wrapper.
It applies only to the V2 GitHub delivery infrastructure. It does not authorize
an Application Runtime, Preview, Production, Storefront, V1, database, provider,
or secret change.

Human-facing Governance remains `1 Change = 1 Branch = 1 PR`. A Human does not
normally create a Task Policy. While the installed GitHub App wrappers require
one as a transport compatibility artifact, Platform Codex materializes a
root-owned mode `0600` transient policy with the exact Change metadata, paths,
and operations, then removes it at closeout.

## Repository and Runtime Contract

| Role | Authority |
| --- | --- |
| Canonical source | `infrastructure/github-app/oripa-github-app-git` |
| Provision manifest | `infrastructure/github-app/oripa-github-app-git.manifest.json` |
| Provision helper | `infrastructure/github-app/provision_git_wrapper.py` |
| Runtime destination | `/usr/local/bin/oripa-github-app-git` |
| Runtime owner and group | `root:root` |
| Runtime mode | `0700` |
| Rollback backup directory | `/var/lib/oripa-github-app-wrapper-backups` |

The manifest fixes the repository source path, source SHA-256, original imported
runtime SHA-256, role, runtime destination, owner, group, mode, and backup
directory. The helper rejects schema, path, checksum, syntax, owner, mode,
symlink, and pre-install SHA drift. It has no credential input and never reads
or copies a GitHub App key or token.

The wrapper does not update itself. Provisioning is a separate root operation
run either from the default exact merged `main` checkout or from an explicitly
selected clean exact merged checkout that satisfies the detached provision
authority below.

## Clean Task Branch Base Sync

For `sync-task-branch-base`, define:

- `H`: exact current Remote task branch head;
- `M`: exact live protected `main` head; and
- `C`: proposed synthetic merge candidate.

A clean sync is accepted only when all of these checks pass:

1. the transient Task Policy exists and its Task ID, branch, original base,
   allowed paths, allowed operation, Risk, Lane, and Activation are valid;
2. live Remote task head equals `H`, and live protected `main` equals `M`;
3. the policy base is an ancestor of both `H` and `M`;
4. the exact candidate parents are `[H, M]`;
5. both `H` and `M` are ancestors of `C`, so `H -> C` is fast-forward only;
6. Git reports a clean merge for `H + M`;
7. `C`'s tree equals Git's canonical clean merge tree byte-for-byte;
8. the Task scope is exactly `diff(M, C)`, and every path in that diff is
   allowed by the Task Policy;
9. Remote `H` and live `M` are re-read immediately before the no-force push;
10. Remote candidate and live `M` are re-read after the push before success is
    reported.

Paths introduced only by synchronizing `M` may appear in `diff(H, C)`. They are
not Task changes and must not be added to `allowed_paths`. Missing task content,
missing main content, a forged parent graph, a non-canonical tree, stale `H`,
stale `M`, a non-fast-forward update, or a path outside `diff(M, C)` scope fails
closed.

If `M` moves inside the narrow push interval, the post-push read rejects the
operation and no sync success evidence is emitted. The task branch may contain
the no-force candidate based on the prior `M`; it is not rolled back by force
and must pass a new exact-base clean sync before merge.

Any merge conflict fails with `sync_conflict_required`. The operation does not
accept or push a manual conflict resolution candidate.

`push-new-branch`, `push-task-branch`, branch prefix validation, Task Policy
validation, PR operations, fixed-head self-review, squash merge, and merged
branch deletion retain their existing authority. The clean-sync contract does
not create a policy-free path, add an `infra/` branch prefix, permit a missing
Task ID, or permit force push.

## Provision

The default interface remains fixed to `/var/www/oripa`. Provision only after
the Change is squash merged, local `main` equals live protected `main`, and the
helper, source, and manifest are read from that exact merged commit.

```text
python3 infrastructure/github-app/provision_git_wrapper.py verify-source
python3 infrastructure/github-app/provision_git_wrapper.py status
python3 infrastructure/github-app/provision_git_wrapper.py install <exact-current-runtime-sha256>
python3 infrastructure/github-app/provision_git_wrapper.py verify-installed
```

`install` requires the observed pre-install SHA-256 as an optimistic lock. It
also requires the helper to run from clean `/var/www/oripa` on branch `main`,
with local `HEAD`, `origin/main`, and live public protected `main` all equal. It
stages the verified source in the runtime destination directory, applies
`root:root` and mode `0700` before exposure, fsyncs the file, atomically replaces
the destination, fsyncs the directory, and verifies the installed SHA-256,
owner, group, mode, and Python syntax. The prior exact wrapper is retained in
the private backup directory under its SHA-256. A failure before replacement
leaves the prior wrapper untouched; a post-replacement verification failure
or merged-main authority change atomically restores and verifies the prior
bytes.

### Explicit Detached Checkout

When the Primary `/var/www/oripa` worktree holds an active Change, do not switch
its branch, reset it, stash it, or repurpose it for provisioning. Refresh the
canonical `origin/main` remote-tracking ref without switching the Primary
branch, then create a separate clean detached worktree at the exact merged
provision commit. The selected commit may be older than live protected `main`,
but it must remain an ancestor of live protected `main`.

The detached commit must contain the provision helper version that supports
the explicit interface. Invoke that helper from the same detached checkout as
the manifest and wrapper source:

```text
python3 <absolute-detached-root>/infrastructure/github-app/provision_git_wrapper.py --repo-root <absolute-detached-root> --expected-head <exact-full-merged-sha> verify-source
python3 <absolute-detached-root>/infrastructure/github-app/provision_git_wrapper.py --repo-root <absolute-detached-root> --expected-head <exact-full-merged-sha> status
python3 <absolute-detached-root>/infrastructure/github-app/provision_git_wrapper.py --repo-root <absolute-detached-root> --expected-head <exact-full-merged-sha> install <exact-current-runtime-sha256>
python3 <absolute-detached-root>/infrastructure/github-app/provision_git_wrapper.py --repo-root <absolute-detached-root> --expected-head <exact-full-merged-sha> verify-installed
```

`--repo-root` and `--expected-head` must be supplied together. The helper
rejects a relative, missing, non-canonical, or symlinked root; a non-Git
worktree; a non-canonical `ideal-sol/oripa` origin; tracked or untracked worktree
changes; a HEAD mismatch; a stale local `origin/main`; or a HEAD that is not an
ancestor of live protected `main`. The helper path, manifest, and wrapper source
must be regular non-symlink files in the same checkout and byte-identical to
their blobs at the expected HEAD. The manifest continues to fix the source
path, checksum, runtime destination, backup directory, owner, group, and mode.

After source verification, install, installed verification, and the required
read-only wrapper checks pass, the temporary detached worktree may be removed.
Removing it does not authorize changing or cleaning the Primary active Change.

Minimal non-mutating host verification is:

```text
/usr/local/bin/oripa-github-app-git ls-remote
/usr/local/bin/oripa-github-app-git GOV-029 push-task-branch
```

The second command must stop at `task_push_arguments_invalid`; it validates the
transient Task Policy path without issuing a token or performing a Git write.
Do not test with a Production write operation.

## Rollback

Read the current runtime SHA, select the previous exact checksum that was
captured during install, and run:

```text
python3 infrastructure/github-app/provision_git_wrapper.py rollback <previous-sha256> <exact-current-runtime-sha256>
python3 infrastructure/github-app/provision_git_wrapper.py verify-installed <previous-sha256>
```

Rollback reads only the fixed private backup directory, verifies its selected
file by SHA-256, owner, group, and mode, and uses the same atomic install path.
It rejects an unexpected current runtime SHA and preserves the current wrapper
on a pre-replacement failure.

## Server Parity

Provision the old Server first. After its source, install, read-only Git, and
Task Policy checks pass, provision the new t4g Server from the same exact merged
source and manifest. Never copy credentials, tokens, configuration, or secrets
between Servers. Activation is not PASS until both Servers report the same
wrapper SHA-256 as the merged canonical source and each reports `root:root`
mode `0700`.
