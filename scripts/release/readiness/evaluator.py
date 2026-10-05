"""Evaluate handed-off evidence without network, runtime inspection, or activation."""

from copy import deepcopy
from datetime import datetime, timezone
from pathlib import Path
import runpy

from .records import (
    DIGEST, SHA, RecordError, check_digest, continuity, fields, matches, require,
    seal, snapshot, strings, text, timestamp,
)


ROOT = Path(__file__).resolve().parents[3]
CHECKS = runpy.run_path(str(ROOT / "infrastructure/github-app/check_run_gate.py"))
SOURCE = runpy.run_path(str(ROOT / "scripts/ops/production_source_authority.py"))
REQUIRED_CHECKS = SOURCE["REQUIRED_CHECKS"]
FULL = "Full Platform + Storefront Release"
NORMAL = "Normal Storefront Release"
MINOR = "Storefront Minor Change Fast Lane"
AUTHORITY = "Authority-only Fast Lane"
STRICT_FALLBACK = "NORMAL_STRICT_CI"
LANES = (FULL, NORMAL, MINOR, AUTHORITY)
RUNTIME_SURFACES = (
    "application", "dependencies", "lockfile", "migration", "env_requirements",
    "runtime_config", "dockerfile", "entrypoint", "image_selection", "workers",
    "systemd", "storefront_release", "proxy", "storage_binding", "contract_consumption",
)
AUTHORITY_CLASSES = {
    "approved_source_metadata", "provenance_metadata", "artifact_candidate_metadata",
    "authority_snapshot", "release_evidence", "handoff_metadata", "non_runtime_metadata",
}
R1_FACTS = ("exact_source", "workflow_authority", "approved_source", "existing_source_policy")
R6_FACTS = ("migration_assessment", "compatibility", "activation_order", "env_requirement_delta", "restore_point")
RB_FACTS = {
    "RB1": "known_good_target", "RB2": "target_artifact_available",
    "RB3": "contract_service_compatibility", "RB4": "db_data_lease_conditions",
    "RB5": "current_procedure_authority", "RB6": "operator_go_authority",
}
MINOR_INVARIANTS = {
    "platform": "SAME", "contract": "SAME", "migration": "NONE", "db_schema": "SAME",
    "api_write_path": "SAME", "env_requirements": "SAME", "runtime_config": "SAME", "routing": "SAME",
}


def result(status, reason, evidence=None):
    return {"status": status, "reason": reason, "evidence": evidence or []}


def aggregate(results):
    states = [entry["status"] for entry in results]
    if "HOLD" in states:
        return "HOLD"
    if not states or "UNKNOWN" in states:
        return "UNKNOWN"
    return "PASS"


def identity(candidate):
    return {key: candidate[key] for key in (
        "candidate_id", "repository", "base_sha", "head_sha", "tree_sha",
        "workflow_authority", "authority_snapshot_digest",
    )}


def fact(candidate, name, expected=None, *, allow_na=False):
    entry = candidate.get("facts", {}).get(name)
    if not isinstance(entry, dict):
        return result("UNKNOWN", name + ":MISSING_EVIDENCE")
    if (entry.get("identity") != identity(candidate) or not text(entry.get("evidence_reference"))
            or entry.get("status") not in {"PASS", "HOLD", "UNKNOWN", "N/A"}):
        return result("UNKNOWN", name + ":UNBOUND_EVIDENCE")
    references = [entry["evidence_reference"]]
    status = entry["status"]
    if status == "N/A":
        if allow_na and text(entry.get("non_applicability_reason")):
            return result("N/A", name + ":PROVEN_NON_APPLICABLE", references)
        return result("UNKNOWN", name + ":NA_NOT_PROVEN", references)
    if status == "PASS" and "value" not in entry:
        return result("UNKNOWN", name + ":VALUE_MISSING", references)
    if status == "PASS" and expected is not None and entry["value"] != expected:
        return result("HOLD", name + ":EXPLICIT_MISMATCH", references)
    return result(status, name + ":" + status, references)


