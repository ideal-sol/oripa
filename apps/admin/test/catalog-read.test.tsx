import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { CatalogConfirmationDialog } from "@/components/catalog/catalog-confirmation-dialog";
import { CatalogConflictBoundary } from "@/components/catalog/catalog-conflict-boundary";
import { CatalogDataTable } from "@/components/catalog/catalog-data-table";
import { CatalogMutationForm } from "@/components/catalog/catalog-mutation-form";
import { CatalogPrizeAssetMutationForm } from "@/components/catalog/catalog-prize-asset-mutation-form";
import { hasCatalogMutationRevision } from "@/components/catalog/catalog-workspace";
import {
  PublicAssetPreview,
  safePublicPath,
} from "@/components/catalog/public-asset-preview";
import { CatalogSectionNavigation } from "@/components/catalog/catalog-section-navigation";
import { CATALOG_SECTIONS } from "@/lib/catalog/catalog-registry";
import { ADMIN_PERMISSION_CODES } from "@/lib/admin-api/generated";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminCatalogPrize } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

const image = {
  alt_text: "公開景品",
  id: "01910191-0191-7191-8191-019101910191",
  is_public: true,
  media_type: "image" as const,
  mime_type: "image/png",
  public_path: "/assets/prize.png",
};

const RANK_ID = "01910191-0191-7191-8191-019101910101";
const CATEGORY_A_ID = "01910191-0191-7191-8191-019101910102";
const CATEGORY_B_ID = "01910191-0191-7191-8191-019101910103";
const BANNER_A_ASSET_ID = "01910191-0191-7191-8191-019101910104";
const BANNER_B_ASSET_ID = "01910191-0191-7191-8191-019101910105";

afterEach(() => vi.restoreAllMocks());

