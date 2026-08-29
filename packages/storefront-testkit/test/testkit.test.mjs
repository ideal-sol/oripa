import assert from "node:assert/strict";
import test from "node:test";

import {
  ApiProblemError,
  StorefrontTransportError,
  createBrowserStorefrontClient,
  createBrowserStorefrontContentContactClient,
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
  PUBLIC_CONTACT_FIXTURE,
  PUBLIC_CONTACT_PROBLEM_FIXTURES,
  PUBLIC_CATALOG_FIXTURE,
  PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES,
  PUBLIC_GACHA_PRESENTATION_FIXTURE,
  PUBLIC_CONTENT_FIXTURE,
  PUBLIC_IDENTITY_RECOVERY_FIXTURE,
  PUBLIC_EXTERNAL_IDENTITY_FIXTURE,
  PUBLIC_LINE_FRIEND_STATE_FIXTURES,
  PUBLIC_LINE_FRIEND_STATE_PROBLEM_FIXTURES,
  PUBLIC_FOOTER_PAGES_FIXTURE,
  PUBLIC_TOP_BANNERS_FIXTURE,
  PUBLIC_DRAW_FIXTURE,
  PUBLIC_DRAW_HISTORY_FIXTURES,
  PUBLIC_DRAW_HISTORY_PROBLEM_FIXTURES,
  PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE,
  PUBLIC_POINT_PRODUCT_FIXTURES,
  PUBLIC_POINT_BALANCE_FIXTURES,
  PUBLIC_POINT_HISTORY_FIXTURES,
  PUBLIC_POINT_READ_PROBLEM_FIXTURES,
  PUBLIC_PAYMENT_GRANT_FIXTURES,
  PUBLIC_PAYMENT_CARD_CAPACITY_FIXTURES,
  PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES,
  PUBLIC_PAYMENT_CARD_REGISTRATION_PROBLEM_FIXTURES,
  PUBLIC_PAYMENT_CARD_UI_BOOTSTRAP_FIXTURES,
  PUBLIC_DRAW_PROBLEM_FIXTURES,
  PUBLIC_FULFILLMENT_PROBLEM_FIXTURES,
  PUBLIC_SHIPPING_REQUEST_FIXTURE,
  PUBLIC_USER_PRIZE_FIXTURE,
  PUBLIC_CONTRACT_FIXTURE,
  PUBLIC_RESPONSE_METADATA_FIXTURE,
  TestkitAssertionError,
  UnexpectedMockRequestError,
  assertBrowserRequestBoundary,
  assertCompatibleSiteManifest,
  assertDrawProblemDetails,
  assertFulfillmentProblemDetails,
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

test("Payment Card UI Bootstrap FixtureはPublic KeyとCanonical environmentだけを公開する", () => {
  assert.deepEqual(PUBLIC_PAYMENT_CARD_UI_BOOTSTRAP_FIXTURES.sandbox, {
    provider: "fincode",
    public_api_key: "p_test_public-safe-fixture",
    is_live_mode: false,
  });
  assert.equal(PUBLIC_PAYMENT_CARD_UI_BOOTSTRAP_FIXTURES.live.is_live_mode, true);
  assert.doesNotMatch(
    JSON.stringify(PUBLIC_PAYMENT_CARD_UI_BOOTSTRAP_FIXTURES),
    /secret|webhook|token|credential|customer_id|card_id|user_id/i,
  );
});

test("Card Registration Fixtureは3DS2 state machine・capacity・typed Problemsを表現する", () => {
  assert.equal(
    PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES.requires_action.next_action.type,
    "three_d_secure",
  );
  assert.equal(PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES.pending.saved_card_id, null);
  assert.equal(PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES.completed.status, "completed");
  assert.equal(
    PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES.completed.saved_card_id,
    PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES.duplicate_return.saved_card_id,
  );
  assert.equal(
    PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES.completed.saved_card_id,
    PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES.duplicate_reconcile.saved_card_id,
  );
  for (const status of ["failed", "canceled", "expired"]) {
    assert.equal(PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES[status].saved_card_id, null);
    assert.equal(PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES[status].next_action, null);
  }
  assert.equal(
    PUBLIC_PAYMENT_CARD_CAPACITY_FIXTURES.saved_2_pending_1.limits.registration_remaining,
    0,
  );
  assert.equal(
    PUBLIC_PAYMENT_CARD_CAPACITY_FIXTURES.pending_terminal_released.limits.registration_remaining,
    1,
  );
  assert.equal(
    PUBLIC_PAYMENT_CARD_REGISTRATION_PROBLEM_FIXTURES.legacy_rejected.code,
    "CARD_REGISTRATION_3DS_REQUIRED",
  );
  assert.equal(
    PUBLIC_PAYMENT_CARD_REGISTRATION_PROBLEM_FIXTURES.unavailable.retryable,
    true,
  );
  assert.doesNotMatch(
    JSON.stringify({
      registrations: PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES,
      capacity: PUBLIC_PAYMENT_CARD_CAPACITY_FIXTURES,
      problems: PUBLIC_PAYMENT_CARD_REGISTRATION_PROBLEM_FIXTURES,
    }),
    /card_token|provider_card_id|customer_id|secret|credential|pan|cvc/i,
  );
});

test("LINE Friend State Fixtureは連携・友だち確認・LINEユーザー判定をBackend Presentationで固定する", () => {
  assert.equal(PUBLIC_LINE_FRIEND_STATE_FIXTURES.unlinked.linked, false);
  assert.equal(
    PUBLIC_LINE_FRIEND_STATE_FIXTURES.friend_add_required.primary_action.code,
    "open_friend_add_url",
  );
  assert.equal(PUBLIC_LINE_FRIEND_STATE_FIXTURES.confirmed.friend_confirmed, true);
  assert.equal(PUBLIC_LINE_FRIEND_STATE_FIXTURES.confirmed.is_line_user, true);
  for (const problem of Object.values(PUBLIC_LINE_FRIEND_STATE_PROBLEM_FIXTURES)) {
    const error = new ApiProblemError(problem);
    assertProblemDetails(error);
    assert.equal(error.code, problem.code);
  }
  assert.doesNotMatch(
    JSON.stringify(PUBLIC_LINE_FRIEND_STATE_FIXTURES),
    /subject_hash|issuer|provider|channel_secret|access_token|internal_id/i,
  );
});

test("Draw FixtureはBulk集計だけを公開し個別ppmと内部IDを含まない", () => {
  assert.equal(PUBLIC_DRAW_FIXTURE.requested_count, 1000);
  assert.equal(PUBLIC_DRAW_FIXTURE.executed_count, 1000);
  assert.equal("results" in PUBLIC_DRAW_FIXTURE, false);
  const serialized = JSON.stringify(PUBLIC_DRAW_FIXTURE);
  assert.doesNotMatch(serialized, /individual_ppm|internal_id|cost_price|secret/i);
});

test("Draw History FixtureはPresentation、Stable Ordering、Cursor、Typed Errorを固定する", () => {
  assert.equal(PUBLIC_DRAW_HISTORY_FIXTURES.empty.items.length, 0);
  assert.equal(PUBLIC_DRAW_HISTORY_FIXTURES.multiple.items[0].executed_count, 2);
  assert.deepEqual(PUBLIC_DRAW_HISTORY_FIXTURES.multiple.items[0].status, {
    code: "completed",
    label: "完了",
  });
  assert.equal(
    PUBLIC_DRAW_HISTORY_FIXTURES.multiple.items[0].gacha.title,
    "Fixture Catalog Gacha",
  );
  assert.equal(
    PUBLIC_DRAW_HISTORY_FIXTURES.continuation.items[0].id,
    PUBLIC_DRAW_HISTORY_FIXTURES.multiple.items[1].id,
  );
  assert.match(PUBLIC_DRAW_HISTORY_FIXTURES.first_page.next_cursor, /^[A-Za-z0-9_-]+$/);
  for (const problem of Object.values(PUBLIC_DRAW_HISTORY_PROBLEM_FIXTURES)) {
    const error = new ApiProblemError(problem);
    assertProblemDetails(error);
    assert.equal(error.code, problem.code);
  }
  assert.doesNotMatch(
    JSON.stringify(PUBLIC_DRAW_HISTORY_FIXTURES),
    /user_id|gacha_version_id|probability_version_id|event_code|is_qa_draw|internal_id/i,
  );
});

test("Point Read Fixtureは残高、増減、空履歴、Cursor、Typed Errorを固定する", () => {
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.positive.total_points, 1000);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.canonical_expiry.paid_points, 130);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.canonical_expiry.free_points, 160);
  assert.deepEqual(PUBLIC_POINT_BALANCE_FIXTURES.canonical_expiry.expiring_within_7_days, [
    { expires_at: "2026-08-21T00:00:00Z", amount: 60 },
    { expires_at: "2026-08-21T01:00:00Z", amount: 10 },
    { expires_at: "2026-08-22T00:00:00Z", amount: 80 },
  ]);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.legacy_no_expiry.total_points, 50);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.legacy_no_expiry.expiring_within_7_days.length, 0);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.expired_excluded.total_points, 0);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.reserved_excluded.total_points, 0);
  assert.deepEqual(PUBLIC_POINT_BALANCE_FIXTURES.seven_day_boundary.expiring_within_7_days, [
    { expires_at: "2026-08-22T00:00:00Z", amount: 20 },
  ]);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.seven_day_boundary.total_points, 30);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.less_than_seven_days.expiring_within_7_days.length, 1);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.over_seven_days.expiring_within_7_days.length, 0);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.expires_at_as_of_excluded.total_points, 0);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.same_expiry_aggregated.expiring_within_7_days[0].amount, 30);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.timestamp_separation.expiring_within_7_days.length, 2);
  assert.equal(PUBLIC_POINT_BALANCE_FIXTURES.zero.total_points, 0);
  assert.equal(PUBLIC_POINT_HISTORY_FIXTURES.empty.items.length, 0);
  assert.equal(PUBLIC_POINT_HISTORY_FIXTURES.multiple.items[0].amount_delta, -300);
  assert.equal(PUBLIC_POINT_HISTORY_FIXTURES.multiple.items[2].amount_delta, 1000);
  assert.ok(
    PUBLIC_POINT_HISTORY_FIXTURES.multiple.items.every(
      (item, index, items) =>
        index === 0 || item.occurred_at < items[index - 1].occurred_at,
    ),
  );
  assert.equal(
    PUBLIC_POINT_HISTORY_FIXTURES.continuation.items[0].id,
    PUBLIC_POINT_HISTORY_FIXTURES.multiple.items[1].id,
  );
  assert.match(PUBLIC_POINT_HISTORY_FIXTURES.first_page.next_cursor, /^[A-Za-z0-9_-]+$/);
  for (const problem of Object.values(PUBLIC_POINT_READ_PROBLEM_FIXTURES)) {
    const error = new ApiProblemError(problem);
    assertProblemDetails(error);
    assert.equal(error.code, problem.code);
  }
  assert.doesNotMatch(
    JSON.stringify({
      balance: PUBLIC_POINT_BALANCE_FIXTURES,
      history: PUBLIC_POINT_HISTORY_FIXTURES,
    }),
    /wallet_id|ledger_id|point_lot_id|source_type|operation_type|internal_id/i,
  );
});

