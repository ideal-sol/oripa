import { expect, test, type Page } from "@playwright/test";

const identifier = "01910191-0191-7191-8191-019101910191";
const initial = {
  is_enabled: true, cutoff_at: "2026-10-21T00:00:00Z", point_amount: 500, revision: 1,
  snapshot_amount: null, started_at: null, completed_at: null,
  summary: { applicants: 2, email_pending: 0, sms_pending: 0, eligible: 2, pending: 2, activated: 0, failed: 0, excluded: 0, granted_points: 0 },
  results: [] as { user_id: string; status: string; reason: string | null }[],
};

async function installApi(page: Page, operator = false) {
  const calls: { path: string; method: string; body: unknown; key: string | undefined }[] = [];
  let data = { ...initial };
  await page.addInitScript(() => {
    Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined });
  });
  await page.route(/\/admin\/api\/v2\//u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const json = (body: unknown) => route.fulfill({ status: 200, contentType: "application/json", headers: { "Cache-Control": "private, no-store" }, body: JSON.stringify(body) });
    if (path.endsWith("/auth/session")) return json({ authenticated: true, mfa_required: false, requires_mfa_enrollment: false, admin: { id: identifier, role: operator ? "operator" : "admin", state: "active", mfa_verified: true } });
    if (path.endsWith("/auth/permissions")) return json({ role: operator ? "operator" : "admin", request_id: identifier, permissions: ["pre_registration.settings.read", ...(operator ? [] : ["pre_registration.settings.manage"])] });
    calls.push({ path, method: request.method(), body: request.postDataJSON(), key: request.headers()["idempotency-key"] });
    if (path.endsWith("/pre-registration/preview")) return json({ affected_applicants: 1 });
    if (path.endsWith("/pre-registration/activate")) {
      data = { ...data, summary: { ...data.summary, pending: 0, activated: 1, failed: 1, granted_points: data.point_amount },
        results: [{ user_id: identifier, status: "failed", reason: "temporary_failure" }] };
      return json({ data: { ...data, snapshot_amount: data.point_amount, started_at: data.cutoff_at }, idempotent_replay: false });
    }
    if (path.endsWith("/settings/pre-registration")) {
      if (request.method() === "PUT") data = { ...data, ...request.postDataJSON(), revision: data.revision + 1 };
      return json({ data });
    }
    return route.fulfill({ status: 404 });
  });
  return calls;
}

test("Admin confirms cutoff impact, saves JST settings, then inspects partial activation", async ({ page }) => {
  const calls = await installApi(page);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/settings/pre-registration");
  await expect(page.getByRole("heading", { name: "事前登録特典設定" })).toBeVisible();
  await expect(page.getByLabel("締切・OPEN日時（日本時間）")).toHaveValue("2026-10-21T09:00");
  await page.getByLabel("付与する無償ポイント").fill("700");
  await page.getByRole("button", { name: "変更内容を確認" }).click();
  await expect(page.getByText(/対象外となる申込：1人/)).toBeVisible();
  expect(calls.some((call) => call.method === "PUT")).toBe(false);
  await page.getByRole("button", { name: "確定する" }).click();
  await expect(page.getByText("設定を保存しました。")).toBeVisible();
  await page.getByRole("button", { name: /一括切替を確認/ }).click();
  await expect(page.getByText(/1人あたり 700pt/)).toBeVisible();
  expect(calls.some((call) => call.path.endsWith("/activate"))).toBe(false);
  await page.getByRole("button", { name: "確定する" }).click();
  await expect(page.getByText("一時的な失敗。再実行できます。")).toBeVisible();
  await expect(page.getByLabel("付与する無償ポイント")).toBeDisabled();
  expect(calls.find((call) => call.path.endsWith("/activate"))?.key).toMatch(/^[0-9a-f-]{36}$/);
  expect(errors).toEqual([]);
});

