import { render, screen } from "@testing-library/react";
import { createElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { RankMasterWorkspace } from "@/components/catalog/rank-master-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminCatalogRank } from "@/lib/admin-api/generated";

vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock("next/image", () => ({
  default: ({ unoptimized: _unoptimized, ...properties }:
    React.ImgHTMLAttributes<HTMLImageElement> & { unoptimized?: boolean }) =>
    createElement("img", properties),
}));
vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}));

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listCatalogRanks").mockResolvedValue({
    items: [rank()],
    next_cursor: null,
  });
});

afterEach(() => vi.restoreAllMocks());

describe("Rank Master workspace", () => {
  it("renders both Public API snapshots through authenticated Admin asset URLs", async () => {
    render(<RankMasterWorkspace />);

    expect(await screen.findByText("１等賞")).toBeVisible();
    expect(screen.getByRole("img", { name: "１等賞 lineup" })).toHaveAttribute(
      "src",
      `/admin/api/v2/catalog/presentation-assets/${uuid("1")}/content`,
    );
    expect(screen.getByRole("img", { name: "１等賞 result" })).toHaveAttribute(
      "src",
      `/admin/api/v2/catalog/presentation-assets/${uuid("2")}/content`,
    );
  });
});

function rank(): AdminCatalogRank {
  return {
    id: uuid("0"),
    rank_name: "１等賞",
    lineup_image: {
      id: uuid("1"),
      path: `/api/v2/catalog/presentation-assets/${uuid("1")}/content`,
      mime_type: "image/png",
      alt_text: "１等賞 lineup",
    },
    result_image: {
      id: uuid("2"),
      path: `/api/v2/catalog/presentation-assets/${uuid("2")}/content`,
      mime_type: "image/png",
      alt_text: "１等賞 result",
    },
    show_total_stock: false,
    status: "active",
    display_order: 0,
    revision_number: 1,
    revision: 1,
    has_usage: false,
    used_by_published_gacha: false,
    created_at: "2026-09-01T12:24:13Z",
    updated_at: "2026-09-01T12:24:13Z",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
