import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CatalogBannerAssetPicker } from "@/components/catalog/catalog-prize-asset-mutation-form";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminManagedBanner } from "@/lib/admin-api/generated";

const category = { id: "cards", name: "Cards" };
function banner(id: string, externalId: string | null): AdminManagedBanner {
  return {
    id, title: id, external_id: externalId, category,
    asset: { id: `asset-${id}`, public_url: "/synthetic-banner.png" },
    status: "published", show_on_top: false, link_url: null,
    version_id: `version-${id}`, version_number: 1, created_at: "", updated_at: "",
  };
}

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listBannerCategories").mockResolvedValue({ items: [category] });
});
afterEach(() => vi.restoreAllMocks());

describe("Prize Banner eligibility", () => {
  it("finishes pagination even when the first page contains only no-ID Banners", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockImplementation(async (query) => ({
      items: query?.cursor ? [banner("Eligible", "CARD-001")] : [banner("No ID", null)],
      next_cursor: query?.cursor ? null : "page-2",
    }));
    const selection = vi.fn();
    render(<CatalogBannerAssetPicker assetId={null} onSelectionChange={selection} />);
    await screen.findByRole("option", { name: "Cards" });
    fireEvent.change(screen.getByLabelText("Banner Category"), { target: { value: category.id } });
    fireEvent.click(await screen.findByRole("button", { name: "Eligible" }));
    expect(screen.queryByRole("button", { name: "No ID" })).not.toBeInTheDocument();
    expect(list).toHaveBeenCalledTimes(2);
    expect(list).toHaveBeenLastCalledWith({ category_id: category.id, cursor: "page-2" }, expect.any(AbortSignal));
    expect(selection).toHaveBeenLastCalledWith({ assetId: "asset-Eligible", bannerId: "Eligible", externalId: "CARD-001", changed: true });
  });

  it("explains a Category with no eligible Banners and offers no choices", async () => {
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({ items: [banner("No ID", null)], next_cursor: null });
    const selection = vi.fn();
    render(<CatalogBannerAssetPicker assetId={null} onSelectionChange={selection} />);
    await screen.findByRole("option", { name: "Cards" });
    fireEvent.change(screen.getByLabelText("Banner Category"), { target: { value: category.id } });
    expect(await screen.findByText("管理IDが設定されたBannerはありません。")).toBeVisible();
    expect(within(screen.getByRole("group", { name: "Banner" })).queryAllByRole("button")).toHaveLength(0);
    expect(selection).toHaveBeenCalledTimes(1);
    expect(selection).toHaveBeenCalledWith({ assetId: null, bannerId: null, changed: true });
  });

  it("does not mutate a legacy no-ID Asset on load or rerender, and offers only eligible replacements", async () => {
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({
      items: [banner("Legacy", null), banner("Eligible", "CARD-001")], next_cursor: null,
    });
    const selection = vi.fn();
    const view = render(<CatalogBannerAssetPicker assetId="asset-Legacy" onSelectionChange={selection} />);
    expect(await screen.findByText(/変更しなければ既存の値は保持されます/u)).toBeVisible();
    expect(selection).not.toHaveBeenCalled();
    view.rerender(<CatalogBannerAssetPicker assetId="asset-Legacy" onSelectionChange={selection} />);
    await waitFor(() => expect(selection).not.toHaveBeenCalled());
    fireEvent.change(screen.getByLabelText("Banner Category"), { target: { value: category.id } });
    expect(await screen.findByRole("button", { name: "Eligible" })).toBeEnabled();
    expect(screen.queryByRole("button", { name: "Legacy" })).not.toBeInTheDocument();
    expect(within(screen.getByRole("group", { name: "Banner" })).getAllByRole("button")).toHaveLength(1);
  });
});