def validate_candidate(candidate):
    require(isinstance(candidate, dict) and candidate.get("schema_version") == "1.0", "CANDIDATE_VERSION_INVALID")
    require(text(candidate.get("candidate_id")) and candidate.get("repository") in {
        "ideal-sol/oripa", "ideal-sol/luxe-pack-storefront"
    }, "CANDIDATE_ID_INVALID")
    for key in ("base_sha", "head_sha", "tree_sha", "workflow_authority"):
        require(matches(SHA, candidate.get(key)), "CANDIDATE_SHA_INVALID")
    require(matches(DIGEST, candidate.get("authority_snapshot_digest")), "CANDIDATE_SNAPSHOT_INVALID")
    require(isinstance(candidate.get("facts"), dict), "FACTS_INVALID")
    require(all(isinstance(entry, dict) for entry in candidate["facts"].values()), "FACT_RECORD_INVALID")
    fields(candidate.get("source"), "platform storefront")
    require(all(value is None or matches(SHA, value) for value in candidate["source"].values()), "SOURCE_INVALID")
    for scope in ("service_inventory", "build_scope", "activation_scope", "acceptance_scope", "rollback_scope"):
        require(strings(candidate.get(scope), nonempty=scope == "service_inventory"), "SERVICE_SCOPE_INVALID")
        require(set(candidate[scope]) <= set(candidate["service_inventory"]), "SERVICE_SCOPE_OUTSIDE_INVENTORY")
    require(set(candidate["activation_scope"]) <= set(candidate["acceptance_scope"])
            and set(candidate["activation_scope"]) <= set(candidate["rollback_scope"]), "ACTIVATION_COVERAGE_MISSING")
    require(isinstance(candidate.get("artifacts"), dict), "ARTIFACTS_INVALID")
    for service, artifact in candidate["artifacts"].items():
        fields(artifact, "service artifact_id digest")
        require(service in candidate["service_inventory"] and artifact["service"] == service
                and text(artifact["artifact_id"]) and matches(DIGEST, artifact["digest"]), "ARTIFACT_IDENTITY_INVALID")
    for service in set(candidate["artifacts"]) | set(candidate["build_scope"]) | set(candidate["activation_scope"]):
        require(matches(SHA, candidate["source"]["storefront" if service == "storefront" else "platform"]), "SERVICE_SOURCE_MISSING")
    contract = candidate.get("target_contract")
    fields(contract, "status artifact_id manifest_digest client_pin testkit_pin openapi_pin evidence_reference")
    require(contract["status"] in {"CONFIRMED", "UNKNOWN", "N/A"}, "TARGET_CONTRACT_STATUS_INVALID")
    if contract["status"] == "CONFIRMED":
        require(all(text(value) for value in contract.values()) and matches(DIGEST, contract["manifest_digest"]), "TARGET_CONTRACT_IDENTITY_INVALID")


def storefront_strict_record(classification):
    return (
        classification.get("schema_version") == "1.0"
        and classification.get("candidate_lane") == STRICT_FALLBACK
        and classification.get("fallback_lane") == STRICT_FALLBACK
        and classification.get("effective_lane", STRICT_FALLBACK) == STRICT_FALLBACK
        and classification.get("policy_approval") in ("HUMAN_APPROVED", "PENDING_HUMAN_APPROVAL")
        and classification.get("blocking_authority") is False
        and classification.get("ci_skip") is False
        and all(text(classification.get(key)) for key in (
            "policy_version", "impact_map_version", "classification_reason",
        ))
        and all(matches(DIGEST, classification.get(key)) for key in ("policy_digest", "impact_map_digest"))
        and all(strings(classification.get(key)) for key in (
            "unknown_reasons", "change_classes", "affected_modules", "affected_routes", "affected_states",
        ))
        and isinstance(classification.get("evidence"), list)
        and all(
            isinstance(entry, dict) and text(entry.get("path")) and strings(entry.get("classes"), nonempty=True)
            and all(isinstance(entry.get(key), str) and matches(DIGEST, "sha256:" + entry[key])
                    for key in ("before_digest", "after_digest"))
            for entry in classification["evidence"]
        )
    )


