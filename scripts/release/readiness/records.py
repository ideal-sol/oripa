"""Non-secret handoff record validation and deterministic identity."""

from copy import deepcopy
from datetime import datetime
import hashlib
import json
import re


SHA = re.compile(r"[0-9a-f]{40}")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}")
CONTINUITY_SCOPE = {
    "platform", "storefront", "contract", "migration", "workers", "admin",
    "agency", "runtime_definitions", "env_config_change_operations",
}
REQUIREMENT_STATES = {"PASS", "HOLD", "UNKNOWN", "N/A"}
BROWSER_STATES = {
    "HUMAN_BROWSER_ACCEPTANCE_PASS", "HUMAN_BROWSER_ACCEPTANCE_FAIL",
    "HUMAN_BROWSER_ACCEPTANCE_PENDING",
}


class RecordError(ValueError):
    pass


def require(condition, code):
    if not condition:
        raise RecordError(code)


def text(value):
    return isinstance(value, str) and bool(value.strip())


def matches(pattern, value):
    return isinstance(value, str) and pattern.fullmatch(value) is not None


def timestamp(value):
    require(isinstance(value, str) and re.fullmatch(
        r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)", value
    ), "TIMESTAMP_INVALID")
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        raise RecordError("TIMESTAMP_INVALID") from None


def fields(record, names):
    require(isinstance(record, dict) and set(record) == set(names.split()), "RECORD_FIELDS_INVALID")


def strings(value, *, nonempty=False):
    return (isinstance(value, list) and all(text(item) for item in value)
            and len(value) == len(set(value)) and (bool(value) or not nonempty))


def canonical(record, digest_field=None):
    payload = deepcopy(record)
    if digest_field:
        payload.pop(digest_field, None)
    if isinstance(payload, dict) and "services" in payload:
        payload["services"] = sorted(payload["services"], key=lambda service: service["service"])
    return (json.dumps(payload, ensure_ascii=False, sort_keys=True,
                       separators=(",", ":"), allow_nan=False) + "\n").encode("utf-8")


def digest(record, digest_field):
    return "sha256:" + hashlib.sha256(canonical(record, digest_field)).hexdigest()


def seal(record, digest_field):
    result = deepcopy(record)
    result[digest_field] = digest(result, digest_field)
    return result


def check_digest(record, digest_field):
    require(matches(DIGEST, record.get(digest_field)), "DIGEST_INVALID")
    require(record[digest_field] == digest(record, digest_field), "DIGEST_MISMATCH")


def load(path):
    def unique_pairs(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, "DUPLICATE_JSON_KEY")
            result[key] = value
        return result

    def invalid_constant(_value):
        raise RecordError("NON_FINITE_JSON")

    with open(path, encoding="utf-8") as stream:
        return json.load(stream, object_pairs_hook=unique_pairs, parse_constant=invalid_constant)


