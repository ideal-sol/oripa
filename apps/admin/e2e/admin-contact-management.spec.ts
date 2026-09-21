import { expect, test, type Page, type Route } from "@playwright/test";

for (const width of [1440, 1366, 390]) {
  test(`UI display ${width}px /contacts table geometry`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });

    await page.goto("/contacts");
    const table = page.locator(".contact-table");
    await expect(table.locator("tbody tr").first()).toBeVisible();
    await expect(table.locator("..")).toHaveCSS("overflow-x", "auto");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const cells = await table.evaluate((element) => {
      const headers = Array.from(element.querySelectorAll("thead th"));
      return Array.from(element.querySelectorAll("tbody tr:first-child td")).map((cell, index) => {
        const header = getComputedStyle(headers[index]);
        const body = getComputedStyle(cell);
        return { headerAlignment: header.textAlign, bodyAlignment: body.textAlign, headerPadding: header.padding, bodyPadding: body.padding, vertical: body.verticalAlign, border: body.borderBottomStyle, background: header.backgroundColor };
      });
    });
    for (const cell of cells) {
      expect(cell.headerAlignment).toBe(cell.bodyAlignment);
      expect(cell.headerPadding).toBe(cell.bodyPadding);
      expect(cell.vertical).toBe("middle");
      expect(cell.border).toBe("solid");
      expect(cell.background).not.toBe("rgba(0, 0, 0, 0)");
    }
    await expect(page.locator(".announcement-pagination")).toHaveCSS("justify-content", "flex-end");


    if (width > 1000) {
      const region = table.locator("..");
      await region.evaluate((element) => { element.scrollLeft = element.scrollWidth; });
      const action = table.locator("tbody tr:first-child td:last-child").locator("button, a").last();
      await expect(action).toBeInViewport();
      const box = (await action.boundingBox())!;
      expect(await action.evaluate((element, point) => element.contains(document.elementFromPoint(point.x, point.y)), { x: box.x + box.width / 2, y: box.y + box.height / 2 })).toBe(true);
    }
    await page.screenshot({ path: testInfo.outputPath("table.png"), fullPage: true });
  });
}

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
  await expect(page.getByRole("region", { name: "対応履歴", exact: true }).getByText("お問い合わせ内容です。")).toBeVisible();
  await expect(page.getByRole("heading", { name: "対応履歴" })).toBeVisible();
  await page.getByRole("textbox", { name: "返信内容", exact: true }).fill("確認してご連絡します。");
  await page.getByRole("button", { name: "返信要求を保存" }).click();
  await expect(page.getByText("返信要求を記録しました。")).toBeVisible();
  await expect(page.getByText("確認してご連絡します。")).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
  expect(errors()).toEqual({ console: [], gateway: [], page: [] });
});

for (const width of [1440, 390]) {
  test(`contact history separation and status dialog ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 844 });
    const errors = observeErrors(page);
    await page.goto(`/contacts/${contactId}`);
    const history = page.getByRole("region", { name: "対応履歴", exact: true });
    await expect(history.locator("li strong")).toHaveText(["ユーザー：初回問い合わせ", "管理者：返信要求", "ユーザー：追加問い合わせ"]);
    await expect(history.getByText("お問い合わせリンク", { exact: true })).toBeVisible();
    await expect(page.getByRole("region", { name: "内部メモ", exact: true }).getByText("内部確認メモ {{inquiry_url}}", { exact: true })).toBeVisible();
    const statuses = page.getByRole("region", { name: "対応状況履歴", exact: true });
    await expect(statuses.locator("li strong")).toHaveText(["完了", "返信済み", "対応中"]);
    const more = page.getByRole("button", { name: "さらに表示" });
    await more.click();
    const dialog = page.getByRole("dialog", { name: "対応状況履歴" });
    await expect(dialog.locator("li strong")).toHaveText(["完了", "返信済み", "対応中", "未対応"]);
    const close = dialog.getByRole("button", { name: "対応状況履歴を閉じる" });
    await expect(close).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(close).toBeFocused();
    await page.keyboard.press("Shift+Tab");
    await expect(close).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath("status-history-dialog.png"), fullPage: true });
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    await expect(more).toBeFocused();
    await more.click();
    await close.click();
    await expect(dialog).not.toBeVisible();
    await page.screenshot({ path: testInfo.outputPath("contact-history.png"), fullPage: true });
    expect(errors()).toEqual({ console: [], gateway: [], page: [] });
  });
  test(`long status history remains scrollable ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 600 });
    await page.route(`**/admin/api/v2/contact-inquiries/${contactId}`, (route) => json(route, {
      ...detail(false),
      status_history: Array.from({ length: 30 }, (_, index) => ({ from_status: "in_progress", to_status: "in_progress", occurred_at: new Date(Date.UTC(2026, 7, 5, index)).toISOString(), reason_code: "follow_up" })),
    }));
    await page.goto(`/contacts/${contactId}`);
    await page.getByRole("button", { name: "さらに表示" }).click();
    const dialog = page.getByRole("dialog", { name: "対応状況履歴" });
    await expect(dialog.getByRole("listitem")).toHaveCount(30);
    expect(await dialog.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
    await dialog.evaluate((element) => { element.scrollTop = element.scrollHeight; });
    await expect(dialog.getByRole("listitem").last()).toBeInViewport();
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
  });
}

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
    internal_notes: [{ created_at: "2026-08-05T00:30:00Z", note: "内部確認メモ {{inquiry_url}}" }],
    user_messages: [{ id: "01910191-0191-7191-8191-019101910193", created_at: "2026-08-05T02:00:00Z", message: "ユーザー追加本文" }],
    reply_requests: [{
      created_at: "2026-08-05T01:00:00Z",
      id: replyId,
      message: replied ? "確認してご連絡します。" : "{{ inquiry_url }}",
    }],
    status_history: [{
      from_status: null,
      occurred_at: "2026-08-05T00:00:00Z",
      reason_code: "contact_received",
      to_status: "new",
    },
    { from_status: "new", to_status: "in_progress", occurred_at: "2026-08-05T01:00:00Z", reason_code: "progress" },
    { from_status: "in_progress", to_status: "replied", occurred_at: "2026-08-05T02:00:00Z", reason_code: "replied" },
    { from_status: "replied", to_status: "closed", occurred_at: "2026-08-05T03:00:00Z", reason_code: "closed" }],
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
