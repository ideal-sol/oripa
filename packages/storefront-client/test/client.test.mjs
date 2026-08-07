import assert from "node:assert/strict";
import test from "node:test";

import {
  ApiProblemError,
  StorefrontTransportError,
  createBrowserStorefrontClient,
  createIdempotencyKey,
  isAuthProblemError,
} from "../dist/browser.js";
import {
  createServerStorefrontClient,
} from "../dist/server.js";
import {
  createStorefrontCatalogClient,
  createStorefrontContentContactClient,
  createStorefrontDrawClient,
  createStorefrontIdentityClient,
  createStorefrontPrizeShippingClient,
} from "../dist/index.js";

const jsonResponse = (body, init = {}) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: {
      "Content-Type": "application/json",
      ...init.headers,
    },
    ...init,
  });

const browserConfig = (fetch) => ({
  base_url: "/api/v2",
  site_version: "1.0.0",
  default_timeout_ms: 500,
  fetch,
});

test("Browser通信はCookie、Version Header、Response Metadataを固定する", async () => {
  let request;
  const client = createBrowserStorefrontClient(
    browserConfig(async (url, init) => {
      request = { url, init };
      return jsonResponse(
        { data: "ok" },
        {
          headers: {
            "X-Request-Id": "req_test",
            "X-Oripa-Api-Version": "2",
            "Idempotency-Replayed": "true",
          },
        },
      );
    }),
  );

  const result = await client.request({ path: "/transport-test" });
  assert.equal(request.url, "/api/v2/transport-test");
  assert.equal(request.init.credentials, "include");
  assert.equal(request.init.headers.get("X-Oripa-Client-Version"), "2.0.0-alpha.3");
  assert.equal(request.init.headers.get("X-Oripa-Site-Version"), "1.0.0");
  assert.equal(result.metadata.request_id, "req_test");
  assert.equal(result.metadata.api_version, "2");
  assert.equal(result.metadata.idempotency_replayed, true);
});

test("Timeoutと外部AbortSignalを区別する", async () => {
  const abortAwareFetch = async (_url, init) =>
    await new Promise((_resolve, reject) => {
      init.signal.addEventListener(
        "abort",
        () => reject(init.signal.reason),
        { once: true },
      );
    });
  const timeoutClient = createBrowserStorefrontClient({
    ...browserConfig(abortAwareFetch),
    default_timeout_ms: 5,
  });
  await assert.rejects(
    timeoutClient.request({ path: "/timeout", retry: false }),
    (error) =>
      error instanceof StorefrontTransportError && error.code === "TIMEOUT",
  );

  const controller = new AbortController();
  const abortClient = createBrowserStorefrontClient(browserConfig(abortAwareFetch));
  const pending = abortClient.request({
    path: "/abort",
    signal: controller.signal,
  });
  controller.abort();
  await assert.rejects(
    pending,
    (error) =>
      error instanceof StorefrontTransportError && error.code === "ABORTED",
  );
});

test("Idempotency-Key付きMutationだけを同じKeyで最大1回再試行する", async () => {
  const calls = [];
  const key = createIdempotencyKey();
  const client = createBrowserStorefrontClient(
    browserConfig(async (_url, init) => {
      calls.push(init.headers.get("Idempotency-Key"));
      if (calls.length === 1) {
        return jsonResponse(
          { unavailable: true },
          { status: 503 },
        );
      }
      return jsonResponse({ data: "ok" });
    }),
  );
  await client.request({
    path: "/mutation",
    method: "POST",
    body: { value: "same" },
    idempotency_key: key,
  });
  assert.deepEqual(calls, [key, key]);

  let unsafeCalls = 0;
  const unsafe = createBrowserStorefrontClient(
    browserConfig(async () => {
      unsafeCalls += 1;
      return jsonResponse({ unavailable: true }, { status: 503 });
    }),
  );
  await assert.rejects(
    unsafe.request({ path: "/mutation", method: "POST" }),
    (error) =>
      error instanceof StorefrontTransportError &&
      error.code === "HTTP_ERROR" &&
      error.metadata.status === 503,
  );
  assert.equal(unsafeCalls, 1);
});

test("GETは502／503／504またはNetwork Errorだけを最大2回再試行する", async () => {
  let calls = 0;
  const client = createBrowserStorefrontClient(
    browserConfig(async () => {
      calls += 1;
      if (calls === 1) {
        throw new TypeError("synthetic fetch network failure");
      }
      if (calls === 2) {
        return jsonResponse({ unavailable: true }, { status: 502 });
      }
      return jsonResponse({ data: "ok" });
    }),
  );
  await client.request({ path: "/safe" });
  assert.equal(calls, 3);
});

