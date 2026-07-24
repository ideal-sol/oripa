#!/usr/bin/env python3
"""Guarded V2-only PostgreSQL and Redis lifecycle for development and CI."""

from __future__ import annotations

import argparse
import base64
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import tempfile
from typing import Any, Iterable


MIGRATION_PATH = "apps/api/database/migrations-v2"
CONTAINER_MIGRATION_PATH = "database/migrations-v2"
V1_MIGRATION_PATH = "apps/api/database/migrations"
V1_PROJECT = "oripa"
V1_VOLUMES = {"oripa_postgres_data", "oripa_redis_data"}
ALLOWED_ENVIRONMENTS = {"local", "testing"}
REQUIRED_ENV_KEYS = {
    "COMPOSE_PROJECT_NAME",
    "V2_APP_ENV",
    "V2_APP_KEY",
    "V2_DB_HOST",
    "V2_DB_PORT",
    "V2_DB_DATABASE",
    "V2_DB_USERNAME",
    "V2_DB_PASSWORD",
    "V2_REDIS_HOST",
    "V2_REDIS_PORT",
    "V2_REDIS_PASSWORD",
}
EXPECTED_V2_SCHEMA_INVENTORY = [
    "public.admin_recovery_codes",
    "public.admin_sessions",
    "public.admin_totp_methods",
    "public.admin_webauthn_credentials",
    "public.admins",
    "public.migrations",
    "public.user_remember_devices",
    "public.user_sessions",
    "public.users",
]


class GuardFailure(RuntimeError):
    """A redacted validation or lifecycle failure."""


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace(
        "+00:00", "Z"
    )


def run(
    command: list[str],
    *,
    cwd: Path,
    input_bytes: bytes | None = None,
    capture: bool = True,
) -> bytes:
    environment = os.environ.copy()
    environment["COMPOSE_BAKE"] = "false"
    try:
        completed = subprocess.run(
            command,
            cwd=cwd,
            env=environment,
            input=input_bytes,
            stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            check=True,
        )
    except subprocess.CalledProcessError as error:
        executable = Path(command[0]).name
        raise GuardFailure(f"{executable} operation failed") from error
    return completed.stdout if capture else b""


def parse_env_file(path: Path) -> dict[str, str]:
    if path.is_symlink():
        raise GuardFailure("Env File must not be a symlink")
    if not path.is_file():
        raise GuardFailure("Env File is missing")
    if any(part.lower() in {"prod", "production"} for part in path.parts):
        raise GuardFailure("Production Credential path is prohibited")
    mode = stat.S_IMODE(path.stat().st_mode)
    if mode & 0o077:
        raise GuardFailure("Env File must not be readable by group or other")
    if path.is_relative_to(Path("/etc")) and path.stat().st_uid != 0:
        raise GuardFailure("Env File under /etc must be owned by root")

    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            raise GuardFailure("Env File contains an invalid entry")
        key, value = line.split("=", 1)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]*", key):
            raise GuardFailure("Env File contains an invalid key")
        if key in values or not value or "\x00" in value:
            raise GuardFailure("Env File contains an invalid value")
        values[key] = value
    missing = sorted(REQUIRED_ENV_KEYS - values.keys())
    if missing:
        raise GuardFailure("Env File is missing required V2 values")
    if values.keys() != REQUIRED_ENV_KEYS:
        raise GuardFailure("Env File contains an unexpected credential field")
    return values


def validate_project(project: str) -> None:
    if project == V1_PROJECT or project.startswith("oripa_"):
        raise GuardFailure("V1 Compose Project is prohibited")
    if project != "oripa-v2-dev" and not re.fullmatch(
        r"mig[0-9]{3}[a-z]?-v2-[a-z0-9][a-z0-9-]{5,48}", project
    ):
        raise GuardFailure("Compose Project is outside the V2 allowlist")


