import { expect, test } from "@playwright/test";

test("Operator views agency user and sales aggregates with date filters and responsive tables", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", error => errors.push(error.message));
  page.on("response", response => { if ([500, 502, 504].includes(response.status())) errors.push(String(response.status())); });
  await page.route(/\/admin\/api\/v2\/.*$/u, async route => {
    const url = new URL(route.request().url());
    const json = (body: unknown) => route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(body) });
    if (url.pathname.endsWith("/auth/session")) return json({ admin: { id: "01900000-0000-7000-8000-000000000001", mfa_verified: true, role: "operator", state: "active" }, authenticated: true, mfa_required: true, requires_mfa_enrollment: false });
    if (url.pathname.endsWith("/auth/permissions")) return json({ permissions: ["agency.read"], role: "operator", request_id: "01900000-0000-7000-8000-000000000001" });
    const metrics = url.pathname.endsWith("/users") ? { temporary_users: 2, full_users: 0 }
      : { temporary_paying_users: 1, temporary_amount: 4000, full_paying_users: 1, full_amount: 4000 };
    return json({ items: [{ company_name: "QA代理店", advertising_code: "AD000001", ...metrics }], next_cursor: null,
      period: { start_date: url.searchParams.get("start_date") ?? "2026-09-01", end_date: url.searchParams.get("end_date") ?? "2026-09-30", timezone: "Asia/Tokyo" } });
  });
  await page.goto("/agencies/aggregates/users");
  await expect(page.getByRole("heading", { name: "本登録・仮登録ユーザー集計" })).toBeVisible();
  await expect(page.getByLabel("開始日")).toHaveValue("2026-09-01");
  await expect(page.getByText("2人", { exact: true })).toBeVisible();
  await page.getByLabel("開始日").fill("2026-08-01");
  await page.getByLabel("終了日").fill("2026-08-31");
  await page.getByRole("button", { name: "適用", exact: true }).click();
  await expect(page.getByText("対象期間：2026-08-01 ～ 2026-08-31")).toBeVisible();
  await page.getByRole("button", { name: "当月", exact: true }).click();
  await expect(page.getByLabel("開始日")).toHaveValue("2026-09-01");
  await page.getByRole("link", { name: "売上集計", exact: true }).click();
  await expect(page.getByRole("heading", { name: "広告コード別売上集計" })).toBeVisible();
  await expect(page.getByText("￥4,000", { exact: true })).toHaveCount(2);
  for (const width of [1280, 390]) {
    await page.setViewportSize({ width, height: 844 });
    await expect(page.getByRole("columnheader", { name: "代理店名" })).toBeAttached();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  }
  expect(errors).toEqual([]);
});
