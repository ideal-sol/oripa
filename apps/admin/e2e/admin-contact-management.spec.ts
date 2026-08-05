import { expect, test, type Page, type Route } from "@playwright/test";

const contactId = "01910191-0191-7191-8191-019101910191";
const replyId = "01910191-0191-7191-8191-019101910192";
const csrf = "a".repeat(64);

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, csrf);
  await installApi(page);
});

test("desktop contact list preserves the V1 columns and exact filters", async ({ page }) => {
  const errors = observeErrors(page);
  const response = await page.goto("/contacts");
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "お問い合わせ一覧" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "ID", "氏名", "メール", "電話番号", "状態", "受付日時", "詳細",
  ]);
  await expect(page.getByText("CNT-ABCDEFGHIJKLMNOPQRST")).toBeVisible();
  await page.getByLabel("状態", { exact: true }).selectOption("new");
  await page.getByLabel("メール").fill("user@example.test");
  await page.getByRole("button", { name: "検索" }).click();
  await page.getByRole("link", { name: "詳細" }).click();
  await expect(page).toHaveURL(new RegExp(`/contacts/${contactId}$`, "u"));
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

test("mobile contact detail queues a reply and stays within the viewport", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  const errors = observeErrors(page);
  await page.goto(`/contacts/${contactId}`);
  await expect(page.getByText("お問い合わせ内容です。")).toBeVisible();
  await expect(page.getByRole("heading", { name: "対応履歴" })).toBeVisible();
  await page.getByLabel("返信内容").fill("確認してご連絡します。");
  await page.getByRole("button", { name: "返信要求を保存" }).click();
  await expect(page.getByText("返信要求を記録しました。")).toBeVisible();
  await expect(page.getByText("確認してご連絡します。")).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

async function installApi(page: Page): Promise<void> {
  let replied = false;
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: uuid("9"), mfa_verified: false, role: "admin", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ["contact.read", "contact.manage"],
        request_id: uuid("9"),
        role: "admin",
      });
    }
    if (path.endsWith(`/contact-inquiries/${contactId}/reply-requests`)) {
      expect(request.headers()["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
      replied = true;
      return json(route, { id: replyId, idempotent_replay: false, status: "queued" }, 202);
    }
    if (path.endsWith(`/contact-inquiries/${contactId}`)) {
      return json(route, detail(replied));
    }
    if (path.endsWith("/contact-inquiries")) {
      if (url.searchParams.get("email")) {
        expect(url.searchParams.get("email")).toBe("user@example.test");
        expect(url.searchParams.get("status")).toBe("new");
      }
      return json(route, { items: [summary()], next_cursor: null });
    }
    return route.fulfill({ status: 404 });
  });
}

function summary() {
  return {
    authenticated: true,
    body_excerpt: "お問い合わせ内容です。",
    email: "user@example.test",
    id: contactId,
    name: "山田 太郎",
    phone: "09000000000",
    receipt_code: "CNT-ABCDEFGHIJKLMNOPQRST",
    received_at: "2026-08-05T00:00:00Z",
    status: "new",
    updated_at: "2026-08-05T00:00:00Z",
  };
}

function detail(replied: boolean) {
  return {
    ...summary(),
    body: "お問い合わせ内容です。",
    closed_at: null,
    internal_notes: [],
    reply_requests: replied ? [{
      created_at: "2026-08-05T01:00:00Z",
      id: replyId,
      message: "確認してご連絡します。",
    }] : [],
    status_history: [{
      from_status: null,
      occurred_at: "2026-08-05T00:00:00Z",
      reason_code: "contact_received",
      to_status: "new",
    }],
    subject: "お問い合わせ件名",
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

async function json(route: Route, body: unknown, status = 200): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" },
    status,
  });
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
