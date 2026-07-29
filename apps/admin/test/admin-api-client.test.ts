import { describe, expect, it, vi } from "vitest";

import {
  AdminApiClient,
  AdminApiError,
  readAdminCsrfCookie,
} from "@/lib/admin-api/client";

const csrf = "a".repeat(64);

describe("AdminApiClient", () => {
  it("uses only the same-origin Admin API with cookie credentials and CSRF", async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(
      jsonResponse({
        expires_in: 300,
        methods: ["totp"],
        status: "mfa_required",
        transaction_token: "b".repeat(64),
        webauthn: null,
      }, 202),
    );
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.login({ email: "admin@example.test", password: "not-persisted" });

    expect(fetcher).toHaveBeenCalledOnce();
    const [url, request] = fetcher.mock.calls[0];
    expect(url).toBe("/admin/api/v2/auth/login");
    expect(request?.credentials).toBe("include");
    expect(request?.cache).toBe("no-store");
    const headers = new Headers(request?.headers);
    expect(headers.get("X-XSRF-TOKEN")).toBe(csrf);
    expect(headers.get("Authorization")).toBeNull();
    expect(headers.get("X-Request-Id")).toMatch(/^[0-9a-f-]{36}$/u);
  });

  it("rejects paths outside the Admin authentication surface", async () => {
    const client = new AdminApiClient(vi.fn(), () => csrf);
    await expect(
      Reflect.get(client, "request").call(client, "GET", "https://example.test/api"),
    ).rejects.toThrow("outside the approved surface");
  });

  it("retrieves effective permissions without CSRF or bearer tokens", async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(
      jsonResponse({
        permissions: ["catalog.read"],
        request_id: "01910191-0191-7191-8191-019101910191",
        role: "operator",
      }),
    );
    const client = new AdminApiClient(fetcher, () => csrf);

    await expect(client.getPermissions()).resolves.toMatchObject({
      permissions: ["catalog.read"],
      role: "operator",
    });
    const [url, request] = fetcher.mock.calls[0];
    expect(url).toBe("/admin/api/v2/auth/permissions");
    expect(request?.credentials).toBe("include");
    const headers = new Headers(request?.headers);
    expect(headers.get("X-XSRF-TOKEN")).toBeNull();
    expect(headers.get("Authorization")).toBeNull();
  });

  it("reads only the same-origin Admin Catalog surface with encoded filters", async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(
      jsonResponse({ items: [], next_cursor: null }),
    );
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listCatalogPrizes({
      direction: "asc",
      q: "S Prize",
      sort: "rank",
      visibility: "visible",
    });

    const [url, request] = fetcher.mock.calls[0];
    expect(url).toBe(
      "/admin/api/v2/catalog/prizes?direction=asc&q=S+Prize&sort=rank&visibility=visible",
    );
    expect(request?.credentials).toBe("include");
    expect(new Headers(request?.headers).get("Authorization")).toBeNull();
    expect(new Headers(request?.headers).get("X-XSRF-TOKEN")).toBeNull();
  });

  it("rejects malformed Catalog detail IDs before transport", async () => {
    const fetcher = vi.fn<typeof fetch>();
    const client = new AdminApiClient(fetcher, () => csrf);

    await expect(client.getCatalogPrize("../internal")).rejects.toMatchObject({
      code: "CATALOG_RESOURCE_NOT_FOUND",
      status: 404,
    });
    expect(fetcher).not.toHaveBeenCalled();
  });

  it("converts RFC 9457 responses without exposing server detail", async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(
      new Response(
        JSON.stringify({
          code: "FRESH_AUTHENTICATION_REQUIRED",
          detail: "internal detail must not be shown",
          request_id: "01910191-0191-7191-8191-019101910191",
          retryable: false,
          status: 403,
          title: "Forbidden",
          type: "about:blank",
        }),
        {
          headers: { "Content-Type": "application/problem+json" },
          status: 403,
        },
      ),
    );
    const client = new AdminApiClient(fetcher, () => csrf);

    const error = await client
      .regenerateRecoveryCodes()
      .catch((cause: unknown) => cause);

    expect(error).toBeInstanceOf(AdminApiError);
    expect(error).toMatchObject({
      code: "FRESH_AUTHENTICATION_REQUIRED",
      requiresFreshMfa: true,
      status: 403,
    });
    expect((error as Error).message).not.toContain("internal detail");
  });

  it("honors AbortSignal and never reaches the transport", async () => {
    const fetcher = vi.fn<typeof fetch>().mockImplementation(
      (_input, request) =>
        new Promise((_resolve, reject) => {
          request?.signal?.addEventListener("abort", () =>
            reject(new DOMException("Aborted", "AbortError")),
          );
        }),
    );
    const client = new AdminApiClient(fetcher, () => csrf);
    const controller = new AbortController();
    controller.abort();

    await expect(client.getSession(controller.signal)).rejects.toMatchObject({
      code: "REQUEST_ABORTED",
    });
  });

  it("reads only the Admin CSRF cookie", () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: `__Host-oripa_user_xsrf=${"b".repeat(64)}; __Host-oripa_admin_xsrf=${csrf}`,
    });
    expect(readAdminCsrfCookie()).toBe(csrf);
  });
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    headers: { "Content-Type": "application/json" },
    status,
  });
}