test("端数Draw Fixtureは要求数と実行数を分離してReplayを固定する", () => {
  assert.deepEqual(
    PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE.presentation.allowed_draw_counts,
    [1, 100, 1000],
  );
  assert.equal(PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE.request.requested_count, 1000);
  assert.equal(PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE.response.executed_count, 900);
  assert.equal(PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE.final_sale_state, "sold_out");
  assert.deepEqual(PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE.replay, {
    requested_count: 1000,
    executed_count: 900,
    idempotent_replay: true,
  });
});

test("Draw Problem FixtureはGenerated Codeと型付きAssertionを同期する", () => {
  for (const problem of PUBLIC_DRAW_PROBLEM_FIXTURES) {
    const error = new ApiProblemError(problem);
    assertDrawProblemDetails(error, problem.code);
    assert.equal(error.code, problem.code);
  }

  const unknown = new ApiProblemError({
    type: "https://oripa.example/problems/unknown",
    title: "Unknown error.",
    status: 500,
    code: "UNOBSERVED_DRAW_ERROR",
    request_id: "request-fixture-unknown",
    retryable: false,
  });
  assert.throws(
    () => assertDrawProblemDetails(unknown),
    TestkitAssertionError,
  );
});

test("Fulfillment Problem FixtureはGenerated Codeと型付きAssertionを同期する", () => {
  for (const problem of PUBLIC_FULFILLMENT_PROBLEM_FIXTURES) {
    const error = new ApiProblemError(problem);
    assertFulfillmentProblemDetails(error, problem.code);
    assert.equal(error.code, problem.code);
  }

  const unknown = new ApiProblemError({
    type: "https://oripa.example/problems/unknown",
    title: "Unknown error.",
    status: 500,
    code: "UNOBSERVED_FULFILLMENT_ERROR",
    request_id: "request-fixture-unknown-fulfillment",
    retryable: false,
  });
  assert.throws(
    () => assertFulfillmentProblemDetails(unknown),
    TestkitAssertionError,
  );
});

