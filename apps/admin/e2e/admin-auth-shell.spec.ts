import { expect, test, type Page, type Route } from "@playwright/test";

const csrf = "a".repeat(64);
const transaction = "b".repeat(64);

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, csrf);
});

test("password pre-auth, TOTP, Fresh MFA, and logout stay in the Admin realm", async ({
  page,
}) => {
  let authenticated = false;
  let loginHeaders: Record<string, string> | null = null;
  let mfaHeaders: Record<string, string> | null = null;
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, authenticated ? adminSession() : {
        admin: null,
        authenticated: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (path.endsWith("/auth/login")) {
      loginHeaders = request.headers();
      return json(route, {
        expires_in: 300,
        methods: ["totp", "recovery_code"],
        status: "mfa_required",
        transaction_token: transaction,
        webauthn: null,
      }, 202);
    }
    if (path.endsWith("/auth/mfa/verify")) {
      mfaHeaders = request.headers();
      authenticated = true;
      return json(route, adminSession());
    }
    if (path.endsWith("/auth/reauthenticate")) {
      return json(route, {
        admin: adminIdentity(),
        authenticated: true,
        fresh_mfa_expires_in: 300,
      });
    }
    if (path.endsWith("/auth/logout")) {
      authenticated = false;
      return route.fulfill({ status: 204 });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/");
  await expect(page).toHaveURL(/\/login$/u);
  await page.getByLabel("メールアドレス").fill("owner@example.test");
  await page.getByLabel("パスワード").fill("temporary password");
  await page.getByRole("button", { name: "続行" }).click();
  await expect.poll(() => loginHeaders).not.toBeNull();
  expect(loginHeaders?.["x-xsrf-token"]).toBe(csrf);
  expect(
    (loginHeaders as Record<string, string> | null)?.authorization,
  ).toBeUndefined();
  await expect(page).toHaveURL(/\/auth\/mfa$/u);
  await page.getByLabel("認証アプリの6桁コード").fill("123456");
  await page.getByRole("button", { name: "コードを確認" }).click();

  await expect(page).toHaveURL(/\/$/u);
  expect(mfaHeaders?.["x-oripa-auth-transaction"]).toBe(transaction);
  await expect(page.getByText("Owner", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "QA Draw" }).first()).toBeVisible();
  await page.screenshot({
    fullPage: true,
    path: "/tmp/oripa-mig-060b-admin-desktop.png",
  });

  await page.getByRole("button", { name: "再確認" }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByLabel("認証アプリの6桁コード").fill("654321");
  await page.getByRole("button", { name: "再認証", exact: true }).click();
  await expect(page.getByText("Fresh", { exact: true })).toBeVisible();

  await page.getByRole("button", { name: "ログアウト" }).click();
  await expect(page).toHaveURL(/\/login$/u);
});

test("renders a generic rate-limit error and keeps credential fields ephemeral", async ({
  page,
}) => {
  await installAdminApi(page, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, { admin: null, authenticated: false });
    }
    return json(route, {
      code: "RATE_LIMITED",
      detail: "internal limiter detail",
      request_id: "01910191-0191-7191-8191-019101910191",
      retry_after: 60,
      retryable: true,
      status: 429,
      title: "Too Many Requests",
      type: "about:blank",
    }, 429, { "Content-Type": "application/problem+json", "Retry-After": "60" });
  });

  await page.goto("/login");
  await page.getByLabel("メールアドレス").fill("owner@example.test");
  const password = page.getByLabel("パスワード");
  await password.fill("not retained");
  await page.getByRole("button", { name: "続行" }).click();
  await expect(password).toHaveValue("");
  await expect(
    page.getByText("試行回数が上限に達しました。時間をおいてください。"),
  ).toBeVisible();
  await expect(page.getByText("internal limiter detail")).toHaveCount(0);

  const storageKeys = await page.evaluate(() => ({
    local: Object.keys(localStorage),
    session: Object.keys(sessionStorage),
  }));
  expect(storageKeys).toEqual({ local: [], session: [] });
});

