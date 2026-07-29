import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CatalogDataTable } from "@/components/catalog/catalog-data-table";
import {
  PublicAssetPreview,
  safePublicPath,
} from "@/components/catalog/public-asset-preview";
import { CatalogSectionNavigation } from "@/components/catalog/catalog-section-navigation";
import { CATALOG_SECTIONS } from "@/lib/catalog/catalog-registry";
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
});
