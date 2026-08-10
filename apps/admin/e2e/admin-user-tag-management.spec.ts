import { expect, test, type Page, type Route } from "@playwright/test";

const userId = uuid("1");

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, "a".repeat(64));
  await installApi(page);
});

test("desktop tag master is usable and does not expose internal IDs", async ({ page }) => {
  const errors = observeErrors(page);
  expect((await page.goto("/users/tags"))?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "会員タグ管理" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText(["タグ名", "状態", "更新日", "編集"]);
  await expect(page.getByText("VIP")).toBeVisible();
  await expect(page.getByRole("button", { name: "VIPを編集" })).toBeVisible();
  expect(await page.locator("body").textContent()).not.toContain("user_tag_id");
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile user detail retains inactive tags and prevents new inactive assignment", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  expect((await page.goto(`/users/${userId}`))?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "会員タグ" })).toBeVisible();
  await expect(page.getByText("Legacy（無効）")).toBeVisible();
  await page.getByRole("button", { name: "タグを管理" }).click();
  await expect(page.getByRole("dialog", { name: "会員タグを管理" })).toBeVisible();
  await expect(page.getByRole("button", { name: "付与不可" })).toBeDisabled();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: uuid("9"), mfa_verified: true, role: "admin", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ["user.tag.read", "user.tag.manage", "point.ledger.read"],
        request_id: uuid("9"),
        role: "admin",
      });
    }
    if (url.pathname.endsWith("/user-tags")) {
      return json(route, { items: tags(), next_cursor: null, request_id: uuid("9") });
    }
    if (url.pathname === `/admin/api/v2/users/${userId}`) {
      return json(route, { data: user(), request_id: uuid("9") });
    }
    return route.fulfill({ status: 404 });
  });
}

function tags() {
  return [
    { created_at: "2026-08-10T00:00:00Z", id: uuid("2"), is_active: true, name: "VIP", revision: 1, updated_at: "2026-08-10T00:00:00Z" },
    { created_at: "2026-08-10T00:00:00Z", id: uuid("3"), is_active: false, name: "Legacy", revision: 2, updated_at: "2026-08-10T00:00:00Z" },
    { created_at: "2026-08-10T00:00:00Z", id: uuid("4"), is_active: false, name: "Paused", revision: 1, updated_at: "2026-08-10T00:00:00Z" },
  ];
}

function user() {
  return {
    created_at: "2026-08-10T00:00:00Z",
    display_name: "Synthetic User",
    email: "synthetic@example.test",
    email_verified_at: "2026-08-10T00:00:00Z",
    id: userId,
    point_balance: { free_balance: 0, paid_balance: 0, total_balance: 0 },
    status: "active",
    tag_assignment_revision: 3,
    tags: [{ assigned_at: "2026-08-10T01:00:00Z", id: uuid("3"), is_active: false, name: "Legacy" }],
    updated_at: "2026-08-10T01:00:00Z",
  };
}

function observeErrors(page: Page) {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const gatewayErrors: number[] = [];
  page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); });
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status()); });
  return () => ({ console: consoleErrors, gateway: gatewayErrors, page: pageErrors });
}

async function json(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" },
    status: 200,
  });
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
