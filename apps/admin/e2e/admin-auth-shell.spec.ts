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

async function installAdminApi(
  page: Page,
  handler: (route: Route) => Promise<unknown>,
): Promise<void> {
  await page.route(/\/admin\/api\/v2\/auth\/[^?]+(?:\?.*)?$/u, handler);
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
