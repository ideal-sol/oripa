import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { RankEffectSettingsWorkspace } from "@/components/catalog/rank-effect-settings-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminRankEffect } from "@/lib/admin-api/generated";

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
  it("hides historical images and blocks direct default switching", async () => {
    vi.spyOn(AdminApiClient.prototype, "listRankEffects").mockResolvedValue({
      items: [{ ...effect(), is_default: true }, { ...effect(), id: uuid("3"), alt_text: "Other video" },
        { ...effect(), id: uuid("4"), media_type: "image", alt_text: "Historical image" }],
      next_cursor: null,
    });
    const update = vi.spyOn(AdminApiClient.prototype, "updateRankVideo")
      .mockResolvedValue({ data: { ...effect(), is_default: false, revision: 2 }, idempotent_replay: false });
    render(<RankEffectSettingsWorkspace mode="list" />);
    expect(await screen.findByRole("button", { name: "デフォルトを解除" })).toBeEnabled();
    expect(screen.getByRole("button", { name: "デフォルトに設定" })).toBeDisabled();
    expect(screen.queryByText("Historical image")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "デフォルトを解除" }));
    await waitFor(() => expect(update).toHaveBeenCalledWith(effect().id,
      expect.objectContaining({ is_default: false, expected_revision: 1, asset_type: "video" }), expect.any(String)));
  });

  it("allows default title editing but disables lifecycle changes", async () => {
    vi.spyOn(AdminApiClient.prototype, "getRankEffect").mockResolvedValue({ data: { ...effect(), is_default: true } });
    render(<RankEffectSettingsWorkspace mode="edit" id={effect().id} />);
    expect(await screen.findByLabelText("タイトル")).toBeEnabled();
    expect(screen.getByLabelText("状態")).toBeDisabled();
    expect(screen.getByLabelText("ファイル差し替え（任意）")).toBeDisabled();
  });

  it("renders the relation-free Asset Master list and edit route", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listRankEffects");
    render(<RankEffectSettingsWorkspace mode="list" />);
    expect(await screen.findByRole("heading", { name: "ランク動画" })).toBeVisible();
    expect((await screen.findAllByRole("columnheader")).map((cell) => cell.textContent)).toEqual([
      "種別", "タイトル", "プレビュー", "状態", "デフォルト", "更新日時", "操作",
    ]);
    expect(screen.getByText("当選演出")).toBeVisible();
    expect(screen.queryByText("Sランク")).not.toBeInTheDocument();
    expect(screen.getByLabelText("ランク動画プレビュー")).toHaveAttribute(
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
    const update = vi.spyOn(AdminApiClient.prototype, "updateRankVideo")
      .mockResolvedValue({ data: { ...effect(), alt_text: "更新演出", revision: 2 }, idempotent_replay: false });
    render(<RankEffectSettingsWorkspace id={effect().id} mode="edit" />);
    expect(await screen.findByRole("heading", { name: "ランク動画編集" })).toBeVisible();
    expect(screen.getByLabelText("タイトル")).toHaveValue("当選演出");
    expect(screen.getByLabelText("ランク動画プレビュー")).toBeVisible();
    expect(screen.getByLabelText("ファイル差し替え（任意）")).not.toBeRequired();
    expect(screen.queryByText("Rank relation")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "対象ランクと表示順" })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("タイトル"), { target: { value: "更新演出" } });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledWith(
      effect().id,
      expect.objectContaining({
        asset_type: "video",
        expected_revision: 1,
        title: "更新演出",
      }),
      expect.any(String),
    ));
    expect(update.mock.calls[0][1]).not.toHaveProperty("rank_assignments");
    expect(await screen.findByLabelText("ランク動画プレビュー")).toHaveAttribute(
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

  it("requires video uploads without image or media type controls", async () => {
    render(<RankEffectSettingsWorkspace mode="create" />);
    expect(await screen.findByRole("heading", { name: "ランク動画登録" })).toBeVisible();
    expect(screen.getByLabelText("ファイル")).toBeRequired();
    expect(screen.queryByRole("radio")).not.toBeInTheDocument();
    expect(screen.getByLabelText("ファイル")).toHaveAttribute("accept", "video/mp4,video/webm,video/quicktime");
    expect(screen.queryByText("Rank relation")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "対象ランクと表示順" })).not.toBeInTheDocument();
    expect(screen.queryByText(/バナー/u)).not.toBeInTheDocument();
  });
});

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
    media_type: "video",
    mime_type: "video/mp4",
    public_path: `/admin/api/v2/catalog/presentation-assets/${uuid("2")}/content`,
    revision: 1,
    updated_at: "2026-08-05T00:00:00Z",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