test("Prize／Shipping FixtureはPublic-safeなOpaque IDと状態だけを公開する", () => {
  assert.equal(PUBLIC_USER_PRIZE_FIXTURE.status, "stored");
  assert.equal(PUBLIC_USER_PRIZE_FIXTURE.presentation.rank.code, "S");
  assert.equal(PUBLIC_USER_PRIZE_FIXTURE.allowed_actions.shipping.allowed, true);
  assert.equal(
    PUBLIC_USER_PRIZE_FIXTURE.allowed_actions.point_exchange.unavailable_reason,
    null,
  );
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
  assert.match(PUBLIC_CONTENT_FIXTURE.banner.image_url, /^\/api\/v2\/content\/assets\//u);
  assert.equal(PUBLIC_CONTENT_FIXTURE.notice.is_important, false);
  const serialized = JSON.stringify(PUBLIC_CONTENT_FIXTURE);
  assert.doesNotMatch(
    serialized,
    /storage_identifier|internal_id|cost_price|individual_ppm|script|secret/i,
  );
});

test("Top Banner Fixtureは公開中かつTop ONだけを一覧へ含める", () => {
  assert.equal(PUBLIC_TOP_BANNERS_FIXTURE.response.items.length, 1);
  assert.equal(PUBLIC_TOP_BANNERS_FIXTURE.response.items[0].link_url, "/gachas");
  assert.equal(PUBLIC_TOP_BANNERS_FIXTURE.excluded.top_off.title, "トップ非表示");
  assert.equal(
    PUBLIC_TOP_BANNERS_FIXTURE.excluded.outside_publication_period.title,
    "公開期間外",
  );
});

test("Point Product Fixtureは順序、Eligibility、CTAをBackend判定済みで表す", () => {
  assert.equal(PUBLIC_POINT_PRODUCT_FIXTURES.anonymous.data.length, 2);
  assert.equal(PUBLIC_POINT_PRODUCT_FIXTURES.anonymous_empty.data.length, 0);
  assert.equal(
    PUBLIC_POINT_PRODUCT_FIXTURES.anonymous.data[0].cta.action,
    "login",
  );
  assert.deepEqual(
    PUBLIC_POINT_PRODUCT_FIXTURES.authenticated_eligible.data.map(
      (product) => product.audience.code,
    ),
    ["all_users", "first_purchase_users"],
  );
  assert.equal(
    PUBLIC_POINT_PRODUCT_FIXTURES.authenticated_after_first_purchase.data[1]
      .ineligible_reason,
    "first_purchase_required",
  );
  assert.equal(PUBLIC_POINT_PRODUCT_FIXTURES.unavailable.data[0].is_available, false);
  assert.equal(PUBLIC_POINT_PRODUCT_FIXTURES.authenticated_eligible.data[0].limited_bonus.state, "active");
  assert.equal(PUBLIC_POINT_PRODUCT_FIXTURES.authenticated_eligible.data[1].limited_bonus.state, "upcoming");
  assert.equal(PUBLIC_POINT_PRODUCT_FIXTURES.unavailable.data[0].limited_bonus.state, "inactive");
  assert.equal(PUBLIC_POINT_PRODUCT_FIXTURES.unavailable.data[0].limited_bonus.presentation.is_visible, false);
  assert.doesNotMatch(
    JSON.stringify(PUBLIC_POINT_PRODUCT_FIXTURES),
    /point_purchase_plan_id|target_user_tag_id|provider_code|internal_id|secret/i,
  );
});

test("Footer Page Fixtureは公開中かつFooter ONだけを一覧へ含める", () => {
  assert.equal(PUBLIC_FOOTER_PAGES_FIXTURE.response.items.length, 1);
  assert.equal(PUBLIC_FOOTER_PAGES_FIXTURE.response.items[0].slug, "terms");
  assert.equal(PUBLIC_FOOTER_PAGES_FIXTURE.excluded.footer_off.slug, "guide");
  assert.equal(
    PUBLIC_FOOTER_PAGES_FIXTURE.excluded.outside_publication_period.slug,
    "future-policy",
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

function browserContactClient(mock, authenticated, csrf = "a".repeat(64)) {
  mock.enqueueJson(
    { method: "GET", url: "/api/v2/auth/session" },
    {
      body: authenticated
        ? PUBLIC_AUTH_FIXTURE.authenticated_session
        : PUBLIC_AUTH_FIXTURE.anonymous_session,
    },
  );
  return createBrowserStorefrontContentContactClient({
    base_url: "/api/v2",
    site_version: SITE_VERSION,
    client_version: CLIENT_VERSION,
    default_timeout_ms: 500,
    fetch: mock.fetch,
    cookie_reader: () => csrf,
  });
}

test("Contact Testkitはanonymous first submit／bootstrap／202を固定する", async () => {
  const mock = createMockFetch();
  const client = browserContactClient(mock, false);
  mock.enqueueJson(
    { method: "POST", url: "/api/v2/contact-inquiries" },
    {
      body: PUBLIC_CONTACT_FIXTURE.receipt,
      status: 202,
      headers: { "X-Request-Id": PUBLIC_CONTACT_FIXTURE.receipt.request_id },
    },
  );
  const response = await client.submitContact(PUBLIC_CONTACT_FIXTURE.input);

  assert.equal(response.metadata.status, 202);
  assert.deepEqual(response.data, PUBLIC_CONTACT_FIXTURE.receipt);
  assert.deepEqual(mock.requests.map(({ method, url }) => ({ method, url })), [
    { method: "GET", url: "/api/v2/auth/session" },
    { method: "POST", url: "/api/v2/contact-inquiries" },
  ]);
  assert.equal(mock.requests[1].headers["x-xsrf-token"], "a".repeat(64));
  assert.equal(mock.requests[1].headers["idempotency-key"], undefined);
  assert.deepEqual(JSON.parse(mock.requests[1].body), PUBLIC_CONTACT_FIXTURE.input);
  assertBrowserRequestBoundary(mock.requests[1], {
    client_version: CLIENT_VERSION,
    site_version: SITE_VERSION,
  });
  mock.assertExhausted();
});

test("Contact Testkitはauthenticated submitを同じBrowser境界で固定する", async () => {
  const mock = createMockFetch();
  const client = browserContactClient(mock, true, "b".repeat(64));
  mock.enqueueJson(
    { method: "POST", url: "/api/v2/contact-inquiries" },
    { body: PUBLIC_CONTACT_FIXTURE.receipt, status: 202 },
  );
  const response = await client.submitContact(PUBLIC_CONTACT_FIXTURE.input);

  assert.equal(response.data.status, "accepted");
  assert.equal(mock.requests[1].headers["x-xsrf-token"], "b".repeat(64));
  assert.equal(mock.requests.every(({ credentials }) => credentials === "include"), true);
  mock.assertExhausted();
});

test("Contact Testkitはvalidation Problem Detailsと429をtyped errorへ変換する", async () => {
  for (const problem of Object.values(PUBLIC_CONTACT_PROBLEM_FIXTURES)) {
    const mock = createMockFetch();
    const client = browserContactClient(mock, false);
    mock.enqueueProblem(
      { method: "POST", url: "/api/v2/contact-inquiries" },
      problem,
    );
    await assert.rejects(
      client.submitContact(PUBLIC_CONTACT_FIXTURE.input),
      (error) => {
        assertProblemDetails(error);
        assert.equal(error.code, problem.code);
        assert.equal(error.status, problem.status);
        if (problem.status === 422) {
          assert.deepEqual(error.errors, problem.errors);
        }
        if (problem.status === 429) {
          assert.equal(error.retry_after_seconds, 3600);
        }
        return true;
      },
    );
    assert.equal(mock.requests.length, 2);
    mock.assertExhausted();
  }
});

test("Contact Testkitはtransport errorをtyped errorへ変換し再送しない", async () => {
  const mock = createMockFetch();
  const client = browserContactClient(mock, true);
  mock.enqueueNetworkError({ method: "POST", url: "/api/v2/contact-inquiries" });
  await assert.rejects(
    client.submitContact(PUBLIC_CONTACT_FIXTURE.input),
    (error) =>
      error instanceof StorefrontTransportError
      && error.code === "NETWORK_ERROR",
  );
  assert.equal(mock.requests.length, 2);
  mock.assertExhausted();
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

test("Public OpenAPIは3.1.1かつ3DS2 Card Registrationを含むOperation 71件である", () => {
  assert.equal(PUBLIC_CONTRACT_FIXTURE.openapi, "3.1.1");
  assert.equal(PUBLIC_CONTRACT_FIXTURE.operation_count, 71);
  assert.deepEqual(PUBLIC_CONTRACT_FIXTURE.operation_ids, [
    "cancelPaymentCardRegistration",
    "completeGoogleOidc",
    "completeLineLogin",
    "completePaymentCardRegistration",
    "confirmPasswordReset",
    "createContactInquiry",
    "createDraw",
    "createPayment",
    "createPaymentCardRegistrationIntent",
    "createShippingAddress",
    "createShippingRequest",
    "deletePaymentCard",
    "deleteShippingAddress",
    "exchangeUserPrizes",
    "getContentNotice",
    "getContentStaticPage",
    "getDrawRequest",
    "getGacha",
    "getGachaBySlug",
    "getGachaPresentation",
    "getLineFriendState",
    "getPayment",
    "getPaymentCardRegistration",
    "getPaymentCardUiBootstrap",
    "getShippingAddress",
    "getShippingRequest",
    "getSmsVerificationStatus",
    "getUserPrize",
    "getUserSession",
    "getWallet",
    "listContentBanners",
    "listContentFooterPages",
    "listContentNotices",
    "listDrawHistory",
    "listExternalIdentities",
    "listGachaCategories",
    "listGachaTags",
    "listGachas",
    "listMyPayments",
    "listPaymentCards",
    "listPointLedgerEntries",
    "listPointProducts",
    "listShippingAddresses",
    "listShippingRequests",
    "listUserPrizes",
    "loginUser",
    "logoutUser",
    "normalizeFincodePaymentFailureReturn",
    "normalizeFincodePaymentReturn",
    "reauthenticateUserPassword",
    "reconcileFincodeCardRegistrationFailureReturn",
    "reconcileFincodeCardRegistrationReturn",
    "reconcilePaymentCardRegistration",
    "registerUser",
    "requestPasswordReset",
    "resendSmsVerification",
    "resendUserEmailVerification",
    "resumeUnpaidPayment",
    "sendSmsVerification",
    "startGoogleIdentityLink",
    "startGoogleLogin",
    "startGoogleReauthentication",
    "startLineIdentityLink",
    "startLineLogin",
    "startLineReauthentication",
    "startPaymentCardRegistration",
    "unlinkGoogleIdentity",
    "unlinkLineIdentity",
    "updateShippingAddress",
    "verifySmsCode",
    "verifyUserEmail",
  ]);
  assert.match(PUBLIC_CONTRACT_FIXTURE.bundle_sha256, /^[0-9a-f]{64}$/);
});

test("Payment Grant Fixtureは通常・期間限定・Canonical合計を分離する", () => {
  assert.deepEqual(PUBLIC_PAYMENT_GRANT_FIXTURES.without_limited_bonus, {
    paid_points: 10000,
    bonus_points: 1000,
    limited_bonus_points: 0,
    total_points: 11000,
  });
  assert.deepEqual(PUBLIC_PAYMENT_GRANT_FIXTURES.limited_bonus_applied, {
    paid_points: 10000,
    bonus_points: 1000,
    limited_bonus_points: 2000,
    total_points: 13000,
  });
  for (const grant of Object.values(PUBLIC_PAYMENT_GRANT_FIXTURES)) {
    assert.equal(
      grant.total_points,
      grant.paid_points + grant.bonus_points + grant.limited_bonus_points,
    );
  }
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

test("Gacha Catalog Fixtureは販売状態とUser判定をBackend Presentationで表現する", () => {
  assert.deepEqual(
    Object.keys(PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES),
    [
      "on_sale",
      "coming_soon",
      "paused",
      "ended",
      "sold_out",
      "authenticated_eligible",
      "authenticated_ineligible",
      "anonymous",
    ],
  );
  assert.equal(
    PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES.authenticated_ineligible.presentation
      .ineligible_reason,
    "audience_not_eligible",
  );
  assert.equal(
    PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES.paused.presentation.cta.state,
    "disabled",
  );
  assert.equal(
    PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES.paused.presentation.cta.reason,
    "sales_paused",
  );
  for (const state of ["ended", "sold_out"]) {
    const display =
      PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES[state].presentation.display;
    assert.equal(display.show_price_points, false);
    assert.equal(display.show_total_count, false);
    assert.equal(display.show_drawn_count, false);
  }
  assert.match(
    PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES.on_sale.presentation_asset.path,
    /^\/api\/v2\/content\/assets\//,
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
    "PUBLIC_CONTACT_FIXTURE",
    "PUBLIC_CONTACT_PROBLEM_FIXTURES",
    "PUBLIC_CONTENT_FIXTURE",
    "PUBLIC_CONTRACT_FIXTURE",
    "PUBLIC_DRAW_FIXTURE",
    "PUBLIC_DRAW_HISTORY_FIXTURES",
    "PUBLIC_DRAW_HISTORY_PROBLEM_FIXTURES",
    "PUBLIC_DRAW_PROBLEM_FIXTURES",
    "PUBLIC_EXTERNAL_IDENTITY_FIXTURE",
    "PUBLIC_FOOTER_PAGES_FIXTURE",
    "PUBLIC_FULFILLMENT_PROBLEM_FIXTURES",
    "PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES",
    "PUBLIC_GACHA_PRESENTATION_FIXTURE",
    "PUBLIC_IDENTITY_RECOVERY_FIXTURE",
    "PUBLIC_LINE_FRIEND_STATE_FIXTURES",
    "PUBLIC_LINE_FRIEND_STATE_PROBLEM_FIXTURES",
    "PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE",
    "PUBLIC_PAYMENT_CARD_CAPACITY_FIXTURES",
    "PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES",
    "PUBLIC_PAYMENT_CARD_REGISTRATION_PROBLEM_FIXTURES",
    "PUBLIC_PAYMENT_CARD_UI_BOOTSTRAP_FIXTURES",
    "PUBLIC_PAYMENT_GRANT_FIXTURES",
    "PUBLIC_POINT_BALANCE_FIXTURES",
    "PUBLIC_POINT_HISTORY_FIXTURES",
    "PUBLIC_POINT_PRODUCT_FIXTURES",
    "PUBLIC_POINT_READ_PROBLEM_FIXTURES",
    "PUBLIC_RESPONSE_METADATA_FIXTURE",
    "PUBLIC_SHIPPING_REQUEST_FIXTURE",
    "PUBLIC_TOP_BANNERS_FIXTURE",
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
  ]);
});
