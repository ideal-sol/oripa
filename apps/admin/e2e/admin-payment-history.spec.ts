import { expect, test, type Page, type Route } from "@playwright/test";

const targetUserId = "01910191-0191-7191-8191-019101910191";
const otherUserId = "01910191-0191-7191-8191-019101910192";
const statuses = [
  "created",
  "requires_action",
  "processing",
  "succeeded",
  "failed",
  "canceled",
  "expired",
] as const;
const methods = ["credit_card", "paypay", "konbini", "virtual_account"] as const;

test("Admin reviews every Payment state, filters, paginates, and opens User history", async ({
  page,
}) => {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const gatewayErrors: number[] = [];
  const paymentRequests: string[] = [];
  page.on("console", (message) => {
    if (message.type() === "error") consoleErrors.push(message.text());
  });
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => {
    if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status());
  });
  await installApi(page, paymentRequests);

  await page.goto("/payments");
  await expect(page.getByLabel("決済状態")).toHaveValue("succeeded");
  await expect(page.getByLabel("支払方法")).toHaveValue("all");
  expect(paymentRequests.some((request) => {
    const url = new URL(request, "http://admin.test");
    return url.pathname === "/admin/api/v2/payments"
      && url.searchParams.get("status") === "succeeded"
      && url.searchParams.get("payment_method") === null;
  })).toBe(true);

  await page.goto("/payments?status=processing&payment_method=konbini");
  await expect(page.getByRole("heading", { name: "決済履歴", level: 1 })).toBeVisible();
  await expect(page.getByLabel("決済状態")).toHaveValue("processing");
  await expect(page.getByLabel("支払方法")).toHaveValue("konbini");
  const filteredTable = page.getByRole("region", { name: "決済履歴一覧" });
  await expect(filteredTable.getByText("未払い", { exact: true })).toBeVisible();
  await expect(filteredTable.getByRole("row")).toHaveCount(2);
  expect(paymentRequests.some((request) => {
    const url = new URL(request, "http://admin.test");
    return url.pathname === "/admin/api/v2/payments"
      && url.searchParams.get("limit") === "20"
      && url.searchParams.get("payment_method") === "konbini"
      && url.searchParams.get("status") === "processing";
  })).toBe(true);

  await page.getByRole("button", { name: "条件を解除" }).click();
  await expect(page.getByLabel("決済状態")).toHaveValue("succeeded");
  await expect(page.getByLabel("支払方法")).toHaveValue("all");
  await expect(page.getByRole("region", { name: "決済履歴一覧" })
    .getByText("決済成功", { exact: true })).toBeVisible();

  await page.getByLabel("決済状態").selectOption("all");
  const allTable = page.getByRole("region", { name: "決済履歴一覧" });
  await expect(allTable.getByRole("row")).toHaveCount(8);
  for (const label of [
    "作成済み",
    "支払操作待ち",
    "未払い",
    "決済成功",
    "失敗",
    "キャンセル",
    "期限切れ",
  ]) {
    await expect(allTable.getByText(label, { exact: true })).toBeVisible();
  }
  for (const label of ["クレジットカード", "PayPay", "コンビニ決済", "銀行振込"]) {
    await expect(allTable.getByText(label, { exact: true }).first()).toBeVisible();
  }
  await expect(allTable.getByText("運営確認ユーザー").first()).toBeVisible();
  await expect(allTable.getByText("￥1,004")).toBeVisible();
  await expect(allTable.getByText("2026/08/25 0:00").first()).toBeVisible();

  await page.getByRole("button", { name: "条件を解除" }).click();
  await page.getByRole("button", { name: "次へ" }).click();
  await expect(page.getByRole("status")).toContainText("決済履歴を読み込んでいます");
  await expect(page.getByText("別ユーザー")).toBeVisible();
  expect(paymentRequests.some((request) => {
    const url = new URL(request, "http://admin.test");
    return url.pathname === "/admin/api/v2/payments"
      && url.searchParams.get("cursor") === "next-payment-page"
      && url.searchParams.get("limit") === "20";
  })).toBe(true);
  await page.getByRole("button", { name: "前へ" }).click();
  await expect(page.getByText("運営確認ユーザー").first()).toBeVisible();

  await page.locator(`a[href="/users/${targetUserId}"]`).first().click();
  await expect(page).toHaveURL(new RegExp(`/users/${targetUserId}$`, "u"));
  const userHistory = page.getByRole("region", { name: "決済履歴" });
  await expect(userHistory.getByRole("row")).toHaveCount(8);
  await expect(userHistory.getByText("決済成功", { exact: true })).toBeVisible();
  await expect(userHistory.getByText("別ユーザー")).toHaveCount(0);
  expect(paymentRequests.some((request) => request.startsWith(
    `/admin/api/v2/users/${targetUserId}/payments?`,
  ))).toBe(true);
  expect(paymentRequests.some((request) => request.includes(
    `/admin/api/v2/payments?user_id=${targetUserId}`,
  ))).toBe(false);

  await page.getByRole("button", { name: "決済" }).click();
  await page.getByRole("link", { name: "決済状況" }).click();
  await expect(page).toHaveURL(/\/payments$/u);
  await expect(page.getByLabel("決済状態")).toHaveValue("succeeded");
  await expect(page.getByLabel("支払方法")).toHaveValue("all");

  expect(await page.locator("body").textContent()).not.toMatch(/PAN|CVC|Secret/u);
  expect(consoleErrors).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(gatewayErrors).toEqual([]);
});

