import assert from "node:assert/strict";
import test from "node:test";

import {
  ApiProblemError,
  StorefrontTransportError,
  createBrowserStorefrontClient,
  createBrowserStorefrontContentContactClient,
  createBrowserStorefrontDrawClient,
  createBrowserStorefrontPrizeShippingClient,
  createBrowserStorefrontPaymentClient,
  createIdempotencyKey,
  isAuthProblemError,
  isDrawProblemError,
  isFulfillmentProblemError,
} from "../dist/browser.js";
import {
  createServerStorefrontClient,
} from "../dist/server.js";
import {
  createStorefrontCatalogClient,
  createStorefrontContentContactClient,
  createStorefrontDrawClient,
  createStorefrontIdentityClient,
  createStorefrontCurrentUserPointClient,
  createStorefrontPointProductClient,
  createStorefrontPaymentClient,
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
  assert.equal(request.init.headers.get("X-Oripa-Client-Version"), "2.0.0-alpha.28");
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

test("Draw Problem Codeは実在Codeだけを型付きGuardで判定する", () => {
  for (const code of [
    "INSUFFICIENT_POINTS",
    "GACHA_AUDIENCE_NOT_ELIGIBLE",
    "DAILY_DRAW_LIMIT_EXCEEDED",
    "GACHA_NOT_DRAWABLE",
    "GACHA_SALES_PAUSED",
    "DRAW_COUNT_INSUFFICIENT",
    "INVALID_DRAW_REQUEST",
    "IDEMPOTENCY_KEY_REUSED",
    "IDEMPOTENCY_REQUEST_IN_PROGRESS",
  ]) {
    const error = new ApiProblemError({
      type: `https://oripa.example/problems/${code.toLowerCase()}`,
      title: "Draw request was rejected.",
      status: 409,
      code,
      request_id: `req_${code.toLowerCase()}`,
      retryable: false,
    });
    assert.equal(isDrawProblemError(error), true);
    assert.equal(isDrawProblemError(error, code), true);
  }

  const unknown = new ApiProblemError({
    type: "https://oripa.example/problems/unknown",
    title: "Unknown failure.",
    status: 500,
    code: "UNOBSERVED_DRAW_ERROR",
    request_id: "req_unknown_draw",
    retryable: false,
  });
  assert.equal(isDrawProblemError(unknown), false);
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
    "listPointProducts",
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
    "getLineFriendState",
    "startGoogleIdentityLink",
    "startGoogleReauthentication",
    "reauthenticateUserPassword",
    "unlinkGoogleIdentity",
    "listContentBanners",
    "listContentFooterPages",
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
  await identity.getLineFriendState();
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
  assert.equal(requests[3].path, "/me/line-friend-state");
  assert.equal(requests[4].path, "/me/external-identities/google/link");
  assert.equal(
    requests[5].path,
    "/me/external-identities/google/reauthenticate",
  );
  assert.equal(requests[6].path, "/me/password/reauthenticate");
  assert.equal(requests[7].path, "/me/external-identities/google");
  assert.equal(requests[7].method, "DELETE");
  assert.equal(requests[7].headers["X-XSRF-TOKEN"], "e".repeat(64));
  assert.equal(requests[8].path, "/auth/external/line/start");
  assert.match(
    requests[9].path,
    /^\/auth\/external\/line\/callback\?code=line-authorization-code&state=b{64}$/,
  );
  assert.equal(requests[10].path, "/me/external-identities/line/link");
  assert.equal(
    requests[11].path,
    "/me/external-identities/line/reauthenticate",
  );
  assert.equal(requests[12].path, "/me/external-identities/line");
  assert.equal(requests[12].method, "DELETE");
  assert.equal(requests[12].headers["X-XSRF-TOKEN"], "e".repeat(64));
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
  await client.listFooterPages();
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
  assert.equal(requests[1].path, "/content/footer-pages");
  assert.equal(requests[2].path, "/content/notices?limit=20&cursor=next");
  assert.equal(
    requests[3].path,
    "/content/notices/0198a001-0000-7000-8000-000000000201",
  );
  assert.equal(requests[4].path, "/content/pages/privacy");
  assert.equal(requests[5].path, "/contact-inquiries");
  assert.equal(requests[5].method, "POST");
  assert.equal(requests[5].headers["X-XSRF-TOKEN"], "c".repeat(64));
  assert.equal(requests[5].csrf, "required");
  assert.equal(requests[5].retry, false);
});

test("Browser Contact Clientは匿名初回送信のCSRF境界を内部化する", async () => {
  const requests = [];
  const csrf = "c".repeat(64);
  const contact = createBrowserStorefrontContentContactClient({
    ...browserConfig(async (url, init) => {
      requests.push({ url, init });
      if (url === "/api/v2/auth/session") {
        return jsonResponse({ authenticated: false, user: null });
      }
      assert.equal(url, "/api/v2/contact-inquiries");
      assert.equal(init.headers.get("X-XSRF-TOKEN"), csrf);
      assert.equal(init.headers.get("Idempotency-Key"), null);
      return jsonResponse(
        {
          receipt_code: "CNT-0123456789ABCDEFGHIJ",
          status: "accepted",
          received_at: "2026-08-20T00:00:00Z",
          request_id: "request-contact-anonymous",
        },
        { status: 202 },
      );
    }),
    cookie_reader: () => csrf,
  });

  const result = await contact.submitContact({
    name: "Fixture User",
    email: "fixture@example.test",
    phone: null,
    subject: "Fixture inquiry",
    body: "Public-safe fixture body.",
    website: "",
  });

  assert.equal(result.metadata.status, 202);
  assert.equal(result.data.status, "accepted");
  assert.deepEqual(requests.map(({ url }) => url), [
    "/api/v2/auth/session",
    "/api/v2/contact-inquiries",
  ]);
  assert.equal(requests.every(({ init }) => init.credentials === "include"), true);
});

test("Browser Contact Clientは認証済み送信とtyped errorを保持し自動再送しない", async () => {
  for (const problem of [
    {
      type: "https://oripa.example/problems/invalid-request",
      title: "The request is invalid.",
      status: 422,
      code: "INVALID_REQUEST",
      request_id: "request-contact-validation",
      retryable: false,
      errors: { email: ["The email field must be a valid email address."] },
    },
    {
      type: "https://oripa.example/problems/rate-limited",
      title: "Too many requests.",
      status: 429,
      code: "RATE_LIMITED",
      request_id: "request-contact-rate",
      retryable: true,
      retry_after_seconds: 3600,
    },
  ]) {
    let mutationCalls = 0;
    const contact = createBrowserStorefrontContentContactClient({
      ...browserConfig(async (url) => {
        if (url === "/api/v2/auth/session") {
          return jsonResponse({
            authenticated: true,
            user: {
              id: "0198a001-0000-7000-8000-000000000501",
              state: "active",
              email_verified: true,
            },
          });
        }
        mutationCalls += 1;
        return jsonResponse(problem, {
          status: problem.status,
          headers: { "Content-Type": "application/problem+json" },
        });
      }),
      cookie_reader: () => "d".repeat(64),
    });

    await assert.rejects(
      contact.submitContact({
        name: "Fixture User",
        email: "fixture@example.test",
        phone: null,
        subject: "Fixture inquiry",
        body: "Public-safe fixture body.",
        website: "",
      }),
      (error) =>
        error instanceof ApiProblemError
        && error.code === problem.code
        && error.status === problem.status,
    );
    assert.equal(mutationCalls, 1);
  }

  const contact = createBrowserStorefrontContentContactClient({
    ...browserConfig(async (url) => {
      if (url === "/api/v2/auth/session") {
        return jsonResponse({ authenticated: true, user: null });
      }
      throw new TypeError("synthetic contact transport failure");
    }),
    cookie_reader: () => "e".repeat(64),
  });
  await assert.rejects(
    contact.submitContact({
      name: "Fixture User",
      email: "fixture@example.test",
      phone: null,
      subject: "Fixture inquiry",
      body: "Public-safe fixture body.",
      website: "",
    }),
    (error) =>
      error instanceof StorefrontTransportError
      && error.code === "NETWORK_ERROR",
  );
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

test("Draw FacadeはCurrent User履歴Read PathとOpaque Cursorだけを呼ぶ", async () => {
  const paths = [];
  const draw = createStorefrontDrawClient({
    request: async (options) => {
      paths.push(options.path);
      return {
        data: { items: [], next_cursor: null },
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });

  await draw.listDrawHistory({ limit: 20, cursor: "opaque-cursor" });
  assert.deepEqual(paths, ["/me/draws?limit=20&cursor=opaque-cursor"]);
  assert.throws(
    () => draw.listDrawHistory({ limit: 101 }),
    /limit must be an integer/,
  );
});

test("Browser Draw ClientはCSRF初期化とCookie Headerを内部化し同じKeyでRetryする", async () => {
  const requests = [];
  const key = "0198a001-0000-7000-8000-000000000099";
  const csrf = "f".repeat(64);
  let drawCalls = 0;
  const draw = createBrowserStorefrontDrawClient({
    ...browserConfig(async (url, init) => {
      requests.push({ url, init });
      if (url === "/api/v2/auth/session") {
        return jsonResponse({ authenticated: true, user: null });
      }
      if (url.includes("/draw-requests/")) {
        assert.equal(init.method, "GET");
        assert.equal(init.headers.get("Idempotency-Key"), null);
        return jsonResponse({
          id: "0198a001-0000-7000-8000-000000000099",
          status: "completed",
        });
      }
      drawCalls += 1;
      assert.equal(init.headers.get("X-XSRF-TOKEN"), csrf);
      assert.equal(init.headers.get("Idempotency-Key"), key);
      if (drawCalls === 1) {
        return jsonResponse({ unavailable: true }, { status: 503 });
      }
      return jsonResponse({
        id: "0198a001-0000-7000-8000-000000000099",
        status: "completed",
      });
    }),
    cookie_reader: () => csrf,
  });

  const result = await draw.createDraw(
    "0198a001-0000-7000-8000-000000000011",
    10,
    { idempotency_key: key },
  );

  assert.equal(result.data.id, "0198a001-0000-7000-8000-000000000099");
  const reloaded = await draw.getDrawRequest(result.data.id);
  assert.equal(reloaded.data.id, result.data.id);
  assert.deepEqual(requests.map(({ url }) => url), [
    "/api/v2/auth/session",
    "/api/v2/gachas/0198a001-0000-7000-8000-000000000011/draws",
    "/api/v2/gachas/0198a001-0000-7000-8000-000000000011/draws",
    "/api/v2/draw-requests/0198a001-0000-7000-8000-000000000099",
  ]);
  assert.equal(drawCalls, 2);
  assert.equal(requests.every(({ init }) => init.credentials === "include"), true);
});

test("Browser Draw ClientはCSRF Problemを型付きErrorとして返す", async () => {
  const csrf = "c".repeat(64);
  const draw = createBrowserStorefrontDrawClient({
    ...browserConfig(async (url) => {
      if (url === "/api/v2/auth/session") {
        return jsonResponse({ authenticated: true, user: null });
      }
      return jsonResponse(
        {
          type: "https://oripa.example/problems/csrf-token-mismatch",
          title: "CSRF token mismatch.",
          status: 419,
          code: "CSRF_TOKEN_MISMATCH",
          request_id: "req_draw_csrf",
          retryable: false,
        },
        { status: 419, headers: { "Content-Type": "application/problem+json" } },
      );
    }),
    cookie_reader: () => csrf,
  });

  await assert.rejects(
    draw.createDraw(
      "0198a001-0000-7000-8000-000000000011",
      1,
      { idempotency_key: "draw-csrf-fixture-key" },
    ),
    (error) => isDrawProblemError(error, "CSRF_TOKEN_MISMATCH"),
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

test("Browser Fulfillment ClientはCSRFを隠蔽し冪等Mutationだけ同一Keyで再試行する", async () => {
  const requests = [];
  const addressKey = "shipping-address-browser-key-0001";
  const exchangeKey = "prize-exchange-browser-key-0001";
  const shippingKey = "shipping-request-browser-key-0001";
  let createAttempts = 0;
  const client = createBrowserStorefrontPrizeShippingClient({
    ...browserConfig(async (url, init) => {
      requests.push({ url, init });
      if (url === "/api/v2/auth/session") {
        return jsonResponse({ authenticated: true, user: null });
      }
      if (url === "/api/v2/me/shipping-addresses" && init.method === "POST") {
        createAttempts += 1;
        if (createAttempts === 1) {
          return jsonResponse({ unavailable: true }, { status: 503 });
        }
        return jsonResponse({ id: "address-public-id" }, { status: 201 });
      }
      if (url.endsWith("/address-public-id") && init.method === "GET") {
        return jsonResponse({ id: "address-public-id" });
      }
      return jsonResponse({ id: "mutation-result" });
    }),
    cookie_reader: () => "f".repeat(64),
  });

  await client.createShippingAddress(
    {
      recipient_name: "Fixture User",
      postal_code: "000-0000",
      prefecture: "Fixture",
      city: "Fixture City",
      street: "1-2-3",
      building: null,
      phone_number: "000-0000-0000",
    },
    { idempotency_key: addressKey },
  );
  await client.getShippingAddress("address-public-id");
  await client.exchangePrizes(
    ["prize-public-id"],
    { idempotency_key: exchangeKey },
  );
  await client.createShippingRequest(
    "address-public-id",
    ["prize-public-id"],
    { idempotency_key: shippingKey },
  );

  const mutations = requests.filter(({ init }) => init.method === "POST");
  assert.equal(createAttempts, 2);
  assert.deepEqual(
    mutations.map(({ init }) => init.headers.get("Idempotency-Key")),
    [addressKey, addressKey, exchangeKey, shippingKey],
  );
  assert.equal(
    mutations.every(({ init }) => init.headers.get("X-XSRF-TOKEN") === "f".repeat(64)),
    true,
  );
  assert.equal(requests.every(({ init }) => init.credentials === "include"), true);
});

test("Address update／deleteは通信結果不明時に自動再送せずGET照合を利用できる", async () => {
  let mutationCalls = 0;
  const client = createBrowserStorefrontPrizeShippingClient({
    ...browserConfig(async (url, init) => {
      if (url === "/api/v2/auth/session") {
        return jsonResponse({ authenticated: true, user: null });
      }
      if (["PUT", "DELETE"].includes(init.method)) {
        mutationCalls += 1;
        throw new TypeError("synthetic unknown network result");
      }
      return jsonResponse({ id: "address-public-id" });
    }),
    cookie_reader: () => "e".repeat(64),
  });
  const address = {
    recipient_name: "Fixture User",
    postal_code: "000-0000",
    prefecture: "Fixture",
    city: "Fixture City",
    street: "1-2-3",
    building: null,
    phone_number: "000-0000-0000",
  };

  await assert.rejects(
    client.updateShippingAddress("address-public-id", address),
    (error) => error instanceof StorefrontTransportError && error.code === "NETWORK_ERROR",
  );
  await assert.rejects(
    client.deleteShippingAddress("address-public-id"),
    (error) => error instanceof StorefrontTransportError && error.code === "NETWORK_ERROR",
  );
  assert.equal(mutationCalls, 2);
  const reconciled = await client.getShippingAddress("address-public-id");
  assert.equal(reconciled.data.id, "address-public-id");
});

test("Fulfillment Problem Codeは実在Codeだけを型付きGuardで判定する", () => {
  for (const code of [
    "PRIZE_NOT_EXCHANGEABLE",
    "PRIZE_NOT_SHIPPABLE",
    "PRIZE_ON_PAYMENT_HOLD",
    "IDEMPOTENCY_KEY_REUSED",
    "CONCURRENT_OPERATION_RETRY_EXHAUSTED",
    "SHIPPING_ADDRESS_NOT_FOUND",
  ]) {
    const error = new ApiProblemError({
      type: `https://oripa.example/problems/${code.toLowerCase()}`,
      title: "Fulfillment request was rejected.",
      status: 409,
      code,
      request_id: `req_${code.toLowerCase()}`,
      retryable: false,
    });
    assert.equal(isFulfillmentProblemError(error), true);
    assert.equal(isFulfillmentProblemError(error, code), true);
  }

  const unknown = new ApiProblemError({
    type: "https://oripa.example/problems/unknown",
    title: "Unknown failure.",
    status: 500,
    code: "UNOBSERVED_FULFILLMENT_ERROR",
    request_id: "req_unknown_fulfillment",
    retryable: false,
  });
  assert.equal(isFulfillmentProblemError(unknown), false);
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
  await catalog.getGacha("Ab3Def7Gh9J");
  await catalog.getGachaBySlug("fixture-catalog");
  await catalog.getGachaPresentation(
    "Ab3Def7Gh9J",
  );
  assert.deepEqual(paths, [
    "/gacha-categories",
    "/gacha-tags",
    "/gachas?limit=20&cursor=opaque-cursor&category=cards&tag=featured",
    "/gachas/Ab3Def7Gh9J",
    "/gachas/by-slug/fixture-catalog",
    "/gacha-presentations/Ab3Def7Gh9J",
  ]);
  assert.throws(
    () => catalog.listGachas({ limit: 101 }),
    /limit must be an integer/,
  );
});

test("Point Product FacadeはCanonical一覧Pathだけを呼ぶ", async () => {
  const paths = [];
  const products = createStorefrontPointProductClient({
    request: async (options) => {
      paths.push(options.path);
      return {
        data: { data: [] },
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });

  await products.listPointProducts();
  assert.deepEqual(paths, ["/point-products"]);
});

test("Current User Point Facadeは認証済みRead PathとCursorだけを呼ぶ", async () => {
  const paths = [];
  const points = createStorefrontCurrentUserPointClient({
    request: async (options) => {
      paths.push(options.path);
      return {
        data: options.path === "/me/wallet"
          ? { paid_points: 800, free_points: 200, total_points: 1000 }
          : { items: [], next_cursor: null },
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });

  await points.getWallet();
  await points.listPointLedgerEntries({ limit: 20, cursor: "opaque-cursor" });
  assert.deepEqual(paths, [
    "/me/wallet",
    "/me/point-ledgers?limit=20&cursor=opaque-cursor",
  ]);
  assert.throws(
    () => points.listPointLedgerEntries({ limit: 101 }),
    /limit must be an integer/,
  );
});

test("Payment FacadeはBootstrap・作成・履歴・未払い再開・カード管理Pathを固定する", async () => {
  const requests = [];
  const payments = createStorefrontPaymentClient({
    request: async (options) => {
      requests.push(options);
      return {
        data: options.path === "/payments/payment-id"
          ? {
              grant: {
                paid_points: 10000,
                bonus_points: 1000,
                limited_bonus_points: 2000,
                total_points: 13000,
              },
            }
          : {},
        metadata: { status: 200, idempotency_replayed: false },
      };
    },
  });
  const csrfToken = "a".repeat(64);
  await payments.getPaymentCardUiBootstrap();
  await payments.startPayment(
    { point_product_id: "plan-id", payment_method: "paypay" },
    { csrf_token: csrfToken, idempotency_key: "start-key" },
  );
  const payment = await payments.getPayment("payment-id");
  await payments.listPayments({ view: "unpaid", limit: 20, cursor: "next" });
  await payments.resumeUnpaidPayment("payment-id", { csrf_token: csrfToken });
  await payments.listCards();
  await payments.createCardRegistrationIntent({
    csrf_token: csrfToken,
    idempotency_key: "card-intent-key",
  });
  await payments.completeCardRegistration(
    "intent-id",
    { provider_card_id: "provider-card-id" },
    { csrf_token: csrfToken },
  );
  await payments.deleteCard("card-id", { csrf_token: csrfToken });
  assert.deepEqual(requests.map(({ path }) => path), [
    "/me/payment-card-ui-bootstrap",
    "/payments",
    "/payments/payment-id",
    "/me/payments?view=unpaid&limit=20&cursor=next",
    "/payments/payment-id/resume",
    "/me/payment-cards",
    "/me/payment-card-registration-intents",
    "/me/payment-card-registration-intents/intent-id/complete",
    "/me/payment-cards/card-id",
  ]);
  assert.equal(requests[0].method, undefined);
  assert.equal(requests[0].csrf, undefined);
  assert.equal(requests[1].idempotency_key, "start-key");
  assert.equal(requests[4].retry, false);
  assert.deepEqual(payment.data.grant, {
    paid_points: 10000,
    bonus_points: 1000,
    limited_bonus_points: 2000,
    total_points: 13000,
  });
});

test("Browser Payment FacadeはCSRFをTransport管理へ委譲する", async () => {
  const calls = [];
  const client = createBrowserStorefrontPaymentClient(browserConfig(async (url, init) => {
    calls.push({ url, init });
    if (url.endsWith("/sanctum/csrf-cookie")) {
      return jsonResponse({ initialized: true });
    }
    return jsonResponse({ id: "payment-id" }, { status: 201 });
  }));
  await client.getPaymentCardUiBootstrap();
  assert.equal(calls.at(-1).url, "/api/v2/me/payment-card-ui-bootstrap");
  assert.equal(calls.at(-1).init.method, "GET");
  await client.startPayment(
    { point_product_id: "plan-id", payment_method: "virtual_account" },
    { idempotency_key: "browser-payment-key" },
  );
  assert.equal(calls.at(-1).url, "/api/v2/payments");
  assert.equal(calls.at(-1).init.headers.get("Idempotency-Key"), "browser-payment-key");
});
