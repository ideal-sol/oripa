import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  SiteManifestValidationError,
  assessSiteCompatibility,
  parseSiteManifest,
  validateSiteManifest,
} from "../dist/index.js";

async function fixture(kind, name) {
  return JSON.parse(
    await readFile(
      new URL(`fixtures/${kind}/${name}.json`, import.meta.url),
      "utf8",
    ),
  );
}

test("Positive FixtureをValidationできる", async () => {
  const manifest = await fixture("positive", "minimal");
  assert.equal(validateSiteManifest(manifest), true);
  assert.deepEqual(parseSiteManifest(manifest), manifest);
});

for (const name of [
  "invalid-semver",
  "family-major",
  "unknown-field",
  "secret-field",
]) {
  test(`Negative Fixtureを拒否する: ${name}`, async () => {
    const manifest = await fixture("negative", name);
    assert.equal(validateSiteManifest(manifest), false);
    assert.throws(
      () => parseSiteManifest(manifest),
      SiteManifestValidationError,
    );
  });
}

test("Required Capabilityが揃う場合だけ互換と判定する", async () => {
  const manifest = await fixture("positive", "requires-capability");
  const compatible = assessSiteCompatibility(manifest, {
    compatibility_family: 2,
    minimum_storefront_client_version: "2.0.0-alpha.1",
    capabilities: ["auth.session.v2"],
  });
  assert.equal(compatible.compatible, true);
  assert.deepEqual(compatible.failures, []);

  const missing = assessSiteCompatibility(manifest, {
    compatibility_family: 2,
    minimum_storefront_client_version: "2.0.0-alpha.1",
    capabilities: [],
  });
  assert.equal(missing.compatible, false);
  assert.deepEqual(
    missing.failures.map(({ code }) => code),
    ["REQUIRED_CAPABILITY_MISSING"],
  );
});

test("Storefront ClientのFamily Major不一致を拒否する", async () => {
  const manifest = structuredClone(await fixture("positive", "minimal"));
  manifest.compatibility.storefront_client_version = "3.0.0";
  const result = assessSiteCompatibility(manifest, {
    compatibility_family: 2,
    minimum_storefront_client_version: "2.0.0-alpha.1",
    capabilities: [],
  });
  assert.equal(result.compatible, false);
  assert.equal(result.failures[0]?.code, "STOREFRONT_CLIENT_FAMILY_MISMATCH");
});

test("Minimum Storefront Client Version未満を拒否する", async () => {
  const manifest = await fixture("positive", "minimal");
  const result = assessSiteCompatibility(manifest, {
    compatibility_family: 2,
    minimum_storefront_client_version: "2.0.0",
    capabilities: [],
  });
  assert.equal(result.compatible, false);
  assert.equal(result.failures[0]?.code, "STOREFRONT_CLIENT_TOO_OLD");
});

test("N／N-1候補は明示されたSchema Versionだけを受け付ける", async () => {
  const manifest = await fixture("positive", "minimal");
  const result = assessSiteCompatibility(
    manifest,
    {
      compatibility_family: 2,
      minimum_storefront_client_version: "2.0.0-alpha.1",
      capabilities: [],
    },
    {
      tested_schema_versions: ["2.1.0", "2.0.0"],
    },
  );
  assert.equal(result.compatible, false);
  assert.equal(result.failures[0]?.code, "SCHEMA_VERSION_UNSUPPORTED");
});

test("Validation Errorへ入力値を含めない", async () => {
  const manifest = await fixture("negative", "secret-field");
  try {
    parseSiteManifest(manifest);
    assert.fail("Validation must fail");
  } catch (error) {
    assert.ok(error instanceof SiteManifestValidationError);
    assert.equal(JSON.stringify(error.issues).includes("fixture-value"), false);
  }
});
