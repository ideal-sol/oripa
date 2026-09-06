import { expect, test, type Page } from "@playwright/test";

const agencyId = "01900000-0000-7000-8000-000000000001";

test("Admin creates and manages an Agency with explicit credential confirmation", async ({ page }) => {
  await installApi(page, "admin");
  const failures: number[] = [];
  page.on("response", (response) => { if ([500, 502, 504].includes(response.status())) failures.push(response.status()); });
  await page.goto("/agencies");
  await page.getByRole("link", { name: "新規代理店登録" }).click();
  for (const [label, value] of [["会社名", "QA代理店"], ["担当者名", "QA担当者"], ["電話番号", "03-1234-5678"], ["担当者メールアドレス", "agency@example.test"], ["住所", "サンプル住所"]]) {
    await page.getByLabel(label, { exact: true }).fill(value);
  }
  await expect(page.getByLabel("Login ID（自動生成）")).toHaveValue("000012");
  await page.getByRole("button", { name: "広告コード発行" }).click();
  await expect(page.getByLabel("Advertising Code")).toHaveValue("ABC123xy");
  await page.getByLabel("初期PW").fill("Initial123");
  await page.getByRole("button", { name: "保存", exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`/agencies/${agencyId}$`));
  await page.getByRole("link", { name: "編集", exact: true }).click();
  await page.getByLabel("Login ID", { exact: true }).fill("000099");
  await expect(page.getByLabel("Advertising Code")).not.toBeEditable();
  await page.getByRole("button", { name: "保存", exact: true }).click();
  await expect(page.getByText("000099", { exact: true })).toBeVisible();
  for (const label of ["停止", "再有効化", "PW再設定", "ログイン情報を再発行"]) {
    await page.getByRole("button", { name: label, exact: true }).click();
    const dialog = page.getByRole("dialog", { name: label, exact: true });
    if (label === "PW再設定" || label === "ログイン情報を再発行") {
      await expect(dialog).toContainText("現在のパスワードは使用できなくなります");
      await dialog.getByLabel("新しいPW").fill("Changed456");
      await dialog.getByRole("checkbox").check();
    }
    await dialog.getByRole("button", { name: "確認して実行" }).click();
    await expect(dialog).toBeHidden();
  }
  expect(failures).toEqual([]);
});

test("Operator mobile detail is read-only and edit route is forbidden", async ({ page }) => {
  await installApi(page, "operator");
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/agencies/${agencyId}`);
  await expect(page.getByRole("heading", { name: "代理店詳細" })).toBeVisible();
  await expect(page.getByText("ABC123xy", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "編集", exact: true })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "ログイン情報を再発行" })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.goto(`/agencies/${agencyId}/edit`);
  await expect(page.getByRole("heading", { name: "アクセスできません" })).toBeVisible();
});

async function installApi(page: Page, role: "admin" | "operator") {
  await page.addInitScript(() => Object.defineProperty(Document.prototype, "cookie", {
    configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined,
  }));
  let agency = { id: agencyId, company_name: "QA代理店", contact_name: "QA担当者", email: "agency@example.test", phone: "03-1234-5678", address: "サンプル住所", memo: null, login_id: "000012", advertising_code: "ABC123xy", status: "active", revision: 1, created_at: "2026-09-06T00:00:00Z", updated_at: "2026-09-06T00:00:00Z" };
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const json = (body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
    if (path.endsWith("/auth/session")) return json({ admin: { id: agencyId, mfa_verified: true, role, state: "active" }, authenticated: true, mfa_required: true, requires_mfa_enrollment: false });
    if (path.endsWith("/auth/permissions")) return json({ permissions: role === "admin" ? ["agency.read", "agency.manage"] : ["agency.read"], role, request_id: agencyId });
    if (path.endsWith("/agencies/issuance")) return json({ login_id: "000012", advertising_code: "ABC123xy", issuance_token: "synthetic", request_id: agencyId });
    if (request.method() === "GET") return json(path.endsWith("/agencies") ? { items: [agency], next_cursor: null, request_id: agencyId } : { data: agency, request_id: agencyId });
    expect(role).toBe("admin");
    expect(request.headers()["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/);
    const input = request.postDataJSON();
    if (request.method() === "PUT") {
      expect(input).not.toHaveProperty("advertising_code");
      agency = { ...agency, login_id: input.login_id, revision: agency.revision + 1 };
    }
    if (path.endsWith("/suspend")) agency = { ...agency, status: "suspended", revision: agency.revision + 1 };
    if (path.endsWith("/reactivate")) agency = { ...agency, status: "active", revision: agency.revision + 1 };
    return json({ data: agency, idempotent_replay: false, request_id: agencyId }, path.endsWith("/agencies") ? 201 : 200);
  });
}