test("Payment history table remains keyboard-scrollable on mobile", async ({ page }) => {
  await installApi(page, []);
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/payments");

  const tableRegion = page.locator(".admin-payment-table-region");
  await expect(tableRegion).toHaveAttribute("tabindex", "0");
  await tableRegion.focus();
  await expect(tableRegion).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

async function installApi(page: Page, paymentRequests: string[]): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    if (path.endsWith("/auth/session")) return json(route, adminSession());
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ["reporting.financial.read"],
        request_id: uuid("9"),
        role: "operator",
      });
    }
    if (path.endsWith(`/users/${targetUserId}/payments`)) {
      paymentRequests.push(`${path}${url.search}`);
      return json(route, paymentCollection(allPayments()));
    }
    if (path.endsWith(`/users/${targetUserId}/referral-history`)) {
      return json(route, {
        items: [],
        next_cursor: null,
        request_id: uuid("9"),
        user_id: targetUserId,
      });
    }
    if (path.endsWith(`/users/${targetUserId}`)) {
      return json(route, { data: userDetail(), request_id: uuid("9") });
    }
    if (path.endsWith("/payments")) {
      paymentRequests.push(`${path}${url.search}`);
      if (url.searchParams.get("cursor") === "next-payment-page") {
        await new Promise((resolve) => setTimeout(resolve, 100));
        return json(route, paymentCollection([
          payment(8, "succeeded", "credit_card", otherUserId, "別ユーザー"),
        ]));
      }
      const filtered = allPayments().filter((item) => (
        (!url.searchParams.get("status") || item.status === url.searchParams.get("status"))
        && (!url.searchParams.get("payment_method")
          || item.method === url.searchParams.get("payment_method"))
      ));
      return json(route, paymentCollection(
        filtered,
        url.searchParams.get("status") === "succeeded"
          && url.searchParams.get("payment_method") === null
          ? "next-payment-page"
          : null,
      ));
    }
    return route.fulfill({ status: 404 });
  });
}

function allPayments() {
  return statuses.map((status, index) => payment(
    index + 1,
    status,
    methods[index % methods.length],
    targetUserId,
    "運営確認ユーザー",
  ));
}

function payment(
  index: number,
  status: typeof statuses[number],
  method: typeof methods[number],
  userId: string,
  displayName: string,
) {
  return {
    amount: { amount: 1000 + index, currency: "JPY" },
    created_at: "2026-08-24T15:00:00Z",
    expires_at: status === "processing" || status === "expired"
      ? "2026-08-27T15:00:00Z"
      : null,
    grant: {
      bonus_points: status === "succeeded" ? 100 : 0,
      granted_at: status === "succeeded" ? "2026-08-24T15:05:00Z" : null,
      paid_points: status === "succeeded" ? 1000 + index : 0,
    },
    id: uuid(String(index)),
    method,
    provider: "fincode",
    provider_payment_reference: `payment-reference-${index}`,
    provider_status: `provider-state-${index}`,
    status,
    succeeded_at: status === "succeeded" ? "2026-08-24T15:05:00Z" : null,
    updated_at: "2026-08-24T15:10:00Z",
    user: { display_name: displayName, id: userId },
  };
}

function paymentCollection(data: ReturnType<typeof payment>[], nextCursor: string | null = null) {
  return {
    data,
    pagination: { has_more: nextCursor !== null, limit: 20, next_cursor: nextCursor },
    request_id: uuid("9"),
  };
}

function adminSession() {
  return {
    admin: { id: uuid("9"), mfa_verified: false, role: "operator", state: "active" },
    authenticated: true,
    mfa_required: false,
    requires_mfa_enrollment: false,
  };
}

function userDetail() {
  return {
    created_at: "2026-08-03T00:00:00Z",
    display_name: "運営確認ユーザー",
    email: "payment-user@example.test",
    email_verified_at: "2026-08-03T00:00:00Z",
    id: targetUserId,
    point_balance: { free_balance: 200, paid_balance: 100, total_balance: 300 },
    state_revision: 1,
    status: "active",
    tag_assignment_revision: 1,
    tags: [],
    updated_at: "2026-08-03T01:00:00Z",
  };
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
