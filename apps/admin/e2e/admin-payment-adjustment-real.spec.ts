import { spawn } from "node:child_process";
import { readFile } from "node:fs/promises";
import { request as httpRequest } from "node:http";
import { expect, test, type Page } from "@playwright/test";

const harness = process.env.ORIPA_M9_ISOLATED_HARNESS;

async function php(script: string, input?: unknown): Promise<string> {
  if (!harness) throw new Error("The isolated M9 harness is required.");
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
        if (value) headers[name] = name === "origin" && value === "http://127.0.0.1:3399" ? "https://admin.example.test" : value;
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

for (const width of [1440, 390]) {
  test(`real Laravel manual refund and CB ${width}px`, async ({ page }, testInfo) => {
    test.skip(!harness, "Requires the M9 task-owned network-none synthetic Laravel/PostgreSQL harness.");
    test.setTimeout(240_000);
    const fixture = JSON.parse(await php("browser_seed.php")) as { payment_id: string; cb_payment_id: string; token: string };
    await install(page, fixture.token);
    const errors: string[] = [];
    page.on("pageerror", error => errors.push(error.message));
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/payments/${fixture.payment_id}`);
    await page.getByRole("button", { name: "返金の準備", exact: true }).click();
    await page.getByLabel("理由（必須）").fill("実API予約");
    await page.getByRole("button", { name: "確認して記録" }).click();
    await page.getByRole("button", { name: "取りやめる", exact: true }).click();
    await page.getByLabel("理由（必須）").fill("実API取消");
    await page.getByRole("button", { name: "確認して記録" }).click();
    await expect(page.getByRole("status", { name: "操作結果" })).toContainText("取りやめ");
    await page.getByRole("button", { name: "返金の準備", exact: true }).click();
    await page.getByLabel("理由（必須）").fill("再予約");
    await page.getByRole("button", { name: "確認して記録" }).click();
    await page.getByRole("button", { name: "返金済みにする", exact: true }).click();
    await page.getByLabel("理由（必須）").fill("実返金完了確認");
    await expect(page.getByRole("button", { name: "確認して記録" })).toBeDisabled();
    await page.getByRole("checkbox").check();
    await page.getByRole("button", { name: "確認して記録" }).click();
    await expect(page.getByRole("status", { name: "操作結果" })).toContainText("有償 1000pt");
    await expect(page.getByRole("heading", { name: "返金：成立", exact: true })).toBeVisible();
    await expect(page.getByRole("region", { name: "付与ポイント" })).toContainText("残り 0pt ／ 予約 0pt");
    await page.screenshot({ path: testInfo.outputPath(`refund-${width}.png`), fullPage: true });
    await page.goto(`/payments/${fixture.cb_payment_id}`);
    await page.getByRole("button", { name: "チャージバックを記録", exact: true }).click();
    await page.getByLabel("理由（必須）").fill("実API CB確認");
    await page.getByRole("button", { name: "取消予定額を確認" }).click();
    await expect(page.getByRole("region", { name: "取消予定額" })).toContainText("有償 2000pt");
    await page.getByRole("button", { name: "確認して記録" }).click();
    await page.getByRole("button", { name: "チャージバック取消を記録", exact: true }).click();
    await page.getByLabel("理由（必須）").fill("異議認容確認");
    await page.getByRole("button", { name: "確認して記録" }).click();
    await expect(page.getByRole("status", { name: "操作結果" })).toContainText("要確認（自動復元なし）");
    await expect(page.getByRole("heading", { name: "チャージバック取消：要確認（自動復元なし）", exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`reversal-${width}.png`), fullPage: true });
    expect(errors).toEqual([]);
  });
}