test("409／422／429は自動再試行せずRetry-Afterを返す", async () => {
  for (const status of [409, 422, 429]) {
    let calls = 0;
    const client = createBrowserStorefrontClient(
      browserConfig(async () => {
        calls += 1;
        return jsonResponse(
          { rejected: true },
          { status, headers: { "Retry-After": "7" } },
        );
      }),
    );
    await assert.rejects(
      client.request({
        path: "/mutation",
        method: "POST",
        idempotency_key: createIdempotencyKey(),
      }),
      (error) =>
        error instanceof StorefrontTransportError &&
        error.metadata.status === status &&
        error.metadata.retry_after_seconds === 7,
    );
    assert.equal(calls, 1);
  }
});

test("RFC 9457 Problem DetailsをApiProblemErrorへ変換する", async () => {
  const client = createBrowserStorefrontClient(
    browserConfig(async () =>
      jsonResponse(
        {
          type: "urn:oripa:problem:validation-failed",
          title: "入力内容を確認してください",
          status: 422,
          code: "VALIDATION_FAILED",
          request_id: "req_problem",
          retryable: false,
          errors: { field: ["invalid"] },
        },
        {
          status: 422,
          headers: { "Content-Type": "application/problem+json" },
        },
      ),
    ),
  );
  await assert.rejects(
    client.request({ path: "/problem" }),
    (error) =>
      error instanceof ApiProblemError &&
      error.code === "VALIDATION_FAILED" &&
      error.request_id === "req_problem" &&
      error.errors.field[0] === "invalid",
  );
});

test("CSRFは設定可能なInitializerをMutation前に一度だけ呼ぶ", async () => {
  let csrfCalls = 0;
  const client = createBrowserStorefrontClient({
    ...browserConfig(async () => jsonResponse({ data: "ok" })),
    csrf_initializer: async ({ signal }) => {
      assert.equal(signal.aborted, false);
      csrfCalls += 1;
    },
  });
  await client.request({
    path: "/first",
    method: "POST",
    csrf: "required",
  });
  await client.request({
    path: "/second",
    method: "POST",
    csrf: "required",
  });
  assert.equal(csrfCalls, 1);
});

test("Browser認証ClientはSession EndpointでCSRFを初期化しCookieを追従する", async () => {
  const requests = [];
  let csrfCookie = "a".repeat(64);
  const transport = createBrowserStorefrontClient({
    ...browserConfig(async (url, init) => {
      requests.push({ url, init });
      if (url === "/api/v2/auth/session") {
        return jsonResponse({ authenticated: false, user: null });
      }
      if (url === "/api/v2/auth/login") {
        assert.equal(init.headers.get("X-XSRF-TOKEN"), "a".repeat(64));
        csrfCookie = "b".repeat(64);
        return jsonResponse({
          authenticated: true,
          user: {
            id: "0198a001-0000-7000-8000-000000000501",
            state: "active",
            email_verified: true,
          },
        });
      }
      assert.equal(url, "/api/v2/auth/logout");
      assert.equal(init.headers.get("X-XSRF-TOKEN"), "b".repeat(64));
      return new Response(null, { status: 204 });
    }),
    cookie_reader: () => csrfCookie,
  });
  const identity = createStorefrontIdentityClient(transport);

  const login = await identity.login({
    email: "fixture@example.test",
    password: "synthetic password",
  });
  assert.equal(login.data.authenticated, true);
  await identity.logout();

  assert.deepEqual(requests.map(({ url }) => url), [
    "/api/v2/auth/session",
    "/api/v2/auth/login",
    "/api/v2/auth/logout",
  ]);
  assert.equal(requests.every(({ init }) => init.credentials === "include"), true);
});

