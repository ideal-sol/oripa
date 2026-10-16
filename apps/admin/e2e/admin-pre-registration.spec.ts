import { expect, test, type Page } from "@playwright/test";

const identifier = "01910191-0191-7191-8191-019101910191";
const initial = {
  is_enabled: true, cutoff_at: "2026-10-21T00:00:00Z", point_amount: 500, revision: 1,
  snapshot_amount: null, started_at: null, completed_at: null,
  summary: { applicants: 2, email_pending: 0, sms_pending: 0, eligible: 2, pending: 2, activated: 0, failed: 0, excluded: 0, granted_points: 0 },
  results: [] as { user_id: string; status: string; reason: string | null }[],
};

async function installApi(page: Page, operator = false) {
  const calls: { path: string; method: string; body: unknown; key: string | undefined }[] = [];
  let data = { ...initial };
  await page.addInitScript(() => {
    Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined });
  });
  await page.route(/\/admin\/api\/v2\//u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const json = (body: unknown) => route.fulfill({ status: 200, contentType: "application/json", headers: { "Cache-Control": "private, no-store" }, body: JSON.stringify(body) });
    if (path.endsWith("/auth/session")) return json({ authenticated: true, mfa_required: false, requires_mfa_enrollment: false, admin: { id: identifier, role: operator ? "operator" : "admin", state: "active", mfa_verified: true } });
    if (path.endsWith("/auth/permissions")) return json({ role: operator ? "operator" : "admin", request_id: identifier, permissions: ["pre_registration.settings.read", ...(operator ? [] : ["pre_registration.settings.manage"])] });
    calls.push({ path, method: request.method(), body: request.postDataJSON(), key: request.headers()["idempotency-key"] });
    if (path.endsWith("/pre-registration/preview")) return json({ affected_applicants: 1 });
    if (path.endsWith("/pre-registration/activate")) {
      data = { ...data, summary: { ...data.summary, pending: 0, activated: 1, failed: 1, granted_points: data.point_amount },
        results: [{ user_id: identifier, status: "failed", reason: "temporary_failure" }] };
      return json({ data: { ...data, snapshot_amount: data.point_amount, started_at: data.cutoff_at }, idempotent_replay: false });
    }
    if (path.endsWith("/settings/pre-registration")) {
      if (request.method() === "PUT") data = { ...data, ...request.postDataJSON(), revision: data.revision + 1 };
      return json({ data });
    }
    return route.fulfill({ status: 404 });
  });
  return calls;
}

test("Admin confirms cutoff impact, saves JST settings, then inspects partial activation", async ({ page }) => {
  const calls = await installApi(page);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/settings/pre-registration");
  await expect(page.getByRole("heading", { name: "事前登録特典設定" })).toBeVisible();
  await expect(page.getByLabel("締切・OPEN日時（日本時間）")).toHaveValue("2026-10-21T09:00");
  await page.getByLabel("付与する無償ポイント").fill("700");
  await page.getByRole("button", { name: "変更内容を確認" }).click();
  await expect(page.getByText(/対象外となる申込：1人/)).toBeVisible();
  expect(calls.some((call) => call.method === "PUT")).toBe(false);
  await page.getByRole("button", { name: "確定する" }).click();
  await expect(page.getByText("設定を保存しました。")).toBeVisible();
  await page.getByRole("button", { name: /一括切替を確認/ }).click();
  await expect(page.getByText(/1人あたり 700pt/)).toBeVisible();
  expect(calls.some((call) => call.path.endsWith("/activate"))).toBe(false);
  await page.getByRole("button", { name: "確定する" }).click();
  await expect(page.getByText("一時的な失敗。再実行できます。")).toBeVisible();
  await expect(page.getByLabel("付与する無償ポイント")).toBeDisabled();
  expect(calls.find((call) => call.path.endsWith("/activate"))?.key).toMatch(/^[0-9a-f-]{36}$/);
  expect(errors).toEqual([]);
});

test("Operator can read on mobile but cannot save or activate", async ({ page }) => {
  const calls = await installApi(page, true);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/settings/pre-registration");
  await expect(page.getByText("閲覧のみ可能です。")).toBeVisible();
  await expect(page.getByLabel("付与する無償ポイント")).toBeDisabled();
  await expect(page.getByRole("button", { name: /一括切替を確認/ })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(calls.every((call) => call.method === "GET")).toBe(true);
});

test("Partial results remain within mobile scroll boundaries", async ({ page }) => {
  await installApi(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/settings/pre-registration");
  await page.getByRole("button", { name: /一括切替を確認/ }).click();
  await page.getByRole("button", { name: "確定する" }).click();
  await expect(page.getByText("一時的な失敗。再実行できます。")).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
