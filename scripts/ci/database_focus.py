"""Narrow database-only exclusions for normal pull-request CI."""

import ast
import json
import re
import unittest

from scripts.release.readiness.change import git

ADMIN_STYLES = {
    "apps/admin/src/components/banners/banner-import.module.css",
    "apps/admin/src/components/member-ranks/member-ranks.module.css",
}
SIMPLE = "scripts/ops/old_test_simple.py"
QUALITY_TEST = "tests/ci/quality/test_quality_gate.py"
AUTHORITY = "manifests/platform-production-approved-source.json"
AUTHORITY_TEST = "tests/ops/test_production_source_authority.py"
NODE_TEST = "tests/ops/test_production_admin_node.py"
WORKLOG = "worklogs/new_ver_main.md"

# Normalize only complete, local declaration values. Selectors, properties,
# imports, URLs and the rest of each declaration remain byte-sensitive.
STYLE_VALUE = re.compile(
    rb"([;{]\s*(?:color|background(?:-color)?|gap|padding|margin|border-radius|font-size)\s*:\s*)"
    rb"(?:#[0-9a-fA-F]{3,8}|(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem))(?=\s*[;}])"
)


def exact_edits(repository, change):
    """Return exact regular-file additions/modifications, or None on uncertainty."""
    base, head = change["base_sha"], change["head_sha"]
    for revision, key in ((base, "base_tree_sha"), (head, "tree_sha")):
        if git(repository, "rev-parse", revision + "^{tree}").decode().strip() != change[key]:
            return None
    rows = git(repository, "diff", "--raw", "--no-renames", "-z", base, head).split(b"\0")
    edits = {}
    if rows[-1] or len(rows) % 2 != 1:
        return None
    for meta, name in zip(rows[0:-1:2], rows[1::2]):
        old_mode, mode, _, _, status = meta.removeprefix(b":").split()
        if mode not in (b"100644", b"100755") or (status, old_mode) not in ((b"A", b"000000"), (b"M", mode)):
            return None
        edits[name.decode()] = (status, mode)
    return edits if sorted(edits) == change["changed_paths"] else None


def normalized_python(path, source):
    module = ast.parse(source)
    for node in module.body:
        if path == SIMPLE and isinstance(node, ast.FunctionDef) and node.name == "main":
            for stmt in node.body:
                if not isinstance(stmt, ast.Expr) or not isinstance(stmt.value, ast.Call):
                    continue
                call = stmt.value
                if ast.unparse(call.func) == "parser.add_argument":
                    for kw in call.keywords:
                        if kw.arg == "help" and isinstance(kw.value, ast.Constant) and isinstance(kw.value.value, str):
                            kw.value = ast.Constant("<help>")
        if path == QUALITY_TEST and isinstance(node, ast.ClassDef) and node.name == "QualityGateTest":
            for method in node.body:
                if isinstance(method, ast.FunctionDef) and method.name == "report" and len(method.body) == 1:
                    statement = method.body[0]
                    if isinstance(statement, ast.Return) and isinstance(ast.literal_eval(statement.value), list):
                        statement.value = ast.Constant("<literal lint report>")
    return ast.dump(module)


def authority_cases(source):
    """Separate discovered test bodies from imports, fixtures and runner hooks."""
    module = ast.parse(source)
    cases = {}
    for cls in module.body:
        if not isinstance(cls, ast.ClassDef) or cls.name != "ProductionSourceAuthorityTest":
            continue
        keep = []
        for item in cls.body:
            if isinstance(item, ast.FunctionDef) and item.name.startswith("test_"):
                if (item.name in cases or item.decorator_list or ast.unparse(item.args) != "self"
                        or item.returns or not any(isinstance(n, ast.Call)
                            and ast.unparse(n.func).startswith("self.assert") for n in ast.walk(item))):
                    raise ValueError("unproven authority test")
                cases[item.name] = item
            else:
                keep.append(item)
        cls.body = keep
    return ast.dump(module), cases