test("認証Facadeは6操作とEmail Verification PathをPublic Contractどおり構築する", async () => {
  const requests = [];
  const transport = createBrowserStorefrontClient({
    ...browserConfig(async (url, init) => {
      requests.push({ url, method: init.method });
      if (url.includes("/auth/email/verify/")) {
        return jsonResponse({ authenticated: true, user: null, redirect_path: "/" });
      }
      if (url.endsWith("/auth/register")) {
        return jsonResponse(
          {
            status: "pending_verification",
            user_id: "0198a001-0000-7000-8000-000000000502",
          },
          { status: 202 },
        );
      }
      if (url.endsWith("/verification-notification")) {
        return jsonResponse({ status: "accepted" }, { status: 202 });
      }
      return jsonResponse({ authenticated: false, user: null });
    }),
    csrf_initializer: async () => "c".repeat(64),
  });
  const identity = createStorefrontIdentityClient(transport);
  const userId = "0198a001-0000-7000-8000-000000000502";

  await identity.register({
    email: "fixture@example.test",
    password: "synthetic password",
  });
  await identity.getCurrentSession();
  await identity.resendEmailVerification({ user_id: userId });
  await identity.completeEmailVerification({
    user_id: userId,
    hash: "d".repeat(64),
  });

  assert.deepEqual(requests.map(({ url }) => url), [
    "/api/v2/auth/register",
    "/api/v2/auth/session",
    "/api/v2/auth/email/verification-notification",
    `/api/v2/auth/email/verify/${userId}/${"d".repeat(64)}`,
  ]);
});

test("認証Problem Codeを型付きGuardで判定する", () => {
  const error = new ApiProblemError({
    type: "https://oripa.example/problems/email-verification-required",
    title: "Email verification is required before login.",
    status: 403,
    code: "EMAIL_VERIFICATION_REQUIRED",
    request_id: "req_auth_problem",
    retryable: false,
  });

  assert.equal(isAuthProblemError(error), true);
  assert.equal(isAuthProblemError(error, "EMAIL_VERIFICATION_REQUIRED"), true);
  assert.equal(isAuthProblemError(error, "INVALID_CREDENTIALS"), false);
});

test("Server ClientはCookie転送とGET／HEADだけを許可する", async () => {
  let cookie;
  const client = createServerStorefrontClient({
    base_url: "https://api.example.test/api/v2",
    site_version: "1.0.0",
    default_timeout_ms: 500,
    cookie_header: "session=synthetic",
    fetch: async (_url, init) => {
      cookie = init.headers.get("Cookie");
      return jsonResponse({ data: "ok" });
    },
  });
  const head = await client.request({ path: "/me", method: "HEAD" });
  assert.equal(cookie, "session=synthetic");
  assert.equal(head.data, undefined);
  await assert.rejects(
    client.request({ path: "/mutation", method: "POST" }),
    /allows only GET and HEAD/,
  );
});

test("Package公開面はPublic ContractだけでAdmin／Webhook Exportがない", async () => {
  const packageJson = JSON.parse(
    await (await import("node:fs/promises")).readFile(
      new URL("../package.json", import.meta.url),
      "utf8",
    ),
  );
  assert.deepEqual(Object.keys(packageJson.exports).sort(), [
    ".",
    "./browser",
    "./server",
    "./types",
  ]);
  const types = await (await import("node:fs/promises")).readFile(
    new URL("../dist/types.d.ts", import.meta.url),
    "utf8",
  );
  assert.doesNotMatch(types, /admin|webhook/i);
  const generated = await (await import("node:fs/promises")).readFile(
    new URL("../src/generated/public.ts", import.meta.url),
    "utf8",
  );
  for (const operationId of [
    "listGachaCategories",
    "listGachaTags",
    "listGachas",
    "getGacha",
    "getGachaBySlug",
    "getGachaPresentation",
    "registerUser",
    "loginUser",
    "logoutUser",
    "resendUserEmailVerification",
    "verifyUserEmail",
    "getUserSession",
    "requestPasswordReset",
    "confirmPasswordReset",
    "getSmsVerificationStatus",
    "sendSmsVerification",
    "resendSmsVerification",
    "verifySmsCode",
    "startGoogleLogin",
    "completeGoogleOidc",
    "listExternalIdentities",
    "startGoogleIdentityLink",
    "startGoogleReauthentication",
    "reauthenticateUserPassword",
    "unlinkGoogleIdentity",
    "listContentBanners",
    "listContentNotices",
    "getContentNotice",
    "getContentStaticPage",
    "createContactInquiry",
    "createDraw",
    "getDrawRequest",
  ]) {
    assert.match(generated, new RegExp(operationId));
  }
  assert.doesNotMatch(generated, /beginAdminLogin|verifyAdminMfa|Webhook/);
});

