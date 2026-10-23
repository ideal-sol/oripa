import { expect, test, type Page, type Route } from "@playwright/test";

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

test("shipping minimum confirmation saves exact maximum and mobile has no overflow", async ({ page }) => {
  const errors = observeErrors(page);
  await page.goto("/settings/shipping-minimum-points");
  await expect(page.getByRole("heading", {name: "発送下限ポイント設定"})).toBeVisible();
  await page.getByLabel(/発送下限ポイント（/).fill("9007199254740991");
  await page.getByRole("button", {name: "保存内容を確認"}).click();
  await page.getByRole("button", {name: "変更を確定"}).click();
  await expect(page.getByText("設定を保存しました。")).toBeVisible();
  await expect(page.getByText("9,007,199,254,740,991pt", {exact: true})).toBeVisible();
  await page.setViewportSize({height: 844, width: 390});
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({console: [], gateway: [], page: []});
});

async function installApi(page: Page): Promise<void> {
  let revision = 1;
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, { admin: { id: uuid("8"), mfa_verified: true, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (path.endsWith("/auth/permissions")) return json(route, { permissions: ["shipping.settings.read", "shipping.settings.manage"], request_id: uuid("9"), role: "admin" });
    if (path.endsWith("/settings/shipping-minimum-points")) {
      const input = request.method() === "PUT" ? request.postDataJSON() : null;
      if (input) revision += 1;
      return json(route, {
        data: {
          id: uuid("1"), minimum_points: input?.minimum_points ?? "0", revision, updated_by_admin_id: input ? uuid("8") : null,
          updated_at: "2026-08-06T00:00:00Z",
        },
        ...(input ? { idempotent_replay: false } : {}),
        request_id: uuid("9"),
      });
    }
    return route.fulfill({ status: 404 });
  });
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
  await route.fulfill({ body: JSON.stringify(body), headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" }, status: 200 });
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
