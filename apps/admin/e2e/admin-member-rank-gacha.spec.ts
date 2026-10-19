import { spawn } from "node:child_process";
import { readFile } from "node:fs/promises";
import { request as httpRequest } from "node:http";
import { expect, test, type Page, type TestInfo } from "@playwright/test";

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
  const loading = { pending: 0, completed: 0, lastCompleted: 0, failures: [] as string[] };
  await page.addInitScript(() => {
    Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => "__Host-oripa_admin_xsrf=" + "c".repeat(64), set: () => undefined });
  });
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    loading.pending += 1;
    try {
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
      if (result.status >= 400) loading.failures.push(`${request.method()} ${url.pathname.replace(/[0-9a-f]{8}-[0-9a-f-]{27,}/gu, ":id")}: ${result.status}`);
      await route.fulfill(result);
    } finally {
      loading.pending -= 1;
      loading.completed += 1;
      loading.lastCompleted = Date.now();
    }
  });
  return loading;
}


type Loading = Awaited<ReturnType<typeof install>>;
type Fixture = { login_id: string; standard_id: string; signup_id: string; sessions: Record<string, string> };
const condition = (page: Page) => page.getByRole("combobox", { name: "会員ランク条件", exact: true });
const conditionRank = (page: Page) => page.getByRole("combobox", { name: "条件の会員ランク", exact: true });

async function dataReady(page: Page, loading: Loading, action: () => Promise<unknown>, testInfo: TestInfo) {
  const started = Date.now();
  const completed = loading.completed;
  await action();
  await expect.poll(() => loading.completed > completed && loading.pending === 0 && Date.now() - loading.lastCompleted >= 500,
    { timeout: 60_000, message: "Real Laravel data loading completes within the bounded observation window" }).toBe(true);
  expect(loading.failures).toEqual([]);
  await testInfo.attach(`data-readiness-${testInfo.attachments.length}`, {
    body: JSON.stringify({ path: new URL(page.url()).pathname.replace(/[0-9a-f]{8}-[0-9a-f-]{27,}/gu, ":id"), elapsed_ms: Date.now() - started, performance: "OPEN", assertion_timeout_ms: 5000 }), contentType: "application/json",
  });
}

async function mutation(page: Page, suffix: string, action: () => Promise<unknown>) {
  const response = page.waitForResponse((item) => item.request().method() !== "GET" && new URL(item.url()).pathname.endsWith(suffix));
  const [result] = await Promise.all([response, action()]);
  expect(result.status()).toBeGreaterThanOrEqual(200);
  expect(result.status()).toBeLessThan(300);
  const headers = result.request().headers();
  expect(headers["origin"]).toBeTruthy();
  expect(headers["x-xsrf-token"]).toBeTruthy();
  expect(headers["idempotency-key"]).toBeTruthy();
}

async function screenshot(page: Page, testInfo: TestInfo, name: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath(name + ".png"), fullPage: true });
}

