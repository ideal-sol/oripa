"""Explicit offline reuse of existing source and artifact validators."""

from .evaluator import SOURCE
from .records import require, text


def replay_source_authority(repository, source_sha, workflow_sha, change_id, pr_number, responses):
    def offline_get(path):
        require(path in responses, "SOURCE_REPLAY_RESPONSE_MISSING")
        return responses[path]

    return SOURCE["authorize"](
        repository, source_sha, workflow_sha, change_id, pr_number, get=offline_get,
    )


def platform_artifact_receipt(verified, artifact, service):
    require(verified.get("status") == "verified", "VERIFICATION_RECEIPT_NOT_SUCCESSFUL")
    require(verified.get("artifact_kind") == "production-candidate"
            and verified.get("architecture") == "arm64" and verified.get("platform") == "linux/arm64",
            "PRODUCTION_ARCHITECTURE_RECEIPT_INVALID")
    require(text(verified.get("manifest_sha256")), "MANIFEST_RECEIPT_MISSING")
    images = [image for image in verified.get("images", []) if image.get("name") == service]
    require(len(images) == 1 and images[0].get("image_id") == artifact["digest"], "IMAGE_RECEIPT_MISMATCH")
    return {
        "status": "VERIFIED", "artifact": artifact, "source_sha": verified["source_commit"],
        "architecture": "arm64", "validator": "scripts/ops/preview_image_artifact.py:verify_artifact",
        "manifest_sha256": verified["manifest_sha256"],
    }
