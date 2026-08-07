import assert from "node:assert/strict";
import test from "node:test";

import {
  ApiProblemError,
  StorefrontTransportError,
  createBrowserStorefrontClient,
} from "@oripa/storefront-client/browser";
import {
  createServerStorefrontClient,
} from "@oripa/storefront-client/server";
import {
  SiteManifestValidationError,
  validateSiteManifest,
} from "@oripa/site-schema";

import {
  CAPABILITY_SITE_MANIFEST_FIXTURE,
  MINIMAL_SITE_MANIFEST_FIXTURE,
  PLATFORM_COMPATIBILITY_FIXTURE,
  PUBLIC_AUTH_FIXTURE,
  PUBLIC_CATALOG_FIXTURE,
  PUBLIC_GACHA_PRESENTATION_FIXTURE,
  PUBLIC_CONTENT_FIXTURE,
  PUBLIC_IDENTITY_RECOVERY_FIXTURE,
  PUBLIC_EXTERNAL_IDENTITY_FIXTURE,
  PUBLIC_DRAW_FIXTURE,
  PUBLIC_SHIPPING_REQUEST_FIXTURE,
  PUBLIC_USER_PRIZE_FIXTURE,
  PUBLIC_CONTRACT_FIXTURE,
  PUBLIC_RESPONSE_METADATA_FIXTURE,
  TestkitAssertionError,
  UnexpectedMockRequestError,
  assertBrowserRequestBoundary,
  assertCompatibleSiteManifest,
  assertProblemDetails,
  assertPublicRequestBoundary,
  assertResponseMetadata,
  assertServerSafeRequest,
  createMockFetch,
} from "../dist/index.js";

test("Public Auth FixtureはCookie Session状態をCredentialなしで表現する", () => {
  assert.equal(PUBLIC_AUTH_FIXTURE.anonymous_session.authenticated, false);
  assert.equal(PUBLIC_AUTH_FIXTURE.authenticated_session.authenticated, true);
  assert.equal(PUBLIC_AUTH_FIXTURE.pending_registration.status, "pending_verification");
  assert.equal(PUBLIC_AUTH_FIXTURE.accepted.status, "accepted");
  assert.doesNotMatch(
    JSON.stringify(PUBLIC_AUTH_FIXTURE),
    /password|token|cookie|session_id|secret/i,
  );
});

test("Draw FixtureはBulk集計だけを公開し個別ppmと内部IDを含まない", () => {
  assert.equal(PUBLIC_DRAW_FIXTURE.requested_count, 1000);
  assert.equal(PUBLIC_DRAW_FIXTURE.executed_count, 1000);
  assert.equal("results" in PUBLIC_DRAW_FIXTURE, false);
  const serialized = JSON.stringify(PUBLIC_DRAW_FIXTURE);
  assert.doesNotMatch(serialized, /individual_ppm|internal_id|cost_price|secret/i);
});

test("Prize／Shipping FixtureはPublic-safeなOpaque IDと状態だけを公開する", () => {
  assert.equal(PUBLIC_USER_PRIZE_FIXTURE.status, "stored");
  assert.equal(PUBLIC_SHIPPING_REQUEST_FIXTURE.status, "requested");
  const serialized = JSON.stringify({
    prize: PUBLIC_USER_PRIZE_FIXTURE,
    shipping: PUBLIC_SHIPPING_REQUEST_FIXTURE,
  });
  assert.doesNotMatch(
    serialized,
    /internal_id|cost_price|individual_ppm|address_ciphertext|phone_number|secret/i,
  );
});

test("Content Fixtureは公開AssetとSanitize済み本文だけを含む", () => {
  assert.equal(PUBLIC_CONTENT_FIXTURE.banner.link_url, "/gachas");
  assert.equal(PUBLIC_CONTENT_FIXTURE.notice.is_important, false);
  const serialized = JSON.stringify(PUBLIC_CONTENT_FIXTURE);
  assert.doesNotMatch(
    serialized,
    /storage_identifier|internal_id|cost_price|individual_ppm|script|secret/i,
  );
});