test("Identity FacadeはPassword ResetとSMSをCSRF付き単一Requestで送る", async () => {
  const requests = [];
  const identity = createStorefrontIdentityClient({
    request: async (options) => {
      requests.push(options);
      return {
        data: { status: "accepted" },
        metadata: { status: 202, idempotency_replayed: false },
      };
    },
  });
  const options = { csrf_token: "d".repeat(64) };
  await identity.requestPasswordReset(
    { email: "fixture@example.test", redirect_path: "/" },
    options,
  );
  await identity.confirmPasswordReset(
    {
      user_id: "0198a001-0000-7000-8000-000000000301",
      token: "a".repeat(64),
      password: "valid fixture password",
    },
    options,
  );
  await identity.getSmsVerificationStatus();
  await identity.sendSmsVerification({ phone: "+819012345678" }, options);
  await identity.resendSmsVerification(options);
  await identity.verifySmsCode(
    {
      challenge_id: "0198a001-0000-7000-8000-000000000302",
      code: "123456",
    },
    options,
  );

  assert.deepEqual(requests.map(({ path }) => path), [
    "/auth/password/forgot",
    "/auth/password/reset",
    "/me/sms-verification",
    "/me/sms-verification",
    "/me/sms-verification/resend",
    "/me/sms-verification/verify",
  ]);
  for (const request of requests.filter(({ method }) => method === "POST")) {
    assert.equal(request.headers["X-XSRF-TOKEN"], "d".repeat(64));
    assert.equal(request.csrf, "required");
  }
});

