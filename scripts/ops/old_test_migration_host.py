"""OLD Test ledger binding. No database connection on the no-migration path."""

import json
import re
from pathlib import Path

import old_test_host as host
import old_test_simple as simple
from old_test_simple_source import PLATFORM, require

DIRECTORY = "apps/api/database/migrations-v2"
LEDGER = (
    "BEGIN READ ONLY; SET LOCAL statement_timeout='5s'; "
    "SELECT json_build_object('database',current_database(),'ledger',coalesce("
    '(SELECT json_agg(migration ORDER BY migration COLLATE "C") FROM public.migrations),'
    "'[]'::json)); ROLLBACK;"
)


def files(settings, sha):
    require(simple.SHA.fullmatch(sha), "migration_source_invalid")
    repo = Path(settings["database"]["source_repository"])
    args = ["/usr/bin/git", "-C", str(repo), "ls-tree", "-r", sha + ":" + DIRECTORY]
    entries = {}
    for line in host.run(args).decode().splitlines():
        mode, kind, item = line.split(" ", 2)
        blob, name = item.split("\t", 1)
        if name == "README.md" and kind == "blob":
            continue
        require(
            mode == "100644"
            and kind == "blob"
            and simple.SHA.fullmatch(blob)
            and simple.MIGRATION.fullmatch(name)
            and name.endswith(".php"),
            "migration_source_inventory_invalid",
        )
        entries[name] = blob
    require(entries, "migration_source_inventory_empty")
    return entries


def classification(settings, sha, name, blob):
    policy = settings["database"]
    enrolled = policy["reviewed_additions"].get(blob)
    if enrolled:
        require(
            enrolled["kind"] == "additive" and enrolled["file"] == name, "migration_classification_changed"
        )
        return enrolled
    # Recognize only Laravel's literal create-table form. An arbitrary PHP
    # migration may run application code, even if its filename says "create".
    raw = host.canonical.source_bytes(Path(policy["source_repository"]), sha, DIRECTORY + "/" + name).decode()
    word = r"'[a-z][a-z0-9_]*'"
    arg = r"(?:" + word + r"|[0-9]{1,5}|true|false|\[(?:" + word + r")(?:\s*,\s*" + word + r")*\])"
    args = r"\(\s*(?:" + arg + r"(?:\s*,\s*" + arg + r")*)?\s*\)"
    fields = r"(?:id|uuid|foreignId|string|boolean|integer|bigInteger|unsignedBigInteger|text|jsonb|timestampTz|timestampsTz|unique|index)"
    modifiers = r"(?:nullable|default|unique|index|constrained|restrictOnDelete|cascadeOnDelete)"
    statement = r"\$table->" + fields + args + r"(?:->" + modifiers + args + r")*;"
    pattern = (
        r"<\?php\s+use Illuminate\\Database\\Migrations\\Migration;\s+"
        r"use Illuminate\\Database\\Schema\\Blueprint;\s+use Illuminate\\Support\\Facades\\Schema;\s+"
        r"return new class extends Migration\s*\{\s*public function up\(\): void\s*\{\s*"
        r"Schema::create\((" + word + r"),\s*(?:static )?function\s*\(Blueprint \$table\)(?:: void)?\s*\{"
        r"(?:\s*" + statement + r")+\s*\}\);\s*\}\s*public function down\(\): void\s*\{\s*"
        r"Schema::dropIfExists\(\1\);\s*\}\s*\};\s*"
    )
    require(re.fullmatch(pattern, raw), "migration_requires_classification_" + name)
    return {"kind": "additive", "file": name, "writers": []}


def prepare(plan, settings, states, migration):
    current = host.container(settings, "api")
    image = json.loads(host.run(["/usr/bin/docker", "image", "inspect", current["Image"]]))[0]
    previous = files(settings, image["Config"]["Labels"]["org.opencontainers.image.revision"])
    requested = files(settings, plan["sources"][PLATFORM]["sha"])
    require(previous.items() <= requested.items(), "previous_migrations_changed")
    additions = sorted(requested.keys() - previous.keys())
    require(all(name in migration["files"] for name in additions), "approved_migration_missing_from_plan")
    if not migration["files"]:
        return migration
    writers = set()
    for name in migration["files"]:
        require(name in requested, "migration_missing_from_approved_source")
        # Independent reviewed classification records the precise file and affected writers.
        reviewed = classification(settings, plan["sources"][PLATFORM]["sha"], name, requested[name])
        require(set(reviewed["writers"]) <= states.keys(), "unknown_migration_writer")
        writers.update(w for w in reviewed["writers"] if states[w] == "active")
    return dict(migration, writers=sorted(writers))


