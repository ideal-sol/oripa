import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CatalogGachaCoreForm } from "@/components/catalog/catalog-gacha-forms";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminCatalogGacha } from "@/lib/admin-api/generated";

const CATEGORY_ID = "01910191-0191-7191-8191-019101910191";
const TAG_ID = "01910191-0191-7191-8191-019101910192";
const ASSET_ID = "01910191-0191-7191-8191-019101910193";

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listCatalogCategories").mockResolvedValue({
    items: [{
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
    }],
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "listCatalogTags").mockResolvedValue({
    items: [{
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
    }],
    next_cursor: null,
  });
});

afterEach(() => vi.restoreAllMocks());

describe("Gacha lifecycle editing", () => {
  it("limits an already-published Gacha to current presentation fields", async () => {
    renderForm(gacha("published", "2026-08-01T00:00:00Z"));

    await screen.findByRole("option", { name: "Category A" });
    expect(screen.getByLabelText("カテゴリ")).toBeEnabled();
    expect(screen.getByLabelText("消費ポイント")).toBeDisabled();
    expect(screen.getByLabelText("総口数")).toBeDisabled();
    expect(screen.getByLabelText(/1日規定回数/u)).toBeDisabled();
    expect(screen.getByLabelText("会員ランク")).toBeDisabled();
    expect(screen.getByLabelText("開始日時（Asia/Tokyo）")).toBeDisabled();
    expect(screen.getByLabelText("ガチャタイトル")).toBeEnabled();
    expect(screen.getByLabelText("終了日時（Asia/Tokyo）")).toBeEnabled();
    expect(screen.getByRole("option", { name: "販売停止" })).toBeVisible();
    expect(screen.getByRole("option", { name: "非公開" })).toBeVisible();
    expect(screen.queryByRole("option", { name: "予約公開" })).not.toBeInTheDocument();
  });

  it("allows only a future initial schedule to be changed or cancelled", async () => {
    const { unmount } = renderForm(gacha("scheduled", "2099-08-01T00:00:00Z"));

    await screen.findByRole("option", { name: "Category A" });
    expect(screen.getByLabelText("カテゴリ")).toBeEnabled();
    expect(screen.getByLabelText("消費ポイント")).toBeEnabled();
    expect(screen.getByLabelText(/1日規定回数/u)).toBeEnabled();
    expect(screen.getByLabelText("開始日時（Asia/Tokyo）")).toBeEnabled();
    expect(screen.getByRole("option", { name: "予約取消（下書きへ戻す）" }))
      .toBeVisible();
    expect(screen.queryByRole("option", { name: "販売停止" })).not.toBeInTheDocument();

    unmount();
    renderForm({
      ...gacha("scheduled", "2020-08-01T00:00:00Z"),
      first_published_at: "2020-08-01T00:00:00Z",
    });
    await screen.findByRole("option", { name: "Category A" });
    expect(screen.getByLabelText("開始日時（Asia/Tokyo）")).toBeDisabled();
    expect(screen.queryByRole("option", { name: "予約取消（下書きへ戻す）" }))
      .not.toBeInTheDocument();
    expect(screen.getByRole("option", { name: "公開中（予約開始済み）" }))
      .toBeVisible();
    expect(screen.getByRole("option", { name: "販売停止" })).toBeVisible();
  });

  it("keeps unpublished terminal in the management selector", async () => {
    renderForm(gacha("unpublished", "2026-08-01T00:00:00Z"));

    await screen.findByRole("option", { name: "Category A" });
    expect(screen.getAllByRole("option", { name: "非公開" })).toHaveLength(1);
    expect(screen.queryByRole("option", { name: "公開" })).not.toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "予約公開" })).not.toBeInTheDocument();
  });

  it("keeps every core field editable for a Draft", async () => {
    renderForm(gacha("draft", "2026-08-01T00:00:00Z"));

    await screen.findByRole("option", { name: "Category A" });
    expect(screen.getByLabelText("カテゴリ")).toBeEnabled();
    expect(screen.getByLabelText("消費ポイント")).toBeEnabled();
    expect(screen.getByLabelText("総口数")).toBeEnabled();
    expect(screen.getByLabelText(/1日規定回数/u)).toBeEnabled();
    expect(screen.getByLabelText("会員ランク")).toBeEnabled();
    expect(screen.getByLabelText("開始日時（Asia/Tokyo）")).toBeEnabled();
    expect(screen.getByRole("checkbox", { name: "5回" })).toBeEnabled();
  });

  it("shows mapped publish errors without Request ID or internal code", async () => {
    const submit = vi.fn().mockRejectedValue(new AdminApiError(
      422,
      "CATALOG_GACHA_PUBLISH_PRIZE_INSUFFICIENT",
      "01910191-0191-7191-8191-019101910199",
      null,
      false,
    ));
    render(
      <CatalogGachaCoreForm
        current={gacha("published", "2026-08-01T00:00:00Z")}
        mode="edit"
        onCancel={vi.fn()}
        onSubmit={submit}
      />,
    );

    await screen.findByRole("option", { name: "Category A" });
    fireEvent.click(screen.getByRole("button", { name: "編集内容を保存" }));

    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(await screen.findByText(/公開に必要な景品が不足/u)).toBeVisible();
    expect(screen.queryByText(/Request ID/u)).not.toBeInTheDocument();
    expect(screen.queryByText("CATALOG_GACHA_PUBLISH_PRIZE_INSUFFICIENT")).not.toBeInTheDocument();
  });
});

function renderForm(current: AdminCatalogGacha) {
  return render(
    <CatalogGachaCoreForm
      current={current}
      mode="edit"
      onCancel={vi.fn()}
      onSubmit={vi.fn().mockResolvedValue(undefined)}
    />,
  );
}

function gacha(
  publicationStatus: AdminCatalogGacha["publication_status"],
  publishStartAt: string,
): AdminCatalogGacha {
  return {
    archived_at: null,
    category: { code: "category-a", id: CATEGORY_ID, name: "Category A" },
    code: "gacha-code",
    created_at: "2026-08-01T00:00:00Z",
    current_version: {
      allowed_draw_counts: [1, 5, 10],
      audience_code: "all_users",
      daily_draw_limit: 0,
      description: "説明",
      first_time_eligible_days: 7,
      id: "01910191-0191-7191-8191-019101910194",
      notices: "注意",
      presentation_asset: {
        alt_text: "現在のサムネイル",
        id: ASSET_ID,
        is_public: true,
        media_type: "image",
        mime_type: "image/png",
        public_path: "/assets/gacha.png",
      },
      price_points: 100,
      publish_end_at: null,
      publish_start_at: publishStartAt,
      revision: 1,
      status: "published",
      title: "公開中ガチャ",
      total_count: 100,
      version_number: 1,
    },
    first_published_at: publicationStatus === "published" ? publishStartAt : null,
    has_draw_history: false,
    id: "01910191-0191-7191-8191-019101910195",
    is_archived: false,
    public_code: "A7k9P2x4Qm8",
    publication_status: publicationStatus,
    published_version: null,
    revision: 1,
    slug: "gacha-code",
    sold_count: 0,
    state: "active",
    tags: [{ code: "featured", id: TAG_ID, name: "Featured" }],
    updated_at: "2026-08-01T00:00:00Z",
    version_count: 1,
  };
}
