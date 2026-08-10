import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const packageJson = JSON.parse(
  await readFile(new URL("../package.json", import.meta.url), "utf8"),
);
assert.deepEqual(Object.keys(packageJson.exports).sort(), [
  ".",
  "./assertions",
  "./fixtures",
  "./mock",
]);

const expected = {
  ".": [
    "CAPABILITY_SITE_MANIFEST_FIXTURE",
    "MINIMAL_SITE_MANIFEST_FIXTURE",
    "PLATFORM_COMPATIBILITY_FIXTURE",
    "PUBLIC_AUTH_FIXTURE",
    "PUBLIC_CATALOG_FIXTURE",
    "PUBLIC_CONTENT_FIXTURE",
    "PUBLIC_CONTRACT_FIXTURE",
    "PUBLIC_DRAW_FIXTURE",
    "PUBLIC_DRAW_PROBLEM_FIXTURES",
    "PUBLIC_EXTERNAL_IDENTITY_FIXTURE",
    "PUBLIC_FULFILLMENT_PROBLEM_FIXTURES",
    "PUBLIC_GACHA_PRESENTATION_FIXTURE",
    "PUBLIC_IDENTITY_RECOVERY_FIXTURE",
    "PUBLIC_RESPONSE_METADATA_FIXTURE",
    "PUBLIC_SHIPPING_REQUEST_FIXTURE",
    "PUBLIC_USER_PRIZE_FIXTURE",
    "TestkitAssertionError",
    "TestkitNetworkError",
    "UnexpectedMockRequestError",
    "assertBrowserRequestBoundary",
    "assertCompatibleSiteManifest",
    "assertDrawProblemDetails",
    "assertFulfillmentProblemDetails",
    "assertProblemDetails",
    "assertPublicRequestBoundary",
    "assertResponseMetadata",
    "assertServerSafeRequest",
    "createMockFetch",
  ],
  "./assertions": [
    "assertBrowserRequestBoundary",
    "assertCompatibleSiteManifest",
    "assertDrawProblemDetails",
    "assertFulfillmentProblemDetails",
    "assertProblemDetails",
    "assertPublicRequestBoundary",
    "assertResponseMetadata",
    "assertServerSafeRequest",
  ],
  "./fixtures": [
    "CAPABILITY_SITE_MANIFEST_FIXTURE",
    "MINIMAL_SITE_MANIFEST_FIXTURE",
    "PLATFORM_COMPATIBILITY_FIXTURE",
    "PUBLIC_AUTH_FIXTURE",
    "PUBLIC_CATALOG_FIXTURE",
    "PUBLIC_CONTENT_FIXTURE",
    "PUBLIC_CONTRACT_FIXTURE",
    "PUBLIC_DRAW_FIXTURE",
    "PUBLIC_DRAW_PROBLEM_FIXTURES",
    "PUBLIC_EXTERNAL_IDENTITY_FIXTURE",
    "PUBLIC_FULFILLMENT_PROBLEM_FIXTURES",
    "PUBLIC_GACHA_PRESENTATION_FIXTURE",
    "PUBLIC_IDENTITY_RECOVERY_FIXTURE",
    "PUBLIC_RESPONSE_METADATA_FIXTURE",
    "PUBLIC_SHIPPING_REQUEST_FIXTURE",
    "PUBLIC_USER_PRIZE_FIXTURE",
  ],
  "./mock": [
    "TestkitAssertionError",
    "TestkitNetworkError",
    "UnexpectedMockRequestError",
    "createMockFetch",
  ],
};

for (const [entry, names] of Object.entries(expected)) {
  const modulePath = entry === "." ? "../dist/index.js" : `../dist/${entry.slice(2)}.js`;
  const actual = Object.keys(await import(new URL(modulePath, import.meta.url))).sort();
  assert.deepEqual(actual, names);
}
