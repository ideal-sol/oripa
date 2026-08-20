import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));
vi.mock("@/components/auth/admin-auth-provider", () => ({
  useAdminAuth: () => ({ expireSession: vi.fn() }),
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock("@/components/catalog/catalog-gacha-forms", () => ({
  CatalogGachaCoreForm: () => (
    <section>
      <h2>基本情報フォーム</h2>
      <button type="button">キャンセル</button>
      <button type="button">保存</button>
    </section>
  ),
  CatalogGachaVersionForm: () => null,
}));
vi.mock("@/components/catalog/catalog-gacha-rank-prize-manager", () => ({
  CatalogGachaRankPrizeManager: () => (
    <section>
      <h2>Rank</h2>
      <button type="button">Rank追加</button>
      <p>Rank一覧・編集</p>
      <h2>景品</h2>
      <button type="button">景品追加</button>
      <p>景品一覧・編集</p>
    </section>
  ),
}));

import { CatalogGachaWorkspace } from "@/components/catalog/catalog-gacha-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminCatalogGacha } from "@/lib/admin-api/generated";

const GACHA_ID = "A7k9P2x4Qm8";
const VERSION_ID = "01910191-0191-7191-8191-019101910194";

afterEach(() => vi.restoreAllMocks());

describe("Canonical Gacha edit workspace", () => {
  it("places Rank and Prize after the basic form without Probability or Preflight controls", async () => {
    vi.spyOn(AdminApiClient.prototype, "getCatalogGacha").mockResolvedValue({
      data: gacha(),
    });
    vi.spyOn(AdminApiClient.prototype, "listCatalogGachaVersions").mockResolvedValue({
      items: [],
      next_cursor: null,
    });
    vi.spyOn(AdminApiClient.prototype, "getCatalogGachaVersion").mockResolvedValue({
      data: {} as never,
    });

    render(
      <CatalogGachaWorkspace editMode gachaId={GACHA_ID} />,
    );

    await screen.findByRole("heading", { name: "基本情報フォーム" });
    expect(screen.getAllByRole("heading").map((heading) => heading.textContent)).toEqual([
      "ガチャ編集",
      "基本情報フォーム",
      "Rank",
      "景品",
    ]);
    expect(screen.getByRole("button", { name: "Rank追加" })).toBeVisible();
    expect(screen.getByRole("button", { name: "景品追加" })).toBeVisible();
    expect(screen.queryByText(/Probability Version|Probability Draft|Publish Preflight|Probability Stage/u)).not.toBeInTheDocument();
  });
});

function gacha(): AdminCatalogGacha {
  return {
    archived_at: null,
    category: { code: "category-a", id: "01910191-0191-7191-8191-019101910191", name: "Category A" },
    code: "gacha-code",
    created_at: "2026-08-01T00:00:00Z",
    current_version: {
      allowed_draw_counts: [1, 5, 10],
      audience_code: "all_users" as const,
      daily_draw_limit: 0,
      description: "説明",
      first_time_eligible_days: 7,
      id: VERSION_ID,
      notices: "注意",
      presentation_asset: null,
      price_points: 100,
      publish_end_at: null,
      publish_start_at: "2026-08-01T00:00:00Z",
      revision: 1,
      status: "draft" as const,
      title: "下書きガチャ",
      total_count: 100,
      version_number: 1,
    },
    first_published_at: null,
    has_draw_history: false,
    id: "01910191-0191-7191-8191-019101910195",
    is_archived: false,
    public_code: GACHA_ID,
    publication_status: "draft" as const,
    published_version: null,
    revision: 1,
    slug: "gacha-code",
    sold_count: 0,
    state: "draft" as const,
    tags: [],
    updated_at: "2026-08-01T00:00:00Z",
    version_count: 1,
  };
}
