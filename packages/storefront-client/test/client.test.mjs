import assert from "node:assert/strict";
import test from "node:test";

import {
  ApiProblemError,
  StorefrontTransportError,
  createBrowserStorefrontClient,
  createIdempotencyKey,
} from "../dist/browser.js";
import {
  createServerStorefrontClient,
} from "../dist/server.js";

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
  assert.equal(request.init.headers.get("X-Oripa-Client-Version"), "2.0.0-alpha.1");
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

test("Package公開面にFake Operation、Admin、Webhook Exportがない", async () => {
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
  assert.match(generated, /export type paths = Record<string, never>/);
  assert.match(generated, /export type operations = Record<string, never>/);
});
