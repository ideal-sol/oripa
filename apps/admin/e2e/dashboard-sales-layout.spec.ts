import { expect, test, type Page, type Route } from "@playwright/test";

const ownerPermissions = [
  "catalog.read",
  "catalog.manage",
  "catalog.publish",
  "shipping.request.manage",
  "qa.draw.manage",
  "reporting.financial.read",
  "reporting.financial.export",
  "content.read",
  "content.manage",
  "contact.read",
];

test.beforeEach(async ({ page }) => {
  await installPreviewApi(page);
});

test("Dashboard follows the V1 sales layout without fabricated values", async ({ page }) => {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const gatewayErrors: number[] = [];
  page.on("console", (message) => {
    if (message.type() === "error") consoleErrors.push(message.text());
  });
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => {
    if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status());
  });

  const response = await page.goto("/");
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "ダッシュボード" })).toBeVisible();
  await expect(page.getByRole("link", { name: "ダッシュボード" })).toHaveAttribute(
    "aria-current",
    "page",
  );
  await expect(page.getByRole("tab")).toHaveCount(5);
  await expect(page.getByRole("heading", { name: "月別売上Summary" })).toBeVisible();
  await expect(page.getByText("総売上", { exact: true })).toBeVisible();
  await expect(page.getByText("返金額", { exact: true })).toBeVisible();
  await expect(page.getByText("CB額", { exact: true })).toBeVisible();
  await expect(page.getByText("純売上", { exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "日別売上Calendar" })).toBeVisible();
  await expect(page.getByText(/0(?:円|件|pt)/u)).toHaveCount(0);
  await expect(page.getByRole("button", { name: "CSV（後続Taskで実装）" })).toBeDisabled();

  await page.getByRole("tab", { name: "日別売上" }).click();
  await expect(page.getByRole("heading", { name: "決済一覧" })).toBeVisible();
  await page.getByRole("tab", { name: "月別ポイント消費" }).click();
  await expect(page.getByText("有償P消費")).toBeVisible();
  await expect(page.getByText("無償P消費")).toBeVisible();
  await page.getByRole("tab", { name: "日別ポイント消費" }).click();
  await expect(page.getByRole("heading", { name: "日別ポイント消費一覧" })).toBeVisible();
  await page.getByRole("tab", { name: "返金/CB履歴" }).click();
  await expect(page.getByRole("button", { name: "検索（後続Taskで実装）" })).toBeDisabled();

  expect(consoleErrors).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(gatewayErrors).toEqual([]);
});

test("mobile Dashboard remains keyboard operable without page overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/");

  const dailyTab = page.getByRole("tab", { name: "日別売上" });
  await dailyTab.focus();
  await page.keyboard.press("Enter");
  await expect(dailyTab).toHaveAttribute("aria-selected", "true");
  await expect(page.getByLabel("対象日")).toBeVisible();
  await expect(page.getByRole("heading", { name: "決済一覧" })).toBeVisible();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);

  await page.getByRole("button", { name: "ナビゲーションを開く" }).click();
  await expect(page.getByRole("navigation", { name: "管理ナビゲーション" })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("button", { name: "ナビゲーションを開く" })).toBeFocused();
});

async function installPreviewApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: {
          id: "01910191-0191-7191-8191-019101910191",
          mfa_verified: false,
          role: "owner",
          state: "active",
        },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ownerPermissions,
        request_id: "01910191-0191-7191-8191-019101910193",
        role: "owner",
      });
    }
    return route.fulfill({ status: 404 });
  });
}

async function json(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: {
      "Cache-Control": "private, no-store",
      "Content-Type": "application/json",
    },
    status: 200,
  });
}