def validate_migration_path(repository: Path, migration_path: str | None) -> Path:
    if not migration_path:
        raise GuardFailure("V2 Migration Path is required")
    if migration_path == V1_MIGRATION_PATH or migration_path.endswith(
        "/database/migrations"
    ):
        raise GuardFailure("V1 Migration Path is prohibited")
    if migration_path != MIGRATION_PATH:
        raise GuardFailure("Unexpected Migration Path")
    resolved = (repository / migration_path).resolve()
    if not resolved.is_relative_to(repository.resolve()) or not resolved.is_dir():
        raise GuardFailure("V2 Migration Path is unavailable")
    return resolved


def validate_values(values: dict[str, str], project: str) -> None:
    validate_project(project)
    if values["COMPOSE_PROJECT_NAME"] != project:
        raise GuardFailure("Compose Project does not match Env File")
    if values["V2_APP_ENV"].lower() not in ALLOWED_ENVIRONMENTS:
        raise GuardFailure("Production or unexpected environment is prohibited")
    if values["V2_DB_HOST"] != "postgres" or values["V2_REDIS_HOST"] != "redis":
        raise GuardFailure("Unexpected Database or Redis Host")
    if values["V2_DB_PORT"] != "5432" or values["V2_REDIS_PORT"] != "6379":
        raise GuardFailure("Unexpected Database or Redis Port")
    if not re.fullmatch(r"oripa_v2_[a-z0-9_]{3,48}", values["V2_DB_DATABASE"]):
        raise GuardFailure("Database name is outside the V2 namespace")
    if not re.fullmatch(r"oripa_v2_[a-z0-9_]{3,48}", values["V2_DB_USERNAME"]):
        raise GuardFailure("Database user is outside the V2 namespace")
    if len(values["V2_DB_PASSWORD"]) < 24 or len(values["V2_REDIS_PASSWORD"]) < 24:
        raise GuardFailure("Generated credentials do not meet minimum length")
    if not values["V2_APP_KEY"].startswith("base64:"):
        raise GuardFailure("V2 Application key format is invalid")
    try:
        decoded = base64.b64decode(values["V2_APP_KEY"][7:], validate=True)
    except ValueError as error:
        raise GuardFailure("V2 Application key format is invalid") from error
    if len(decoded) != 32:
        raise GuardFailure("V2 Application key length is invalid")


def compose_command(
    repository: Path, compose_file: Path, env_file: Path, project: str
) -> list[str]:
    return [
        "docker",
        "compose",
        "--project-name",
        project,
        "--env-file",
        str(env_file),
        "--file",
        str(compose_file),
    ]


def validate_compose(
    repository: Path,
    compose_file: Path,
    env_file: Path,
    project: str,
    values: dict[str, str],
) -> dict[str, Any]:
    if compose_file.resolve() != (repository / "docker-compose.v2.yml").resolve():
        raise GuardFailure("Unexpected Compose File")
    config_bytes = run(
        compose_command(repository, compose_file, env_file, project)
        + ["config", "--format", "json"],
        cwd=repository,
    )
    try:
        config = json.loads(config_bytes)
    except json.JSONDecodeError as error:
        raise GuardFailure("Compose Config is invalid") from error
    services = config.get("services", {})
    if set(services) != {"api", "admin", "postgres", "redis"}:
        raise GuardFailure("Unexpected V2 Compose services")
    if not str(services["postgres"].get("image", "")).startswith("postgres:17"):
        raise GuardFailure("PostgreSQL major version is invalid")
    if not str(services["redis"].get("image", "")).startswith("redis:7"):
        raise GuardFailure("Redis major version is invalid")
    for service_name in ("postgres", "redis"):
        if services[service_name].get("ports"):
            raise GuardFailure("Database and Redis Host Ports are prohibited")
        if services[service_name].get("container_name"):
            raise GuardFailure("Fixed Container names are prohibited")
    for service in services.values():
        if service.get("container_name"):
            raise GuardFailure("Fixed Container names are prohibited")
    volumes = config.get("volumes", {})
    expected_volume_names = {f"{project}_v2_postgres", f"{project}_v2_redis"}
    actual_volume_names = {
        str(value.get("name")) for value in volumes.values() if isinstance(value, dict)
    }
    if set(volumes) != {"v2_postgres", "v2_redis"}:
        raise GuardFailure("V2 Volume isolation is invalid")
    if actual_volume_names != expected_volume_names or actual_volume_names & V1_VOLUMES:
        raise GuardFailure("V2 Volume isolation is invalid")
    networks = config.get("networks", {})
    network = networks.get("v2_private")
    if not isinstance(network, dict) or network.get("name") != f"{project}_v2_private":
        raise GuardFailure("V2 Network isolation is invalid")
    serialized = json.dumps(config, sort_keys=True)
    if "tenant_id" in serialized or any(name in serialized for name in V1_VOLUMES):
        raise GuardFailure("Shared V1 or tenant configuration is prohibited")
    if values["V2_DB_DATABASE"] == "oripa":
        raise GuardFailure("V1 Database is prohibited")
    return config


