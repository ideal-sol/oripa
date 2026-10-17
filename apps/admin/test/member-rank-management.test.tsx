import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AdminMemberRankHistoryCollection, AdminMemberRankPreview, AdminPermissionCode, AdminUserDetail } from "@/lib/admin-api/generated";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

const permissions = new Set<AdminPermissionCode>();
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ hasPermission: (code: AdminPermissionCode) => permissions.has(code) }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: ReactNode }) => children }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: ReactNode }) => children }));
import { MemberRankDetail } from "@/components/member-ranks/member-rank-detail";
import { MemberRankSummary } from "@/components/member-ranks/member-rank-summary";

const preview: AdminMemberRankPreview = {
  month: "2026-10-01", expected_revision: 3, setting_revision: 1, before_rank: "gold", after_rank: "gold",
  before_points: 0, after_points: 10000, before_skip: 1, after_skip: 1, grants: [], recoveries: [], excluded_ranks: [],
  total_bonus: 0, last_transfer: null, preview_token: "a".repeat(64),
};
const user: AdminUserDetail = {
  id: "01910191-0191-7191-8191-019101910191", display_name: "Synthetic", status: "active", point_balance: null,
  created_at: "2026-10-01T00:00:00Z", updated_at: "2026-10-01T00:00:00Z", email: "synthetic@example.test",
  email_verified_at: null, sms_verified: false, phone: null, verified_at: null, state_revision: 1,
  member_rank_detail: { enabled: true, rank: "gold", month: "2026-10-01", rank_points: 0, revision: 3,
    default_skip_count: 2, skip_count: 1, guaranteed_until: "2026-11-30T14:59:59Z", transferred_at: null,
    last_transfer: null, next_rank: null, maintain: null, achievements: [], frozen_at: null },
};