def classify(candidate):
    evidence = []
    unknown = []
    classes = candidate.get("change_classes", [])
    authority_candidate = strings(classes, nonempty=True) and set(classes) <= AUTHORITY_CLASSES
    completeness = fact(candidate, "complete_diff", True)
    baseline = fact(candidate, "classification_baseline", {key: candidate[key] for key in ("base_sha", "head_sha", "tree_sha")})
    platform = fact(candidate, "platform_impact", "NONE")
    if platform["status"] != "PASS" or completeness["status"] != "PASS" or baseline["status"] != "PASS":
        lane, reason = (
            (STRICT_FALLBACK, "AUTHORITY_ONLY_FAST_LANE_INDETERMINATE")
            if authority_candidate else (FULL, "PLATFORM_IMPACT_PRESENT_OR_UNKNOWN")
        )
        unknown.extend(proof["reason"] for proof in (platform, completeness, baseline) if proof["status"] != "PASS")
    else:
        if authority_candidate:
            proofs = [fact(candidate, "runtime_delta." + surface, "NONE") for surface in RUNTIME_SURFACES]
            proofs += [fact(candidate, "authority_metadata_not_in_runtime", True)]
            if aggregate(proofs) == "PASS" and not candidate["activation_scope"] and not candidate["build_scope"]:
                lane, reason = AUTHORITY, "RUNTIME_NONE_AND_MANDATORY_PROOF"
                evidence.extend(reference for proof in proofs for reference in proof["evidence"])
            else:
                lane, reason = STRICT_FALLBACK, "AUTHORITY_ONLY_FAST_LANE_INDETERMINATE"
                unknown.extend(proof["reason"] for proof in proofs if proof["status"] != "PASS")
        else:
            minor = fact(candidate, "storefront_minor_classification")
            classification = candidate.get("facts", {}).get("storefront_minor_classification", {}).get("value", {})
            valid_minor = False
            valid_strict = False
            if minor["status"] == "PASS" and isinstance(classification, dict):
                try:
                    check_digest(classification, "record_digest")
                    bound = (
                        classification.get("repository") == candidate["repository"]
                        and classification.get("base_sha") == candidate["base_sha"]
                        and classification.get("head_sha") == candidate["head_sha"]
                        and classification.get("tree_sha") == candidate["tree_sha"]
                        and classification.get("production_impact") == "NONE"
                    )
                    valid_minor = (
                        bound and classification.get("candidate_lane") == MINOR
                        and classification.get("policy_approval") == "HUMAN_APPROVED"
                        and classification.get("unknown_reasons") == []
                    )
                    valid_strict = bound and storefront_strict_record(classification)
                except (RecordError, TypeError, ValueError):
                    valid_minor = False
            lane, reason = (MINOR, "EXACT_STOREFRONT_CLASSIFIER_EVIDENCE") if valid_minor else (NORMAL, "MINOR_NOT_PROVEN")
            if valid_strict:
                lane, reason = STRICT_FALLBACK, "EXACT_STOREFRONT_STRICT_FALLBACK_EVIDENCE"
            evidence.extend(minor["evidence"])
    return {
        "candidate_lane": lane, "classification_reason": reason, "evidence": evidence,
        "unknown_reasons": unknown,
        "fallback_lane": lane if lane in {STRICT_FALLBACK, FULL} else NORMAL,
    }


def required_checks(candidate):
    required_targets = {(candidate["repository"], candidate["head_sha"]),
                        (candidate["repository"], candidate["workflow_authority"])}
    repositories = {"platform": "ideal-sol/oripa", "storefront": "ideal-sol/luxe-pack-storefront"}
    required_targets.update((repositories[realm], source) for realm, source in candidate["source"].items() if source is not None)
    rows = candidate.get("required_check_evidence", [])
    checks = []
    for repository, head in sorted(required_targets):
        matches_rows = [row for row in rows if isinstance(row, dict)
                        and row.get("repository") == repository and row.get("head_sha") == head]
        if not matches_rows:
            matches_rows = [row for row in rows if isinstance(row, dict)
                            and row.get("repository") == repository and row.get("source_sha") == head
                            and matches(SHA, row.get("head_sha")) and matches(SHA, row.get("source_tree_sha"))
                            and row.get("source_tree_sha") == row.get("checked_tree_sha")
                            and text(row.get("tree_evidence_reference"))]
        if len(matches_rows) != 1 or not text(matches_rows[0].get("evidence_reference")):
            checks.append(result("UNKNOWN", repository + ":REQUIRED_CHECKS_MISSING"))
            continue
        row = matches_rows[0]
        if not isinstance(row.get("check_runs"), list) or row.get("complete") is not True:
            checks.append(result("UNKNOWN", "CHECK_INVENTORY_INCOMPLETE"))
            continue
        evaluation = CHECKS["evaluate_required_check_runs"](
            row["check_runs"], head_sha=row["head_sha"], required_checks=REQUIRED_CHECKS,
        )
        failures = evaluation["failures"]
        mismatch = any(failure.endswith((":not_success", ":stale_head", ":source_mismatch", ":invalid_order")) for failure in failures)
        checks.append(result("PASS" if evaluation["passed"] else "HOLD" if mismatch else "UNKNOWN",
                             repository + ":REQUIRED_CHECKS:" + (",".join(failures) or "PASS"),
                             [row["evidence_reference"]] + ([row["tree_evidence_reference"]] if row["head_sha"] != head else [])))
    return checks