def validate(
    repository: Path,
    compose_file: Path,
    env_file: Path,
    project: str,
    migration_path: str | None,
) -> dict[str, str]:
    repository = repository.resolve()
    validate_migration_path(repository, migration_path)
    values = parse_env_file(env_file)
    validate_values(values, project)
    validate_compose(repository, compose_file, env_file, project, values)
    return values


def migration_checksum(repository: Path) -> tuple[int, str]:
    files = sorted((repository / MIGRATION_PATH).glob("*.php"))
    digests = sorted(hashlib.sha256(path.read_bytes()).hexdigest() for path in files)
    payload = ("\n".join(digests) + ("\n" if digests else "")).encode()
    return len(files), hashlib.sha256(payload).hexdigest()


def compose_exec(
    base: list[str],
    repository: Path,
    service: str,
    script: str,
    *,
    input_bytes: bytes | None = None,
) -> bytes:
    return run(
        base + ["exec", "-T", service, "sh", "-euc", script],
        cwd=repository,
        input_bytes=input_bytes,
    )


def migrate_fresh(base: list[str], repository: Path, one_shot: bool) -> None:
    prefix = ["run", "--rm", "--no-deps"] if one_shot else ["exec", "-T"]
    run(
        base
        + prefix
        + [
            "api",
            "php",
            "artisan",
            "migrate:fresh",
            f"--path={CONTAINER_MIGRATION_PATH}",
            "--no-interaction",
        ],
        cwd=repository,
        capture=False,
    )


def migration_status(base: list[str], repository: Path, one_shot: bool) -> None:
    prefix = ["run", "--rm", "--no-deps"] if one_shot else ["exec", "-T"]
    run(
        base
        + prefix
        + [
            "api",
            "php",
            "artisan",
            "migrate:status",
            f"--path={CONTAINER_MIGRATION_PATH}",
            "--no-interaction",
        ],
        cwd=repository,
        capture=False,
    )


def schema_inventory(base: list[str], repository: Path) -> list[str]:
    output = compose_exec(
        base,
        repository,
        "postgres",
        'psql --tuples-only --no-align --username "$POSTGRES_USER" '
        '--dbname "$POSTGRES_DB" --command '
        "\"SELECT schemaname || '.' || tablename FROM pg_tables "
        "WHERE schemaname NOT IN ('pg_catalog', 'information_schema') "
        'ORDER BY schemaname, tablename;\"',
    )
    return [line for line in output.decode().splitlines() if line]


def migration_rows(base: list[str], repository: Path) -> bytes:
    return compose_exec(
        base,
        repository,
        "postgres",
        'psql --tuples-only --no-align --username "$POSTGRES_USER" '
        '--dbname "$POSTGRES_DB" --command '
        '"SELECT migration || \':\' || batch FROM migrations ORDER BY id;"',
    )


def validate_schema_inventory(inventory: list[str]) -> None:
    if inventory != EXPECTED_V2_SCHEMA_INVENTORY:
        raise GuardFailure("Unexpected V2 schema inventory")


def run_identity_tests(
    base: list[str], repository: Path, one_shot: bool
) -> None:
    prefix = ["run", "--rm", "--no-deps"] if one_shot else ["exec", "-T"]
    run(
        base
        + prefix
        + [
            "api",
            "vendor/bin/phpunit",
            "--configuration",
            "phpunit.v2.xml",
        ],
        cwd=repository,
        capture=False,
    )


