import { describe, expect, it } from "vitest";

import { initialListFilter } from "@/lib/list-filter";

describe("initialListFilter", () => {
  const allowed = ["published,draft", "published", "draft"] as const;

  it("uses the canonical default without an explicit query", () => {
    expect(initialListFilter(undefined, allowed, "published,draft")).toBe("published,draft");
  });

  it("prefers a valid explicit query without persisting prior state", () => {
    expect(initialListFilter("draft", allowed, "published,draft")).toBe("draft");
    expect(initialListFilter(undefined, allowed, "published,draft")).toBe("published,draft");
  });

  it("fails closed to the default for unknown query values", () => {
    expect(initialListFilter("unknown", allowed, "published,draft")).toBe("published,draft");
  });
});
