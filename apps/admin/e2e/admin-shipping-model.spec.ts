import { expect, test } from "@playwright/test";

for (const width of [1280, 390]) {
  test(`shipping snapshot image and current identifiers at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const id = "01910191-0191-7191-8191-019101910191";
    await page.route("**/synthetic-snapshot.png", (route) => route.fulfill({ contentType: "image/png", body: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64") }));
    await page.route("**/admin/api/v2/**", async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith("/auth/session")) return route.fulfill({ json: { admin: { id, role: "admin", state: "active", mfa_verified: true }, authenticated: true, mfa_required: false, requires_mfa_enrollment: false } });
      if (path.endsWith("/auth/permissions")) return route.fulfill({ json: { permissions: ["shipping.request.manage"], request_id: id, role: "admin" } });
      if (path.endsWith(`/shipping-requests/${id}`)) return route.fulfill({ json: {
        id, user_id: id, status: "requested", requested_at: "2026-11-04T00:00:00Z", shipped_at: null,
        carrier_code: null, tracking_number: null, prize_count: 2, prize_ids: [id, id + "x"], status_history: [],
        shipping_address: { recipient_name: "合成受取人", postal_code: "0000000", prefecture: "テスト県", city: "テスト市", street: "テスト町", building: null, phone_number: "0000000000" },
        items: [
          { user_prize_id: id, product_id: id, name: "当選商品", external_id: "CARD-1", model_number: "MODEL-1", image: { id, media_type: "image", mime_type: "image/png", alt_text: "当選時画像", public_path: "/synthetic-snapshot.png", is_public: true } },
          { user_prize_id: id + "x", product_id: id, name: "画像なし商品", external_id: null, model_number: null, image: null },
        ],
      } });
      return route.fulfill({ status: 404 });
    });
    await page.goto(`/shipping/${id}`);
    const section = page.getByRole("region", { name: "配送対象商品" });
    await expect(section.getByRole("columnheader")).toHaveText(["画像", "商品名", "管理ID", "型番", "商品ID", "保有景品ID"]);
    await expect(section.getByRole("img", { name: "当選時画像" })).toBeVisible();
    await expect(section.getByRole("img", { name: "Previewなし" })).toBeVisible();
    await expect(section.locator("code").filter({ hasText: /^-$/ })).toHaveCount(2);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const scroll = section.locator(".admin-user-prize-table-region");
    await expect(scroll).toHaveAttribute("tabindex", "0");
    if (width === 390) expect(await scroll.evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
  });
}
