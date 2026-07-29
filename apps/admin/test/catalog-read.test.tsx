import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

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
import { navigationItem } from "@/lib/permissions/admin-navigation";

const image = {
  alt_text: "公開景品",
  id: "01910191-0191-7191-8191-019101910191",
  is_public: true,
  media_type: "image" as const,
  mime_type: "image/png",
  public_path: "/assets/prize.png",
};

describe("Admin Catalog read components", () => {
  it("keeps the Catalog registry typed, unique, and available", () => {
    expect(CATALOG_SECTIONS).toHaveLength(5);
    expect(new Set(CATALOG_SECTIONS.map((item) => item.resource)).size).toBe(5);
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

  it("provides focused Archive confirmation and stale conflict reload", () => {
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
    rerender(<CatalogConflictBoundary onReload={reload} />);
    fireEvent.click(screen.getByRole("button", { name: "再取得" }));
    expect(reload).toHaveBeenCalledOnce();
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
