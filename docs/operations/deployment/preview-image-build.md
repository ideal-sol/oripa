# Preview Image Build Pipeline

## Boundary

Platform API and Admin Preview images are built only by
`.github/workflows/preview-image-build.yml` on GitHub-hosted
`ubuntu-24.04-arm`. The Preview host must never run `docker build`.

The workflow accepts an approved Task ID, an open internal PR number, and its
exact head SHA. It rejects an external PR, a non-`main` base, a branch or title
without the Task ID, and a changed head before checking out source.

## Artifact

The one-day GitHub Artifact is named
`oripa-preview-images-<TASK_ID>-<FULL_HEAD_SHA>` and contains:

- `oripa-v2-api-linux-arm64.docker.tar.zst`
- `oripa-v2-admin-linux-arm64.docker.tar.zst`
- `manifest.json`
- `SHA256SUMS`

The manifest fixes the Task, PR, source commit, `linux/arm64` platform, image
references, image IDs, archive checksums, and OCI labels. The OCI revision is
the requested PR head SHA.

## Host Import

Use the installed GitHub App read wrapper to list and download the exact
artifact. It validates the current Task Policy and open internal PR, successful
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

`load` first re-verifies the artifact, then rejects any Docker host that is not
ARM64. It only invokes `docker image load`; it neither builds nor removes an
image.

## Preview Update

After load, create a repository-external Compose override containing the exact
loaded API and Admin image references. Preserve the existing project,
container, network, fixed IP, environment, loopback port, and restart policy.

```bash
docker compose -p mig061a-v2-preview \
  -f /var/www/oripa/docker-compose.v2.yml \
  -f <REPOSITORY_OUTSIDE_VERIFIED_IMAGE_OVERRIDE> \
  up -d --no-build --no-deps api admin
```

Record current and rollback image IDs before replacement. Do not remove either
image. Health and browser checks remain Task-specific deployment gates.

## Current Host Limitation

As of OPS-004, the existing Preview host and Docker daemon are `x86_64`. The
required ARM64 artifact can be built and downloaded, but `load` intentionally
rejects this host before importing images. Preview deployment requires an
approved ARM64 Preview host; emulation or a cloud resource change is not part
of OPS-004.
