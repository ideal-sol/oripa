import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  CatalogGachaCoreForm,
  CatalogGachaMasterForm,
  CatalogGachaVersionForm,
} from "@/components/catalog/catalog-gacha-forms";
import { CatalogGachaWorkspace, isEditableGachaVersion } from "@/components/catalog/catalog-gacha-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import { CATALOG_SECTIONS } from "@/lib/catalog/catalog-registry";

const CATEGORY_ID = "01910191-0191-7191-8191-019101910191";
const TAG_ID = "01910191-0191-7191-8191-019101910192";
const PRIZE_ID = "01910191-0191-7191-8191-019101910193";
const ASSET_ID = "01910191-0191-7191-8191-019101910194";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ hasPermission: () => true, permissions: new Set(["catalog.read", "catalog.manage"]) }) }));
vi.mock("@/components/auth/admin-auth-provider", () => ({ useAdminAuth: () => ({ expireSession: vi.fn() }) }));

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("Admin Gacha Draft management", () => {
  it("starts with Published and Draft, allows another status, and accepts an explicit override", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listCatalogGachas")
      .mockResolvedValue({ items: [], next_cursor: null });
    const { unmount } = render(<CatalogGachaWorkspace />);
    expect(await screen.findByLabelText("公開ステータス")).toHaveValue("published,draft");
    expect(list).toHaveBeenCalledWith(
      expect.objectContaining({ management_status: "published,draft" }),
      expect.any(AbortSignal),
    );
    fireEvent.change(screen.getByLabelText("公開ステータス"), { target: { value: "sales_paused" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      expect.objectContaining({ management_status: "sales_paused" }),
      expect.any(AbortSignal),
    ));
    unmount();
    render(<CatalogGachaWorkspace initialStatus="unpublished" />);
    expect(await screen.findByLabelText("公開ステータス")).toHaveValue("unpublished");
  });

  it("registers Gacha as a stable Catalog section", () => {
    const gacha = CATALOG_SECTIONS.find((section) => section.resource === "gachas");
    expect(gacha).toMatchObject({
      path: "/catalog/gachas",
      supportsMediaType: false,
    });
    expect(new Set(CATALOG_SECTIONS.map((section) => section.resource)).size).toBe(
      CATALOG_SECTIONS.length,
    );
  });

  it("submits a Gacha Master with Public Category and Tag IDs", async () => {
    mockMasterSelections();
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogGachaMasterForm
        mode="create"
        onCancel={vi.fn()}
        onSubmit={submit}
      />,
    );
    await screen.findByRole("option", { name: "Category A (category-a)" });
    fireEvent.change(screen.getByLabelText("Code"), {
      target: { value: "new-gacha" },
    });
    fireEvent.change(screen.getByLabelText("Slug"), {
      target: { value: "new-gacha" },
    });
    fireEvent.change(screen.getByLabelText("Category"), {
      target: { value: CATEGORY_ID },
    });
    fireEvent.click(screen.getByRole("checkbox", { name: "Featured" }));
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit).toHaveBeenCalledWith({
      categoryId: CATEGORY_ID,
      code: "new-gacha",
      slug: "new-gacha",
      tagIds: [TAG_ID],
    });
  });

  it("creates only a Draft core with audience and daily limit fields", async () => {
    mockMasterSelections();
    const submit = vi.fn().mockResolvedValue(undefined);
    render(<CatalogGachaCoreForm onCancel={vi.fn()} onSubmit={submit} />);

    await screen.findByRole("option", { name: "Category A" });
    fireEvent.change(screen.getByLabelText("ガチャタイトル"), {
      target: { value: "新しいガチャ" },
    });
    fireEvent.change(screen.getByLabelText("カテゴリ"), {
      target: { value: CATEGORY_ID },
    });
    const thumbnail = new File(["thumbnail"], "gacha.png", { type: "image/png" });
    fireEvent.change(screen.getByLabelText(/サムネイル画像/u), {
      target: { files: [thumbnail] },
    });
    fireEvent.click(screen.getByRole("checkbox", { name: "Featured" }));
    fireEvent.change(screen.getByLabelText("消費ポイント"), {
      target: { value: "200" },
    });
    fireEvent.change(screen.getByLabelText("総口数"), {
      target: { value: "500" },
    });
    fireEvent.change(
      screen.getByLabelText("1日規定回数（0は無制限・JST 0時リセット）"),
      { target: { value: "10" } },
    );
    expect(screen.getByRole("checkbox", { name: "1回" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "1回" })).toBeDisabled();
    fireEvent.click(screen.getByRole("checkbox", { name: "5回" }));
    fireEvent.click(screen.getByRole("checkbox", { name: "100回" }));
    fireEvent.change(screen.getByLabelText("会員ランク"), {
      target: { value: "first_time_users" },
    });
    fireEvent.change(screen.getByLabelText("新規登録後の日数（1日＝24時間）"), {
      target: { value: "14" },
    });
    fireEvent.change(screen.getByLabelText("開始日時（Asia/Tokyo）"), {
      target: { value: "2026-08-20T09:00" },
    });
    fireEvent.change(screen.getByLabelText("終了日時（Asia/Tokyo）"), {
      target: { value: "2026-09-20T09:00" },
    });
    fireEvent.submit(
      screen.getByRole("button", { name: "下書きを登録" }).closest("form")!,
    );

    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit).toHaveBeenCalledWith(
      expect.objectContaining({
        audienceCode: "first_time_users",
        allowedDrawCounts: [1, 10, 100],
        categoryId: CATEGORY_ID,
        dailyDrawLimit: 10,
        firstTimeEligibleDays: 14,
        presentationAssetId: null,
        pricePoints: 200,
        tagIds: [TAG_ID],
        title: "新しいガチャ",
        thumbnailFile: thumbnail,
        totalCount: 500,
      }),
    );
    expect(screen.getByLabelText("状態")).toHaveValue("下書き");
    expect(screen.queryByRole("button", { name: /公開/u })).not.toBeInTheDocument();
  });

  it("keeps the current thumbnail when editing without a new file", async () => {
    mockMasterSelections();
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogGachaCoreForm
        current={gachaFixture()}
        mode="edit"
        onCancel={vi.fn()}
        onSubmit={submit}
      />,
    );

    await screen.findByRole("option", { name: "Category A" });
    expect(screen.getByAltText("現在のサムネイル")).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "1回" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "5回" })).not.toBeChecked();
    fireEvent.change(screen.getByLabelText("ガチャタイトル"), {
      target: { value: "編集後ガチャ" },
    });
    fireEvent.change(screen.getByLabelText("状態"), {
      target: { value: "scheduled" },
    });
    fireEvent.click(screen.getByRole("button", { name: "編集内容を保存" }));

    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit).toHaveBeenCalledWith(expect.objectContaining({
      allowedDrawCounts: [1],
      presentationAssetId: ASSET_ID,
      managementStatus: "scheduled",
      thumbnailFile: null,
      title: "編集後ガチャ",
    }));
  });

  it("submits a typed Draft Version without Probability mutation fields", async () => {
    mockVersionSelections();
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogGachaVersionForm
        mode="create"
        onCancel={vi.fn()}
        onSubmit={submit}
      />,
    );
    await screen.findByRole("option", { name: "S / Prize S" });
    fireEvent.change(screen.getByLabelText("タイトル"), {
      target: { value: "Draft Version" },
    });
    fireEvent.change(screen.getByLabelText("消費ポイント"), {
      target: { value: "100" },
    });
    fireEvent.change(screen.getByLabelText("販売口数"), {
      target: { value: "1000" },
    });
    fireEvent.change(screen.getByLabelText("表示素材"), {
      target: { value: ASSET_ID },
    });
    fireEvent.change(screen.getByLabelText("公開開始"), {
      target: { value: "2026-08-01T09:00" },
    });
    fireEvent.change(screen.getByLabelText("公開終了"), {
      target: { value: "2027-08-01T09:00" },
    });
    fireEvent.change(screen.getByLabelText("景品"), {
      target: { value: PRIZE_ID },
    });
    fireEvent.change(screen.getByLabelText("初期在庫"), {
      target: { value: "50" },
    });
    fireEvent.change(screen.getByLabelText("表示順"), {
      target: { value: "10" },
    });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    const submitted = submit.mock.calls[0][0];
    expect(submitted).toMatchObject({
      presentationAssetId: ASSET_ID,
      pricePoints: 100,
      prizes: [
        {
          initialInventory: 50,
          prizeId: PRIZE_ID,
          sortOrder: 10,
        },
      ],
      title: "Draft Version",
      totalCount: 1000,
    });
    expect(submitted).not.toHaveProperty("probability");
    expect(submitted.publishStartAt).toMatch(/Z$/);
  });

  it("keeps Published and archived Versions read-only", () => {
    expect(isEditableGachaVersion({ is_archived: false, status: "draft" })).toBe(
      true,
    );
    expect(
      isEditableGachaVersion({ is_archived: false, status: "published" }),
    ).toBe(false);
    expect(isEditableGachaVersion({ is_archived: true, status: "draft" })).toBe(
      false,
    );
  });
});