describe("Member rank management", () => {
  beforeEach(() => {
    permissions.clear(); permissions.add("member_rank.user.manage");
    Object.defineProperty(HTMLDialogElement.prototype, "showModal", { configurable: true,
      value: function (this: HTMLDialogElement) { this.setAttribute("open", ""); } });
    vi.spyOn(AdminApiClient.prototype, "getMemberRankHistory").mockResolvedValue({ items: [], next_cursor: null, request_id: "test" });
    vi.spyOn(AdminApiClient.prototype, "previewMemberRank").mockResolvedValue({ data: { ...preview }, request_id: "test" });
  });

  it("uses the effective default skip and hides grants when lowering", () => {
    render(<MemberRankDetail user={user} onRefresh={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "ランクを変更" }));
    expect(screen.getByLabelText("降格判定の見送り回数")).toHaveValue(2);
    expect(screen.getByLabelText("昇格ボーナス（無償）")).toHaveValue("false");
    fireEvent.change(screen.getByLabelText("変更後のランク"), { target: { value: "silver" } });
    expect(screen.queryByLabelText("昇格ボーナス（無償）")).toBeNull();
  });

  it("retains the exact key after uncertain failure and forces a new preview after conflict", async () => {
    const save = vi.spyOn(AdminApiClient.prototype, "saveMemberRank")
      .mockRejectedValueOnce(new Error("network failure"))
      .mockRejectedValueOnce(new AdminApiError(409, "MEMBER_RANK_REVISION_CONFLICT", "test", null, false));
    render(<MemberRankDetail user={user} onRefresh={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "ランクポイントを調整" }));
    fireEvent.change(screen.getByLabelText("ランクポイント増減"), { target: { value: "10000" } });
    fireEvent.change(screen.getByLabelText("理由", { exact: true }), { target: { value: "Support" } });
    fireEvent.click(screen.getByRole("button", { name: "変更内容を確認" }));
    fireEvent.click(await screen.findByRole("button", { name: "確認して保存" }));
    await screen.findByText("network failure");
    fireEvent.click(screen.getByRole("button", { name: "確認して保存" }));
    await screen.findByText("状態が変わりました。最新の差分を再確認してください。");
    expect(save.mock.calls[0][3]).toBe(save.mock.calls[1][3]);
    expect(screen.queryByRole("button", { name: "確認して保存" })).toBeNull();
  });

  it("requires explicit confirmation tied to the last transfer", async () => {
    const last = { id: "01910191-0191-7191-8191-019101910192", user_id: user.id, actor_id: null, reason: "manual" as const,
      reason_text: "Previous transfer", kind: "transfer" as const, from_rank: "beginner", to_rank: "gold", month: "2026-10-01",
      rank_points: 0, requested_delta: null, applied_delta: null, grant_bonus: false, bonus_amount: 0, skip_count: 1,
      setting_revision: 1, origin_id: null, occurred_at: "2026-10-10T00:00:00Z" };
    vi.mocked(AdminApiClient.prototype.previewMemberRank).mockResolvedValue({ data: { ...preview, last_transfer: last }, request_id: "test" });
    const save = vi.spyOn(AdminApiClient.prototype, "saveMemberRank").mockResolvedValue({ data: preview, idempotent_replay: false, request_id: "test" });
    render(<MemberRankDetail user={user} onRefresh={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "ランクを変更" }));
    fireEvent.change(screen.getByLabelText("種別", { exact: true }), { target: { value: "transfer" } });
    fireEvent.change(screen.getByLabelText("理由", { exact: true }), { target: { value: "Repeat" } });
    fireEvent.click(screen.getByRole("button", { name: "変更内容を確認" }));
    const button = await screen.findByRole("button", { name: "確認して保存" });
    expect(button).toBeDisabled();
    fireEvent.click(screen.getByRole("checkbox"));
    fireEvent.click(button);
    await waitFor(() => expect(save).toHaveBeenCalledOnce());
    expect(save.mock.calls[0][2]).toMatchObject({ confirmed_transfer_id: last.id });
  });

  it("keeps Operator and closed accounts read-only", () => {
    permissions.clear();
    const { rerender } = render(<MemberRankDetail user={user} onRefresh={vi.fn()} />);
    expect(screen.queryByRole("button", { name: "ランクを変更" })).toBeNull();
    permissions.add("member_rank.user.manage");
    rerender(<MemberRankDetail user={{ ...user, status: "closed" }} onRefresh={vi.fn()} />);
    expect(screen.queryByRole("button", { name: "ランクポイントを調整" })).toBeNull();
  });

  it("discards an old rank-history page after switching to point history", async () => {
    let finish!: (value: AdminMemberRankHistoryCollection) => void;
    const deferred = new Promise<AdminMemberRankHistoryCollection>((resolve) => { finish = resolve; });
    const history = vi.mocked(AdminApiClient.prototype.getMemberRankHistory)
      .mockResolvedValueOnce({ items: [], next_cursor: "old-page", request_id: "test" })
      .mockImplementationOnce(() => deferred)
      .mockResolvedValueOnce({ items: [], next_cursor: null, request_id: "test" });
    render(<MemberRankDetail user={user} onRefresh={vi.fn()} />);
    fireEvent.click(await screen.findByText("次の履歴を表示"));
    const signal = history.mock.calls[1][3]!;
    fireEvent.change(screen.getByLabelText("履歴の種類"), { target: { value: "points" } });
    expect(signal.aborted).toBe(true);
    await act(async () => { finish({ items: [], next_cursor: "stale-page", request_id: "test" }); });
    await waitFor(() => expect(history).toHaveBeenCalledTimes(3));
    expect(screen.queryByText("次の履歴を表示")).toBeNull();
  });

  it("marks only a real shortfall as requiring review", async () => {
    vi.mocked(AdminApiClient.prototype.previewMemberRank).mockResolvedValue({ request_id: "test", data: {
      ...preview, recoveries: [
        { rank: "bronze", amount: 500, recovered_amount: 500, shortfall_amount: 0, point_type: "free", expires_in_days: 180 },
        { rank: "silver", amount: 2000, recovered_amount: 1000, shortfall_amount: 1000, point_type: "free", expires_in_days: 180 },
      ],
    } });
    render(<MemberRankDetail user={user} onRefresh={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "ランクポイントを調整" }));
    fireEvent.change(screen.getByLabelText("ランクポイント増減"), { target: { value: "-10000" } });
    fireEvent.change(screen.getByLabelText("理由", { exact: true }), { target: { value: "Recovery" } });
    fireEvent.click(screen.getByRole("button", { name: "変更内容を確認" }));
    expect(await screen.findByText("ブロンズ 昇格ボーナス回収 500pt / 不足 0pt（全額回収）")).toBeVisible();
    expect(screen.getByText("シルバー 昇格ボーナス回収 1,000pt / 不足 1,000pt（要確認）")).toBeVisible();
  });

  it("discards an old manual-history page after applying new filters", async () => {
    let finish!: (value: AdminMemberRankHistoryCollection) => void;
    const deferred = new Promise<AdminMemberRankHistoryCollection>((resolve) => { finish = resolve; });
    vi.spyOn(AdminApiClient.prototype, "getMemberRankSummary").mockResolvedValue({ request_id: "test", data: {
      enabled: true, month: "2026-10-01", active_total: 0, ranks: [], reviews: [],
      promotion_bonus: { count: 0, amount: 0, point_type: "free" }, purchase_bonus: { count: 0, amount: 0, point_type: "free" },
      progress: { provisional: false, remaining: 0, last_completed_month: null, status: null, lease_expired: false },
    } });
    const history = vi.spyOn(AdminApiClient.prototype, "listMemberRankManualChanges")
      .mockResolvedValueOnce({ items: [], next_cursor: "old-page", request_id: "test" })
      .mockImplementationOnce(() => deferred)
      .mockResolvedValueOnce({ items: [], next_cursor: null, request_id: "test" });
    render(<MemberRankSummary />);
    fireEvent.click(await screen.findByText("次の変更履歴を表示"));
    const signal = history.mock.calls[1][1]!;
    fireEvent.change(screen.getByLabelText("種別"), { target: { value: "transfer" } });
    fireEvent.click(screen.getByText("履歴を検索"));
    expect(signal.aborted).toBe(true);
    await act(async () => { finish({ items: [], next_cursor: "stale-page", request_id: "test" }); });
    await waitFor(() => expect(history).toHaveBeenCalledTimes(3));
    expect(history.mock.calls[2][0]).toMatchObject({ kind: "transfer" });
    expect(screen.queryByText("次の変更履歴を表示")).toBeNull();
  });
});
