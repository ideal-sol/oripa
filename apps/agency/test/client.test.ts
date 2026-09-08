import { expect, it, vi } from "vitest";
import { agencyApi } from "../src/lib/agency-api/client";

it("requests aggregates only on the Agency surface with date query and no body", async () => {
  const fetcher = vi.spyOn(globalThis, "fetch").mockImplementation(async () => new Response(JSON.stringify({ items: [] }), { status: 200 }));
  await agencyApi.userAggregate({ month: "2026-09" });
  await agencyApi.salesAggregate({ start_date: "2026-09-01", end_date: "2026-09-30" });
  expect(fetcher.mock.calls[0][0]).toBe("/agency/api/v2/aggregates/users?month=2026-09");
  expect(fetcher.mock.calls[1][0]).toBe("/agency/api/v2/aggregates/sales?start_date=2026-09-01&end_date=2026-09-30");
  for (const call of fetcher.mock.calls) {
    expect(call[1]).toMatchObject({ method: "GET", credentials: "same-origin", cache: "no-store" });
    expect(call[1]).not.toHaveProperty("body");
  }
});

it("uses Agency surface, credentials and dedicated CSRF cookie", async () => {
  document.cookie = "__Host-oripa_agency_xsrf=" + "a".repeat(64) + "; Secure; Path=/";
  document.cookie = "__Host-oripa_admin_xsrf=" + "b".repeat(64) + "; Secure; Path=/";
  const fetcher = vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ data: {} }), { status: 200 }));
  await agencyApi.contact({ contact_name: "QA", phone: "03-1234-5678" });
  expect(fetcher).toHaveBeenCalledWith("/agency/api/v2/me/contact", expect.objectContaining({ method: "PATCH", credentials: "same-origin", cache: "no-store", headers: expect.objectContaining({ "Content-Type": "application/json", "X-XSRF-TOKEN": "a".repeat(64) }) }));
  expect(JSON.stringify(fetcher.mock.calls)).not.toContain("b".repeat(64));
});