def snapshot(record):
    fields(record, "schema_version generated_at environment services contract migration "
                   "external_runtime_definitions snapshot_digest")
    require(record["schema_version"] == "1.0" and record["environment"] == "production", "SNAPSHOT_VERSION_ENV_INVALID")
    generated = timestamp(record["generated_at"])
    require(isinstance(record["services"], list) and record["services"], "SERVICES_MISSING")
    names = []
    for service in record["services"]:
        fields(service, "service status observed_at runtime_source artifact_or_image_digest "
                        "digest_kind observation_method authority_record")
        require(text(service["service"]) and service["status"] in {"ACTIVE", "INACTIVE", "UNKNOWN"}, "SERVICE_INVALID")
        names.append(service["service"])
        require(text(service["observation_method"]), "OBSERVATION_METHOD_MISSING")
        for key, pattern in (("runtime_source", SHA), ("artifact_or_image_digest", DIGEST)):
            require(service[key] is None or matches(pattern, service[key]), "SERVICE_IDENTITY_INVALID")
        require(service["digest_kind"] in {None, "docker_image_id", "archive_sha256", "artifact_sha256", "other"}, "DIGEST_KIND_INVALID")
        require(service["authority_record"] is None or text(service["authority_record"]), "AUTHORITY_REFERENCE_INVALID")
        if service["observed_at"] is not None:
            require(timestamp(service["observed_at"]) <= generated, "OBSERVATION_AFTER_GENERATION")
        if service["status"] == "ACTIVE":
            require(all(service[key] is not None for key in (
                "observed_at", "runtime_source", "artifact_or_image_digest", "digest_kind", "authority_record"
            )), "ACTIVE_SERVICE_EVIDENCE_MISSING")
    require(len(names) == len(set(names)) and names == sorted(names), "SERVICE_ORDER_OR_DUPLICATE")
    contract = record["contract"]
    fields(contract, "status artifact_id manifest_digest client_pin testkit_pin openapi_pin evidence_reference")
    require(contract["status"] in {"CONFIRMED", "UNKNOWN", "N/A"}, "CONTRACT_STATUS_INVALID")
    for key in set(contract) - {"status"}:
        require(contract[key] is None or text(contract[key]), "CONTRACT_VALUE_INVALID")
    require(contract["manifest_digest"] is None or matches(DIGEST, contract["manifest_digest"]), "CONTRACT_DIGEST_INVALID")
    if contract["status"] == "CONFIRMED":
        require(all(text(value) for value in contract.values()), "CONTRACT_EVIDENCE_MISSING")
    migration = record["migration"]
    fields(migration, "status revision observed_at evidence_reference")
    require(migration["status"] in {"CONFIRMED", "HISTORICAL_ONLY", "UNKNOWN", "N/A"}, "MIGRATION_STATUS_INVALID")
    require(migration["revision"] is None or text(migration["revision"]), "MIGRATION_REVISION_INVALID")
    require(migration["evidence_reference"] is None or text(migration["evidence_reference"]), "MIGRATION_REFERENCE_INVALID")
    if migration["observed_at"] is not None:
        require(timestamp(migration["observed_at"]) <= generated, "MIGRATION_OBSERVATION_INVALID")
    if migration["status"] == "CONFIRMED":
        require(all(migration[key] is not None for key in ("revision", "observed_at", "evidence_reference")), "MIGRATION_EVIDENCE_MISSING")
    external = record["external_runtime_definitions"]
    fields(external, "status docker workers systemd proxy storage_binding")
    require(external["status"] in {"CONFIRMED", "PARTIAL", "UNKNOWN", "N/A"}, "EXTERNAL_STATUS_INVALID")
    require(all(value is None or text(value) for key, value in external.items() if key != "status"), "EXTERNAL_REFERENCE_INVALID")
    if external["status"] in {"CONFIRMED", "N/A"}:
        require(all(text(value) for value in external.values()), "EXTERNAL_EVIDENCE_MISSING")
    for section in (contract, migration):
        if section["status"] == "N/A":
            require(text(section["evidence_reference"]), "NA_EVIDENCE_MISSING")
    check_digest(record, "snapshot_digest")
    return record


def continuity(record, base):
    fields(record, "schema_version base_snapshot_digest checked_at continuity_status checked_scope "
                   "authority_events evidence_references unresolved_gaps confirmed_by_role record_digest")
    require(record["schema_version"] == "1.0", "CONTINUITY_VERSION_INVALID")
    require(matches(DIGEST, record["base_snapshot_digest"]), "CONTINUITY_BASE_INVALID")
    checked = timestamp(record["checked_at"])
    require(record["continuity_status"] in {
        "CONFIRMED_NO_AUTHORITY_CHANGE", "AUTHORITY_CHANGE_DETECTED", "CONTINUITY_UNKNOWN"
    }, "CONTINUITY_STATUS_INVALID")
    require(strings(record["checked_scope"]) and strings(record["evidence_references"])
            and strings(record["unresolved_gaps"]), "CONTINUITY_LIST_INVALID")
    require(record["confirmed_by_role"] in {"human_operator", "approved_producer"}, "CONTINUITY_ROLE_INVALID")
    require(isinstance(record["authority_events"], list), "CONTINUITY_EVENTS_INVALID")
    for event in record["authority_events"]:
        fields(event, "event_type occurred_at scope evidence_reference")
        require(text(event["event_type"]) and text(event["scope"]), "CONTINUITY_EVENT_INVALID")
        require(event["evidence_reference"] is None or text(event["evidence_reference"]), "EVENT_REFERENCE_INVALID")
        if event["occurred_at"] is not None:
            require(timestamp(event["occurred_at"]) <= checked, "EVENT_TIME_INVALID")
    check_digest(record, "record_digest")
    if record["continuity_status"] == "AUTHORITY_CHANGE_DETECTED" or record["authority_events"]:
        return "AUTHORITY_CHANGE_DETECTED"
    if (record["base_snapshot_digest"] != base["snapshot_digest"]
            or checked < timestamp(base["generated_at"])
            or set(record["checked_scope"]) != CONTINUITY_SCOPE
            or record["unresolved_gaps"] or not record["evidence_references"]
            or record["confirmed_by_role"] != "human_operator"):
        return "CONTINUITY_UNKNOWN"
    return record["continuity_status"]