test("Identity FacadeはGoogle／LINE Tokenを保持せずContractどおり送る", async () => {
  const requests = [];
  const identity = createStorefrontIdentityClient({
    request: async (options) => {
      requests.push(options);
      return {
        data: { status: "accepted" },
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });
  const options = { csrf_token: "e".repeat(64) };
  await identity.startGoogleLogin({ return_path: "/" }, options);
  await identity.completeGoogleOidc({
    code: "authorization-code",
    state: "a".repeat(64),
    iss: "https://accounts.google.com",
  });
  await identity.listExternalIdentities();
  await identity.startGoogleIdentityLink({ return_path: "/" }, options);
  await identity.startGoogleReauthentication({ return_path: "/" }, options);
  await identity.reauthenticateUserPassword(
    { password: "valid fixture password" },
    options,
  );
  await identity.unlinkGoogleIdentity(options);
  await identity.startLineLogin({ return_path: "/" }, options);
  await identity.completeLineLogin({
    code: "line-authorization-code",
    state: "b".repeat(64),
  });
  await identity.startLineIdentityLink({ return_path: "/" }, options);
  await identity.startLineReauthentication({ return_path: "/" }, options);
  await identity.unlinkLineIdentity(options);

  assert.equal(requests[0].path, "/auth/external/google/start");
  assert.match(
    requests[1].path,
    /^\/auth\/external\/google\/callback\?code=authorization-code&state=a{64}&iss=/,
  );
  assert.equal(requests[2].path, "/me/external-identities");
  assert.equal(requests[3].path, "/me/external-identities/google/link");
  assert.equal(
    requests[4].path,
    "/me/external-identities/google/reauthenticate",
  );
  assert.equal(requests[5].path, "/me/password/reauthenticate");
  assert.equal(requests[6].path, "/me/external-identities/google");
  assert.equal(requests[6].method, "DELETE");
  assert.equal(requests[6].headers["X-XSRF-TOKEN"], "e".repeat(64));
  assert.equal(requests[7].path, "/auth/external/line/start");
  assert.match(
    requests[8].path,
    /^\/auth\/external\/line\/callback\?code=line-authorization-code&state=b{64}$/,
  );
  assert.equal(requests[9].path, "/me/external-identities/line/link");
  assert.equal(
    requests[10].path,
    "/me/external-identities/line/reauthenticate",
  );
  assert.equal(requests[11].path, "/me/external-identities/line");
  assert.equal(requests[11].method, "DELETE");
  assert.equal(requests[11].headers["X-XSRF-TOKEN"], "e".repeat(64));
  assert.throws(
    () =>
      identity.completeGoogleOidc({
        code: "",
        state: "invalid",
      }),
    /invalid/,
  );
  assert.throws(
    () =>
      identity.completeLineLogin({
        code: "",
        state: "invalid",
      }),
    /invalid/,
  );
});

test("Content／Contact Facadeは公開GETとCSRF付き問い合わせだけを送る", async () => {
  const requests = [];
  const client = createStorefrontContentContactClient({
    request: async (options) => {
      requests.push(options);
      return {
        data: { status: "accepted" },
        metadata: { status: 202, idempotency_replayed: false },
      };
    },
  });
  await client.listBanners();
  await client.listNotices({ limit: 20, cursor: "next" });
  await client.getNotice("0198a001-0000-7000-8000-000000000201");
  await client.getStaticPage("privacy");
  await client.submitContact(
    {
      name: "Fixture User",
      email: "fixture@example.test",
      phone: null,
      subject: "Fixture inquiry",
      body: "Public-safe fixture body.",
      website: "",
    },
    { csrf_token: "c".repeat(64) },
  );

  assert.equal(requests[0].path, "/content/banners");
  assert.equal(requests[1].path, "/content/notices?limit=20&cursor=next");
  assert.equal(
    requests[2].path,
    "/content/notices/0198a001-0000-7000-8000-000000000201",
  );
  assert.equal(requests[3].path, "/content/pages/privacy");
  assert.equal(requests[4].path, "/contact-inquiries");
  assert.equal(requests[4].method, "POST");
  assert.equal(requests[4].headers["X-XSRF-TOKEN"], "c".repeat(64));
  assert.equal(requests[4].csrf, "required");
});

test("Draw Facadeは単一Bulk Requestと同じIdempotency-KeyをTransportへ渡す", async () => {
  const requests = [];
  const draw = createStorefrontDrawClient({
    request: async (options) => {
      requests.push(options);
      return {
        data: { status: "completed" },
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });
  const key = "0198a001-0000-7000-8000-000000000099";
  const csrf = "a".repeat(64);
  await draw.createDraw(
    "0198a001-0000-7000-8000-000000000011",
    1000,
    { idempotency_key: key, csrf_token: csrf, timeout_ms: 2000 },
  );
  await draw.getDrawRequest("0198a001-0000-7000-8000-000000000099");

  assert.equal(requests[0].method, "POST");
  assert.equal(
    requests[0].path,
    "/gachas/0198a001-0000-7000-8000-000000000011/draws",
  );
  assert.deepEqual(requests[0].body, { draw_count: 1000 });
  assert.equal(requests[0].idempotency_key, key);
  assert.equal(requests[0].headers["X-XSRF-TOKEN"], csrf);
  assert.equal(requests[0].csrf, "required");
  assert.equal(
    requests[1].path,
    "/draw-requests/0198a001-0000-7000-8000-000000000099",
  );
  assert.throws(
    () => draw.createDraw("valid", 2, { idempotency_key: key, csrf_token: csrf }),
    /draw_count is invalid/,
  );
});

test("Prize Shipping FacadeはUser Prize一覧／詳細のCanonical Pathだけを送る", async () => {
  const paths = [];
  const prizeShipping = createStorefrontPrizeShippingClient({
    request: async (options) => {
      paths.push(options.path);
      return {
        data: { items: [], next_cursor: null },
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });
  await prizeShipping.listPrizes("opaque-cursor");
  await prizeShipping.getPrize("0198a001-0000-7000-8000-000000000120");

  assert.deepEqual(paths, [
    "/me/prizes?cursor=opaque-cursor",
    "/me/prizes/0198a001-0000-7000-8000-000000000120",
  ]);
});

test("Catalog FacadeはPublic GETだけを決定的なPathへ送る", async () => {
  const paths = [];
  const catalog = createStorefrontCatalogClient({
    request: async (options) => {
      paths.push(options.path);
      return {
        data: { data: [] },
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });
  await catalog.listGachaCategories();
  await catalog.listGachaTags();
  await catalog.listGachas({
    limit: 20,
    cursor: "opaque-cursor",
    category: "cards",
    tag: "featured",
  });
  await catalog.getGacha("0198a001-0000-7000-8000-000000000011");
  await catalog.getGachaBySlug("fixture-catalog");
  await catalog.getGachaPresentation(
    "0198a001-0000-7000-8000-000000000011",
  );
  assert.deepEqual(paths, [
    "/gacha-categories",
    "/gacha-tags",
    "/gachas?limit=20&cursor=opaque-cursor&category=cards&tag=featured",
    "/gachas/0198a001-0000-7000-8000-000000000011",
    "/gachas/by-slug/fixture-catalog",
    "/gacha-presentations/0198a001-0000-7000-8000-000000000011",
  ]);
  assert.throws(
    () => catalog.listGachas({ limit: 101 }),
    /limit must be an integer/,
  );
});
