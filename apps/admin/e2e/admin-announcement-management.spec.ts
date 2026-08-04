import { expect, test, type Page, type Route } from "@playwright/test";

const noticeId = uuid("1");
const versionId = uuid("2");
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

test("desktop announcement list previews the sanitized publication", async ({ page }) => {
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

  const response = await page.goto("/announcements");
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "お知らせ一覧" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "ID", "サムネイル", "カテゴリ", "タイトル", "公開状態",
    "公開開始日時", "公開終了日時", "更新日時", "プレビュー", "編集",
  ]);
  await expect(page.getByText(noticeId)).toBeVisible();
  await expect(page.getByText("公開", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "運用のお知らせをプレビュー" }).click();
  await expect(page.getByRole("dialog", { name: "運用のお知らせ" })).toContainText("安全な本文");
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog", { name: "運用のお知らせ" })).toBeHidden();
  await expect(page.getByRole("button", { name: "運用のお知らせをプレビュー" })).toBeFocused();

  expect(consoleErrors).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(gatewayErrors).toEqual([]);
});

test("mobile announcement editor validates, previews, and publishes without overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/announcements/new");
  await expect(page.getByRole("heading", { name: "お知らせ登録" })).toBeVisible();
  await page.getByLabel("タイトル").fill("Previewのお知らせ");
  await page.getByLabel("本文（HTML）").fill("<p>安全な本文</p><script>alert(1)</script>");
  await page.getByLabel("公開状態").selectOption("published");
  await page.getByRole("button", { name: "プレビュー" }).click();
  await expect(page.getByRole("dialog", { name: "Previewのお知らせ" })).toContainText("安全な本文");
  await expect(page.getByRole("dialog", { name: "Previewのお知らせ" })).not.toContainText("alert(1)");
  await page.getByRole("button", { name: "プレビューを閉じる" }).click();
  await page.getByRole("button", { name: "登録" }).click();
  await expect(page).toHaveURL(new RegExp(`/announcements/${noticeId}$`, "u"));
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
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
        permissions: ["content.read", "content.manage"],
        request_id: uuid("9"),
        role: "admin",
      });
    }
    if (path.endsWith("/catalog/presentation-assets")) {
      return json(route, { items: [], next_cursor: null });
    }
    if (path.endsWith("/content/notices/preview")) {
      const input = request.postDataJSON() as Record<string, unknown>;
      return json(route, {
        ...input,
        body_html: "<p>安全な本文</p>",
      });
    }
    if (path.endsWith(`/content/notices/${noticeId}/versions/${versionId}/publish`)) {
      return json(route, { ...detail(), status: "published" });
    }
    if (path.endsWith(`/content/notices/${noticeId}`)) {
      return json(route, detail());
    }
    if (path.endsWith("/content/notices") && request.method() === "POST") {
      expect(request.headers()["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
      return json(route, detail(), 201);
    }
    if (path.endsWith("/content/notices")) {
      return json(route, { items: [summary()], next_cursor: null });
    }
    return route.fulfill({ status: 404 });
  });
}

function summary() {
  return {
    created_at: "2026-08-01T00:00:00Z",
    id: noticeId,
    identifier: "notice-fixture",
    is_legal: false,
    latest_version: version(),
    published_version_id: versionId,
    status: "published",
    updated_at: "2026-08-04T00:00:00Z",
  };
}

function detail() {
  return {
    id: noticeId,
    identifier: "notice-fixture",
    is_legal: false,
    status: "draft",
    versions: [version()],
  };
}

function version() {
  return {
    asset_id: null,
    body_html: "<p>安全な本文</p>",
    checksum_sha256: "a".repeat(64),
    id: versionId,
    is_important: true,
    link_url: null,
    publish_end_at: "2026-08-31T14:59:59Z",
    publish_start_at: "2026-08-01T00:00:00Z",
    published_at: "2026-08-01T00:00:00Z",
    sort_order: 0,
    status: "published",
    summary: null,
    title: "運用のお知らせ",
    version_number: 1,
  };
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
