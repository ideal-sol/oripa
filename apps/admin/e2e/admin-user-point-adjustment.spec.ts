import { expect, test, type Page, type Route } from "@playwright/test";

const userId = "01910191-0191-7191-8191-019101910191";
const csrf = "a".repeat(64);

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, csrf);
});

test("Owner adjusts free points once and the detail reloads canonical balances", async ({ page }) => {
  const errors = monitor(page);
  const api = await installApi(page, "owner");
  await page.goto(`/users/${userId}`);
  await page.getByRole("button", { name: "ポイント調整" }).click();
  const dialog = page.getByRole("dialog", { name: "ポイント調整" });
  await expect(dialog).toHaveAttribute("aria-modal", "true");
  await expect(dialog.getByText("この操作は実際のWalletとPoint Ledgerへ即時反映されます。")).toBeVisible();
  await dialog.getByRole("button", { name: "無償P" }).click();
  await dialog.getByRole("button", { name: "加算" }).click();
  await dialog.getByLabel("調整ポイント数").fill("50");
  await expect(dialog.getByText("250 pt")).toBeVisible();
  await dialog.getByLabel("調整理由").fill("Preview synthetic correction");
  await dialog.getByLabel("現在の管理者パスワード").fill("not-recorded");
  await dialog.getByRole("button", { name: "内容を確認して実行" }).click();

  await expect(page.getByRole("status")).toContainText("最新残高を再取得しました");
  await expect(page.getByText("250 pt")).toBeVisible();
  expect(api.mutationCount()).toBe(1);
  expect(errors.console).toEqual([]);
  expect(errors.page).toEqual([]);
  expect(errors.gateway).toEqual([]);
});

test("Operator cannot see the point adjustment action on desktop or mobile", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await installApi(page, "operator");
  await page.goto(`/users/${userId}`);
  await expect(page.getByRole("button", { name: "ポイント調整" })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

async function installApi(page: Page, role: "owner" | "operator") {
  let freeBalance = 200;
  let mutationCount = 0;
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: uuid("8"), mfa_verified: true, role, state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: role === "owner" ? ["point.adjustment.manage"] : [],
        request_id: uuid("9"),
        role,
      });
    }
    if (path.endsWith(`/users/${userId}/point-adjustments`)) {
      mutationCount += 1;
      freeBalance += 50;
      return json(route, {
        data: {
          adjustment_public_id: uuid("2"),
          user_public_id: userId,
          operation_public_id: uuid("3"),
          point_type: "free",
          direction: "grant",
          amount: 50,
          reason: "Preview synthetic correction",
          paid_balance_before: 100,
          paid_balance_after: 100,
          free_balance_before: 200,
          free_balance_after: freeBalance,
          executed_at: "2026-08-03T00:00:00Z",
        },
        idempotent_replay: false,
        request_id: uuid("9"),
      });
    }
    if (path.endsWith(`/users/${userId}`)) {
      return json(route, {
        data: {
          created_at: "2026-08-03T00:00:00Z",
          display_name: "Synthetic user",
          email: "synthetic@example.test",
          email_verified_at: "2026-08-03T00:00:00Z",
          id: userId,
          point_balance: {
            free_balance: freeBalance,
            paid_balance: 100,
            total_balance: freeBalance + 100,
          },
          status: "active",
          updated_at: "2026-08-03T01:00:00Z",
        },
        request_id: uuid("9"),
      });
    }
    return route.fulfill({ status: 404 });
  });
  return { mutationCount: () => mutationCount };
}

function monitor(page: Page) {
  const result = { console: [] as string[], page: [] as string[], gateway: [] as number[] };
  page.on("console", (message) => {
    if (message.type() === "error") result.console.push(message.text());
  });
  page.on("pageerror", (error) => result.page.push(error.message));
  page.on("response", (response) => {
    if ([500, 502, 504].includes(response.status())) result.gateway.push(response.status());
  });
  return result;
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}

async function json(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" },
    status: 200,
  });
}