test("Identity Recovery FixtureはToken、Code、Full PIIを公開しない", () => {
  assert.equal(PUBLIC_IDENTITY_RECOVERY_FIXTURE.password_reset.status, "accepted");
  assert.equal(PUBLIC_IDENTITY_RECOVERY_FIXTURE.sms_status.verified, false);
  assert.match(PUBLIC_IDENTITY_RECOVERY_FIXTURE.sms_status.phone_masked, /\*/);
  const serialized = JSON.stringify(PUBLIC_IDENTITY_RECOVERY_FIXTURE);
  assert.doesNotMatch(
    serialized,
    /"(?:password|token|verification_code|full_email|full_phone|secret)"\s*:/i,
  );
  assert.doesNotMatch(serialized, /@[a-z0-9.-]+|\+819[0-9]{9}/i);
});

const SITE_VERSION = "1.0.0-alpha.1";
const CLIENT_VERSION = "2.0.0-alpha.1";
const TEST_URL = "/api/v2/transport-boundary";

function browserClient(mock, timeout = 500) {
  return createBrowserStorefrontClient({
    base_url: "/api/v2",
    site_version: SITE_VERSION,
    client_version: CLIENT_VERSION,
    default_timeout_ms: timeout,
    fetch: mock.fetch,
  });
}

test("Mock TransportはRequestとFIFO応答順序を決定的に記録する", async () => {
  const mock = createMockFetch();
  mock.enqueueJson(
    { method: "POST", url: TEST_URL },
    {
      body: { sequence: 1 },
      headers: { "X-Request-Id": "request-fixture-001" },
    },
  );
  mock.enqueueJson(
    { method: "GET", url: TEST_URL },
    { body: { sequence: 2 } },
  );
  const client = browserClient(mock);

  const first = await client.request({
    path: "/transport-boundary",
    method: "POST",
    body: { fixture: true },
    idempotency_key: "idempotency-fixture-0001",
    retry: false,
  });
  const second = await client.request({
    path: "/transport-boundary",
    retry: false,
  });

  assert.deepEqual(first.data, { sequence: 1 });
  assert.deepEqual(second.data, { sequence: 2 });
  assert.equal(mock.requests.length, 2);
  assert.equal(mock.requests[0].body, '{"fixture":true}');
  assert.equal(mock.requests[0].headers["idempotency-key"], "idempotency-fixture-0001");
  assertBrowserRequestBoundary(mock.requests[0], {
    client_version: CLIENT_VERSION,
    site_version: SITE_VERSION,
  });
  assertResponseMetadata(first.metadata);
  mock.assertExhausted();
});

test("未登録Requestと期待値不一致を即時拒否し入力値をErrorへ含めない", async () => {
  const mock = createMockFetch();
  const client = browserClient(mock);
  await assert.rejects(
    client.request({
      path: "/transport-boundary",
      retry: false,
    }),
    (error) => {
      assert.equal(error instanceof StorefrontTransportError, true);
      assert.equal(error.code, "NETWORK_ERROR");
      assert.equal(error.cause instanceof UnexpectedMockRequestError, true);
      assert.equal(error.message.includes(TEST_URL), false);
      return true;
    },
  );
});

test("応答Queue残存を明示的に拒否する", () => {
  const mock = createMockFetch();
  mock.enqueueJson(
    { method: "GET", url: TEST_URL },
    { body: { fixture: true } },
  );
  assert.throws(() => mock.assertExhausted(), TestkitAssertionError);
});

test("Network ErrorをStorefront Clientの安全なErrorへ変換する", async () => {
  const mock = createMockFetch();
  mock.enqueueNetworkError({ method: "GET", url: TEST_URL });
  await assert.rejects(
    browserClient(mock).request({
      path: "/transport-boundary",
      retry: false,
    }),
    (error) => {
      assert.equal(error instanceof StorefrontTransportError, true);
      assert.equal(error.code, "NETWORK_ERROR");
      return true;
    },
  );
  mock.assertExhausted();
});