describe("Admin Catalog read components", () => {
  it("keeps the Catalog registry typed, unique, and available", () => {
    expect(CATALOG_SECTIONS).toHaveLength(6);
    expect(new Set(CATALOG_SECTIONS.map((item) => item.resource)).size).toBe(6);
    expect(navigationItem("catalog").implementation).toBe("available");
  });

  it("renders section navigation and marks the active section", () => {
    render(<CatalogSectionNavigation active="prizes" />);
    expect(screen.getByRole("link", { name: "Prize" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(screen.getByRole("link", { name: "Presentation Asset" })).toBeVisible();
  });

  it("previews only public same-origin image and video paths", () => {
    expect(safePublicPath("/assets/prize.png")).toBe(true);
    expect(safePublicPath("//external.example/prize.png")).toBe(false);
    expect(safePublicPath("https://external.example/prize.png")).toBe(false);

    const { rerender } = render(<PublicAssetPreview asset={image} />);
    expect(screen.getByRole("img", { name: "公開景品" })).toHaveAttribute(
      "src",
      "/assets/prize.png",
    );
    rerender(
      <PublicAssetPreview
        asset={{
          ...image,
          media_type: "video",
          mime_type: "video/mp4",
          public_path: "/assets/result.mp4",
        }}
      />,
    );
    const video = screen.getByLabelText("公開景品");
    expect(video).not.toHaveAttribute("autoplay");
    expect(video).toHaveAttribute("controls");
    rerender(
      <PublicAssetPreview
        asset={{ ...image, public_path: "https://external.example/a.png" }}
      />,
    );
    expect(screen.getByRole("img", { name: "Previewなし" })).toBeVisible();
  });

  it("renders stable rows without internal storage or probability fields", () => {
    const { container } = render(
      <CatalogDataTable
        resource="prizes"
        rows={[
          {
            asset: image,
            code: "prize-s",
            id: "01910191-0191-7191-8191-019101910192",
            name: "S景品",
            secondary: "S / 8000 Point交換",
            visible: true,
          },
        ]}
      />,
    );
    expect(screen.getByRole("link", { name: "S景品の詳細" })).toHaveAttribute(
      "href",
      "/catalog/prizes/01910191-0191-7191-8191-019101910192",
    );
    expect(container.textContent).not.toContain("storage_identifier");
    expect(container.textContent).not.toContain("probability_ppm");
  });

  it("renders Category and Tag columns in the V1 management order", () => {
    render(
      <CatalogDataTable
        resource="categories"
        rows={[
          {
            asset: null,
            code: "cards",
            id: "01910191-0191-7191-8191-019101910196",
            name: "カード",
            secondary: "cards",
            slug: "cards",
            sortOrder: 10,
            visible: true,
          },
        ]}
      />,
    );

    expect(
      screen.getAllByRole("columnheader").map((cell) => cell.textContent),
    ).toEqual(["ID", "カテゴリ名", "Slug", "表示順", "状態", "編集"]);
    expect(screen.getByRole("link", { name: "カードを編集" })).toHaveAttribute(
      "href",
      "/catalog/categories/01910191-0191-7191-8191-019101910196",
    );
  });

  it("renders reusable create form validation and submits normalized master input", async () => {
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogMutationForm
        mode="create"
        onCancel={vi.fn()}
        onSubmit={submit}
        resource="categories"
      />,
    );
    fireEvent.change(screen.getByLabelText("Code"), {
      target: { value: "new-category" },
    });
    fireEvent.change(screen.getByLabelText("Slug"), {
      target: { value: "new-category" },
    });
    fireEvent.change(screen.getByLabelText("名称"), {
      target: { value: "新しいCategory" },
    });
    fireEvent.change(screen.getByLabelText("説明"), {
      target: { value: "Plain text" },
    });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit).toHaveBeenCalledWith(
      expect.objectContaining({
        code: "new-category",
        description: "Plain text",
        name: "新しいCategory",
        slug: "new-category",
      }),
    );
  });

  it("separates stale revision and published-reference conflict guidance", () => {
    const confirm = vi.fn();
    const { rerender } = render(
      <CatalogConfirmationDialog
        busy={false}
        name="Category A"
        onCancel={vi.fn()}
        onConfirm={confirm}
      />,
    );
    expect(screen.getByRole("heading", { name: "Archiveしますか" })).toHaveFocus();
    fireEvent.click(screen.getByRole("button", { name: "Archive" }));
    expect(confirm).toHaveBeenCalledOnce();

    const reload = vi.fn();
    rerender(
      <CatalogConflictBoundary
        error={new AdminApiError(
          409,
          "CATALOG_REVISION_CONFLICT",
          null,
          null,
          false,
        )}
        onReload={reload}
      />,
    );
    expect(screen.getByText("最新状態との競合を検出しました")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "再取得" }));
    expect(reload).toHaveBeenCalledOnce();

    rerender(
      <CatalogConflictBoundary
        error={new AdminApiError(
          409,
          "CATALOG_PUBLISHED_REFERENCE_CONFLICT",
          null,
          null,
          false,
        )}
        onReload={reload}
      />,
    );
    expect(
      screen.getByText("公開中Gachaの参照により変更できません"),
    ).toBeInTheDocument();
    expect(screen.queryByText("最新状態との競合を検出しました")).toBeNull();
    expect(screen.getByText(/SlugまたはArchiveは変更できません/u)).toBeInTheDocument();
  });

  it("shows server validation feedback without a revision-conflict message", async () => {
    const submit = vi.fn().mockRejectedValue(
      new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
    );
    render(
      <CatalogMutationForm
        initial={{
          code: "cards",
          description: "説明",
          isVisible: true,
          name: "カード",
          slug: "cards",
          sortOrder: 10,
        }}
        mode="edit"
        onCancel={vi.fn()}
        onSubmit={submit}
        resource="categories"
      />,
    );
    fireEvent.change(screen.getByLabelText("名称"), {
      target: { value: "更新後カード" },
    });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() =>
      expect(screen.getByText("入力内容を確認してください。")).toBeInTheDocument(),
    );
    expect(screen.queryByText("最新状態との競合を検出しました")).toBeNull();
  });

  it("submits Presentation Asset registration without inventing upload behavior", async () => {
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogPrizeAssetMutationForm
        mode="create"
        onCancel={vi.fn()}
        onSubmit={submit}
        resource="presentation-assets"
      />,
    );
    fireEvent.change(screen.getByLabelText("Storage識別子"), {
      target: { value: "catalog/prize/new.png" },
    });
    fireEvent.change(screen.getByLabelText("Public Path"), {
      target: { value: "/assets/catalog/prize/new.png" },
    });
    fireEvent.change(screen.getByLabelText("SHA-256"), {
      target: { value: "a".repeat(64) },
    });
    fireEvent.change(screen.getByLabelText("MIME Type"), {
      target: { value: "image/png" },
    });
    fireEvent.change(screen.getByLabelText("Byte Size"), {
      target: { value: "128" },
    });
    fireEvent.change(screen.getByLabelText("Alt"), {
      target: { value: "新しい景品画像" },
    });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit).toHaveBeenCalledWith(
      expect.objectContaining({
        kind: "asset",
        storageIdentifier: "catalog/prize/new.png",
        publicPath: "/assets/catalog/prize/new.png",
        checksumSha256: "a".repeat(64),
        byteSize: 128,
      }),
    );
  });

  it("filters Banner candidates by Category, renders image and title, and submits the selected Asset ID", async () => {
    mockPrizeBannerPicker();
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogPrizeAssetMutationForm
        mode="create"
        onCancel={vi.fn()}
        onSubmit={submit}
        resource="prizes"
      />,
    );

    await screen.findByRole("option", { name: "Category A" });
    fillPrizeDraft();
    fireEvent.change(screen.getByLabelText("Banner Category"), {
      target: { value: CATEGORY_A_ID },
    });

    const bannerA = await screen.findByRole("button", { name: "Banner A" });
    expect(screen.queryByRole("button", { name: "Banner B" })).not.toBeInTheDocument();
    expect(bannerA.querySelector("img")).toHaveAttribute("src", "/banners/a.png");
    fireEvent.click(bannerA);
    expect(bannerA).toHaveAttribute("aria-pressed", "true");

    fireEvent.change(screen.getByLabelText("Banner Category"), {
      target: { value: CATEGORY_B_ID },
    });
    const bannerB = await screen.findByRole("button", { name: "Banner B" });
    expect(screen.queryByRole("button", { name: "Banner A" })).not.toBeInTheDocument();
    expect(bannerB).toHaveAttribute("aria-pressed", "false");
    fireEvent.click(bannerB);

    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit).toHaveBeenCalledWith(expect.objectContaining({
      kind: "prize",
      presentationAssetId: BANNER_B_ASSET_ID,
    }));
  });

  it("requires a replacement Banner after changing Category instead of keeping a stale selection", async () => {
    mockPrizeBannerPicker();
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogPrizeAssetMutationForm
        mode="create"
        onCancel={vi.fn()}
        onSubmit={submit}
        resource="prizes"
      />,
    );

    await screen.findByRole("option", { name: "Category A" });
    fillPrizeDraft();
    fireEvent.change(screen.getByLabelText("Banner Category"), {
      target: { value: CATEGORY_A_ID },
    });
    await screen.findByRole("button", { name: "Banner A" });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));

    expect(submit).not.toHaveBeenCalled();
    expect(screen.getByRole("alert")).toHaveTextContent(
      "選択したBanner CategoryからBannerを選択してください。",
    );
  });

  it("preserves an unresolved existing thumbnail when saving another Prize edit", async () => {
    mockPrizeBannerPicker();
    const submit = vi.fn().mockResolvedValue(undefined);
    render(
      <CatalogPrizeAssetMutationForm
        current={prizeWithPresentationAsset("01910191-0191-7191-8191-019101910199")}
        mode="edit"
        onCancel={vi.fn()}
        onSubmit={submit}
        resource="prizes"
      />,
    );

    expect(await screen.findByText("既存のPresentation Assetに対応するBannerを一意に特定できませんでした。", { exact: false })).toBeVisible();
    fireEvent.change(screen.getByLabelText("名称"), { target: { value: "更新後の景品" } });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));

    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit).toHaveBeenCalledWith(expect.objectContaining({
      name: "更新後の景品",
      presentationAssetId: "01910191-0191-7191-8191-019101910199",
    }));
  });

  it("initializes an existing thumbnail only when one Banner resolves to its Asset", async () => {
    mockPrizeBannerPicker();
    render(
      <CatalogPrizeAssetMutationForm
        current={prizeWithPresentationAsset(BANNER_A_ASSET_ID)}
        mode="edit"
        onCancel={vi.fn()}
        onSubmit={vi.fn().mockResolvedValue(undefined)}
        resource="prizes"
      />,
    );

    const bannerA = await screen.findByRole("button", { name: "Banner A" });
    expect(screen.getByLabelText("Banner Category")).toHaveValue(CATEGORY_A_ID);
    expect(bannerA).toHaveAttribute("aria-pressed", "true");
  });

  it("registers catalog.manage without changing read-only Operator navigation", () => {
    expect(ADMIN_PERMISSION_CODES).toContain("catalog.manage");
    expect(ADMIN_PERMISSION_CODES).toContain("catalog.read");
  });

  it("fails closed when an older read response has no mutation revision", () => {
    expect(hasCatalogMutationRevision(null)).toBe(true);
    expect(
      hasCatalogMutationRevision({
        code: "category-a",
        created_at: "2026-07-29T00:00:00Z",
        description: null,
        id: "01910191-0191-7191-8191-019101910193",
        is_visible: true,
        name: "Category A",
        slug: "category-a",
        sort_order: 1,
        updated_at: "2026-07-29T00:00:00Z",
      }),
    ).toBe(false);
  });
});