def artifact_verification(candidate):
    checks = []
    statuses = []
    for service in sorted(set(candidate["artifacts"]) | set(candidate["build_scope"]) | set(candidate["activation_scope"])):
        artifact = candidate["artifacts"].get(service)
        verified = fact(candidate, "artifact." + service)
        receipt = candidate["facts"].get("artifact." + service, {}).get("value", {})
        if verified["status"] != "PASS" or not isinstance(receipt, dict) or artifact is None:
            status = "PROVENANCE_INVALID" if verified["status"] == "HOLD" else "VERIFICATION_INCOMPLETE"
        else:
            status = receipt.get("status", "VERIFICATION_INCOMPLETE")
            if status not in {"VERIFIED", "PROVENANCE_INVALID", "VERIFICATION_INFRA_FAILURE", "VERIFICATION_INCOMPLETE"}:
                status = "VERIFICATION_INCOMPLETE"
            if status == "VERIFIED":
                expected_source = candidate["source"]["storefront" if service == "storefront" else "platform"]
                if receipt.get("artifact") != artifact or receipt.get("source_sha") != expected_source:
                    status = "PROVENANCE_INVALID"
                elif receipt.get("architecture") != "arm64" or not text(receipt.get("validator")):
                    status = "VERIFICATION_INCOMPLETE"
        statuses.append(status)
        checks.append(result("PASS" if status == "VERIFIED" else "HOLD" if status == "PROVENANCE_INVALID" else "UNKNOWN",
                             service + ":" + status, verified["evidence"]))
    for priority in ("PROVENANCE_INVALID", "VERIFICATION_INFRA_FAILURE", "VERIFICATION_INCOMPLETE"):
        if priority in statuses:
            return {"status": priority, "results": checks}
    if not statuses:
        proof = fact(candidate, "artifacts_non_applicable", True, allow_na=True)
        return {"status": "N/A" if proof["status"] in {"PASS", "N/A"} else "VERIFICATION_INCOMPLETE", "results": [proof]}
    return {"status": "VERIFIED", "results": checks}


