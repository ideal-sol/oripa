import { describe, expect, it, vi } from "vitest";
import { AdminApiClient } from "@/lib/admin-api/client";
import { paymentId } from "./payment-adjustment-fixture";

describe("Payment adjustment transport", () => {
  it("uses the same-origin Admin surface, CSRF, no-store and stable idempotency", async () => {
    const fetcher = vi.fn<typeof fetch>().mockImplementation(async () => new Response(JSON.stringify({ data: {} }), { status: 200, headers: { "Content-Type": "application/json" } }));
    const client = new AdminApiClient(fetcher, () => "a".repeat(64));
    await client.getAdminPayment(paymentId);
    await client.previewAdminPaymentChargeback(paymentId, { reason: "確認" });
    for (const operation of ["refund", "complete", "cancel", "chargeback", "reversal"] as const) {
      await client.writeAdminPaymentAdjustment(paymentId, operation, { reason: "確認" }, "stable-synthetic-key", paymentId);
    }
    expect(fetcher).toHaveBeenCalledTimes(7);
    for (const [url, request] of fetcher.mock.calls) {
      expect(String(url)).toMatch(/^\/admin\/api\/v2\/payments\//u);
      expect(request?.credentials).toBe("include");
      expect(request?.cache).toBe("no-store");
      if (request?.method !== "GET") expect(new Headers(request?.headers).get("X-XSRF-TOKEN")).toBe("a".repeat(64));
    }
    for (const [, request] of fetcher.mock.calls.slice(2)) {
      expect(new Headers(request?.headers).get("Idempotency-Key")).toBe("stable-synthetic-key");
    }
  });
});
