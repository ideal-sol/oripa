import { expect, test } from "@playwright/test";
import { adjustment, detail, impact, paymentId } from "../test/payment-adjustment-fixture";

for (const width of [1440, 390]) {
  test(`M8 cancellation preview, actual shortfall and separate reviews ${width}px`, async ({ page }, testInfo) => {
    const value = detail();
    const completed = adjustment("succeeded", "chargeback");
    completed.impact = { ...impact, required_free_amount: 170, reversed_free_from_free: 150, shortfall_free_amount: 20,
      purchase_bonus: { required_amount: 20, reversed_amount: 0, shortfall_amount: 20 },
      rank_adjustment: { month: "2026-10-01", effect: "past_month", requested_points: 1000, applied_points: 0,
        before_rank: "bronze", after_rank: "bronze", recoveries: [] } };
    const errors: string[] = [];
    page.on("pageerror", error => errors.push(error.message));
    await page.setViewportSize({ width, height: 900 });
    await page.addInitScript(() => {
      Object.defineProperty(Document.prototype, "cookie", { configurable: true,
        get: () => `__Host-oripa_admin_xsrf=${"a".repeat(64)}`, set: () => undefined });
    });
    await page.route(/\/admin\/api\/v2\//u, async route => {
      const path = new URL(route.request().url()).pathname;
      let response: unknown;
      if (path.endsWith("/auth/session")) response = { admin: { id: paymentId, role: "owner", state: "active", mfa_verified: true },
        authenticated: true, mfa_required: false, requires_mfa_enrollment: false };
      else if (path.endsWith("/auth/permissions")) response = { role: "owner",
        permissions: ["reporting.financial.read", "payment.adjustment.manage", "member_rank.settings.read"], request_id: paymentId };
      else if (path.endsWith("/preview")) response = { data: { impact: { ...completed.impact,
        purchase_bonus: { required_amount: 20, reversed_amount: 20, shortfall_amount: 0 } }, rank_adjustment_pending: false }, request_id: paymentId };
      else if (path.endsWith("/chargebacks")) {
        expect(route.request().headers()["idempotency-key"]).toBeTruthy();
        expect(route.request().headers()["x-xsrf-token"]).toBe("a".repeat(64));
        value.adjustments = [completed];
        value.refund_eligibility.allowed = false;
        response = { data: completed, rank_adjustment_pending: false, idempotent_replay: false, request_id: paymentId };
      } else if (path.includes("/member-ranks/summary")) response = { request_id: paymentId, data: {
        enabled: true, month: "2026-11-01", active_total: 1, ranks: [], reviews: [],
        promotion_bonus: { count: 0, amount: 0, point_type: "free" }, purchase_bonus: { count: 0, amount: 0, point_type: "free" },
        progress: { provisional: false, remaining: 0, last_completed_month: null, status: null, lease_expired: false },
        payment_adjustment_reviews: [{ id: paymentId, user_id: paymentId, payment_id: paymentId, adjustment_id: completed.id,
          type: "chargeback", month: "2026-10-01", past_month: true, requested_points: 1000, applied_points: 0,
          purchase_bonus_shortfall: 20, occurred_at: "2026-11-01T00:00:00Z" }] } };
      else if (path.includes("/member-ranks/")) response = { items: [], next_cursor: null, request_id: paymentId };
      else response = { data: value, request_id: paymentId };
      await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(response),
        headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto(`/payments/${paymentId}`);
    await page.getByRole("button", { name: "チャージバックを記録", exact: true }).click();
    await page.getByLabel("理由（必須）").fill("M8合成Fixture");
    await page.getByRole("button", { name: "取消予定額を確認" }).click();
    await expect(page.getByRole("region", { name: "取消予定額" })).toContainText("不足 0pt（無償不足の内数）");
    await page.getByRole("button", { name: "確認して記録" }).click();
    await expect(page.getByRole("status", { name: "操作結果" })).toContainText("不足 20pt（無償不足の内数）");
    await expect(page.getByRole("status", { name: "操作結果" })).toContainText("実減算 0pt");
    await page.screenshot({ path: testInfo.outputPath(`m8-cancellation-${width}.png`), fullPage: true });
    await page.goto("/users/member-ranks");
    const link = page.getByRole("link", { name: `チャージバック ${completed.id}` });
    await expect(link).toHaveAttribute("href", `/payments/${paymentId}#adjustment-${completed.id}`);
    await page.getByLabel("確認分類").selectOption("bonus");
    await expect(link).toBeVisible();
    await page.getByLabel("決済取消を検索").fill("no-match");
    await expect(link).toHaveCount(0);
    await page.getByLabel("決済取消を検索").fill("2026-10");
    await expect(link).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath(`m8-reviews-${width}.png`), fullPage: true });
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}