def rollback(candidate, lane):
    if not candidate["rollback_scope"]:
        proof = fact(candidate, "rollback_non_applicable", True, allow_na=True)
        return {"status": "ROLLBACK_READY" if proof["status"] in {"PASS", "N/A"} else "ROLLBACK_UNKNOWN", "requirements": {"N/A": proof}}
    rows = {key: fact(candidate, "rollback." + name, True) for key, name in RB_FACTS.items()}
    target = candidate.get("rollback_target", {})
    authority = candidate.get("rollback_authority", {})
    if (not isinstance(authority, dict) or not text(authority.get("operator_role"))
            or authority.get("go_actor_role") != "human_operator" or not text(authority.get("go_evidence_reference"))):
        rows["RB6"] = result("UNKNOWN", "ROLLBACK_OPERATOR_OR_GO_UNDEFINED")
    if (not isinstance(authority, dict) or not text(authority.get("procedure_reference"))
            or authority.get("procedure_status") != "CURRENT" or authority.get("target") != target):
        rows["RB5"] = result("UNKNOWN", "ROLLBACK_CURRENT_PROCEDURE_AUTHORITY_MISSING")
    if not isinstance(target, dict) or set(target) != set(candidate["rollback_scope"]):
        rows["RB1"] = result("UNKNOWN", "ROLLBACK_TARGET_SCOPE_MISSING")
    else:
        for service, record in target.items():
            if (not isinstance(record, dict) or not matches(SHA, record.get("source_sha"))
                    or not matches(DIGEST, record.get("digest")) or not text(record.get("release_id"))
                    or record.get("acceptance") != "KNOWN_GOOD" or not text(record.get("evidence_reference"))):
                rows["RB1"] = result("UNKNOWN", "PREVIOUS_ONLY_NOT_KNOWN_GOOD")
    minor = []
    if lane == MINOR:
        minor = [fact(candidate, "minor_rollback." + key, value) for key, value in MINOR_INVARIANTS.items()]
        minor.append(fact(candidate, "minor_rollback.exact_previous_release", target))
    status = aggregate([*rows.values(), *minor])
    preauthorization = fact(candidate, "rollback_preauthorization", {
        "status": "ROLLBACK_PREAUTHORIZED", "target": target, "actor_role": "human_operator"
    })
    return {
        "status": {"PASS": "ROLLBACK_READY", "HOLD": "ROLLBACK_HOLD", "UNKNOWN": "ROLLBACK_UNKNOWN"}[status],
        "requirements": rows, "minor_invariants": minor, "target": target,
        "preauthorization": "ROLLBACK_PREAUTHORIZED" if lane == MINOR and status == "PASS" and preauthorization["status"] == "PASS" else "NOT_CONFIRMED",
        "execution_authorized": False,
    }


def step_matrix(candidate, lane):
    lane_index = (AUTHORITY, MINOR, NORMAL, FULL).index(FULL if lane == STRICT_FALLBACK else lane)
    matrix = {
        "required_security_policy": ("REQUIRED",) * 4,
        "exact_source": ("REQUIRED",) * 4,
        "approved_source": ("REQUIRED",) * 4,
        "lane_scope_classification": ("REQUIRED",) * 4,
        "platform_build": ("N/A|REUSE", "N/A|REUSE", "N/A|REUSE", "CONDITIONAL"),
        "storefront_build": ("N/A|REUSE", "REQUIRED", "REQUIRED", "CONDITIONAL"),
        "contract_publication": ("N/A|REUSE", "N/A|REUSE", "CONDITIONAL", "CONDITIONAL"),
        "artifact_provenance": ("REQUIRED",) * 4,
        "arm64_proof": ("REQUIRED|REUSE", "REQUIRED|REUSE", "REQUIRED|REUSE", "REQUIRED"),
        "platform_stage": ("N/A", "N/A", "N/A", "CONDITIONAL"),
        "storefront_stage": ("N/A", "REQUIRED", "REQUIRED", "CONDITIONAL"),
        "db_migration_assessment": ("N/A", "N/A", "REQUIRED", "REQUIRED"),
        "rds_release_snapshot": ("N/A", "N/A", "CONDITIONAL", "CONDITIONAL"),
        "platform_activation": ("N/A", "N/A", "N/A", "CONDITIONAL"),
        "storefront_activation": ("N/A", "REQUIRED", "REQUIRED", "CONDITIONAL"),
        "worker_admin_agency_acceptance": ("N/A", "N/A|REUSE", "CONDITIONAL", "CONDITIONAL"),
        "authority_sync": ("CONDITIONAL", "REUSE", "CONDITIONAL", "CONDITIONAL"),
        "human_go": ("N/A", "REQUIRED", "REQUIRED", "REQUIRED"),
        "technical_acceptance": ("N/A", "REQUIRED", "REQUIRED", "REQUIRED"),
        "human_browser_acceptance": ("N/A", "REQUIRED", "REQUIRED", "REQUIRED"),
        "rollback_readiness": ("N/A", "REQUIRED", "REQUIRED", "REQUIRED"),
    }
    result_rows = {}
    for step, modes in matrix.items():
        modes = modes[lane_index].split("|")
        proof = fact(candidate, "step." + step, allow_na=True)
        requested = candidate["facts"].get("step." + step, {}).get("value")
        non_applicable_proven = requested != "N/A" or text(candidate["facts"].get("step." + step, {}).get("non_applicability_reason"))
        if requested in {"REUSE", "N/A"} and proof["status"] in {"PASS", "N/A"} and non_applicable_proven and (requested in modes or "CONDITIONAL" in modes):
            disposition = requested
        else:
            disposition = modes[0] if modes[0] not in {"N/A", "REUSE"} else "CONDITIONAL"
        result_rows[step] = {"policy_modes": modes, "disposition": disposition, "evidence": proof["evidence"],
                             "evidence_status": proof["status"], "operation_executed": False}
    return result_rows