def schema_dump(base: list[str], repository: Path) -> bytes:
    return compose_exec(
        base,
        repository,
        "postgres",
        'pg_dump --schema-only --no-owner --no-privileges '
        '--username "$POSTGRES_USER" --dbname "$POSTGRES_DB"',
    )


def normalize_schema_dump(value: bytes) -> bytes:
    lines = []
    for line in value.decode("utf-8").splitlines():
        if line.startswith("\\restrict "):
            line = "\\restrict <normalized>"
        elif line.startswith("\\unrestrict "):
            line = "\\unrestrict <normalized>"
        lines.append(line)
    return ("\n".join(lines) + "\n").encode("utf-8")


def backup_database(base: list[str], repository: Path) -> bytes:
    return compose_exec(
        base,
        repository,
        "postgres",
        'pg_dump --format=custom --no-owner --no-privileges '
        '--username "$POSTGRES_USER" --dbname "$POSTGRES_DB"',
    )


def restore_database(base: list[str], repository: Path, backup: bytes) -> None:
    compose_exec(
        base,
        repository,
        "postgres",
        'pg_restore --exit-on-error --no-owner --no-privileges '
        '--username "$POSTGRES_USER" --dbname "$POSTGRES_DB"',
        input_bytes=backup,
    )


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(path.parent, 0o700)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    os.chmod(temporary, 0o600)
    temporary.replace(path)
    os.chmod(path, 0o600)


def create_env_file(path: Path, project: str, environment: str, suffix: str) -> None:
    validate_project(project)
    if environment not in ALLOWED_ENVIRONMENTS:
        raise GuardFailure("Production or unexpected environment is prohibited")
    if path.exists() or path.is_symlink():
        raise GuardFailure("Env File already exists")
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(path.parent, 0o700)
    database_suffix = re.sub(r"[^a-z0-9]+", "_", suffix.lower()).strip("_")
    values = {
        "COMPOSE_PROJECT_NAME": project,
        "V2_APP_ENV": environment,
        "V2_APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
        "V2_DB_HOST": "postgres",
        "V2_DB_PORT": "5432",
        "V2_DB_DATABASE": f"oripa_v2_{database_suffix}",
        "V2_DB_USERNAME": f"oripa_v2_{database_suffix}_user",
        "V2_DB_PASSWORD": secrets.token_urlsafe(36),
        "V2_REDIS_HOST": "redis",
        "V2_REDIS_PORT": "6379",
        "V2_REDIS_PASSWORD": secrets.token_urlsafe(36),
    }
    path.write_text(
        "".join(f"{key}={value}\n" for key, value in values.items()),
        encoding="utf-8",
    )
    os.chmod(path, 0o600)


def project_resources(kind: str, project: str, repository: Path) -> list[str]:
    output = run(
        [
            "docker",
            kind,
            "ls",
            "--filter",
            f"label=com.docker.compose.project={project}",
            "--format",
            "{{.Name}}",
        ],
        cwd=repository,
    )
    return [line for line in output.decode().splitlines() if line]


def cleanup_project(base: list[str], project: str, repository: Path) -> None:
    run(base + ["down", "--remove-orphans"], cwd=repository, capture=False)
    volumes = project_resources("volume", project, repository)
    for volume in volumes:
        if not volume.startswith(project + "_") or volume in V1_VOLUMES:
            raise GuardFailure("Refusing to remove an unscoped Volume")
        run(["docker", "volume", "rm", volume], cwd=repository, capture=False)
    if project_resources("network", project, repository):
        raise GuardFailure("Task Network cleanup failed")
    if project_resources("volume", project, repository):
        raise GuardFailure("Task Volume cleanup failed")
    containers = run(
        [
            "docker",
            "ps",
            "-a",
            "--filter",
            f"label=com.docker.compose.project={project}",
            "--format",
            "{{.Names}}",
        ],
        cwd=repository,
    )
    if containers.strip():
        raise GuardFailure("Task Container cleanup failed")