test("Pending応答でTimeoutを検証できる", async () => {
  const mock = createMockFetch();
  mock.enqueuePending({ method: "GET", url: TEST_URL });
  await assert.rejects(
    browserClient(mock, 10).request({
      path: "/transport-boundary",
      retry: false,
    }),
    (error) => {
      assert.equal(error instanceof StorefrontTransportError, true);
      assert.equal(error.code, "TIMEOUT");
      return true;
    },
  );
  mock.assertExhausted();
});

test("Pending応答でCaller Abortを検証できる", async () => {
  const mock = createMockFetch();
  mock.enqueuePending({ method: "GET", url: TEST_URL });
  const controller = new AbortController();
  const pending = browserClient(mock).request({
    path: "/transport-boundary",
    signal: controller.signal,
    retry: false,
  });
  controller.abort();
  await assert.rejects(pending, (error) => {
    assert.equal(error instanceof StorefrontTransportError, true);
    assert.equal(error.code, "ABORTED");
    return true;
  });
});

test("RFC 9457 Problem DetailsをApiProblemErrorへ変換する", async () => {
  const mock = createMockFetch();
  mock.enqueueProblem(
    { method: "GET", url: TEST_URL },
    {
      type: "urn:oripa:problem:fixture",
      title: "Contract fixture failure",
      status: 409,
      code: "CONTRACT_FIXTURE",
      request_id: "request-fixture-problem",
      retryable: false,
    },
  );
  try {
    await browserClient(mock).request({
      path: "/transport-boundary",
      retry: false,
    });
    assert.fail("Problem Details must reject");
  } catch (error) {
    assertProblemDetails(error);
    assert.equal(error instanceof ApiProblemError, true);
    assert.equal(error.code, "CONTRACT_FIXTURE");
    assert.equal(error.request_id, "request-fixture-problem");
  }
});

test("Authorization HeaderをStorefront Clientへ付与できない", async () => {
  const mock = createMockFetch();
  await assert.rejects(
    browserClient(mock).request({
      path: "/transport-boundary",
      headers: { Authorization: "" },
      retry: false,
    }),
    /Authorization header is not accepted/,
  );
  assert.equal(mock.requests.length, 0);
});

test("Public Surface AssertionはAdmin／Webhook相当Pathを拒否する", () => {
  const base = {
    method: "GET",
    headers: {},
    credentials: "include",
  };
  assert.doesNotThrow(() =>
    assertPublicRequestBoundary({ ...base, url: TEST_URL }),
  );
  assert.throws(
    () => assertPublicRequestBoundary({ ...base, url: "/api/v2/admin/users" }),
    TestkitAssertionError,
  );
  assert.throws(
    () => assertPublicRequestBoundary({ ...base, url: "/webhook/events" }),
    TestkitAssertionError,
  );
});

test("Server ClientとAssertionはGET／HEADだけを許可する", async () => {
  const mock = createMockFetch();
  mock.enqueueJson(
    { method: "GET", url: TEST_URL },
    { body: { fixture: true } },
  );
  mock.enqueueJson(
    { method: "HEAD", url: TEST_URL },
    { body: undefined, status: 204 },
  );
  const client = createServerStorefrontClient({
    base_url: "/api/v2",
    site_version: SITE_VERSION,
    default_timeout_ms: 500,
    fetch: mock.fetch,
  });
  await client.request({ path: "/transport-boundary", retry: false });
  assertServerSafeRequest(mock.requests[0]);
  await client.request({
    path: "/transport-boundary",
    method: "HEAD",
    retry: false,
  });
  assertServerSafeRequest(mock.requests[1]);
  assert.throws(
    () => assertServerSafeRequest({ ...mock.requests[0], method: "POST" }),
    TestkitAssertionError,
  );
  await assert.rejects(
    client.request({
      path: "/transport-boundary",
      method: "POST",
      retry: false,
    }),
    /allows only GET and HEAD/,
  );
});