test("Operator can read on mobile but cannot save or activate", async ({ page }) => {
  const calls = await installApi(page, true);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/settings/pre-registration");
  await expect(page.getByText("閲覧のみ可能です。")).toBeVisible();
  await expect(page.getByLabel("付与する無償ポイント")).toBeDisabled();
  await expect(page.getByRole("button", { name: /一括切替を確認/ })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(calls.every((call) => call.method === "GET")).toBe(true);
});

test("Partial results remain within mobile scroll boundaries", async ({ page }) => {
  await installApi(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/settings/pre-registration");
  await page.getByRole("button", { name: /一括切替を確認/ }).click();
  await page.getByRole("button", { name: "確定する" }).click();
  await expect(page.getByText("一時的な失敗。再実行できます。")).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

import { readFileSync } from "node:fs";

// Isolated stylesheet fixtures exercise the real cascade without a runtime DB.
const globalCss = readFileSync("src/app/globals.css", "utf8");
const moduleCss = (path: string, prefix: string) => readFileSync(`src/components/${path}`, "utf8")
  .replace(/\.([a-zA-Z][a-zA-Z0-9]*)/gu, `.${prefix}-$1`);
const css = globalCss + moduleCss("inventory/card-inventory.module.css", "inventory")
  + moduleCss("banners/banner-import.module.css", "banner")
  + moduleCss("member-ranks/member-ranks.module.css", "rank")
  + moduleCss("settings/member-rank-settings.module.css", "settings");

for (const width of [1440, 390]) {
  test(`ADMIN-UI-001–016 and 018 stylesheet geometry at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.setContent(`<style>${css}</style><main style="padding:16px;min-width:0">
      <section class="inventory-panel"><form class="inventory-filters"><label>カード名<input></label><button class="primary-button">検索</button></form><div class="inventory-tableScroll"><table><tr><th>カード</th><td style="min-width:900px" class="inventory-negative">-1</td></tr></table></div></section>
      <section class="admin-payment-detail-panel"><ol><li class="admin-payment-detail-event"><time>2026/11/05</time><strong>決済会社から通知</strong><pre>${"synthetic-detail-".repeat(50)}</pre></li></ol><button class="danger-button" disabled>チャージバックを記録</button></section>
      <section class="rank-panel"><div class="rank-header"><button class="secondary-button">再取得</button></div><p>条件に該当する有効会員はいません。</p><div class="rank-form"><label>履歴の種類<select><option>ランク</option></select></label><label>決済取消を検索<input></label><label>確認分類<select><option>すべて</option></select></label></div></section>
      <section class="banner-workspace"><form><label>既定カテゴリ<select><option>選択してください</option></select></label><label class="banner-checkbox"><input type="checkbox">既存画像を再取得する</label><button class="primary-button" disabled>CSVを取り込む</button></form></section>
      <form class="admin-user-filters"><button class="secondary-button">条件を解除</button></form>
      <form class="catalog-gacha-search"><input><select class="admin-ui-control"><option>公開ステータス</option></select><select class="admin-ui-control"><option>ガチャ種別</option></select><button class="primary-button">検索</button></form>
      <fieldset class="catalog-rank-video-control"><legend>演出動画</legend><label>動画<select><option>選択してください</option></select></label><button class="secondary-button" disabled>動画を追加</button></fieldset>
      <div class="referral-settings-grid"><label class="admin-ui-check-row"><input type="checkbox">事前登録受付を有効にする</label><label>日時<input type="datetime-local"></label></div>
      <div class="rank-effect-workspace"><label class="announcement-filter rank-effect-status-filter">状態<select class="admin-ui-control"><option>有効</option></select></label></div>
      <input class="admin-ui-control" id="shipping-minimum"><textarea class="admin-ui-control admin-ui-prose">日本語の長い注意事項</textarea>
      <div style="overflow:auto"><table class="settings-table"><tr><td><input type="number" value="100"></td></tr></table></div>
      <form class="catalog-mutation-form"><label class="admin-ui-check-row"><input type="checkbox"><span>配送のみ・ポイント交換不可<span class="field-hint">公開後は変更できません。</span></span></label></form>
      <section class="login-gacha-actions"><p>状態: draft / 下書き編集中</p><a class="secondary-button">コピーして新規登録</a><button class="primary-button">保存済み構成を公開・予約</button></section>
    </main>`);
    for (const selector of [".inventory-panel", ".admin-payment-detail-panel", ".rank-panel", ".banner-workspace", ".login-gacha-actions"]) {
      await expect(page.locator(selector)).toHaveCSS("background-color", "rgb(255, 255, 255)");
    }
    for (const selector of [".banner-workspace select", ".catalog-gacha-search select", ".catalog-rank-video-control select", ".admin-ui-control", ".settings-table input"]) {
      for (const control of await page.locator(selector).all()) expect((await control.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    }
    for (const checkbox of await page.locator(".admin-ui-check-row input").all()) {
      const box = (await checkbox.boundingBox())!;
      expect(box.width).toBe(18); expect(box.height).toBe(18);
      const label = (await checkbox.locator("..").boundingBox())!;
      expect(Math.abs(label.y - box.y)).toBeLessThan(5);
    }
    expect((await page.getByRole("button", { name: "再取得", exact: true }).boundingBox())!.width).toBeLessThan(200);
    await expect(page.locator(".inventory-tableScroll")).toHaveCSS("overflow-x", "auto");
    await expect(page.locator(".inventory-negative")).toHaveCSS("color", "rgb(185, 28, 28)");
    await expect(page.locator(".admin-payment-detail-event time")).toHaveCSS("display", "block");
    await expect(page.locator(".admin-payment-detail-event pre")).toHaveCSS("white-space", "pre-wrap");
    await expect(page.locator(".admin-ui-prose")).toHaveCSS("font-family", await page.locator("main").evaluate(el => getComputedStyle(el).fontFamily));
    await expect(page.getByRole("button", { name: "CSVを取り込む" })).toBeDisabled();
    const actions = page.locator(".login-gacha-actions");
    if (width === 1440) expect((await actions.locator("a").boundingBox())!.width).toBeLessThan(300);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}