function fillPrizeDraft() {
  fireEvent.change(screen.getByLabelText("Code"), { target: { value: "banner-prize" } });
  fireEvent.change(screen.getByLabelText("Rank"), { target: { value: RANK_ID } });
  fireEvent.change(screen.getByLabelText("名称"), { target: { value: "バナー景品" } });
  fireEvent.change(screen.getByLabelText("表示価格"), { target: { value: "1000" } });
  fireEvent.change(screen.getByLabelText("交換Point"), { target: { value: "800" } });
}

function mockPrizeBannerPicker() {
  vi.spyOn(AdminApiClient.prototype, "listCatalogRanks").mockResolvedValue({
    items: [{
      code: "S",
      created_at: "2026-08-19T00:00:00Z",
      description: null,
      id: RANK_ID,
      is_archived: false,
      is_visible: true,
      name: "S",
      revision: 1,
      sort_order: 1,
      updated_at: "2026-08-19T00:00:00Z",
    }],
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "listBannerCategories").mockResolvedValue({
    items: [
      { id: CATEGORY_A_ID, name: "Category A" },
      { id: CATEGORY_B_ID, name: "Category B" },
    ],
  });
  vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockImplementation(async (query = {}) => ({
    items: query.category_id === CATEGORY_A_ID ? [banner("A")] : [banner("B")],
    next_cursor: null,
  }));
}

function banner(key: "A" | "B") {
  const categoryId = key === "A" ? CATEGORY_A_ID : CATEGORY_B_ID;
  const assetId = key === "A" ? BANNER_A_ASSET_ID : BANNER_B_ASSET_ID;
  return {
    asset: { id: assetId, public_url: `/banners/${key.toLowerCase()}.png` },
    category: { id: categoryId, name: `Category ${key}` },
    created_at: "2026-08-19T00:00:00Z",
    id: `01910191-0191-7191-8191-01910191010${key === "A" ? "6" : "7"}`,
    link_url: null,
    show_on_top: false,
    status: "draft" as const,
    title: `Banner ${key}`,
    updated_at: "2026-08-19T00:00:00Z",
    version_id: `01910191-0191-7191-8191-01910191010${key === "A" ? "8" : "9"}`,
    version_number: 1,
  };
}

function prizeWithPresentationAsset(presentationAssetId: string): AdminCatalogPrize {
  return {
    code: "banner-prize",
    created_at: "2026-08-19T00:00:00Z",
    description: null,
    display_price: 1000,
    exchange_points: 800,
    id: "01910191-0191-7191-8191-019101910110",
    is_archived: false,
    is_visible: true,
    name: "既存景品",
    presentation_asset: {
      alt_text: "既存画像",
      id: presentationAssetId,
      is_public: true,
      media_type: "image",
      mime_type: "image/png",
      public_path: "/assets/existing.png",
    },
    rank: { code: "S", id: RANK_ID, name: "S", sort_order: 1 },
    revision: 1,
    updated_at: "2026-08-19T00:00:00Z",
  };
}
