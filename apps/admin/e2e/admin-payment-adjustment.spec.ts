import { expect, test, type Page } from "@playwright/test";
import { adjustment, detail, impact, paymentId } from "../test/payment-adjustment-fixture";

test("refund preparation, explicit cancellation, preparation again and confirmed completion", async ({ page }) => {
  const writes = await installApi(page);
  await page.goto(`/payments/${paymentId}`);
  await page.getByRole("button", { name: "返金の準備", exact: true }).click();
  await page.getByLabel("理由（必須）").fill("全額返金準備");
  await page.getByRole("button", { name: "確認して記録" }).click();
  await page.getByRole("button", { name: "取りやめる", exact: true }).click();
  await page.getByLabel("理由（必須）").fill("取りやめ");
  await page.getByRole("button", { name: "確認して記録" }).click();
  await expect(page.getByRole("status", { name: "操作結果" })).toContainText("取りやめ");
  await page.getByRole("button", { name: "返金の準備", exact: true }).click();
  await page.getByLabel("理由（必須）").fill("再準備");
  await page.getByRole("button", { name: "確認して記録" }).click();
  await page.getByRole("button", { name: "返金済みにする", exact: true }).click();
  await page.getByLabel("理由（必須）").fill("実返金確認");
  await expect(page.getByRole("button", { name: "確認して記録" })).toBeDisabled();
  await page.getByRole("checkbox").check();
  await page.getByRole("button", { name: "確認して記録" }).click();
  await expect(page.getByRole("status", { name: "操作結果" })).toContainText("有償 1000pt");
  expect(writes.map(value => value.path.split("/").pop())).toEqual(["refunds", "cancel", "refunds", "complete"]);
  expect(writes[3].body.provider_refund_confirmed).toBe(true);
});

test("CB preview, recalculated shortfall and reversal without automatic restoration", async ({ page }) => {
  const writes = await installApi(page);
  await page.goto(`/payments/${paymentId}`);
  await page.getByRole("button", { name: "チャージバックを記録", exact: true }).click();
  await page.getByLabel("理由（必須）").fill("CB通知");
  await page.getByRole("button", { name: "取消予定額を確認" }).click();
  await expect(page.getByRole("region", { name: "取消予定額" })).toContainText("有償 1000pt");
  expect(writes).toHaveLength(0);
  await page.getByRole("button", { name: "確認して記録" }).click();
  await expect(page.getByRole("status", { name: "操作結果" })).toContainText("不足：有償 1000pt");
  await page.getByRole("button", { name: "チャージバック取消を記録", exact: true }).click();
  await expect(page.getByRole("dialog")).toContainText("ポイントは自動復元されません");
  await page.getByLabel("理由（必須）").fill("異議認容");
  await page.getByRole("button", { name: "確認して記録" }).click();
  await expect(page.getByRole("status", { name: "操作結果" })).toContainText("要確認（自動復元なし）");
  expect(writes).toHaveLength(2);
});

test("mobile M8 hold exposes bonus lots and permits cancellation only", async ({ page }) => {
  await installApi(page, true);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/payments/${paymentId}`);
  await expect(page.getByRole("button", { name: "返金の準備", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "返金済みにする", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "チャージバックを記録", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "取りやめる", exact: true })).toBeEnabled();
  await expect(page.getByText(/会員ランク購入ボーナス/u)).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.getByRole("button", { name: "取りやめる", exact: true }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);
});

async function installApi(page: Page, held = false) {
  const value = detail();
  const writes: { path: string; body: Record<string, unknown> }[] = [];
  if (held) {
    value.rank_adjustment_pending = true;
    value.refund_eligibility = { allowed: false, code: "PAYMENT_RANK_ADJUSTMENT_NOT_READY", reason: "M8連動未対応" };
    value.lots[0].member_rank_purchase_bonus = true;
    value.adjustments = [adjustment()];
  }
  await page.addInitScript(() => { Object.defineProperty(Document.prototype, "cookie", { configurable: true, get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined }); });
  await page.route(/\/admin\/api\/v2\//u, async route => {
    const path = new URL(route.request().url()).pathname;
    let response: unknown;
    if (path.endsWith("/auth/session")) response = { admin: { id: paymentId, role: "owner", state: "active", mfa_verified: true }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false };
    else if (path.endsWith("/auth/permissions")) response = { role: "owner", permissions: ["reporting.financial.read", "payment.adjustment.manage"], request_id: paymentId };
    else if (path.endsWith("/preview")) response = { data: { impact, rank_adjustment_pending: false }, request_id: paymentId };
    else if (route.request().method() === "GET") response = { data: value, request_id: paymentId };
    else {
      expect(route.request().headers()["idempotency-key"]).toBeTruthy();
      expect(route.request().headers()["x-xsrf-token"]).toBe("a".repeat(64));
      writes.push({ path, body: route.request().postDataJSON() });
      let result = adjustment();
      if (path.endsWith("/cancel")) result = adjustment("canceled");
      if (path.endsWith("/complete")) result = adjustment("succeeded");
      if (path.endsWith("/chargebacks")) { result = adjustment("succeeded", "chargeback"); result.impact = { ...impact, reversed_paid_from_paid: 0, shortfall_paid_amount: 1000 }; }
      if (path.endsWith("/reversals")) result = { ...adjustment("manual_review", "chargeback_reversal"), parent_id: adjustment().id };
      value.adjustments = [result];
      value.refund_eligibility.allowed = result.status === "canceled";
      response = { data: result, rank_adjustment_pending: false, idempotent_replay: false, request_id: paymentId };
    }
    await route.fulfill({ status: 200, contentType: "application/json", headers: { "Cache-Control": "private, no-store" }, body: JSON.stringify(response) });
  });
  return writes;
}