test("mobile shell remains keyboard operable without horizontal overflow", async ({
  page,
}) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await installAdminApi(page, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, adminSession());
    if (path.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/");
  await expect(page.getByRole("link", { name: "QA Draw" }).first()).toBeVisible();
  await page.getByRole("button", { name: "ナビゲーションを開く" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("navigation", { name: "管理ナビゲーション" })).toBeVisible();
  const sidebar = page.locator(".admin-sidebar");
  await expect
    .poll(() => sidebar.evaluate((element) => getComputedStyle(element).transform))
    .toBe("matrix(1, 0, 0, 1, 0, 0)");
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
  await page.screenshot({
    fullPage: true,
    path: "/tmp/oripa-mig-060b-admin-mobile.png",
  });
});

for (const role of ["owner", "admin", "operator"] as const) {
  test(`${role} navigation follows backend effective permissions`, async ({ page }) => {
    await installAdminApi(page, async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith("/auth/session")) {
        return json(route, adminSession(role));
      }
      if (path.endsWith("/auth/permissions")) {
        return json(route, permissionResponse(role));
      }
      return route.fulfill({ status: 404 });
    });

    await page.goto("/");
    await expect(page.getByRole("link", { name: "カタログ" }).first()).toBeVisible();
    await expect(page.getByRole("link", { name: "景品・配送" }).first()).toBeVisible();
    if (role === "owner") {
      await expect(page.getByRole("link", { name: "QA Draw" }).first()).toBeVisible();
    } else {
      await expect(page.getByRole("link", { name: "QA Draw" })).toHaveCount(0);
    }
    if (role === "operator") {
      await expect(page.getByRole("link", { name: "レポート" })).toHaveCount(0);
      await page.goto("/qa");
      await expect(
        page.getByRole("heading", { name: "アクセスできません" }),
      ).toBeVisible();
    } else {
      await expect(page.getByRole("link", { name: "レポート" }).first()).toBeVisible();
    }
  });
}

test("Catalog list, search, detail, and mobile view use the Admin read contract", async ({
  page,
}) => {
  await installAdminApi(page, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("operator"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("operator"));
    }
    if (url.pathname.endsWith("/catalog/prizes")) {
      return json(route, {
        items: [
          {
            code: "fixture-s",
            created_at: "2026-07-28T00:00:00Z",
            description: "Read-only fixture",
            display_price: 10000,
            exchange_points: 8000,
            id: "01910191-0191-7191-8191-019101910194",
            is_visible: true,
            name: "Fixture S景品",
            presentation_asset: {
              alt_text: "Fixture S景品",
              id: "01910191-0191-7191-8191-019101910195",
              is_public: true,
              media_type: "image",
              mime_type: "image/png",
              public_path: "/fixture-prize.png",
            },
            rank: {
              code: "S",
              id: "01910191-0191-7191-8191-019101910196",
              name: "Sランク",
              sort_order: 10,
            },
            updated_at: "2026-07-28T00:00:00Z",
          },
        ],
        next_cursor: null,
      });
    }
    if (url.pathname.endsWith("/catalog/prizes/01910191-0191-7191-8191-019101910194")) {
      return json(route, {
        data: {
          code: "fixture-s",
          created_at: "2026-07-28T00:00:00Z",
          description: "Read-only fixture",
          display_price: 10000,
          exchange_points: 8000,
          id: "01910191-0191-7191-8191-019101910194",
          is_visible: true,
          name: "Fixture S景品",
          presentation_asset: null,
          rank: {
            code: "S",
            id: "01910191-0191-7191-8191-019101910196",
            name: "Sランク",
            sort_order: 10,
          },
          updated_at: "2026-07-28T00:00:00Z",
        },
      });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/prizes");
  await expect(page.getByRole("heading", { name: "Prize" })).toBeVisible();
  await expect(page.getByText("Fixture S景品")).toBeVisible();
  await page.getByPlaceholder("名称・Codeで検索").fill("Fixture");
  await page.getByRole("button", { name: "検索" }).click();
  await expect(page.getByText("Fixture S景品")).toBeVisible();
  await page.getByRole("link", { name: "Fixture S景品の詳細" }).click();
  await expect(page.getByText("Read-only fixture")).toBeVisible();

  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/catalog/prizes");
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
  await page.getByPlaceholder("名称・Codeで検索").focus();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("button", { name: "検索" })).toBeFocused();
});