def run_persistent(args: argparse.Namespace) -> dict[str, Any]:
    repository = Path(args.repository).resolve()
    compose_file = (repository / args.compose_file).resolve()
    env_file = Path(args.env_file).resolve()
    values = validate(
        repository, compose_file, env_file, args.project, args.migration_path
    )
    base = compose_command(repository, compose_file, env_file, args.project)
    run(base + ["up", "--detach", "--wait", "postgres", "redis"], cwd=repository)
    run(base + ["build", "api"], cwd=repository, capture=False)
    migrate_fresh(base, repository, one_shot=True)
    migrate_fresh(base, repository, one_shot=True)
    migration_status(base, repository, one_shot=True)
    run_identity_tests(base, repository, one_shot=True)
    inventory = schema_inventory(base, repository)
    validate_schema_inventory(inventory)
    migration_count, migration_set = migration_checksum(repository)
    evidence = {
        "schema_version": "1.0",
        "created_at": utc_now(),
        "project": args.project,
        "environment": values["V2_APP_ENV"],
        "migration_path": MIGRATION_PATH,
        "migration_file_count": migration_count,
        "migration_set_sha256": migration_set,
        "migrate_fresh_runs": 2,
        "migration_status": "PASS",
        "identity_tests": "PASS",
        "schema_inventory": inventory,
        "postgres_health": "PASS",
        "redis_health": "PASS",
        "host_ports_published": False,
    }
    write_json(Path(args.evidence_dir) / "persistent-result.json", evidence)
    return evidence