test("Valid Site ManifestとRequired Capabilityを検証する", () => {
  assert.equal(validateSiteManifest(MINIMAL_SITE_MANIFEST_FIXTURE), true);
  assert.deepEqual(
    assertCompatibleSiteManifest(
      CAPABILITY_SITE_MANIFEST_FIXTURE,
      PLATFORM_COMPATIBILITY_FIXTURE,
    ),
    CAPABILITY_SITE_MANIFEST_FIXTURE,
  );
});

test("Invalid Site ManifestとSecret風Fieldを拒否する", () => {
  const prohibitedField = ["api", "token"].join("_");
  const invalid = {
    ...MINIMAL_SITE_MANIFEST_FIXTURE,
    public: {
      ...MINIMAL_SITE_MANIFEST_FIXTURE.public,
      [prohibitedField]: "",
    },
  };
  assert.equal(validateSiteManifest(invalid), false);
  assert.throws(
    () => assertCompatibleSiteManifest(invalid, PLATFORM_COMPATIBILITY_FIXTURE),
    SiteManifestValidationError,
  );
});

test("Compatibility Family不一致とRequired Capability不足を拒否する", () => {
  assert.throws(
    () =>
      assertCompatibleSiteManifest(MINIMAL_SITE_MANIFEST_FIXTURE, {
        ...PLATFORM_COMPATIBILITY_FIXTURE,
        compatibility_family: 3,
      }),
    TestkitAssertionError,
  );
  assert.throws(
    () =>
      assertCompatibleSiteManifest(CAPABILITY_SITE_MANIFEST_FIXTURE, {
        ...PLATFORM_COMPATIBILITY_FIXTURE,
        capabilities: [],
      }),
    TestkitAssertionError,
  );
});

test("Public OpenAPIは3.1.1かつGacha Presentationを含むOperation 48件である", () => {
  assert.equal(PUBLIC_CONTRACT_FIXTURE.openapi, "3.1.1");
  assert.equal(PUBLIC_CONTRACT_FIXTURE.operation_count, 48);
  assert.deepEqual(PUBLIC_CONTRACT_FIXTURE.operation_ids, [
    "completeGoogleOidc",
    "completeLineLogin",
    "confirmPasswordReset",
    "createContactInquiry",
    "createDraw",
    "createShippingAddress",
    "createShippingRequest",
    "deleteShippingAddress",
    "exchangeUserPrizes",
    "getContentNotice",
    "getContentStaticPage",
    "getDrawRequest",
    "getGacha",
    "getGachaBySlug",
    "getGachaPresentation",
    "getShippingAddress",
    "getShippingRequest",
    "getSmsVerificationStatus",
    "getUserPrize",
    "getUserSession",
    "listContentBanners",
    "listContentNotices",
    "listExternalIdentities",
    "listGachaCategories",
    "listGachaTags",
    "listGachas",
    "listShippingAddresses",
    "listShippingRequests",
    "listUserPrizes",
    "loginUser",
    "logoutUser",
    "reauthenticateUserPassword",
    "registerUser",
    "requestPasswordReset",
    "resendSmsVerification",
    "resendUserEmailVerification",
    "sendSmsVerification",
    "startGoogleIdentityLink",
    "startGoogleLogin",
    "startGoogleReauthentication",
    "startLineIdentityLink",
    "startLineLogin",
    "startLineReauthentication",
    "unlinkGoogleIdentity",
    "unlinkLineIdentity",
    "updateShippingAddress",
    "verifySmsCode",
    "verifyUserEmail",
  ]);
  assert.match(PUBLIC_CONTRACT_FIXTURE.bundle_sha256, /^[0-9a-f]{64}$/);
});