def evaluate(candidate, authority_snapshot, continuity_record, *, generated_at=None):
    validate_candidate(candidate)
    snapshot(authority_snapshot)
    continuity_state = continuity(continuity_record, authority_snapshot)
    synced = (continuity_state == "CONFIRMED_NO_AUTHORITY_CHANGE"
              and candidate["authority_snapshot_digest"] == authority_snapshot["snapshot_digest"])
    lane = classify(candidate)
    artifacts = artifact_verification(candidate)
    rollback_result = rollback(candidate, lane["candidate_lane"])
    if not synced:
        rollback_result["preauthorization"] = "NOT_CONFIRMED"
        if rollback_result["status"] != "ROLLBACK_HOLD":
            rollback_result["status"] = "ROLLBACK_UNKNOWN"
    requirements = {}
    source_checks = [fact(candidate, name, True) for name in R1_FACTS]
    source_checks += [fact(candidate, "source_identity", candidate["source"])]
    service_checks = [fact(candidate, "service_scope", {key: candidate[key] for key in (
        "service_inventory", "build_scope", "activation_scope", "acceptance_scope", "rollback_scope"
    )})]
    service_checks.extend(artifacts["results"])
    current = {service["service"]: service for service in authority_snapshot["services"]}
    if set(current) != set(candidate["service_inventory"]):
        service_checks.append(result("UNKNOWN", "SNAPSHOT_SERVICE_COVERAGE_MISSING"))
    contract_checks = [fact(candidate, "contract_provenance", candidate.get("target_contract"), allow_na=True)]
    if candidate["target_contract"]["status"] == "UNKNOWN":
        contract_checks.append(result("UNKNOWN", "TARGET_CONTRACT_UNKNOWN"))
    if not isinstance(candidate.get("target_contract"), dict) or not candidate["target_contract"]:
        contract_checks.append(result("UNKNOWN", "TARGET_CONTRACT_MISSING"))
    delta_checks = [fact(candidate, "runtime_delta_inventory", {"current": current, "surfaces": list(RUNTIME_SURFACES)}),
                    fact(candidate, "all_runtime_deltas_approved", True), fact(candidate, "complete_diff", True),
                    fact(candidate, "no_known_holds", True)]
    if any(service["status"] == "UNKNOWN" for service in current.values()):
        delta_checks.append(result("UNKNOWN", "CURRENT_RUNTIME_UNKNOWN"))
    if authority_snapshot["contract"]["status"] == "UNKNOWN" or authority_snapshot["external_runtime_definitions"]["status"] in {"PARTIAL", "UNKNOWN"}:
        delta_checks.append(result("UNKNOWN", "CURRENT_AUTHORITY_INCOMPLETE"))
    plan_checks = [fact(candidate, name, True, allow_na=True) for name in R6_FACTS]
    if authority_snapshot["migration"]["status"] in {"UNKNOWN", "HISTORICAL_ONLY"}:
        plan_checks.append(result("UNKNOWN", "CURRENT_MIGRATION_AUTHORITY_UNKNOWN"))
    plan_checks.append(result({"ROLLBACK_READY": "PASS", "ROLLBACK_HOLD": "HOLD", "ROLLBACK_UNKNOWN": "UNKNOWN"}[rollback_result["status"]], rollback_result["status"]))
    plan_checks.extend(fact(candidate, "stage." + service, candidate["artifacts"].get(service)) for service in candidate["activation_scope"])
    for number, checks in enumerate((source_checks, service_checks, contract_checks, required_checks(candidate), delta_checks, plan_checks), 1):
        requirements["R" + str(number)] = {
            "status": "N/A" if checks and all(check["status"] == "N/A" for check in checks) else aggregate(checks),
            "results": checks,
        }
    combined = aggregate(list(requirements.values()))
    final_status = "AUTHORITY_SYNC_REQUIRED" if not synced else {
        "PASS": "SHADOW_READY", "HOLD": "SHADOW_HOLD", "UNKNOWN": "SHADOW_UNKNOWN",
    }[combined]
    details = [row for requirement in requirements.values() for row in requirement["results"]]
    classifier = candidate["facts"].get("storefront_minor_classification", {}).get("value", {})
    if not isinstance(classifier, dict):
        classifier = {}
    record = {
        "schema_version": "1.0", "generated_at": generated_at or datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        **identity(candidate), "production_runtime_baseline": deepcopy(authority_snapshot),
        "continuity_record_digest": continuity_record["record_digest"], "continuity_status": continuity_state,
        "lane": lane["candidate_lane"], "lane_reason": lane["classification_reason"], "fallback_lane": lane["fallback_lane"],
        "classification": lane, "requirements": requirements, "artifact_verification": artifacts,
        **{scope: candidate[scope] for scope in ("build_scope", "activation_scope", "acceptance_scope", "rollback_scope")},
        "production_step_matrix": step_matrix(candidate, lane["candidate_lane"]),
        "affected_modules": classifier.get("affected_modules", []), "affected_routes": classifier.get("affected_routes", []),
        "affected_states": classifier.get("affected_states", []),
        "required_browser_acceptance": {"status": "HUMAN_BROWSER_ACCEPTANCE_PENDING", "plan": classifier.get("browser_acceptance_plan")},
        "rollback_readiness": rollback_result,
        "unknown_reasons": [row["reason"] for row in details if row["status"] == "UNKNOWN"] + ([] if synced else ["AUTHORITY_CONTINUITY_NOT_CONFIRMED"]),
        "hold_reasons": [row["reason"] for row in details if row["status"] == "HOLD"],
        "shadow_final_status": final_status,
        "blocking_model_status": {"SHADOW_READY": "READY", "SHADOW_HOLD": "HOLD", "SHADOW_UNKNOWN": "INCOMPLETE / UNKNOWN", "AUTHORITY_SYNC_REQUIRED": "AUTHORITY_SYNC_REQUIRED"}[final_status],
        "gate_result": "OBSERVATION ONLY", "production_impact": "NONE", "blocking_authority": False,
        "env_actual_values": "UNKNOWN — ENV FILE / RUNTIME ENV ACCESS PROHIBITED",
    }
    timestamp(record["generated_at"])
    return seal(record, "record_digest")


