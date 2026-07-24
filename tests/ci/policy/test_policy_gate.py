import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/ci/policy_gate.py"
FIXTURES = Path(__file__).parent / "fixtures"
SPEC = importlib.util.spec_from_file_location("policy_gate", SCRIPT)
policy_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(policy_gate)


def fixture(name):
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


class PolicyGateTest(unittest.TestCase):
    def test_positive_pull_request_fixture_passes(self):
        data = fixture("positive.json")
        policy_gate.validate_pr_body(
            data["pr_body"],
            data["title"],
            data["changed_paths"],
            data["base_sha"],
        )
        policy_gate.validate_workflow_text("fixture.yml", data["workflow"])
        policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_missing_metadata_fixture_fails(self):
        data = fixture("negative_missing_metadata.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "Risk"):
            policy_gate.validate_pr_body(
                data["pr_body"],
                data["title"],
                data["changed_paths"],
                data["base_sha"],
            )

    def test_floating_action_fixture_fails(self):
        data = fixture("negative_floating_action.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "full SHA"):
            policy_gate.validate_workflow_text("fixture.yml", data["workflow"])

    def test_secret_path_fixture_fails(self):
        data = fixture("negative_secret_path.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "dangerous tracked"):
            policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_declared_directory_prefix_allows_nested_path(self):
        self.assertTrue(
            policy_gate.declared_path_allowed(
                "apps/api/app/Models/User.php",
                ["apps/api/**"],
            )
        )
        self.assertFalse(
            policy_gate.declared_path_allowed(
                "legacy/v1-frontend/src/app/page.tsx",
                ["apps/api/**"],
            )
        )

    def test_codeql_job_can_upload_security_events(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: codeql
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: github/codeql-action/analyze@e4fba868fa4b1b91e1fdab776edc8cfbe6e9fb81 # v4
"""
        policy_gate.validate_workflow_text(".github/workflows/codeql.yml", workflow)

    def test_non_codeql_security_events_write_fails(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: unsafe
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
"""
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "write workflow permission"):
            policy_gate.validate_workflow_text(".github/workflows/unsafe.yml", workflow)

    def test_dependency_review_allowlist_matches_exact_security_baseline(self):
        policy_gate.validate_dependency_review_allowlist(ROOT)

    def make_v2_database_boundary(self, root):
        migration = root / "apps/api/database/migrations/2026_01_01_000000_v1.php"
        migration.parent.mkdir(parents=True, exist_ok=True)
        migration.write_text("<?php return 'v1';\n", encoding="utf-8")
        v2_root = root / "apps/api/database/migrations-v2"
        v2_root.mkdir(parents=True)
        (v2_root / "README.md").write_text(
            "scripts/db/v2_database.py uses apps/api/database/migrations-v2 "
            "instead of apps/api/database/migrations in non-Production.\n",
            encoding="utf-8",
        )
        count, checksum = policy_gate.migration_content_set(
            root, "apps/api/database/migrations"
        )
        baseline = root / ".ci/baselines/v1-migrations.json"
        baseline.parent.mkdir(parents=True)
        baseline.write_text(
            json.dumps(
                {
                    "schema_version": "1.0",
                    "path": "apps/api/database/migrations",
                    "file_count": count,
                    "content_sha256_set": checksum,
                }
            ),
            encoding="utf-8",
        )
        compose = root / "docker-compose.v2.yml"
        compose.write_text(
            """# This is never a Production deployment.
services:
  api:
    environment:
      DB_DATABASE: ${V2_DB_DATABASE:?required}
      DB_USERNAME: ${V2_DB_USERNAME:?required}
      DB_PASSWORD: ${V2_DB_PASSWORD:?required}
      REDIS_PASSWORD: ${V2_REDIS_PASSWORD:?required}
  admin:
    image: admin
  postgres:
    image: postgres:17-alpine
    volumes:
      - v2_postgres:/var/lib/postgresql/data
    networks:
      - v2_private
  redis:
    image: redis:7-alpine
    volumes:
      - v2_redis:/data
    networks:
      - v2_private
networks:
  v2_private:
    internal: true
volumes:
  v2_postgres:
  v2_redis:
""",
            encoding="utf-8",
        )
        runner = root / "scripts/db/v2_database.py"
        runner.parent.mkdir(parents=True)
        runner.write_text(
            """
MIGRATION_PATH = "apps/api/database/migrations-v2"
V1_MIGRATION_PATH = "apps/api/database/migrations"
# Production or unexpected environment is prohibited
# V1 Compose Project is prohibited
# V1 Migration Path is prohibited
# Unexpected Database or Redis Host
# Database and Redis Host Ports are prohibited
# Refusing to remove an unscoped Volume
""",
            encoding="utf-8",
        )
        for relative in (
            "docs/operations/database/README.md",
            "scripts/db/README.md",
            "tests/db/test_v2_database.py",
        ):
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("fixture\n", encoding="utf-8")
        workflow = root / ".github/workflows/platform-ci.yml"
        workflow.parent.mkdir(parents=True)
        workflow.write_text(
            """
php apps/api/artisan migrate --path=database/migrations
python3 -m unittest discover -s tests/db -p 'test_*.py'
python3 scripts/db/v2_database.py smoke \\
  --migration-path apps/api/database/migrations-v2
""",
            encoding="utf-8",
        )
        paths = set(policy_gate.V2_DATABASE_REQUIRED_FILES)
        paths.update(
            {
                "apps/api/database/migrations/2026_01_01_000000_v1.php",
                ".github/workflows/platform-ci.yml",
            }
        )
        return paths

    def test_v2_database_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            policy_gate.validate_v2_database_boundary(root, paths)

    def test_v1_migration_change_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            migration = next((root / "apps/api/database/migrations").glob("*.php"))
            migration.write_text("<?php return 'changed';\n", encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "checksum"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_host_port_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "    volumes:\n      - v2_postgres",
                    "    ports:\n      - 5432:5432\n    volumes:\n      - v2_postgres",
                    1,
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "Host Port"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_shared_volume_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "v2_postgres", "oripa_postgres_data"
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "missing|prohibited"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8") + "\n# tenant_id\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def copy_v2_identity_boundary(self, root):
        paths = set(policy_gate.V2_IDENTITY_REQUIRED_FILES)
        supporting = {
            "apps/api/config/auth.php",
            ".github/workflows/platform-ci.yml",
            "openapi/bundled/public.openapi.json",
            "openapi/bundled/admin.openapi.json",
            "scripts/db/v2_database.py",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_identity_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_identity_boundary(root)
            policy_gate.validate_v2_identity_boundary(root, paths)

    def test_v2_identity_missing_admin_guard_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_identity_boundary(root)
            auth = root / "apps/api/config/auth.php"
            auth.write_text(
                auth.read_text(encoding="utf-8").replace("'v2_admin'", "'removed'"),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "Auth separation"):
                policy_gate.validate_v2_identity_boundary(root, paths)

    def test_v2_identity_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_identity_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_24_000001_create_v2_identity_accounts.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8") + "\n// tenant_id\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_identity_boundary(root, paths)

    def make_workspace(self, root):
        paths = set(policy_gate.WORKSPACE_REQUIRED_FILES)
        for relative in paths:
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            if relative.endswith("README.md"):
                path.write_text(
                    """# Fixture

## Responsibility

This fixture defines one clear repository responsibility.

## Ownership

Platform Codex follows the nearest AGENTS.md.

## Planned Components

Only a future V2 component belongs here.

## Allowed Scope

Documentation and approved V2 implementation are allowed.

## Forbidden Scope

V1 Code copying and Production use are forbidden.

## Status

This is a non-Production Skeleton and contains no application implementation.
""",
                    encoding="utf-8",
                )
            elif relative.endswith("AGENTS.md"):
                path.write_text("# Fixture AGENTS\n", encoding="utf-8")
            else:
                path.write_text("fixture\n", encoding="utf-8")

        (root / "package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/platform-workspace",
                    "version": "2.0.0-alpha.1",
                    "private": True,
                    "packageManager": "pnpm@10.12.1",
                    "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
                    "pnpm": {
                        "overrides": {
                            "js-yaml": "4.3.0",
                            "postcss": "8.5.12",
                            "sharp": "0.35.0",
                        }
                    },
                    "devDependencies": policy_gate.ROOT_DEV_DEPENDENCY_VERSIONS,
                }
            ),
            encoding="utf-8",
        )
        (root / "pnpm-workspace.yaml").write_text(
            "packages:\n  - apps/admin\n  - packages/*\n",
            encoding="utf-8",
        )
        (root / ".github/dependabot.yml").write_text(
            """version: 2
updates:
  - package-ecosystem: npm
    directory: /
  - package-ecosystem: npm
    directory: /legacy/v1-frontend
""",
            encoding="utf-8",
        )
        (root / "pnpm-lock.yaml").write_text(
            """lockfileVersion: '9.0'

importers:

  .: {}

  apps/admin: {}

  packages/platform: {}

  packages/site-schema: {}

  packages/storefront-client: {}

  packages/storefront-testkit: {}

packages:
""",
            encoding="utf-8",
        )
        (root / "apps/admin/package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/admin",
                    "version": "2.0.0-alpha.1",
                    "private": True,
                    "packageManager": "pnpm@10.12.1",
                    "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
                    "scripts": {
                        "build": "next build",
                        "dev": "next dev",
                        "lint": "eslint .",
                        "start": "next start",
                        "typecheck": "tsc --noEmit",
                    },
                    "dependencies": policy_gate.ADMIN_DEPENDENCY_VERSIONS,
                    "devDependencies": policy_gate.ADMIN_DEV_DEPENDENCY_VERSIONS,
                }
            ),
            encoding="utf-8",
        )
        for relative, name in policy_gate.PACKAGE_SKELETONS.items():
            (root / relative).write_text(
                json.dumps(
                    {
                        "name": name,
                        "version": "2.0.0-alpha.1",
                        "private": True,
                        "description": "Fixture Skeleton",
                        "license": "UNLICENSED",
                    }
                ),
                encoding="utf-8",
            )
        (root / "packages/site-schema/package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/site-schema",
                    "version": "2.0.0-alpha.1",
                    "private": True,
                    "description": "Fixture Alpha",
                    "license": "UNLICENSED",
                    "type": "module",
                    "sideEffects": False,
                    "packageManager": "pnpm@10.12.1",
                    "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
                    "files": ["dist", "schema"],
                    "exports": {
                        ".": {
                            "types": "./dist/index.d.ts",
                            "import": "./dist/index.js",
                        },
                        "./schema": "./schema/site-manifest.schema.json",
                    },
                    "scripts": {
                        "build": "tsc",
                        "generate": "node generate --write",
                        "generate:check": "node generate --check",
                        "lint": "eslint",
                        "test": "node --test",
                        "typecheck": "tsc --noEmit",
                    },
                    "dependencies": policy_gate.SITE_SCHEMA_DEPENDENCY_VERSIONS,
                    "devDependencies": policy_gate.SITE_SCHEMA_DEV_DEPENDENCY_VERSIONS,
                    "oripaCompatibility": {
                        "family": 2,
                        "currentSchemaVersion": "2.0.0-alpha.1",
                        "testedSchemaVersions": ["2.0.0-alpha.1"],
                        "nMinusOneStatus": "pending-first-minor",
                    },
                }
            ),
            encoding="utf-8",
        )
        site_schema = {
            "$schema": "https://json-schema.org/draft/2020-12/schema",
            "$id": "urn:oripa:site-manifest:2.0.0-alpha.1",
            "type": "object",
            "additionalProperties": False,
            "required": [
                "schema_version",
                "site_version",
                "compatibility",
                "public",
            ],
            "properties": {
                "schema_version": {
                    "type": "string",
                    "const": "2.0.0-alpha.1",
                },
                "site_version": {"$ref": "#/$defs/semantic_version"},
                "compatibility": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": [
                        "family",
                        "storefront_client_version",
                        "required_capabilities",
                    ],
                    "properties": {
                        "family": {"type": "integer", "const": 2},
                        "storefront_client_version": {
                            "$ref": "#/$defs/semantic_version"
                        },
                        "required_capabilities": {
                            "$ref": "#/$defs/capability_list"
                        },
                    },
                },
                "public": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": ["locale", "timezone", "features"],
                    "properties": {
                        "locale": {"type": "string"},
                        "timezone": {"type": "string"},
                        "features": {
                            "type": "object",
                            "additionalProperties": False,
                            "required": ["enabled"],
                            "properties": {
                                "enabled": {
                                    "$ref": "#/$defs/capability_list",
                                    "default": [],
                                }
                            },
                        },
                    },
                },
            },
            "$defs": {
                "semantic_version": {
                    "type": "string",
                    "pattern": policy_gate.SEMANTIC_VERSION.pattern,
                },
                "capability_name": {
                    "type": "string",
                    "pattern": "^[a-z]+\\.[a-z-]+\\.v[1-9][0-9]*$",
                },
                "capability_list": {
                    "type": "array",
                    "items": {"$ref": "#/$defs/capability_name"},
                    "uniqueItems": True,
                    "default": [],
                },
            },
        }
        (
            root / "packages/site-schema/schema/site-manifest.schema.json"
        ).write_text(json.dumps(site_schema), encoding="utf-8")
        (root / "packages/site-schema/.gitignore").write_text(
            "/dist/\n",
            encoding="utf-8",
        )
        (root / "packages/site-schema/src/generated/site-manifest.ts").write_text(
            """/**
 * This file is generated from schema/site-manifest.schema.json.
 */
export type SiteManifest = {
  readonly schema_version: "2.0.0-alpha.1";
  readonly compatibility: {
    readonly family: 2;
    readonly required_capabilities: ReadonlyArray<string>;
  };
};
""",
            encoding="utf-8",
        )
        site_schema_readme = root / "packages/site-schema/README.md"
        site_schema_readme.write_text(
            site_schema_readme.read_text(encoding="utf-8")
            + "\nThis package is an Alpha boundary.\n",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/storefront-client",
                    "version": "2.0.0-alpha.1",
                    "private": True,
                    "description": "Fixture Client",
                    "license": "UNLICENSED",
                    "type": "module",
                    "sideEffects": False,
                    "packageManager": "pnpm@10.12.1",
                    "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
                    "files": ["dist"],
                    "exports": {
                        ".": {"types": "./dist/index.d.ts", "import": "./dist/index.js"},
                        "./browser": {
                            "types": "./dist/browser.d.ts",
                            "import": "./dist/browser.js",
                        },
                        "./server": {
                            "types": "./dist/server.d.ts",
                            "import": "./dist/server.js",
                        },
                        "./types": {
                            "types": "./dist/types.d.ts",
                            "import": "./dist/types.js",
                        },
                    },
                    "scripts": {
                        "build": "tsc -p tsconfig.build.json",
                        "generate": "openapi-typescript fixture",
                        "generate:check": "node scripts/check-generated.mjs",
                        "lint": "eslint src",
                        "test": "node --test",
                        "typecheck": "tsc --noEmit",
                    },
                    "devDependencies": (
                        policy_gate.STOREFRONT_CLIENT_DEV_DEPENDENCY_VERSIONS
                    ),
                    "oripaCompatibility": {
                        "family": 2,
                        "apiMajor": 2,
                        "minimumPublicApiContract": "2.0.0-alpha.1",
                        "requiredCapabilities": [],
                    },
                }
            ),
            encoding="utf-8",
        )
        (root / "packages/storefront-client/.gitignore").write_text(
            "/dist/\n",
            encoding="utf-8",
        )
        storefront_readme = root / "packages/storefront-client/README.md"
        storefront_readme.write_text(
            storefront_readme.read_text(encoding="utf-8")
            + "\nThis package is an Alpha boundary.\n",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/generated/public.ts").write_text(
            """/**
 * This file was auto-generated by openapi-typescript.
 */
export interface paths { "/auth/register": unknown; }
export interface operations {
  registerUser: unknown;
  loginUser: unknown;
  logoutUser: unknown;
  resendUserEmailVerification: unknown;
  verifyUserEmail: unknown;
  getUserSession: unknown;
}
""",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/index.ts").write_text(
            'export * from "./browser.js";\n',
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/types.ts").write_text(
            'export type { paths as PublicPaths } from "./generated/public.js";\n',
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/browser.ts").write_text(
            'export const browser = { credentials: "include" };\n',
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/server.ts").write_text(
            "export const server = true;\n",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/constants.ts").write_text(
            """export const headers = [
  "X-Oripa-Client-Version",
  "X-Oripa-Site-Version",
  "Idempotency-Key",
];
""",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/transport.ts").write_text(
            """const RETRYABLE_STATUS = new Set([502, 503, 504]);
const signal: AbortSignal | undefined = undefined;
const csrf_initializer = true;
const problem = "application/problem+json";
""",
            encoding="utf-8",
        )
        shutil.copytree(
            ROOT / "packages/storefront-testkit",
            root / "packages/storefront-testkit",
            dirs_exist_ok=True,
            ignore=shutil.ignore_patterns("dist", "node_modules"),
        )
        (root / "apps/admin/src/app/layout.tsx").write_text(
            "export const metadata = { robots: { index: false, follow: false } };\n",
            encoding="utf-8",
        )
        (root / "apps/admin/src/app/page.tsx").write_text(
            "export default function Page() { return <main>Skeleton</main>; }\n",
            encoding="utf-8",
        )
        (root / "apps/admin/src/app/api/health/route.ts").write_text(
            'export function GET() { return { status: "ok", production_ready: false }; }\n',
            encoding="utf-8",
        )
        (root / ".dockerignore").write_text(
            "legacy/v1-frontend\n",
            encoding="utf-8",
        )
        (root / "docker-compose.yml").write_text(
            """# V1 reference for non-production characterization only.
services:
  api:
    volumes:
      - ./apps/api:/app
  frontend:
    build: ./legacy/v1-frontend
  postgres:
    image: postgres:17-alpine
  redis:
    image: redis:7-alpine
""",
            encoding="utf-8",
        )
        (root / "docker-compose.v2.yml").write_text(
            """# This is never a Production deployment.
services:
  api:
    healthcheck:
      test: health
  admin:
    healthcheck:
      test: health
  postgres:
    image: postgres:17-alpine
  redis:
    image: redis:7-alpine
""",
            encoding="utf-8",
        )
        semantic_version = {
            "type": "string",
            "pattern": policy_gate.SEMANTIC_VERSION.pattern,
        }
        release_properties = {
            field: {"type": "string"}
            for field in policy_gate.RELEASE_MANIFEST_REQUIRED
        }
        release_properties["schema_version"] = {"const": "1.0"}
        release_properties["platform_version"] = {
            "$ref": "#/$defs/semantic_version"
        }
        release_properties["api_contract_version"] = {
            "$ref": "#/$defs/semantic_version"
        }
        release_properties["package_versions"] = {
            "type": "object",
            "additionalProperties": {"$ref": "#/$defs/semantic_version"},
        }
        deployment_properties = {
            field: {"type": "string"}
            for field in policy_gate.DEPLOYMENT_MANIFEST_REQUIRED
        }
        deployment_properties["schema_version"] = {"const": "1.0"}
        deployment_properties["platform_version"] = {
            "$ref": "#/$defs/semantic_version"
        }
        deployment_properties["package_versions"] = {
            "type": "object",
            "additionalProperties": {"$ref": "#/$defs/semantic_version"},
        }
        for relative, required, properties in (
            (
                "manifests/schemas/release-manifest.schema.json",
                policy_gate.RELEASE_MANIFEST_REQUIRED,
                release_properties,
            ),
            (
                "manifests/schemas/deployment-manifest.schema.json",
                policy_gate.DEPLOYMENT_MANIFEST_REQUIRED,
                deployment_properties,
            ),
        ):
            (root / relative).write_text(
                json.dumps(
                    {
                        "$schema": "https://json-schema.org/draft/2020-12/schema",
                        "type": "object",
                        "additionalProperties": False,
                        "required": sorted(required),
                        "properties": properties,
                        "$defs": {"semantic_version": semantic_version},
                    }
                ),
                encoding="utf-8",
            )
        (root / "manifests/examples/release-manifest.example.json").write_text(
            json.dumps(
                {
                    "schema_version": "1.0",
                    "platform_version": "2.0.0-alpha.1",
                    "package_versions": {"@oripa/platform": "2.0.0-alpha.1"},
                    "api_contract_version": "2.0.0-alpha.1",
                    "migration_revision": "fixture",
                    "source_commit": "0" * 40,
                    "image_digest": "sha256:" + "0" * 64,
                    "sbom_reference": "fixture",
                    "created_at": "1970-01-01T00:00:00Z",
                }
            ),
            encoding="utf-8",
        )
        (root / "manifests/examples/deployment-manifest.example.json").write_text(
            json.dumps(
                {
                    "schema_version": "1.0",
                    "site_id": "fixture-site",
                    "environment": "platform-staging",
                    "platform_version": "2.0.0-alpha.1",
                    "package_versions": {"@oripa/platform": "2.0.0-alpha.1"},
                    "image_digest": "sha256:" + "0" * 64,
                    "migration_revision": "fixture",
                    "deployed_at": "1970-01-01T00:00:00Z",
                    "approved_by": "fixture",
                    "source_release_manifest": "fixture",
                }
            ),
            encoding="utf-8",
        )
        return paths

    def test_workspace_skeleton_fixture_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_admin_type_export_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "packages/storefront-client/src/types.ts").write_text(
                "export type AdminSecret = string;\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "Admin or Webhook type"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_required_auth_operation_missing_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            generated = root / "packages/storefront-client/src/generated/public.ts"
            generated.write_text(
                generated.read_text(encoding="utf-8").replace(
                    "registerUser: unknown;",
                    "fakeDraw: unknown;",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "generated Public API types"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_browser_credentials_omit_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "packages/storefront-client/src/browser.ts").write_text(
                'export const browser = { credentials: "omit" };\n',
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "credentials must be include"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_site_schema_secret_field_definition_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            schema_path = (
                root / "packages/site-schema/schema/site-manifest.schema.json"
            )
            schema = json.loads(schema_path.read_text(encoding="utf-8"))
            schema["properties"]["api_token"] = {"type": "string"}
            schema_path.write_text(json.dumps(schema), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "prohibited field"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_site_schema_unknown_top_level_field_policy_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            schema_path = (
                root / "packages/site-schema/schema/site-manifest.schema.json"
            )
            schema = json.loads(schema_path.read_text(encoding="utf-8"))
            schema["additionalProperties"] = True
            schema_path.write_text(json.dumps(schema), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "reject unknown fields"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_dependency_range_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "packages/storefront-testkit/package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["dependencies"]["@oripa/storefront-client"] = "workspace:*"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "exact runtime dependencies"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_forbidden_export_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "packages/storefront-testkit/package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["exports"]["./admin"] = "./dist/admin.js"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "exports are invalid"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_fake_operation_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            generated = (
                root
                / "packages/storefront-testkit/src/generated/public-contract.ts"
            )
            generated.write_text(
                generated.read_text(encoding="utf-8").replace(
                    "operation_count: 6",
                    "operation_count: 7",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "Public Contract Fixture"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_real_network_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            source = root / "packages/storefront-testkit/src/mock.ts"
            source.write_text(
                source.read_text(encoding="utf-8")
                + "\nexport const unsafe = () => globalThis.fetch('/');\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "real network access"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_noop_test_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            test_path = root / "packages/storefront-testkit/test/testkit.test.mjs"
            test_path.write_text(
                'import test from "node:test";\ntest("noop", () => {});\n',
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "substantive assertions"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_missing_mock_boundary_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            source = root / "packages/storefront-testkit/src/mock.ts"
            source.write_text(
                source.read_text(encoding="utf-8").replace(
                    "queue.shift()",
                    "queue.at(0)",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "Mock Transport missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_missing_root_lockfile_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "pnpm-lock.yaml").unlink()
            paths.remove("pnpm-lock.yaml")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required workspace files missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_workspace_missing_readme_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            paths.remove("apps/admin/README.md")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required workspace files missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v1_frontend_workspace_member_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "pnpm-workspace.yaml").write_text(
                "packages:\n  - apps/admin\n  - packages/*\n  - legacy/v1-frontend\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "workspace members"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_api_workspace_member_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "pnpm-workspace.yaml").write_text(
                "packages:\n  - apps/admin\n  - packages/*\n  - apps/api\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "workspace members"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_dependency_range_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "apps/admin/package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["dependencies"]["next"] = "^16.2.9"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "exact runtime dependencies"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_unapproved_root_tool_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["devDependencies"]["unapproved-tool"] = "1.0.0"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure,
                "only the pinned OpenAPI validation tool",
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_health_endpoint_missing_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            relative = "apps/admin/src/app/api/health/route.ts"
            (root / relative).unlink()
            paths.remove(relative)
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required workspace files missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_business_logic_file_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            relative = "apps/admin/src/domain/point-ledger.ts"
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("export const calculatePoints = () => 1;\n", encoding="utf-8")
            paths.add(relative)
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "unapproved application files"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v2_compose_with_legacy_frontend_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8")
                + "\n# COPY legacy/v1-frontend into V2\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "prohibited value"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_api_application_layout_passes(self):
        policy_gate.validate_api_application_layout(
            policy_gate.API_APPLICATION_REQUIRED_FILES
        )

    def test_legacy_backend_path_fails(self):
        paths = set(policy_gate.API_APPLICATION_REQUIRED_FILES)
        paths.add("backend/artisan")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure, "legacy backend path remains tracked"
        ):
            policy_gate.validate_api_application_layout(paths)

    def test_missing_api_application_file_fails(self):
        paths = set(policy_gate.API_APPLICATION_REQUIRED_FILES)
        paths.remove("apps/api/composer.lock")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure, "required API application files missing"
        ):
            policy_gate.validate_api_application_layout(paths)

    def test_manifest_required_field_removal_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            schema_path = root / "manifests/schemas/release-manifest.schema.json"
            schema = json.loads(schema_path.read_text(encoding="utf-8"))
            schema["required"].remove("source_commit")
            schema_path.write_text(json.dumps(schema), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required manifest fields"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v1_content_copy_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            content = "const copiedV1Implementation = true;\n" * 4
            for relative in (
                "legacy/v1-frontend/source.ts",
                "apps/admin/source.ts",
            ):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8")
                paths.add(relative)
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "V1 content copied"):
                policy_gate.validate_no_v1_copy(root, paths)

    def test_legacy_frontend_layout_passes(self):
        policy_gate.validate_legacy_frontend_layout(
            ROOT,
            set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES),
        )

    def test_tracked_frontend_path_fails(self):
        paths = set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES)
        paths.add("frontend/src/app/page.tsx")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure,
            "legacy frontend source path remains tracked",
        ):
            policy_gate.validate_legacy_frontend_layout(ROOT, paths)

    def test_nested_legacy_frontend_path_fails(self):
        paths = set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES)
        paths.add("legacy/v1-frontend/frontend/src/app/page.tsx")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure,
            "nested frontend directory",
        ):
            policy_gate.validate_legacy_frontend_layout(ROOT, paths)

    def test_v2_dockerfile_copying_legacy_frontend_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES)
            for relative in paths:
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("fixture\n", encoding="utf-8")
            dockerfile = root / "apps/admin/Dockerfile"
            dockerfile.parent.mkdir(parents=True, exist_ok=True)
            dockerfile.write_text(
                "COPY legacy/v1-frontend /app\n",
                encoding="utf-8",
            )
            paths.add("apps/admin/Dockerfile")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure,
                "must not copy legacy frontend",
            ):
                policy_gate.validate_legacy_frontend_layout(root, paths)


if __name__ == "__main__":
    unittest.main()
