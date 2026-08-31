import { describe, expect, it } from "vitest";

import { GET } from "@/app/api/health/route";

describe("Admin health route", () => {
  it("reports only process readiness", async () => {
    const response = GET();

    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual({
      checks: {
        process: "ok",
      },
      component: "apps/admin",
      readiness_scope: "process",
      status: "ok",
    });
  });
});