def unknown_record():
    return seal({
        "schema_version": "1.0", "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        **{key: None for key in ("candidate_id", "repository", "base_sha", "head_sha", "tree_sha",
                                "workflow_authority", "production_runtime_baseline", "authority_snapshot_digest", "continuity_record_digest")},
        "lane": FULL, "lane_reason": "INPUT_INVALID_OR_UNAVAILABLE", "fallback_lane": FULL,
        "requirements": {"R" + str(number): result("UNKNOWN", "INPUT_INVALID_OR_UNAVAILABLE") for number in range(1, 7)},
        "artifact_verification": {"status": "VERIFICATION_INCOMPLETE", "results": []},
        "build_scope": [], "activation_scope": [], "acceptance_scope": [], "rollback_scope": [],
        "production_step_matrix": {}, "affected_modules": [], "affected_routes": [], "affected_states": [],
        "required_browser_acceptance": {"status": "HUMAN_BROWSER_ACCEPTANCE_PENDING", "plan": None},
        "rollback_readiness": {"status": "ROLLBACK_UNKNOWN"}, "unknown_reasons": ["INPUT_INVALID_OR_UNAVAILABLE"],
        "hold_reasons": [], "shadow_final_status": "SHADOW_UNKNOWN", "blocking_model_status": "INCOMPLETE / UNKNOWN",
        "production_impact": "NONE", "gate_result": "OBSERVATION ONLY", "blocking_authority": False,
        "env_actual_values": "UNKNOWN — ENV FILE / RUNTIME ENV ACCESS PROHIBITED",
    }, "record_digest")
