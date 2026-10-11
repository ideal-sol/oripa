import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { BannerImportWorkspace } from "@/components/banners/banner-import-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminBannerImportJob } from "@/lib/admin-api/generated";

const category = { id: "00000000-0000-4000-8000-000000000001", name: "カード", created_at: "2026-10-11T00:00:00Z" };
const job: AdminBannerImportJob = { id: "00000000-0000-4000-8000-000000000002", status: "completed", total: 1,
  processed: 1, created: 1, updated: 0, unchanged: 0, failed: 0, rows: [], created_at: "2026-10-11T00:00:00Z", completed_at: "2026-10-11T00:00:01Z" };
afterEach(() => vi.restoreAllMocks());

describe("Banner CSV import", () => {
  it("hides the disabled feature without submitting", async () => {
    const listing = vi.spyOn(AdminApiClient.prototype, "listBannerImports").mockResolvedValue({ enabled: false, items: [] });
    const submit = vi.spyOn(AdminApiClient.prototype, "createBannerImport");
    render(<BannerImportWorkspace categories={[category]} canManage />);
    await waitFor(() => expect(listing).toHaveBeenCalledOnce());
    expect(screen.queryByLabelText("CSVファイル")).not.toBeInTheDocument();
    expect(submit).not.toHaveBeenCalled();
  });

  it("defaults refresh off and submits only through the Admin client", async () => {
    vi.spyOn(AdminApiClient.prototype, "listBannerImports").mockResolvedValue({ enabled: true, items: [] });
    const submit = vi.spyOn(AdminApiClient.prototype, "createBannerImport").mockResolvedValue(job);
    render(<BannerImportWorkspace categories={[category]} canManage />);
    const file = new File(["管理ID,タイトル\nCARD-1,Title"], "synthetic.csv", { type: "text/csv" });
    Object.defineProperty(file, "arrayBuffer", { value: async () => new TextEncoder().encode("管理ID,タイトル\nCARD-1,Title").buffer });
    fireEvent.change(await screen.findByLabelText("CSVファイル"), { target: { files: [file] } });
    fireEvent.change(screen.getByLabelText("既定カテゴリ"), { target: { value: category.id } });
    expect(screen.getByLabelText("既存画像を再取得する")).not.toBeChecked();
    fireEvent.click(screen.getByRole("button", { name: "CSVを取り込む" }));
    await waitFor(() => expect(submit).toHaveBeenCalledOnce());
    expect(submit.mock.calls[0][0]).toMatchObject({ default_category_id: category.id, refresh_existing_images: false });
    expect(await screen.findByText("新規 1 / 更新 0 / 変更なし 0 / 失敗 0")).toBeVisible();
  });

  it("keeps Operator read-only and explains redacted failure URLs", async () => {
    const failed = { ...job, created: 0, failed: 1 };
    vi.spyOn(AdminApiClient.prototype, "listBannerImports").mockResolvedValue({ enabled: true, items: [failed] });
    vi.spyOn(AdminApiClient.prototype, "bannerImport").mockResolvedValue(failed);
    render(<BannerImportWorkspace categories={[category]} canManage={false} />);
    expect(await screen.findByText(/参照専用/)).toBeVisible();
    expect(screen.queryByLabelText("CSVファイル")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /completed/ }));
    expect(await screen.findByText(/新しい署名付きURLを取得・再入力/)).toBeVisible();
  });

  it("does not expose arbitrary backend exception text", () => {
    const error = new AdminApiError(503, "BANNER_IMPORT_FAILED", null, null, true);
    expect(error.message).toBe("管理APIで処理できませんでした。");
  });
});