def human_go(record, candidate):
    fields(record, "schema_version candidate_id approved_at service_scope source artifacts "
                   "authority_snapshot_digest approval_actor_role approval_evidence_reference record_digest")
    require(record["schema_version"] == "1.0", "GO_VERSION_INVALID")
    timestamp(record["approved_at"])
    require(record["candidate_id"] == candidate["candidate_id"], "GO_CANDIDATE_MISMATCH")
    require(strings(record["service_scope"], nonempty=True)
            and set(record["service_scope"]) == set(candidate["activation_scope"]), "GO_SCOPE_MISMATCH")
    require(record["approval_actor_role"] == "human_operator" and text(record["approval_evidence_reference"]), "GO_HUMAN_EVIDENCE_MISSING")
    fields(record["source"], "platform storefront")
    require(all(value is None or matches(SHA, value) for value in record["source"].values()), "GO_SOURCE_INVALID")
    require(isinstance(record["artifacts"], list), "GO_ARTIFACTS_INVALID")
    for artifact in record["artifacts"]:
        fields(artifact, "service artifact_id digest")
        require(text(artifact["service"]) and text(artifact["artifact_id"]) and matches(DIGEST, artifact["digest"]), "GO_ARTIFACT_INVALID")
    require(record["source"] == candidate["source"] and record["authority_snapshot_digest"] == candidate["authority_snapshot_digest"], "GO_AUTHORITY_MISMATCH")
    require(record["artifacts"] == [candidate["artifacts"][service] for service in sorted(record["service_scope"])], "GO_ARTIFACT_MISMATCH")
    check_digest(record, "record_digest")
    return "HUMAN_GO_RECORD_VALID_NOT_AUTHENTICATED"


def browser_acceptance(record, candidate, plan):
    fields(record, "schema_version candidate_id source_sha artifact_id build_id status checked_at "
                   "actor_role change_class checked_routes checked_states checked_viewports "
                   "acceptance_plan_reference result_notes record_digest")
    require(record["schema_version"] == "1.0" and record["status"] in BROWSER_STATES, "BROWSER_STATUS_INVALID")
    require(text(record["candidate_id"]) and matches(SHA, record["source_sha"])
            and text(record["artifact_id"]) and text(record["build_id"]) and text(record["change_class"])
            and isinstance(record["result_notes"], str), "BROWSER_FIELDS_INVALID")
    check_digest(plan, "record_digest")
    require(record["candidate_id"] == candidate["candidate_id"] and record["source_sha"] == candidate["source"]["storefront"], "BROWSER_SOURCE_MISMATCH")
    require(record["artifact_id"] == candidate["artifacts"]["storefront"]["artifact_id"]
            and record["build_id"] == candidate["storefront_build_id"], "BROWSER_ARTIFACT_MISMATCH")
    for key, expected in (("checked_routes", "affected_routes"), ("checked_states", "affected_states"), ("checked_viewports", "required_viewport_classes")):
        require(strings(record[key]), "BROWSER_SCOPE_INVALID")
        if record["status"] == "HUMAN_BROWSER_ACCEPTANCE_PASS":
            require(set(record[key]) >= set(plan[expected]), "BROWSER_SCOPE_INCOMPLETE")
    require(record["acceptance_plan_reference"] == plan["record_digest"], "BROWSER_PLAN_MISMATCH")
    if record["status"] != "HUMAN_BROWSER_ACCEPTANCE_PENDING":
        timestamp(record["checked_at"])
        require(record["actor_role"] == "human_operator", "BROWSER_HUMAN_MISSING")
    else:
        require(record["actor_role"] in {None, "human_operator"}, "BROWSER_ROLE_INVALID")
        if record["checked_at"] is not None:
            timestamp(record["checked_at"])
    check_digest(record, "record_digest")
    return record["status"]