def run_smoke(args: argparse.Namespace) -> dict[str, Any]:
    repository = Path(args.repository).resolve()
    compose_file = (repository / args.compose_file).resolve()
    evidence_dir = Path(args.evidence_dir).resolve()
    evidence_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(evidence_dir, 0o700)
    source_project = args.project_prefix + "-source"
    restore_project = args.project_prefix + "-restore"
    with tempfile.TemporaryDirectory(prefix="v2-db-") as temporary:
        temporary_path = Path(temporary)
        source_env = temporary_path / "source.env"
        restore_env = temporary_path / "restore.env"
        create_env_file(source_env, source_project, "testing", "ci_source")
        create_env_file(restore_env, restore_project, "testing", "ci_restore")
        validate(
            repository,
            compose_file,
            source_env,
            source_project,
            args.migration_path,
        )
        validate(
            repository,
            compose_file,
            restore_env,
            restore_project,
            args.migration_path,
        )
        source_base = compose_command(
            repository, compose_file, source_env, source_project
        )
        restore_base = compose_command(
            repository, compose_file, restore_env, restore_project
        )
        source_started = False
        restore_started = False
        try:
            source_started = True
            run(
                source_base + ["up", "--detach", "--wait", "--build"],
                cwd=repository,
                capture=False,
            )
            migrate_fresh(source_base, repository, one_shot=False)
            migrate_fresh(source_base, repository, one_shot=False)
            migration_status(source_base, repository, one_shot=False)
            run_identity_tests(source_base, repository, one_shot=False)
            run(
                source_base
                + [
                    "exec",
                    "-T",
                    "api",
                    "curl",
                    "--fail",
                    "--silent",
                    "http://localhost:8000/api/health",
                ],
                cwd=repository,
            )
            run(
                source_base
                + [
                    "exec",
                    "-T",
                    "admin",
                    "wget",
                    "--quiet",
                    "--output-document=-",
                    "http://127.0.0.1:3000/api/health",
                ],
                cwd=repository,
            )
            source_inventory = schema_inventory(source_base, repository)
            validate_schema_inventory(source_inventory)
            source_schema_raw = schema_dump(source_base, repository)
            source_schema = normalize_schema_dump(source_schema_raw)
            source_rows = migration_rows(source_base, repository)
            backup = backup_database(source_base, repository)
            backup_path = evidence_dir / "v2-identity-database.dump"
            backup_path.write_bytes(backup)
            os.chmod(backup_path, 0o600)

            restore_started = True
            run(
                restore_base + ["up", "--detach", "--wait", "postgres", "redis"],
                cwd=repository,
                capture=False,
            )
            restore_database(restore_base, repository, backup)
            restore_inventory = schema_inventory(restore_base, repository)
            restore_schema_raw = schema_dump(restore_base, repository)
            restore_schema = normalize_schema_dump(restore_schema_raw)
            restore_rows = migration_rows(restore_base, repository)
            source_schema_path = evidence_dir / "source-schema.sql"
            restore_schema_path = evidence_dir / "restore-schema.sql"
            source_schema_path.write_bytes(source_schema_raw)
            restore_schema_path.write_bytes(restore_schema_raw)
            os.chmod(source_schema_path, 0o600)
            os.chmod(restore_schema_path, 0o600)
            if source_inventory != restore_inventory:
                raise GuardFailure("Backup restore schema inventory mismatch")
            if source_schema != restore_schema:
                raise GuardFailure("Backup restore schema checksum mismatch")
            if source_rows != restore_rows:
                raise GuardFailure("Backup restore migration checksum mismatch")
            migration_count, migration_set = migration_checksum(repository)
            evidence = {
                "schema_version": "1.0",
                "created_at": utc_now(),
                "source_project": source_project,
                "restore_project": restore_project,
                "migration_path": MIGRATION_PATH,
                "migration_file_count": migration_count,
                "migration_set_sha256": migration_set,
                "migrate_fresh_runs": 2,
                "migration_status": "PASS",
                "identity_tests": "PASS",
                "schema_inventory": source_inventory,
                "source_schema_sha256": sha256(source_schema),
                "restore_schema_sha256": sha256(restore_schema),
                "source_migration_rows_sha256": sha256(source_rows),
                "restore_migration_rows_sha256": sha256(restore_rows),
                "backup_sha256": sha256(backup),
                "backup_restore_match": True,
                "api_health": "PASS",
                "admin_health": "PASS",
                "postgres_health": "PASS",
                "redis_health": "PASS",
                "host_ports_published": False,
            }
            write_json(evidence_dir / "ephemeral-result.json", evidence)
        finally:
            if restore_started:
                cleanup_project(restore_base, restore_project, repository)
            if source_started:
                cleanup_project(source_base, source_project, repository)
        evidence["resource_cleanup"] = "PASS"
        write_json(evidence_dir / "ephemeral-result.json", evidence)
        return evidence


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)
    for name in ("init-env", "validate", "persistent"):
        command = subparsers.add_parser(name)
        command.add_argument("--repository", required=True)
        command.add_argument("--compose-file", default="docker-compose.v2.yml")
        command.add_argument("--env-file", required=True)
        command.add_argument("--project", required=True)
        command.add_argument("--migration-path", required=True)
        if name == "persistent":
            command.add_argument("--evidence-dir", required=True)
    smoke = subparsers.add_parser("smoke")
    smoke.add_argument("--repository", required=True)
    smoke.add_argument("--compose-file", default="docker-compose.v2.yml")
    smoke.add_argument("--project-prefix", required=True)
    smoke.add_argument("--migration-path", required=True)
    smoke.add_argument("--evidence-dir", required=True)
    return parser


def main() -> int:
    args = build_parser().parse_args()
    try:
        repository = Path(args.repository).resolve()
        if args.command == "init-env":
            validate_migration_path(repository, args.migration_path)
            create_env_file(
                Path(args.env_file), args.project, "local", "dev"
            )
            result = {
                "status": "created",
                "path": str(Path(args.env_file)),
                "owner": "root:root" if Path(args.env_file).stat().st_uid == 0 else "current",
                "mode": oct(stat.S_IMODE(Path(args.env_file).stat().st_mode)),
            }
        elif args.command == "validate":
            validate(
                repository,
                (repository / args.compose_file).resolve(),
                Path(args.env_file).resolve(),
                args.project,
                args.migration_path,
            )
            result = {"status": "PASS", "project": args.project}
        elif args.command == "persistent":
            result = run_persistent(args)
        else:
            result = run_smoke(args)
    except GuardFailure as error:
        print(json.dumps({"status": "REJECTED", "reason": str(error)}))
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