test("External Identity FixtureはProvider Subject／Token／Secretを公開しない", () => {
  assert.equal(PUBLIC_EXTERNAL_IDENTITY_FIXTURE.start.provider, "google");
  assert.equal(PUBLIC_EXTERNAL_IDENTITY_FIXTURE.line_start.provider, "line");
  assert.equal(PUBLIC_EXTERNAL_IDENTITY_FIXTURE.linked.items.length, 2);
  const serialized = JSON.stringify(PUBLIC_EXTERNAL_IDENTITY_FIXTURE);
  assert.doesNotMatch(
    serialized,
    /subject|access_token|refresh_token|authorization_code|client_secret|internal_id/i,
  );
});

test("Public Catalog Fixtureは集約確率だけを持ち内部情報を公開しない", () => {
  const serialized = JSON.stringify(PUBLIC_CATALOG_FIXTURE);
  assert.equal(PUBLIC_CATALOG_FIXTURE.data.probability_stages.length, 1);
  assert.equal(
    PUBLIC_CATALOG_FIXTURE.data.probability_stages[0].rank_probabilities[0]
      .total_ppm,
    100000,
  );
  for (const prohibited of [
    "individual_ppm",
    "cost_price",
    "internal_id",
    "provider_secret",
  ]) {
    assert.equal(serialized.includes(prohibited), false);
  }
});

test("Gacha Presentation FixtureはBackend判定済みCTAだけを公開する", () => {
  assert.equal(PUBLIC_GACHA_PRESENTATION_FIXTURE.data.sale_state, "on_sale");
  assert.deepEqual(
    PUBLIC_GACHA_PRESENTATION_FIXTURE.data.allowed_draw_counts,
    [1, 5, 10],
  );
  assert.equal(PUBLIC_GACHA_PRESENTATION_FIXTURE.data.cta.action, "draw");
  assert.doesNotMatch(
    JSON.stringify(PUBLIC_GACHA_PRESENTATION_FIXTURE),
    /user_id|internal_id|email|cookie|session|password/i,
  );
});

test("Public-safeなResponse Metadata Fixtureを固定する", () => {
  assert.doesNotThrow(() =>
    assertResponseMetadata(PUBLIC_RESPONSE_METADATA_FIXTURE),
  );
  assert.deepEqual(Object.keys(PUBLIC_RESPONSE_METADATA_FIXTURE).sort(), [
    "api_version",
    "idempotency_replayed",
    "request_id",
    "status",
  ]);
});

test("実Networkを使わず固定Export Surfaceだけを公開する", async () => {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => {
    throw new Error("Real network must not be used");
  };
  try {
    const mock = createMockFetch();
    mock.enqueueJson(
      { method: "GET", url: TEST_URL },
      { body: { fixture: true } },
    );
    const response = await browserClient(mock).request({
      path: "/transport-boundary",
      retry: false,
    });
    assert.deepEqual(response.data, { fixture: true });
  } finally {
    globalThis.fetch = originalFetch;
  }

  const exports = Object.keys(await import("../dist/index.js")).sort();
  assert.deepEqual(exports, [
    "CAPABILITY_SITE_MANIFEST_FIXTURE",
    "MINIMAL_SITE_MANIFEST_FIXTURE",
    "PLATFORM_COMPATIBILITY_FIXTURE",
    "PUBLIC_AUTH_FIXTURE",
    "PUBLIC_CATALOG_FIXTURE",
    "PUBLIC_CONTENT_FIXTURE",
    "PUBLIC_CONTRACT_FIXTURE",
    "PUBLIC_DRAW_FIXTURE",
    "PUBLIC_EXTERNAL_IDENTITY_FIXTURE",
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
    "assertProblemDetails",
    "assertPublicRequestBoundary",
    "assertResponseMetadata",
    "assertServerSafeRequest",
    "createMockFetch",
  ]);
});
