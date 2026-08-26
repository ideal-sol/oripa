import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { RankEffectSettingsWorkspace } from "@/components/catalog/rank-effect-settings-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminCatalogRank, AdminRankEffect } from "@/lib/admin-api/generated";

const replace = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace }) }));
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
  vi.spyOn(AdminApiClient.prototype, "listRankEffects").mockResolvedValue({
    items: [effect()],
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "getRankEffect").mockResolvedValue({ data: effect() });
});

afterEach(() => vi.restoreAllMocks());

describe("Rank effect settings", () => {
  it("renders the V1-equivalent ordered list, preview, rank, state, and edit route", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listRankEffects");
    render(<RankEffectSettingsWorkspace mode="list" />);
    expect(await screen.findByRole("heading", { name: "ランク演出" })).toBeVisible();
    expect((await screen.findAllByRole("columnheader")).map((cell) => cell.textContent)).toEqual([
      "種別", "タイトル", "ランク", "プレビュー", "表示順", "状態", "更新日時", "操作",
    ]);
    expect(screen.getByText("当選演出")).toBeVisible();
    expect(screen.getByText("Sランク")).toBeVisible();
    expect(screen.getByRole("img", { name: "ランク演出プレビュー" })).toHaveAttribute(
      "src",
      `/admin/api/v2/catalog/presentation-assets/${effect().id}/content`,
    );
    expect(screen.getByRole("link", { name: "当選演出を編集" })).toHaveAttribute(
      "href",
      `/catalog/presentation-assets/${effect().id}/edit`,
    );
    expect(screen.getByLabelText("状態")).toHaveValue("visible");
    expect(list).toHaveBeenCalledWith(expect.objectContaining({ visibility: "visible" }), expect.any(AbortSignal));
    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "hidden" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: undefined, visibility: "hidden" }),
      expect.any(AbortSignal),
    ));
  });

  it("edits metadata without requiring a replacement file and preserves current preview", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateRankEffect")
      .mockResolvedValue({ data: { ...effect(), alt_text: "更新演出", revision: 2 }, idempotent_replay: false });
    render(<RankEffectSettingsWorkspace id={effect().id} mode="edit" />);
    expect(await screen.findByRole("heading", { name: "ランク演出編集" })).toBeVisible();
    expect(screen.getByLabelText("タイトル")).toHaveValue("当選演出");
    expect(screen.getByRole("img", { name: "ランク演出プレビュー" })).toBeVisible();
    expect(screen.getByLabelText("ファイル差し替え（任意）")).not.toBeRequired();
    expect(screen.queryByText("Rank relation")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "対象ランクと表示順" })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("タイトル"), { target: { value: "更新演出" } });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledWith(
      effect().id,
      expect.objectContaining({
        asset_type: "image",
        expected_revision: 1,
        title: "更新演出",
      }),
      expect.any(String),
    ));
    expect(update.mock.calls[0][1]).not.toHaveProperty("rank_assignments");
    expect(await screen.findByRole("img", { name: "ランク演出プレビュー" })).toHaveAttribute(
      "src",
      effect().content_path,
    );
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenLastCalledWith(
      effect().id,
      expect.objectContaining({ expected_revision: 2 }),
      expect.any(String),
    ));
    expect(replace).toHaveBeenCalledWith(`/catalog/presentation-assets/${effect().id}/edit`);
  });

  it("requires direct upload for new effects and exposes image/video choices", async () => {
    render(<RankEffectSettingsWorkspace mode="create" />);
    expect(await screen.findByRole("heading", { name: "ランク演出登録" })).toBeVisible();
    expect(screen.getByLabelText("ファイル")).toBeRequired();
    expect(screen.getByLabelText("画像")).toBeChecked();
    expect(screen.getByLabelText("動画")).not.toBeChecked();
    expect(screen.queryByText("Rank relation")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "対象ランクと表示順" })).not.toBeInTheDocument();
    expect(screen.queryByText(/バナー/u)).not.toBeInTheDocument();
  });
});

function rank(): AdminCatalogRank {
  return {
    archived_at: null,
    code: "S",
    created_at: "2026-08-05T00:00:00Z",
    description: null,
    id: uuid("1"),
    image_asset: null,
    is_archived: false,
    is_visible: true,
    name: "Sランク",
    revision: 1,
    sort_order: 4,
    updated_at: "2026-08-05T00:00:00Z",
    video_asset: null,
  };
}

function effect(): AdminRankEffect {
  return {
    alt_text: "当選演出",
    archived_at: null,
    byte_size: 68,
    checksum_sha256: "a".repeat(64),
    content_path: `/admin/api/v2/catalog/presentation-assets/${uuid("2")}/content`,
    created_at: "2026-08-05T00:00:00Z",
    id: uuid("2"),
    is_archived: false,
    is_public: true,
    media_type: "image",
    mime_type: "image/png",
    public_path: `/admin/api/v2/catalog/presentation-assets/${uuid("2")}/content`,
    rank_assignments: [{ rank: { code: "S", id: rank().id, name: "Sランク" }, sort_order: 4 }],
    revision: 1,
    updated_at: "2026-08-05T00:00:00Z",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