for (const width of [1440, 390]) {
  test(`real Laravel member rank new conditions ${width}px`, async ({ page }, testInfo) => {
    test.setTimeout(300_000);
    const fixture = JSON.parse(await php("browser_seed.php")) as Fixture;
    const loading = await install(page, fixture.sessions.admin);
    await page.setViewportSize({ width, height: 900 });
    for (const kind of ["standard", "login"]) {
      await dataReady(page, loading, () => page.goto(`/catalog/gachas/new/${kind}`), testInfo);
      await expect(condition(page)).toHaveValue("none");
      await expect(condition(page).locator("option")).toHaveText(["指定なし", "このランク以上", "このランクのみ", "このランク以下"]);
      for (const mode of ["at_least", "only", "at_most"]) {
        await condition(page).selectOption(mode);
        await expect(conditionRank(page).locator("option")).toHaveText(["選択してください", "ビギナー", "ブロンズ", "シルバー", "ゴールド", "プラチナ", "ダイヤモンド", "VIP"]);
        for (const rank of ["1", "2", "3", "4", "5", "6", "7"]) {
          await conditionRank(page).selectOption(rank);
          await expect(conditionRank(page)).toHaveValue(rank);
        }
      }
      await condition(page).selectOption("none");
      await expect(conditionRank(page)).toHaveCount(0);
      await condition(page).selectOption("only");
      await expect(conditionRank(page)).toHaveValue("");
      await screenshot(page, testInfo, `rank-new-${kind}-${width}`);
    }
  });

  test(`real Laravel member rank Gacha composition ${width}px`, async ({ page }, testInfo) => {
    test.setTimeout(300_000);
    const fixture = JSON.parse(await php("browser_seed.php")) as Fixture;
    const loading = await install(page, fixture.sessions.admin);
    await page.setViewportSize({ width, height: 900 });
    page.on("dialog", (dialog) => dialog.accept());
    await dataReady(page, loading, () => page.goto(`/catalog/gachas/${fixture.login_id}`), testInfo);
    await expect(condition(page)).toBeDisabled();
    await expect(conditionRank(page)).toHaveValue("4");
    await dataReady(page, loading, () => page.getByRole("link", { name: "コピーして新規登録" }).click(), testInfo);
    await expect(condition(page)).toHaveValue("only");
    await expect(conditionRank(page)).toHaveValue("4");
    await condition(page).selectOption("at_most");
    await conditionRank(page).selectOption("2");
    await page.getByLabel("公開開始日時（JST）", { exact: true }).fill("2026-07-01T09:00");
    await dataReady(page, loading, () => mutation(page, "/catalog/gacha-compositions", () => page.getByRole("button", { name: "構成を一括保存" }).click()), testInfo);
    await expect(page.getByRole("button", { name: "保存済み構成を公開・予約" })).toBeVisible();
    await dataReady(page, loading, () => page.reload(), testInfo);
    await expect(condition(page)).toHaveValue("at_most");
    await expect(conditionRank(page)).toHaveValue("2");
    await condition(page).selectOption("none");
    await expect(conditionRank(page)).toHaveCount(0);
    await dataReady(page, loading, () => mutation(page, "/composition", () => page.getByRole("button", { name: "構成を一括保存" }).click()), testInfo);
    await dataReady(page, loading, () => page.reload(), testInfo);
    await expect(condition(page)).toHaveValue("none");
    await expect(conditionRank(page)).toHaveCount(0);
    await condition(page).selectOption("only");
    await expect(conditionRank(page)).toHaveValue("");
    await condition(page).selectOption("at_most");
    await conditionRank(page).selectOption("2");
    await dataReady(page, loading, () => mutation(page, "/composition", () => page.getByRole("button", { name: "構成を一括保存" }).click()), testInfo);
    await dataReady(page, loading, () => mutation(page, "/publish", () => page.getByRole("button", { name: "保存済み構成を公開・予約" }).click()), testInfo);
    await expect(condition(page)).toBeDisabled();
    await expect(conditionRank(page)).toBeDisabled();
    await dataReady(page, loading, () => page.reload(), testInfo);
    await expect(condition(page)).toBeDisabled();
    await expect(condition(page)).toHaveValue("at_most");
    await expect(conditionRank(page)).toHaveValue("2");
    await screenshot(page, testInfo, `rank-login-${width}`);
  });

  test(`real Laravel member rank schedule and authorization ${width}px`, async ({ page }, testInfo) => {
    test.setTimeout(300_000);
    const fixture = JSON.parse(await php("browser_seed.php")) as Fixture;
    let loading = await install(page, fixture.sessions.admin);
    await page.setViewportSize({ width, height: 900 });
    page.on("dialog", (dialog) => dialog.accept());
    await dataReady(page, loading, () => page.goto(`/catalog/gachas/${fixture.login_id}`), testInfo);
    await dataReady(page, loading, () => page.getByRole("link", { name: "コピーして新規登録" }).click(), testInfo);
    await expect(condition(page)).toHaveValue("only");
    await expect(conditionRank(page)).toHaveValue("4");
    await page.getByLabel("公開開始日時（JST）", { exact: true }).fill("2026-10-26T21:00");
    await dataReady(page, loading, () => mutation(page, "/catalog/gacha-compositions", () => page.getByRole("button", { name: "構成を一括保存" }).click()), testInfo);
    await expect(page.getByRole("button", { name: "保存済み構成を公開・予約" })).toBeVisible();
    await dataReady(page, loading, () => mutation(page, "/publish-schedule", () => page.getByRole("button", { name: "保存済み構成を公開・予約" }).click()), testInfo);
    await dataReady(page, loading, () => page.reload(), testInfo);
    await expect(page.getByRole("button", { name: "公開予約を取り消す" })).toBeVisible();
    await expect(condition(page)).toBeDisabled();
    await screenshot(page, testInfo, `rank-scheduled-${width}`);
    await dataReady(page, loading, () => page.goto(`/catalog/gachas/${fixture.standard_id}`), testInfo);
    await expect(page.getByText("ゴールドのみ", { exact: true }).first()).toBeVisible();
    await screenshot(page, testInfo, `rank-standard-${width}`);
    await dataReady(page, loading, () => page.goto(`/catalog/gachas/${fixture.signup_id}`), testInfo);
    await expect(page.getByText("会員ランク条件: 指定なし（固定）")).toBeVisible();
    await screenshot(page, testInfo, `rank-signup-${width}`);
    await dataReady(page, loading, () => page.goto("/catalog/gachas"), testInfo);
    await expect(page.getByRole("columnheader", { name: "会員ランク条件" })).toBeVisible();
    await screenshot(page, testInfo, `rank-list-${width}`);
    await page.unrouteAll({ behavior: "wait" });
    loading = await install(page, fixture.sessions.operator);
    await dataReady(page, loading, () => page.goto(`/catalog/gachas/${fixture.login_id}`), testInfo);
    await expect(condition(page)).toBeDisabled();
    await expect(conditionRank(page)).toBeDisabled();
    await expect(page.getByRole("link", { name: "コピーして新規登録" })).toHaveCount(0);
    await screenshot(page, testInfo, `rank-operator-${width}`);
  });
}