def ledger(plan, settings):
    db = host.container(settings, "postgres")
    api = host.container(settings, "api")
    db_vars = dict(x.split("=", 1) for x in db["Config"]["Env"])
    api_vars = dict(x.split("=", 1) for x in api["Config"]["Env"])
    labels = db["Config"]["Labels"]
    require(
        labels["com.docker.compose.service"] == "postgres"
        and db["State"]["Running"]
        and db_vars["POSTGRES_DB"] == api_vars["DB_DATABASE"]
        and db_vars["POSTGRES_USER"] == api_vars["DB_USERNAME"]
        and api_vars["DB_CONNECTION"] == "pgsql",
        "old_database_identity_changed",
    )
    network = settings["database"]["private_network"]
    endpoint = db["NetworkSettings"]["Networks"][network]
    require(
        endpoint["NetworkID"] == api["NetworkSettings"]["Networks"][network]["NetworkID"]
        and api_vars["DB_HOST"] in set(endpoint.get("Aliases") or []) | {endpoint["IPAddress"]},
        "old_database_network_changed",
    )
    result = json.loads(
        host.run(
            [
                "/usr/bin/docker",
                "exec",
                db["Id"],
                "psql",
                "-X",
                "-qAt",
                "-v",
                "ON_ERROR_STOP=1",
                "-U",
                db_vars["POSTGRES_USER"],
                "-d",
                db_vars["POSTGRES_DB"],
                "-c",
                LEDGER,
            ]
        )
    )
    require(result["database"] == api_vars["DB_DATABASE"], "unexpected_connected_database")
    return [name + ".php" for name in result["ledger"]]


def inventory(plan, target, data, guard):
    host.approved(plan, guard)
    require(
        target == "api" and "api" in plan["targets"] and plan["migration"]["files"], "migration_not_selected"
    )
    settings = host.config(plan)
    source = plan["sources"][PLATFORM]["sha"]
    entries = files(settings, source)
    for name in plan["migration"]["files"]:
        classification(settings, source, name, entries[name])
    current = ledger(plan, settings)
    simple.migration_suffix(sorted(entries), current, plan["migration"]["files"])
    return dict(
        inventory=sorted(entries), ledger=current, source=source, destructive=False, side_effects_known=True
    )


def observe(plan, op, target, guard):
    require(op == "migrate" and target in plan["migration"]["files"], "migration_effect_outside_plan")
    state = inventory(plan, "api", None, guard)
    if target in state["ledger"]:
        return "done"
    require(
        simple.migration_suffix(state["inventory"], state["ledger"], plan["migration"]["files"])[0] == target,
        "unexpected_next_migration",
    )
    return "ready"


def database_binding(plan, target, data, guard):
    require(
        data["operation"] == "migrate" and target in plan["migration"]["files"],
        "migration_effect_outside_plan",
    )
    settings = host.config(plan)
    state = inventory(plan, "api", None, guard)
    next_file = simple.migration_suffix(state["inventory"], state["ledger"], plan["migration"]["files"])[0]
    scope = host.pinned(settings["warm"])["active_info"](plan["migration"]["writers"])
    api = host.container(settings, "api")
    require(
        next_file == target
        and simple.asset(plan, "api", host.cached(plan, "api")) == data["image"]
        and all(s == "inactive" for s in scope.values()),
        "migration_writers_or_image_changed",
    )
    return dict(
        plan=simple.digest(plan),
        image=data["image"],
        container=api["Id"],
        old_test_database_verified=True,
        writers_drained=True,
        side_effects_known=True,
        non_destructive=True,
        next_file=next_file,
        compose_digest=host.compose_binding(api, settings["containers"]["api"]),
    )