function mockMasterSelections() {
  vi.spyOn(AdminApiClient.prototype, "listCatalogCategories").mockResolvedValue({
    items: [
      {
        code: "category-a",
        created_at: "2026-08-01T00:00:00Z",
        description: null,
        id: CATEGORY_ID,
        is_archived: false,
        is_visible: true,
        name: "Category A",
        revision: 1,
        slug: "category-a",
        sort_order: 1,
        updated_at: "2026-08-01T00:00:00Z",
      },
    ],
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "listCatalogTags").mockResolvedValue({
    items: [
      {
        code: "featured",
        created_at: "2026-08-01T00:00:00Z",
        id: TAG_ID,
        is_archived: false,
        is_visible: true,
        name: "Featured",
        revision: 1,
        slug: "featured",
        sort_order: 1,
        updated_at: "2026-08-01T00:00:00Z",
      },
    ],
    next_cursor: null,
  });
}

function mockVersionSelections() {
  vi.spyOn(
    AdminApiClient.prototype,
    "listCatalogPresentationAssets",
  ).mockResolvedValue({
    items: [
      {
        alt_text: "Gacha Main",
        byte_size: 128,
        checksum_sha256: "a".repeat(64),
        created_at: "2026-08-01T00:00:00Z",
        id: ASSET_ID,
        is_archived: false,
        is_public: true,
        media_type: "image",
        mime_type: "image/png",
        public_path: "/assets/gacha-main.png",
        revision: 1,
        updated_at: "2026-08-01T00:00:00Z",
      },
    ],
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "listCatalogPrizes").mockResolvedValue({
    items: [
      {
        code: "prize-s",
        created_at: "2026-08-01T00:00:00Z",
        description: null,
        display_price: 10_000,
        exchange_points: 8_000,
        id: PRIZE_ID,
        is_archived: false,
        is_visible: true,
        name: "Prize S",
        presentation_asset: null,
        rank: {
          code: "S",
          id: "01910191-0191-7191-8191-019101910195",
          name: "S",
          sort_order: 1,
        },
        revision: 1,
        updated_at: "2026-08-01T00:00:00Z",
      },
    ],
    next_cursor: null,
  });
}

function gachaFixture() {
  return {
    archived_at: null,
    category: { code: "category-a", id: CATEGORY_ID, name: "Category A" },
    code: "gacha-code",
    created_at: "2026-08-01T00:00:00Z",
    current_version: {
      audience_code: "all_users" as const,
      allowed_draw_counts: [1] as Array<1 | 5 | 10 | 100 | 1000>,
      daily_draw_limit: 0,
      first_time_eligible_days: 7,
      description: "説明",
      id: "01910191-0191-7191-8191-019101910199",
      notices: "注意",
      presentation_asset: {
        alt_text: "現在のサムネイル",
        id: ASSET_ID,
        is_public: true,
        media_type: "image" as const,
        mime_type: "image/png",
        public_path: "/admin/api/v2/catalog/presentation-assets/asset/content",
      },
      price_points: 100,
      publish_end_at: null,
      publish_start_at: "2026-08-20T00:00:00Z",
      revision: 1,
      status: "draft" as const,
      title: "編集前ガチャ",
      total_count: 100,
      version_number: 1,
    },
    has_draw_history: false,
    id: "01910191-0191-7191-8191-019101910198",
    is_archived: false,
    public_code: "A7k9P2x4Qm8",
    publication_status: "draft" as const,
    published_version: null,
    revision: 1,
    slug: "gacha-code",
    sold_count: 0,
    state: "draft" as const,
    tags: [{ code: "featured", id: TAG_ID, name: "Featured" }],
    updated_at: "2026-08-01T00:00:00Z",
    version_count: 1,
  };
}
