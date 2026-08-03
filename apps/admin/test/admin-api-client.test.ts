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

  it("reads only the typed same-origin Dashboard reporting surface", async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ days: [], month: "2026-08", summary: {} }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.getDashboardMonthlySales("2026-08");
    await client.getDashboardReversals("2026-08-01", "2026-08-31", "djE6MTA=");

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/reports/dashboard/sales/monthly?month=2026-08",
      "/admin/api/v2/reports/dashboard/reversals?cursor=djE6MTA%3D&end_date=2026-08-31&limit=50&start_date=2026-08-01",
    ]);
    for (const [, request] of fetcher.mock.calls) {
      expect(request?.credentials).toBe("include");
      expect(request?.cache).toBe("no-store");
      expect(new Headers(request?.headers).get("Authorization")).toBeNull();
      expect(new Headers(request?.headers).get("X-XSRF-TOKEN")).toBeNull();
    }
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

  it("sends Catalog mutations with CSRF and the caller-owned Idempotency-Key", async () => {
    const data = {
      archived_at: null,
      code: "cards",
      created_at: "2026-07-29T00:00:00Z",
      description: null,
      id: "01910191-0191-7191-8191-019101910191",
      is_archived: false,
      is_visible: true,
      name: "Cards",
      revision: 1,
      slug: "cards",
      sort_order: 1,
      updated_at: "2026-07-29T00:00:00Z",
    };
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(
      jsonResponse({ data, idempotent_replay: false }, 201),
    );
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.createCatalogCategory(
      {
        code: "cards",
        description: null,
        is_visible: true,
        name: "Cards",
        slug: "cards",
        sort_order: 1,
      },
      "catalog-create-key",
    );

    const [url, request] = fetcher.mock.calls[0];
    const headers = new Headers(request?.headers);
    expect(url).toBe("/admin/api/v2/catalog/categories");
    expect(request?.method).toBe("POST");
    expect(headers.get("Idempotency-Key")).toBe("catalog-create-key");
    expect(headers.get("X-XSRF-TOKEN")).toBe(csrf);
    expect(headers.get("Authorization")).toBeNull();
    expect(JSON.parse(String(request?.body))).not.toHaveProperty("code", undefined);
  });

  it("validates mutation IDs and concurrency input before transport", async () => {
    const fetcher = vi.fn<typeof fetch>();
    const client = new AdminApiClient(fetcher, () => csrf);

    await expect(
      client.archiveCatalogRank("../internal", 0, "", undefined),
    ).rejects.toMatchObject({
      code: "CATALOG_MUTATION_INVALID",
      status: 422,
    });
    expect(fetcher).not.toHaveBeenCalled();
  });

  it("uses the shared mutation transport for Prize and Presentation Asset", async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: {}, idempotent_replay: false }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: {}, idempotent_replay: false }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.createCatalogPrize(
      {
        code: "prize-a",
        description: null,
        display_price: 3000,
        exchange_points: 2000,
        is_visible: true,
        name: "Prize A",
        presentation_asset_id: null,
        rank_id: "01910191-0191-7191-8191-019101910191",
      },
      "prize-create-key",
    );
    await client.updateCatalogPresentationAsset(
      "01910191-0191-7191-8191-019101910192",
      { alt_text: "Asset A", expected_revision: 1, is_public: true },
      "asset-update-key",
    );

    expect(fetcher.mock.calls[0][0]).toBe("/admin/api/v2/catalog/prizes");
    expect(fetcher.mock.calls[1][0]).toBe(
      "/admin/api/v2/catalog/presentation-assets/01910191-0191-7191-8191-019101910192",
    );
    expect(fetcher.mock.calls[1][1]?.method).toBe("PUT");
    expect(new Headers(fetcher.mock.calls[1][1]?.headers).get("Idempotency-Key"))
      .toBe("asset-update-key");
  });

  it("uses the same-origin LINE settings surface with preview and idempotent update", async () => {
    const setting = {
      id: "01910191-0191-7191-8191-019101910191",
      linked_follow_message: "完了",
      login_relative_path: "/login",
      pending_follow_message: "{login_url}",
      reward_enabled: false,
      reward_expiration_days: 180,
      reward_point_amount: 0,
      revision: 1,
      updated_at: "2026-07-29T00:00:00Z",
    };
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: setting, request_id: setting.id }))
      .mockResolvedValueOnce(jsonResponse({
        linked_follow_message: "完了",
        pending_follow_message: "/login",
        reward_enabled: false,
        reward_expiration_days: 180,
        reward_point_amount: 0,
        request_id: setting.id,
      }))
      .mockResolvedValueOnce(jsonResponse({
        data: { ...setting, revision: 2 },
        idempotent_replay: false,
        request_id: setting.id,
      }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.getLineMessagingSetting();
    await client.previewLineMessagingSetting({
      linked_follow_message: "完了",
      pending_follow_message: "{login_url}",
      reward_enabled: false,
      reward_expiration_days: 180,
      reward_point_amount: 0,
    });
    await client.updateLineMessagingSetting(
      {
        expected_revision: 1,
        linked_follow_message: "完了",
        pending_follow_message: "{login_url}",
        reward_enabled: false,
        reward_expiration_days: 180,
        reward_point_amount: 0,
      },
      "line-setting-update-key",
    );

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/identity/line-messaging",
      "/admin/api/v2/identity/line-messaging/preview",
      "/admin/api/v2/identity/line-messaging",
    ]);
    const previewHeaders = new Headers(fetcher.mock.calls[1][1]?.headers);
    const updateHeaders = new Headers(fetcher.mock.calls[2][1]?.headers);
    expect(previewHeaders.get("X-XSRF-TOKEN")).toBe(csrf);
    expect(updateHeaders.get("Idempotency-Key")).toBe("line-setting-update-key");
    expect(updateHeaders.get("Authorization")).toBeNull();
  });

  it("uses the typed authentication policy and invitation surfaces without bearer storage", async () => {
    const setting = {
      active_owner_count: 1,
      id: "01910191-0191-7191-8191-019101910191",
      invitation_required: false,
      mfa_enrolled_admin_count: 0,
      mfa_required: false,
      revision: 1,
      updated_at: "2026-08-17T00:00:00Z",
    };
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: setting, request_id: setting.id }))
      .mockResolvedValueOnce(jsonResponse({
        data: { ...setting, mfa_required: true, revision: 2 },
        idempotent_replay: false,
        request_id: setting.id,
      }))
      .mockResolvedValueOnce(jsonResponse({
        admin: null,
        authenticated: false,
        expires_in: 300,
        methods: [],
        mfa_required: true,
        requires_mfa_enrollment: true,
        status: "enrollment_required",
        transaction_token: "d".repeat(64),
        webauthn: null,
      }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.getAuthenticationPolicy();
    await client.updateAuthenticationPolicy({
      current_password: "not-persisted",
      expected_revision: 1,
      invitation_required: false,
      mfa_required: true,
    }, "auth-policy-update-key");
    await client.acceptInvitation({
      email: "admin@example.test",
      invitation_token: "a".repeat(64),
      password: "not-persisted",
    });

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/auth/policy",
      "/admin/api/v2/auth/policy",
      "/admin/api/v2/auth/invitations/accept",
    ]);
    expect(new Headers(fetcher.mock.calls[1][1]?.headers).get("Idempotency-Key"))
      .toBe("auth-policy-update-key");
    for (const [, request] of fetcher.mock.calls) {
      expect(new Headers(request?.headers).get("Authorization")).toBeNull();
      expect(request?.credentials).toBe("include");
    }
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
