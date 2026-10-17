import { spawn } from "node:child_process";
import { readFile } from "node:fs/promises";
import { request as httpRequest } from "node:http";
import { expect, test, type Page } from "@playwright/test";

const harness = process.env.ORIPA_M7_ISOLATED_HARNESS;

async function php(script: string, input?: unknown): Promise<string> {
  if (!harness) throw new Error("The isolated M7 harness is required.");
  const source = (await readFile(`${harness}/${script}`, "utf8")).replace(/^<\?php\s*/u, "");
  return new Promise((resolve, reject) => {
    const child = spawn("python3", [`${harness}/isolated.py`, "browser-php", "-r", source], { stdio: ["pipe", "pipe", "pipe"] });
    let output = "";
    child.stdout.on("data", (chunk: Buffer) => { output += chunk.toString(); });
    child.stderr.resume();
    child.on("error", reject);
    child.on("close", (status) => status === 0 ? resolve(output) : reject(new Error(`Isolated PHP bridge failed (${status}).`)));
    child.stdin.end(input === undefined ? "" : JSON.stringify(input));
  });
}

async function install(page: Page, token: string) {
  await page.addInitScript(() => {
    Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => "__Host-oripa_admin_xsrf=" + "c".repeat(64), set: () => undefined });
  });
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const result = await new Promise<{ status: number; headers: Record<string, string>; body: Buffer }>((resolve, reject) => {
      const body = request.postData() ?? "";
      const headers: Record<string, string> = { host: "admin.example.test", accept: "application/json",
        "content-type": "application/json", "content-length": String(Buffer.byteLength(body)), connection: "close",
        cookie: `__Host-oripa_admin_session=${token}; __Host-oripa_admin_xsrf=${"c".repeat(64)}` };
      for (const name of ["origin", "idempotency-key", "x-request-id", "x-xsrf-token", "sec-fetch-site"]) {
        const value = request.headers()[name];
        if (value) headers[name] = name === "origin" && value === "http://127.0.0.1:3397" ? "https://admin.example.test" : value;
      }
      const relay = httpRequest({ socketPath: `${harness}/browser-http.sock`, path: url.pathname + url.search,
        method: request.method(), headers }, (response) => {
        const chunks: Buffer[] = [];
        response.on("data", (chunk: Buffer) => chunks.push(chunk));
        response.on("end", () => resolve({ status: response.statusCode ?? 500,
          headers: { "content-type": String(response.headers["content-type"] ?? "application/json") }, body: Buffer.concat(chunks) }));
        response.on("error", reject);
      });
      relay.on("error", reject); relay.end(body);
    });
    await route.fulfill(result);
  });
}

