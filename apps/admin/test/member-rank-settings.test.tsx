import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { memberRankFixture } from "./member-rank-fixture";

const permissionState = { permissions: new Set(["member_rank.settings.read", "member_rank.settings.manage"]) };
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => permissionState }));
vi.mock("@/components/navigation/breadcrumb", () => ({ Breadcrumb: () => null }));
vi.mock("@/components/shell/admin-page-header", () => ({ AdminPageHeader: ({ title }: { title: string }) => <h1>{title}</h1> }));
import { MemberRankSettings } from "@/components/settings/member-rank-settings";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

describe("Member rank settings", () => {
  beforeEach(() => {
    permissionState.permissions = new Set(["member_rank.settings.read", "member_rank.settings.manage"]);
    vi.spyOn(AdminApiClient.prototype, "getMemberRankSettings").mockResolvedValue({ data: memberRankFixture(), request_id: "test" });
    vi.spyOn(AdminApiClient.prototype, "getMemberRankSettingsHistory").mockResolvedValue({ items: [{ before: null, after: memberRankFixture().current }], next_before_revision: null, request_id: "test" });
  });
  afterEach(() => vi.restoreAllMocks());

  it("shows seven ranks, integer basis points, diffs and disabled activation", async () => {
    const setting = memberRankFixture();
    const update = vi.spyOn(AdminApiClient.prototype, "updateMemberRankSettings").mockResolvedValue({ data: { ...setting, revision: 2 }, request_id: "test", idempotent_replay: false });
    render(<MemberRankSettings />);
    expect(await screen.findByText("準備中・未開始")).toBeVisible();
    expect(screen.queryByRole("checkbox")).toBeNull();
    expect(screen.getByLabelText("ブロンズ 購入ボーナス率（無償）")).toHaveValue(0.5);
    fireEvent.change(screen.getByLabelText("ブロンズ 購入ボーナス率（無償）"), { target: { value: "0.75" } });
    fireEvent.click(screen.getByRole("button", { name: "変更内容を確認" }));
    expect(screen.getByText("ブロンズ 購入ボーナス率（無償）：0.50% → 0.75%")).toBeVisible();
    expect(update).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "確認して予約を保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][0].ranks[1].purchase_bonus_rate_bp).toBe(75);
    expect(update.mock.calls[0][0]).toMatchObject({ expected_revision: 1, enabled: false });
    expect(await screen.findByText("翌月の設定を予約しました。")).toBeVisible();
  });

  it("keeps Operator read-only", async () => {
    permissionState.permissions = new Set(["member_rank.settings.read"]);
    render(<MemberRankSettings />);
    expect(await screen.findByLabelText("VIP 到達ライン")).toBeDisabled();
    expect(screen.queryByRole("button", { name: "変更内容を確認" })).toBeNull();
  });

  it("validates monotonic thresholds and bonus limits", async () => {
    render(<MemberRankSettings />);
    fireEvent.change(await screen.findByLabelText("シルバー 到達ライン"), { target: { value: "10000" } });
    expect(screen.getByRole("button", { name: "変更内容を確認" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("シルバー 到達ライン"), { target: { value: "30000" } });
    fireEvent.change(screen.getByLabelText("ブロンズ 昇格ボーナス（無償）"), { target: { value: "1000001" } });
    expect(screen.getByRole("button", { name: "変更内容を確認" })).toBeDisabled();
  });

  it("retains an idempotency key after uncertain failure and exposes conflict reload", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateMemberRankSettings")
      .mockRejectedValueOnce(new Error("network"))
      .mockRejectedValueOnce(new AdminApiError(409, "MEMBER_RANK_SETTINGS_REVISION_CONFLICT", "test", null, false));
    render(<MemberRankSettings />);
    fireEvent.change(await screen.findByLabelText("ブロンズ 昇格ボーナス（無償）"), { target: { value: "600" } });
    fireEvent.click(screen.getByRole("button", { name: "変更内容を確認" }));
    fireEvent.click(screen.getByRole("button", { name: "確認して予約を保存" }));
    await screen.findByText("通信に失敗しました。同じ操作を再試行できます。");
    fireEvent.click(screen.getByRole("button", { name: "確認して予約を保存" }));
    await screen.findByText("設定が更新されたか、予約を取り消せません。最新の設定を再読み込みしてください。");
    expect(update.mock.calls[0][1]).toBe(update.mock.calls[1][1]);
  });

  it("confirms cancellation and sends expected revision", async () => {
    const setting = memberRankFixture();
    setting.scheduled = { ...setting.current, action: "schedule", revision: 2, effective_at: setting.next_effective_at };
    setting.revision = 2;
    vi.mocked(AdminApiClient.prototype.getMemberRankSettings).mockResolvedValue({ data: setting, request_id: "test" });
    const cancel = vi.spyOn(AdminApiClient.prototype, "cancelMemberRankSettings").mockResolvedValue({ data: { ...setting, revision: 3, scheduled: null }, request_id: "test", idempotent_replay: false });
    render(<MemberRankSettings />);
    fireEvent.click(await screen.findByRole("button", { name: "予約を取り消す" }));
    expect(cancel).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "確認して予約を取消" }));
    await screen.findByText("翌月の予約を取り消しました。");
    expect(cancel.mock.calls[0][0]).toBe(2);
  });
});
