import { expect, test } from "@playwright/test";

test("synthetic Banner import acceptance, progress and redacted failures", async ({ page }) => {
  const category = "00000000-0000-4000-8000-000000000001";
  const job = { id: "00000000-0000-4000-8000-000000000002", status: "completed", total: 2, processed: 2,
    created: 1, updated: 0, unchanged: 0, failed: 1, rows: [], created_at: "2026-10-11T00:00:00Z", completed_at: "2026-10-11T00:00:01Z" };
  let requests = 0;
  await page.addInitScript(() => Object.defineProperty(Document.prototype, "cookie", {
    configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined,
  }));
  await page.route("**/admin/api/v2/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body: unknown = {};
    if (path.endsWith("/auth/session")) body = { admin: { id: category, role: "admin", state: "active", mfa_verified: true }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false };
    else if (path.endsWith("/auth/permissions")) body = { permissions: ["content.read", "content.manage"], request_id: category, role: "admin" };
    else if (path.endsWith("/categories")) body = { items: [{ id: category, name: "カード", created_at: job.created_at }] };
    else if (path.endsWith("/banners")) body = { items: [], next_cursor: null };
    else if (path.endsWith("/imports") && route.request().method() === "GET") body = { enabled: true, items: [] };
    else if (path.endsWith("/imports") && route.request().method() === "POST") {
      requests++;
      expect(route.request().postDataJSON().refresh_existing_images).toBe(false);
      expect(route.request().headers()["idempotency-key"]).toBeTruthy();
      body = job;
    } else return route.fulfill({ status: 404 });
    await route.fulfill({ status: 200, json: body });
  });
  await page.goto("/banners");
  await page.getByRole("button", { name: "CSV取込・履歴" }).click();
  await expect(page.getByText(/最大200行・2MiB/)).toBeVisible();
  await expect(page.getByText(/保持期限は30分/)).toBeVisible();
  await page.getByLabel("CSVファイル").setInputFiles({ name: "synthetic.csv", mimeType: "text/csv", buffer: Buffer.from("管理ID,タイトル\nCARD-1,Title") });
  await page.getByLabel("既定カテゴリ").selectOption(category);
  await expect(page.getByLabel("既存画像を再取得する")).not.toBeChecked();
  await page.getByRole("button", { name: "CSVを取り込む" }).click();
  await expect(page.getByText("新規 1 / 更新 0 / 変更なし 0 / 失敗 1")).toBeVisible();
  await expect(page.getByText(/新しい署名付きURLを取得・再入力/)).toBeVisible();
  expect(requests).toBe(1);
});
