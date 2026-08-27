# Preview Image Build Pipeline

## Boundary

Platform API and Admin Preview images are built only by
`.github/workflows/preview-image-build.yml` on GitHub-hosted
`ubuntu-24.04` x64. The Preview host must never run `docker build`.

The workflow accepts an approved Task ID, an internal PR number, its exact
reviewed head SHA, and `image_mode`. The PR may be open or already merged; a
closed unmerged PR fails closed. This permits a Task to merge the canonical
workflow capability before dispatching it from `main` while retaining the exact
checked and reviewed source head. The default `normal` mode builds API and Admin.
Explicit `api-only` mode builds API and skips the Admin build entirely. Any
other mode fails before checkout or build. It also rejects an external PR, a
non-`main` base, a branch or title without the Task ID, and a changed head.

Only the workflow merged on `main` is canonical for Preview Activation. A run
from a task branch is rejected by the artifact read wrapper even when its source
head passed checks.

## Artifact

The one-day GitHub Artifact is named
`oripa-preview-images-<TASK_ID>-<FULL_HEAD_SHA>`. Normal mode contains:

- `oripa-v2-api-linux-amd64.docker.tar.zst`
- `oripa-v2-admin-linux-amd64.docker.tar.zst`
- `manifest.json`
- `SHA256SUMS`

API-only mode contains the same metadata and checksum files plus only the API
archive. An Admin-only artifact, reversed inventory, unknown file, or missing
API archive fails closed.

The manifest fixes the Task, PR, source commit, `linux/amd64` platform, image
references, image IDs, archive checksums, and OCI labels. The OCI revision is
the requested PR head SHA.

## Host Import

Use the installed GitHub App read wrapper to list and download the exact
artifact. It validates the current Task Policy and open or merged internal PR, successful
workflow identity, GitHub's outer artifact digest, safe ZIP members, and all
inner checksums and image metadata.

```bash
oripa-github-app-api preview-artifacts <TASK_ID> <PR_NUMBER> <HEAD_SHA>
oripa-github-app-api download-preview-artifact \
  <TASK_ID> <PR_NUMBER> <HEAD_SHA> <ARTIFACT_ID> \
  /var/lib/oripa-v2-evidence/<TASK_ID>/preview-image-artifacts/<HEAD_SHA>
python3 /var/www/oripa/scripts/ops/preview_image_artifact.py load \
  --directory /var/lib/oripa-v2-evidence/<TASK_ID>/preview-image-artifacts/<HEAD_SHA>/payload \
  --task-id <TASK_ID> --pr-number <PR_NUMBER> --source-sha <HEAD_SHA>
```

`load` first re-verifies the artifact, then rejects any machine or Docker host
that is not AMD64. It only invokes `docker image load`; it neither builds nor
removes an image.

## Preview Update

After load, create a repository-external Compose override containing the exact
loaded image references. Preserve the existing project, container, network,
fixed IP, environment, loopback port, and restart policy.

```bash
docker compose -p mig061a-v2-preview \
  -f /var/www/oripa/docker-compose.v2.yml \
  -f <REPOSITORY_OUTSIDE_VERIFIED_IMAGE_OVERRIDE> \
  up -d --no-build --no-deps api admin
```

For an API-only artifact, the override contains only the API image and the
activation command targets only `api` with the same `--no-build --no-deps`
boundary. It must not recreate Admin.

Record current and rollback image IDs before replacement. Do not remove either
image. Health and browser checks remain Task-specific deployment gates.

## Target Architecture

OPS-005 confirms that the existing Preview host and Docker daemon are `x86_64`.
The canonical artifact target is therefore `linux/amd64`. The artifact helper
owns this target value; both CI build selection and the host load guard consume
that boundary. Cross-architecture loading remains fail closed.
