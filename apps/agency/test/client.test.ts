import { expect, it, vi } from "vitest";
import { agencyApi } from "../src/lib/agency-api/client";

it("uses Agency surface, credentials and dedicated CSRF cookie", async () => {
  document.cookie = "__Host-oripa_agency_xsrf=" + "a".repeat(64) + "; Secure; Path=/";
  document.cookie = "__Host-oripa_admin_xsrf=" + "b".repeat(64) + "; Secure; Path=/";
  const fetcher = vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ data: {} }), { status: 200 }));
  await agencyApi.contact({ contact_name: "QA", phone: "03-1234-5678" });
  expect(fetcher).toHaveBeenCalledWith("/agency/api/v2/me/contact", expect.objectContaining({ method: "PATCH", credentials: "same-origin", cache: "no-store", headers: expect.objectContaining({ "Content-Type": "application/json", "X-XSRF-TOKEN": "a".repeat(64) }) }));
  expect(JSON.stringify(fetcher.mock.calls)).not.toContain("b".repeat(64));
});