for (const width of [1440, 390, 360]) {
  test(`real Laravel member rank management ${width}px`, async ({ page }, testInfo) => {
    test.skip(!harness, "Requires the task-owned network-isolated PostgreSQL/Laravel harness.");
    test.setTimeout(180_000);
    const fixture = JSON.parse(await php("browser_seed.php")) as { user_id: string; review_user_id: string; sessions: Record<string, string> };
    await install(page, fixture.sessions.admin);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/users/${fixture.user_id}`);
    await page.getByRole("button", { name: "ランクを変更", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("変更後のランク").selectOption("gold");
    await dialog.getByLabel("種別", { exact: true }).selectOption("transfer");
    await dialog.getByLabel("理由", { exact: true }).fill("E2E 引き継ぎ");
    await dialog.getByRole("button", { name: "変更内容を確認" }).click();
    await expect(dialog.getByText("ビギナー → ゴールド", { exact: true })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath(`rank-confirm-${width}.png`), fullPage: true });
    await dialog.getByRole("button", { name: "確認して保存" }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText("引き継ぎ適用済み", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "ランクポイントを調整", exact: true }).click();
    await dialog.getByLabel("ランクポイント増減").fill("10000");
    await dialog.getByLabel("理由", { exact: true }).fill("E2E 自力到達");
    await dialog.getByRole("button", { name: "変更内容を確認" }).click();
    await expect(dialog.getByText("ブロンズ：500pt（無償）")).toBeVisible();
    await dialog.getByRole("button", { name: "確認して保存" }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole("button", { name: "ランクポイントを調整", exact: true }).click();
    await dialog.getByLabel("ランクポイント増減").fill("-10000");
    await dialog.getByLabel("理由", { exact: true }).fill("E2E 回収");
    await dialog.getByRole("button", { name: "変更内容を確認" }).click();
    await expect(dialog.getByText("ブロンズ 昇格ボーナス回収 500pt / 不足 0pt（要確認）")).toBeVisible();
    await dialog.getByRole("button", { name: "確認して保存" }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole("button", { name: "ランクを変更", exact: true }).click();
    await dialog.getByLabel("種別", { exact: true }).selectOption("transfer");
    await dialog.getByLabel("理由", { exact: true }).fill("E2E 再引き継ぎ");
    await dialog.getByRole("button", { name: "変更内容を確認" }).click();
    await expect(dialog.getByRole("button", { name: "確認して保存" })).toBeDisabled();
    await dialog.getByRole("checkbox").check();
    await dialog.getByRole("button", { name: "確認して保存" }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole("button", { name: "ランクポイントを調整", exact: true }).click();
    await dialog.getByLabel("ランクポイント増減").fill("1");
    await dialog.getByLabel("理由", { exact: true }).fill("E2E 競合後の再確認");
    await dialog.getByRole("button", { name: "変更内容を確認" }).click();
    await expect(dialog.getByRole("button", { name: "確認して保存" })).toBeVisible();
    const concurrentStatus = await page.evaluate(async (userId) => {
      const base = `/admin/api/v2/users/${userId}`;
      const request = { delta: 1, reason: "Synthetic concurrent Admin request" };
      const query = new URLSearchParams({ operation: "points", delta: "1", reason: request.reason });
      const response = await fetch(`${base}/member-rank/preview?${query}`);
      if (!response.ok) return response.status;
      const preview = await response.json() as { data: { expected_revision: number; preview_token: string } };
      const saved = await fetch(`${base}/member-rank-point-adjustments`, { method: "POST", headers: {
        "Content-Type": "application/json", "X-XSRF-TOKEN": "c".repeat(64),
        "X-Request-Id": crypto.randomUUID(), "Idempotency-Key": crypto.randomUUID(),
      }, body: JSON.stringify({ ...request, expected_revision: preview.data.expected_revision, preview_token: preview.data.preview_token }) });
      return saved.status;
    }, fixture.user_id);
    expect(concurrentStatus).toBe(200);
    await dialog.getByRole("button", { name: "確認して保存" }).click();
    await expect(dialog.getByRole("alert")).toHaveText("状態が変わりました。最新の差分を再確認してください。");
    await expect(dialog.getByRole("button", { name: "確認して保存" })).toHaveCount(0);
    await dialog.getByRole("button", { name: "変更内容を確認" }).click();
    await dialog.getByRole("button", { name: "確認して保存" }).click();
    await expect(dialog).not.toBeVisible();
    await page.goto("/users/member-ranks");
    await expect(page.getByRole("heading", { name: "有効会員のランク別人数" })).toBeVisible();
    const review = page.locator("tr").filter({ has: page.locator(`a[href="/users/${fixture.review_user_id}"]`) });
    await expect(review.getByRole("cell").last()).toHaveText("300pt");
    await page.screenshot({ path: testInfo.outputPath(`rank-summary-${width}.png`), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.goto("/users");
    await page.getByLabel("会員ランク", { exact: true }).selectOption("gold");
    await page.getByLabel("引き継ぎ", { exact: true }).selectOption("true");
    await page.getByRole("button", { name: "検索", exact: true }).click();
    await expect(page.getByRole("columnheader", { name: "当月ランクポイント" })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(errors).toEqual([]);
    await page.unrouteAll({ behavior: "wait" });
    await install(page, fixture.sessions.operator);
    await page.goto(`/users/${fixture.user_id}`);
    await expect(page.getByRole("heading", { name: "会員ランク", exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "ランクを変更", exact: true })).toHaveCount(0);
  });
}