def read_only_authority_case(method):
    # Only local data, assertions, repository JSON reads and Git queries.
    # A new helper/import, write, decorator or different operation needs full CI.
    allowed = (ast.Assign, ast.Expr, ast.For, ast.Name, ast.Constant, ast.Subscript,
               ast.Attribute, ast.Call, ast.Load, ast.Store, ast.List, ast.Tuple,
               ast.BinOp, ast.Add, ast.Div, ast.ListComp, ast.comprehension,
               ast.Compare, ast.Eq)
    calls = {"self.assertEqual", "self.assertIs", "self.assertIn", "json.loads",
             "(ROOT / MODULE['AUTHORITY_PATH']).read_text",
             "(ROOT / 'manifests/storefront-contract-releases.json').read_text"}
    literal_revisions = {id(stmt.targets[0]) for stmt in method.body
                         if isinstance(stmt, ast.Assign) and len(stmt.targets) == 1
                         and isinstance(stmt.value, ast.Constant)
                         and re.fullmatch("[0-9a-f]{40}", str(stmt.value.value))}
    for stmt in method.body:
        for node in ast.walk(stmt):
            if not isinstance(node, allowed):
                return False
            if isinstance(node, ast.Name) and isinstance(node.ctx, ast.Store) and (
                    node.id in {"MODULE", "ROOT", "json", "self"} or node.id.startswith("_")):
                return False
            if (isinstance(node, ast.Name) and isinstance(node.ctx, ast.Store)
                    and node.id in {"source", "old"} and id(node) not in literal_revisions):
                return False
            if isinstance(node, (ast.Subscript, ast.Attribute)) and not isinstance(node.ctx, ast.Load):
                return False
            if isinstance(node, ast.Attribute) and node.attr.startswith("_"):
                return False
            if isinstance(node, ast.Call):
                func = ast.unparse(node.func)
                if func == "MODULE['git']":
                    args = [ast.unparse(arg) for arg in node.args]
                    if args not in (["ROOT", "'rev-parse'", "source + '^{tree}'"],
                                    ["ROOT", "'diff'", "'--name-status'", "old", "source",
                                     "'--'", "'apps/api/database/migrations-v2'"]):
                        return False
                elif isinstance(node.func, ast.Attribute) and node.func.attr == "splitlines":
                    if node.args or not isinstance(node.func.value, ast.Call):
                        return False
                elif func not in calls:
                    return False
    return True


def database_focus(repository, change, edits):
    paths = set(edits) - {WORKLOG}
    inputs = {p for p in paths if not (p.startswith("docs/operations/deployment/") and p.endswith(".md"))}
    if not inputs or any(edits[p][1] != b"100644" for p in edits):
        return ""
    if any(edits[p][0] != b"M" for p in inputs):
        return ""

    def sources(path):
        return tuple(git(repository, "show", change[k] + ":" + path) for k in ("base_sha", "head_sha"))

    if paths <= ADMIN_STYLES:
        for path in paths:
            before, after = sources(path)
            if (re.search(rb"""['"/\\]|url\s*\(|@(?!media\b)""", before + after, re.I)
                    or STYLE_VALUE.sub(rb"\1#", before) != STYLE_VALUE.sub(rb"\1#", after)):
                return ""
        return "admin"
    if paths in ({SIMPLE}, {QUALITY_TEST}):
        path, = paths
        before, after = sources(path)
        if normalized_python(path, before) == normalized_python(path, after):
            return "wrapper" if path == SIMPLE else "quality"
    if (AUTHORITY not in inputs or not inputs <= {AUTHORITY, AUTHORITY_TEST, NODE_TEST}
            or len(change["authority_records"]) != 1
            or change["authority_records"][0].get("provenance") != "PROTECTED_BASE_ANCESTOR"):
        return ""
    # The existing policy gate verifies the reviewed source tree and its five
    # checks. The normal quality path runs the production authority tests.
    if AUTHORITY_TEST in inputs:
        (old_suite, old_cases), (new_suite, new_cases) = map(authority_cases, sources(AUTHORITY_TEST))
        if not old_cases or old_suite != new_suite or not old_cases.keys() <= new_cases.keys():
            return ""
        for name, method in new_cases.items():
            if name in old_cases and ast.dump(old_cases[name]) == ast.dump(method):
                continue
            if not read_only_authority_case(method):
                return ""
    if NODE_TEST not in inputs:
        return "authority"
    old, new = (json.loads(blob)["source_sha"] for blob in sources(AUTHORITY))
    before, after = sources(NODE_TEST)
    expected = 'self.assertEqual(line["source_sha"], "{}")'
    old_assert, new_assert = (expected.format(value).encode() for value in (old, new))
    if (re.fullmatch("[0-9a-f]{40}", old) and before.count(old_assert) == 1 and old != new
            and before.replace(old_assert, new_assert) == after):
        return "authority_node"
    return ""


def run_focused_tests(focus):
    loader = unittest.defaultTestLoader
    modules = {
        "wrapper": ["tests.ops.test_old_test_simple"],
        "authority": ["tests.ops.test_production_source_authority"],
        "authority_node": ["tests.ops.test_production_source_authority", "tests.ops.test_production_admin_node"],
    }
    if focus == "quality":
        # Run the original complete discovery; require the changed module to
        # contribute tests even if another quality module also succeeds.
        suite = loader.discover("tests/ci/quality", pattern="test_*.py")
        def cases(group):
            for test in group:
                if isinstance(test, unittest.TestSuite):
                    yield from cases(test)
                else:
                    yield test
        if not any(test.id().startswith("test_quality_gate.") for test in cases(suite)):
            return False
        suites = [suite]
    else:
        suites = [loader.loadTestsFromName(name) for name in modules[focus]]
    for suite in suites:
        count = suite.countTestCases()
        result = unittest.TextTestRunner().run(suite)
        if not count or result.testsRun != count or not result.wasSuccessful() or result.skipped:
            return False
    return True


if __name__ == "__main__":
    import sys
    focus, = sys.argv[1:]
    raise SystemExit(int(not run_focused_tests(focus)))
