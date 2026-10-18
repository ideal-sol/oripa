import { spawn } from "node:child_process";
import { readFile } from "node:fs/promises";
import { request as httpRequest } from "node:http";
import { expect, test, type Page } from "@playwright/test";

const harness = process.env.ORIPA_M5_ISOLATED_HARNESS;

async function php(script: string, input?: unknown): Promise<string> {
  if (!harness) throw new Error("The isolated M5 harness is required.");
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
        if (value) headers[name] = name === "origin" && value === "http://127.0.0.1:3398" ? "https://admin.example.test" : value;
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
  test(`real Laravel member rank Gacha composition ${width}px`, async ({ page }, testInfo) => {
    test.skip(!harness, "Requires the task-owned isolated PostgreSQL/Laravel harness.");
    test.setTimeout(300_000);
    const fixture = JSON.parse(await php("browser_seed.php")) as { login_id: string; standard_id: string; signup_id: string; sessions: Record<string, string> };
    await install(page, fixture.sessions.admin);
    await page.setViewportSize({ width, height: 900 });
    page.on("dialog", (dialog) => dialog.accept());
    await page.goto(`/catalog/gachas/${fixture.login_id}`);
    await expect(page.getByRole("combobox", { name: "会員ランク条件", exact: true })).toBeDisabled();
    await expect(page.getByRole("combobox", { name: "条件の会員ランク", exact: true })).toHaveValue("4");
    await page.getByRole("link", { name: "コピーして新規登録" }).click();
    await expect(page.getByRole("combobox", { name: "会員ランク条件", exact: true })).toHaveValue("only");
    await page.getByRole("combobox", { name: "会員ランク条件", exact: true }).selectOption("at_most");
    await page.getByRole("combobox", { name: "条件の会員ランク", exact: true }).selectOption("2");
    await page.getByLabel("公開開始日時（JST）", { exact: true }).fill("2026-07-01T09:00");
    await page.getByRole("button", { name: "構成を一括保存" }).click();
    await expect(page.getByRole("button", { name: "保存済み構成を公開・予約" })).toBeVisible();
    await page.reload();
    await expect(page.getByRole("combobox", { name: "会員ランク条件", exact: true })).toHaveValue("at_most");
    await expect(page.getByRole("combobox", { name: "条件の会員ランク", exact: true })).toHaveValue("2");
    await page.getByRole("button", { name: "保存済み構成を公開・予約" }).click();
    await expect(page.getByRole("combobox", { name: "会員ランク条件", exact: true })).toBeDisabled();
    await page.screenshot({ path: testInfo.outputPath(`rank-login-${width}.png`), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.goto(`/catalog/gachas/${fixture.standard_id}`);
    await expect(page.getByText("ゴールドのみ", { exact: true }).first()).toBeVisible();
    await page.goto(`/catalog/gachas/${fixture.signup_id}`);
    await expect(page.getByText("会員ランク条件: 指定なし（固定）")).toBeVisible();
    await page.goto("/catalog/gachas");
    await expect(page.getByRole("columnheader", { name: "会員ランク条件" })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath(`rank-list-${width}.png`), fullPage: true });
    await page.unrouteAll({ behavior: "wait" });
    await install(page, fixture.sessions.operator);
    await page.goto(`/catalog/gachas/${fixture.login_id}`);
    await expect(page.getByRole("combobox", { name: "会員ランク条件", exact: true })).toBeDisabled();
    await expect(page.getByRole("link", { name: "コピーして新規登録" })).toHaveCount(0);
  });
}
