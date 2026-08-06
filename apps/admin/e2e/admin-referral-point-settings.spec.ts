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

test("desktop settings save both rewards without changing balances", async ({ page }) => {
  const errors = observeErrors(page);
  expect((await page.goto("/settings/referral"))?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "紹介ポイント設定" })).toBeVisible();
  await expect(page.getByText("紹介されたユーザーのSMS認証完了")).toBeVisible();
  await page.getByLabel("紹介者へ付与するポイント").fill("300");
  await page.getByLabel("紹介されたユーザーへ付与するポイント").fill("150");
  await page.getByRole("button", { name: "保存" }).click();
  await expect(page.getByText("設定を保存しました。")).toBeVisible();
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile settings remain usable without horizontal overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  await page.goto("/settings/referral");
  await expect(page.getByRole("heading", { name: "紹介ポイント設定" })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

async function installApi(page: Page): Promise<void> {
  let revision = 1;
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, { admin: { id: uuid("8"), mfa_verified: true, role: "admin", state: "active" }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false });
    if (path.endsWith("/auth/permissions")) return json(route, { permissions: ["referral.settings.read", "referral.settings.manage"], request_id: uuid("9"), role: "admin" });
    if (path.endsWith("/settings/referral-points")) {
      const input = request.method() === "PUT" ? request.postDataJSON() : null;
      if (input) revision += 1;
      return json(route, {
        data: {
          applies_to: "future_referrals_only",
          grant_condition: "referred_user_sms_verified",
          grant_timing: "on_sms_verification_completion",
          id: uuid("1"),
          is_enabled: input?.is_enabled ?? true,
          referred_user_point_amount: input?.referred_user_point_amount ?? 50,
          referrer_point_amount: input?.referrer_point_amount ?? 100,
          revision,
          reward_expiration_days: input?.reward_expiration_days ?? 180,
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
