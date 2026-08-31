import { describe, expect, it, vi } from "vitest";

import {
  AdminApiClient,
  AdminApiError,
  initialListFilter,
  readAdminCsrfCookie,
} from "@/lib/admin-api/client";

const csrf = "a".repeat(64);

describe("AdminApiClient", () => {
  it("uses the canonical list default without an explicit query", () => {
    const allowed = ["published,draft", "published", "draft"] as const;

    expect(initialListFilter(undefined, allowed, "published,draft")).toBe("published,draft");
  });

  it("prefers a canonical list query without persisting prior state", () => {
    const allowed = ["published,draft", "published", "draft"] as const;

    expect(initialListFilter("draft", allowed, "published,draft")).toBe("draft");
    expect(initialListFilter(undefined, allowed, "published,draft")).toBe("published,draft");
  });

  it("falls back to the list default for unknown query values", () => {
    const allowed = ["published,draft", "published", "draft"] as const;

    expect(initialListFilter("unknown", allowed, "published,draft")).toBe("published,draft");
  });

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

  it("uses typed same-origin Mail Template read, preview, and OCC update paths", async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [] }))
      .mockResolvedValueOnce(jsonResponse({ key: "shipping_requested" }))
      .mockResolvedValueOnce(jsonResponse({ body_html: "<p>preview</p>" }))
      .mockResolvedValueOnce(jsonResponse({ key: "shipping_requested", revision: 2 }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listMailTemplates();
    await client.getMailTemplate("shipping_requested");
    await client.previewMailTemplate("shipping_requested", { body_html: "<p>{{user_name}}</p>" });
    await client.updateMailTemplate("shipping_requested", {
      body_html: "<p>{{user_name}}</p>",
      expected_revision: 1,
      subject: "Subject",
    }, "mail-template-update-key");

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/mail-templates",
      "/admin/api/v2/mail-templates/shipping_requested",
      "/admin/api/v2/mail-templates/shipping_requested/preview",
      "/admin/api/v2/mail-templates/shipping_requested",
    ]);
    expect(fetcher.mock.calls[2][1]?.method).toBe("POST");
    expect(fetcher.mock.calls[3][1]?.method).toBe("PUT");
    expect(new Headers(fetcher.mock.calls[3][1]?.headers).get("Idempotency-Key"))
      .toBe("mail-template-update-key");
    expect(new Headers(fetcher.mock.calls[3][1]?.headers).get("X-XSRF-TOKEN")).toBe(csrf);
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

  it("reads User list, detail, gacha history, and referral history only through public IDs", async () => {
    const userId = "01910191-0191-7191-8191-019101910191";
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: { id: userId } }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null, user_id: userId }))
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null, user_id: userId }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listAdminUsers({
      cursor: "djE6MTA=",
      date_from: "2026-08-01",
      date_to: "2026-08-31",
      status: "verification_failed",
      user_id: userId,
    });
    await client.getAdminUser(userId);
    await client.listAdminUserGachaHistory(userId);
    await client.listAdminUserReferralHistory(userId, "djE6MTA=");

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      `/admin/api/v2/users?limit=50&cursor=djE6MTA%3D&date_from=2026-08-01&date_to=2026-08-31&status=verification_failed&user_id=${userId}`,
      `/admin/api/v2/users/${userId}`,
      `/admin/api/v2/users/${userId}/gacha-history?limit=50`,
      `/admin/api/v2/users/${userId}/referral-history?limit=50&cursor=djE6MTA%3D`,
    ]);
    for (const [, request] of fetcher.mock.calls) {
      expect(request?.credentials).toBe("include");
      expect(request?.cache).toBe("no-store");
      expect(new Headers(request?.headers).get("Authorization")).toBeNull();
      expect(new Headers(request?.headers).get("X-XSRF-TOKEN")).toBeNull();
    }
  });

  it("reads all-user and user-specific Payment history with canonical filters", async () => {
    const userId = "01910191-0191-7191-8191-019101910191";
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: [], pagination: { next_cursor: null } }))
      .mockResolvedValueOnce(jsonResponse({ data: [], pagination: { next_cursor: null } }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listAdminPayments({
      cursor: "djE6MTA=",
      limit: 20,
      payment_method: "konbini",
      status: "processing",
    });
    await client.listAdminUserPayments(userId, {
      payment_method: "credit_card",
      status: "succeeded",
    });

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/payments?limit=20&cursor=djE6MTA%3D&status=processing&payment_method=konbini",
      `/admin/api/v2/users/${userId}/payments?limit=20&status=succeeded&payment_method=credit_card`,
    ]);
    for (const [, request] of fetcher.mock.calls) {
      expect(request?.credentials).toBe("include");
      expect(request?.cache).toBe("no-store");
      expect(new Headers(request?.headers).get("Authorization")).toBeNull();
      expect(new Headers(request?.headers).get("X-XSRF-TOKEN")).toBeNull();
    }
  });

  it("reads global User Prize list and detail with typed filters and public IDs", async () => {
    const userPrizeId = "01910191-0191-7191-8191-019101910195";
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: { id: userPrizeId } }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listAdminUserPrizes({
      cursor: "djE6MTA=",
      gacha: "TESTGACHA01",
      limit: 20,
      prize_name: "Prize A",
      status: "stored",
      user: "Test User",
    });
    await client.getAdminUserPrize(userPrizeId);

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/user-prizes?limit=20&cursor=djE6MTA%3D&user=Test+User&prize_name=Prize+A&gacha=TESTGACHA01&status=stored",
      `/admin/api/v2/user-prizes/${userPrizeId}`,
    ]);
    for (const [, request] of fetcher.mock.calls) {
      expect(request?.credentials).toBe("include");
      expect(request?.cache).toBe("no-store");
      expect(new Headers(request?.headers).get("X-XSRF-TOKEN")).toBeNull();
    }
  });

  it("rejects internal or malformed User identifiers before transport", async () => {
    const fetcher = vi.fn<typeof fetch>();
    const client = new AdminApiClient(fetcher, () => csrf);

    await expect(client.getAdminUser("42")).rejects.toMatchObject({
      code: "ADMIN_USER_NOT_FOUND",
      status: 404,
    });
    await expect(client.listAdminUserGachaHistory("../internal")).rejects.toMatchObject({
      code: "ADMIN_USER_NOT_FOUND",
      status: 404,
    });
    await expect(client.listAdminUserPayments("../internal")).rejects.toMatchObject({
      code: "ADMIN_USER_NOT_FOUND",
      status: 404,
    });
    expect(fetcher).not.toHaveBeenCalled();
  });

  it("uses typed User Tag reads and mutations with public IDs and CSRF", async () => {
    const userId = "01910191-0191-7191-8191-019101910191";
    const tagId = "01910191-0191-7191-8191-019101910192";
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: { revision: 1, tags: [], user_id: userId } }))
      .mockResolvedValueOnce(jsonResponse({ data: { revision: 2, tags: [], user_id: userId }, idempotent_replay: false }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listUserTags();
    await client.getUserTags(userId);
    await client.assignUserTag(userId, tagId, { expected_revision: 1 }, "tag-key");

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/user-tags?limit=50",
      `/admin/api/v2/users/${userId}/tags`,
      `/admin/api/v2/users/${userId}/tags/${tagId}`,
    ]);
    const mutation = fetcher.mock.calls[2][1];
    expect(mutation?.method).toBe("POST");
    expect(new Headers(mutation?.headers).get("Idempotency-Key")).toBe("tag-key");
    expect(new Headers(mutation?.headers).get("X-XSRF-TOKEN")).toBe(csrf);
  });

  it("updates User state with CSRF, OCC, and an explicit idempotency key", async () => {
    const userId = "01910191-0191-7191-8191-019101910191";
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({
      data: { user_id: userId, status: "suspended", state_revision: 2 },
      idempotent_replay: false,
    }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.updateAdminUserState(userId, {
      status: "suspended",
      expected_revision: 1,
      reason: "Support review.",
    }, "user-state-key");

    const [url, request] = fetcher.mock.calls[0];
    expect(url).toBe(`/admin/api/v2/users/${userId}/state`);
    expect(request?.method).toBe("PUT");
    const headers = new Headers(request?.headers);
    expect(headers.get("X-XSRF-TOKEN")).toBe(csrf);
    expect(headers.get("Idempotency-Key")).toBe("user-state-key");
    expect(JSON.parse(String(request?.body))).toEqual({
      status: "suspended",
      expected_revision: 1,
      reason: "Support review.",
    });
  });

  it("uses typed QA test mode and Gacha guarantee endpoints", async () => {
    const userId = "01910191-0191-7191-8191-019101910191";
    const prizeId = "01910191-0191-7191-8191-019101910192";
    const gachaId = "A7k9P2x4Qm8";
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ mode: null, user_id: userId }))
      .mockResolvedValueOnce(jsonResponse({ gacha_id: gachaId, items: [], prizes: [], test_users: [] }))
      .mockResolvedValueOnce(jsonResponse({ data: {}, idempotent_replay: false }))
      .mockResolvedValueOnce(jsonResponse({ data: {}, idempotent_replay: false }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.getQaTestUserMode(userId);
    await client.getQaGachaGuarantees(gachaId);
    await client.saveQaGachaGuarantee(
      gachaId,
      { prize_id: prizeId, user_id: userId },
      "qa-guarantee-save-key",
    );
    await client.disableQaGachaGuarantee(
      gachaId,
      userId,
      3,
      "qa-guarantee-disable-key",
    );

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      `/admin/api/v2/users/${userId}/qa-mode`,
      `/admin/api/v2/catalog/gachas/${gachaId}/qa-guarantees`,
      `/admin/api/v2/catalog/gachas/${gachaId}/qa-guarantees`,
      `/admin/api/v2/catalog/gachas/${gachaId}/qa-guarantees/${userId}/disable`,
    ]);
    expect(new Headers(fetcher.mock.calls[2][1]?.headers).get("Idempotency-Key"))
      .toBe("qa-guarantee-save-key");
    expect(new Headers(fetcher.mock.calls[3][1]?.headers).get("X-XSRF-TOKEN")).toBe(csrf);
  });

  it("sends point adjustments with CSRF and an explicit idempotency key", async () => {
    const userId = "01910191-0191-7191-8191-019101910191";
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({
      data: { adjustment_public_id: "01910191-0191-7191-8191-019101910192" },
      idempotent_replay: false,
      request_id: "01910191-0191-7191-8191-019101910193",
    }));
    const client = new AdminApiClient(fetcher, () => csrf);
    await client.adjustAdminUserPoints(userId, {
      point_type: "paid",
      direction: "grant",
      amount: 100,
      reason: "Correction",
      current_password: "not-persisted",
    }, "adjustment-key");

    const [url, request] = fetcher.mock.calls[0];
    expect(url).toBe(`/admin/api/v2/users/${userId}/point-adjustments`);
    expect(request?.method).toBe("POST");
    const headers = new Headers(request?.headers);
    expect(headers.get("X-XSRF-TOKEN")).toBe(csrf);
    expect(headers.get("Idempotency-Key")).toBe("adjustment-key");
    expect(JSON.parse(String(request?.body))).toMatchObject({
      amount: 100,
      current_password: "not-persisted",
    });
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

  it("classifies revision, published-reference, and validation problems distinctly", async () => {
    const problems = [
      [
        409,
        "CATALOG_REVISION_CONFLICT",
        "別の操作で更新されています。最新状態を再取得してください。",
      ],
      [
        409,
        "CATALOG_PUBLISHED_REFERENCE_CONFLICT",
        "公開中Gachaから参照されているため、この項目は変更できません。",
      ],
      [422, "CATALOG_MUTATION_INVALID", "入力内容を確認してください。"],
    ] as const;
    const fetcher = vi.fn<typeof fetch>();
    for (const [status, code] of problems) {
      fetcher.mockResolvedValueOnce(jsonResponse({ code, retryable: false }, status));
    }
    const client = new AdminApiClient(fetcher, () => csrf);
    const input = {
      description: null,
      expected_revision: 1,
      is_visible: true,
      name: "Cards",
      slug: "cards",
      sort_order: 1,
    };

    for (const [, code, message] of problems) {
      await expect(
        client.updateCatalogCategory(
          "01910191-0191-7191-8191-019101910191",
          input,
          crypto.randomUUID(),
        ),
      ).rejects.toMatchObject({ code, message });
    }
  });

  it("creates a Gacha core Draft through the dedicated same-origin mutation", async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(
      jsonResponse({ data: { id: "01910191-0191-7191-8191-019101910199" } }, 201),
    );
    const client = new AdminApiClient(fetcher, () => csrf);
    await client.createCatalogGachaCore(
      {
        audience_code: "all_users",
        category_id: "01910191-0191-7191-8191-019101910191",
        daily_draw_limit: 0,
        description: null,
        notices: null,
        presentation_asset_id: "01910191-0191-7191-8191-019101910194",
        price_points: 100,
        publish_end_at: null,
        publish_start_at: "2026-08-20T00:00:00.000Z",
        tag_ids: [],
        title: "Core Draft",
        total_count: 1000,
      },
      "gacha-core-key",
    );

    const [url, request] = fetcher.mock.calls[0];
    expect(url).toBe("/admin/api/v2/catalog/gachas/core");
    expect(request?.method).toBe("POST");
    expect(new Headers(request?.headers).get("Idempotency-Key")).toBe(
      "gacha-core-key",
    );
    expect(JSON.parse(String(request?.body))).not.toHaveProperty("state");
  });

  it("validates mutation IDs and concurrency input before transport", async () => {
    const fetcher = vi.fn<typeof fetch>();
    const client = new AdminApiClient(fetcher, () => csrf);

    await expect(
      client.updateCatalogRank(
        "../internal",
        {
          expected_revision: 0,
          rank_name: "Rank",
          show_total_stock: false,
          status: "active",
        },
        "",
        undefined,
      ),
    ).rejects.toMatchObject({
      code: "CATALOG_MUTATION_INVALID",
      status: 422,
    });
    expect(fetcher).not.toHaveBeenCalled();
  });

  it("uses the rank-fixed Prize transport and shared Presentation Asset transport", async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ data: {}, idempotent_replay: false }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: {}, idempotent_replay: false }));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.createGachaRankPrize(
      "01910191-0191-7191-8191-019101910190",
      "01910191-0191-7191-8191-019101910191",
      "01910191-0191-7191-8191-019101910192",
      {
        cost_price: 1000,
        exchange_points: 2000,
        expected_version_revision: 1,
        is_active: true,
        name: "Prize A",
        presentation_asset_id: null,
        total_inventory: 10,
      },
      "prize-create-key",
    );
    await client.updateCatalogPresentationAsset(
      "01910191-0191-7191-8191-019101910192",
      { alt_text: "Asset A", expected_revision: 1, is_public: true },
      "asset-update-key",
    );

    expect(fetcher.mock.calls[0][0]).toBe(
      "/admin/api/v2/catalog/gachas/01910191-0191-7191-8191-019101910190/versions/01910191-0191-7191-8191-019101910191/ranks/01910191-0191-7191-8191-019101910192/prizes",
    );
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

  it("uses the dedicated rank effect read and idempotent mutation endpoints", async () => {
    const id = "01910191-0191-7191-8191-019101910191";
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null }))
      .mockResolvedValueOnce(jsonResponse({ data: { id }, idempotent_replay: false }, 201));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listRankEffects({ direction: "desc", limit: 20, sort: "created_at" });
    await client.createRankEffect({
      asset_type: "image",
      content_base64: "aGVsbG8=",
      file_name: "effect.png",
      is_active: true,
      mime_type: "image/png",
      title: "当選演出",
    }, "rank-effect-create-key");

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/catalog/rank-effects?direction=desc&limit=20&sort=created_at",
      "/admin/api/v2/catalog/rank-effects",
    ]);
    expect(new Headers(fetcher.mock.calls[1][1]?.headers).get("Idempotency-Key"))
      .toBe("rank-effect-create-key");
  });

  it("uses the point purchase read surface and idempotent management endpoints", async () => {
    const id = "01910191-0191-7191-8191-019101910191";
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [], next_cursor: null, request_id: id }))
      .mockResolvedValueOnce(jsonResponse({ data: { id }, idempotent_replay: false, request_id: id }, 201));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listPointPurchasePlans({ status: "published" });
    await client.createPointPurchasePlan({
      amount: 1000,
      audience_code: "all_users",
      available_from: null,
      available_until: null,
      free_point_amount: 100,
      is_active: true,
      name: "スタンダード",
      paid_point_amount: 1000,
      sort_order: 10,
    }, "point-purchase-create-key");

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/point-purchase-plans?limit=20&status=published",
      "/admin/api/v2/point-purchase-plans",
    ]);
    expect(new Headers(fetcher.mock.calls[1][1]?.headers).get("Idempotency-Key"))
      .toBe("point-purchase-create-key");
  });

  it("serializes canonical multi-status Admin list filters", async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockImplementation(() => Promise.resolve(jsonResponse({ items: [], next_cursor: null })));
    const client = new AdminApiClient(fetcher, () => csrf);

    await client.listCatalogGachas({ management_status: "published,draft" });
    await client.listContentNotices({ status: "published,draft" });
    await client.listManagedPages({ status: "published,draft" });

    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([
      "/admin/api/v2/catalog/gachas?management_status=published%2Cdraft",
      "/admin/api/v2/content/notices?limit=20&status=published%2Cdraft",
      "/admin/api/v2/page-management/pages?limit=20&status=published%2Cdraft",
    ]);
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