test("Catalog master mutation sends CSRF and idempotency headers then reloads canonical data", async ({
  page,
}) => {
  let categories: Array<Record<string, unknown>> = [];
  let mutationHeaders: Record<string, string> | null = null;
  let mutationCount = 0;
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("owner"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (url.pathname.endsWith("/catalog/categories") && request.method() === "GET") {
      return json(route, { items: categories, next_cursor: null });
    }
    if (url.pathname.endsWith("/catalog/categories") && request.method() === "POST") {
      mutationCount += 1;
      mutationHeaders = request.headers();
      const input = request.postDataJSON() as {
        code: string;
        description: string | null;
        is_visible: boolean;
        name: string;
        slug: string;
        sort_order: number;
      };
      const created = {
        archived_at: null,
        code: input.code,
        created_at: "2026-07-29T00:00:00Z",
        description: input.description,
        id: "01910191-0191-7191-8191-019101910199",
        is_archived: false,
        is_visible: input.is_visible,
        name: input.name,
        revision: 1,
        slug: input.slug,
        sort_order: input.sort_order,
        updated_at: "2026-07-29T00:00:00Z",
      };
      categories = [created];
      return json(route, { data: created, idempotent_replay: false }, 201);
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/categories");
  await page.getByRole("button", { name: "新規作成" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("textbox", { name: "Code", exact: true }).fill("e2e-category");
  await dialog.getByRole("textbox", { name: "Slug", exact: true }).fill("e2e-category");
  await dialog.getByRole("textbox", { name: "名称", exact: true }).fill("E2E Category");
  await dialog
    .getByRole("textbox", { name: "説明", exact: true })
    .fill("Browser mutation fixture");
  await dialog.getByRole("button", { name: "保存" }).click();
  await expect(page.getByText("E2E Category")).toBeVisible();
  expect(mutationCount).toBe(1);
  expect(mutationHeaders?.["x-xsrf-token"]).toBe(csrf);
  expect(mutationHeaders?.["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
  expect(
    (mutationHeaders as Record<string, string> | null)?.authorization,
  ).toBeUndefined();

  await page.screenshot({
    fullPage: true,
    path: "/tmp/oripa-mig-060d-catalog-mutation.png",
  });
});

test("Operator Catalog remains read-only even on a direct module URL", async ({
  page,
}) => {
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) {
      return json(route, adminSession("operator"));
    }
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("operator"));
    }
    if (url.pathname.endsWith("/catalog/categories") && request.method() === "GET") {
      return json(route, { items: [], next_cursor: null });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/categories");
  await expect(page.getByRole("heading", { name: "Category" })).toBeVisible();
  await expect(page.getByRole("button", { name: "新規作成" })).toHaveCount(0);
});

async function installAdminApi(
  page: Page,
  handler: (route: Route) => Promise<unknown>,
): Promise<void> {
  await page.route(/\/admin\/api\/v2\/(?:auth|catalog)\/[^?]+(?:\?.*)?$/u, handler);
}

function adminIdentity(role: "owner" | "admin" | "operator" = "owner") {
  return {
    id: "01910191-0191-7191-8191-019101910191",
    mfa_verified: true,
    role,
    state: "active",
  };
}

function adminSession(role: "owner" | "admin" | "operator" = "owner") {
  return {
    admin: adminIdentity(role),
    authenticated: true,
    requires_mfa_enrollment: false,
  };
}

function permissionResponse(role: "owner" | "admin" | "operator") {
  const common = [
    "catalog.read",
    "shipping.request.manage",
    "content.read",
    "contact.read",
  ];
  return {
    permissions: [
      ...common,
      ...(role === "owner" ? ["qa.draw.manage"] : []),
      ...(role !== "operator" ? ["catalog.manage"] : []),
      ...(role !== "operator" ? ["reporting.financial.read"] : []),
    ],
    request_id: "01910191-0191-7191-8191-019101910193",
    role,
  };
}

async function json(
  route: Route,
  body: unknown,
  status = 200,
  headers: Record<string, string> = {},
): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: {
      "Cache-Control": "private, no-store",
      "Content-Type": "application/json",
      ...headers,
    },
    status,
  });
}
