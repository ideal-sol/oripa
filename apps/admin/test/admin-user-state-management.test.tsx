import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AdminPermissionCode, AdminUserDetail } from "@/lib/admin-api/generated";

const update = vi.fn();
const permissions = new Set<AdminPermissionCode>();

vi.mock("@/lib/admin-api/client", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/lib/admin-api/client")>();
  return {
    ...original,
    AdminApiClient: class {
      updateAdminUserState = update;
    },
  };
});
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: (permission: AdminPermissionCode) => permissions.has(permission),
    permissions,
    role: "admin",
    status: "ready",
  }),
}));

import { AdminUserStateManagement } from "@/components/users/admin-user-state-management";

describe("Admin User state management", () => {
  beforeEach(() => {
    permissions.clear();
    update.mockReset();
    update.mockResolvedValue({
      data: { user_id: user.id, status: "suspended", state_revision: 2, updated_at: "2026-09-02T00:00:00Z" },
      idempotent_replay: false,
      request_id: uuid("8"),
    });
  });

  it("keeps Operator read-only", () => {
    render(<AdminUserStateManagement onRefresh={vi.fn()} user={user} />);
    expect(screen.getByText("有効")).toBeVisible();
    expect(screen.getByText("閲覧のみ")).toBeVisible();
    expect(screen.queryByRole("button", { name: "状態を変更" })).toBeNull();
  });

  it("offers only canonical active transitions and requires a reason", async () => {
    permissions.add("user.state.manage");
    render(<AdminUserStateManagement onRefresh={vi.fn()} user={user} />);
    fireEvent.click(screen.getByRole("button", { name: "状態を変更" }));

    const select = screen.getByLabelText("変更後の状態");
    expect(Array.from((select as HTMLSelectElement).options).map((option) => option.text)).toEqual([
      "選択してください", "停止", "退会",
    ]);
    fireEvent.change(select, { target: { value: "suspended" } });
    fireEvent.submit(screen.getByRole("button", { name: "確認して変更" }).closest("form")!);
    expect(screen.getByRole("alert")).toHaveTextContent("変更理由を入力してください");
    expect(update).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText("変更理由"), { target: { value: "  Support review.  " } });
    fireEvent.click(screen.getByRole("button", { name: "確認して変更" }));
    expect(update).toHaveBeenCalledOnce();
    expect(screen.queryByLabelText("現在のパスワード")).toBeNull();
  });

  it("updates with OCC and idempotency then requests canonical refetch", async () => {
    permissions.add("user.state.manage");
    const onRefresh = vi.fn();
    render(<AdminUserStateManagement onRefresh={onRefresh} user={user} />);
    fireEvent.click(screen.getByRole("button", { name: "状態を変更" }));
    fireEvent.change(screen.getByLabelText("変更後の状態"), { target: { value: "suspended" } });
    fireEvent.change(screen.getByLabelText("変更理由"), { target: { value: "Support review." } });
    fireEvent.click(screen.getByRole("button", { name: "確認して変更" }));
    expect(screen.queryByLabelText("現在のパスワード")).toBeNull();

    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update).toHaveBeenCalledWith(
      user.id,
      { status: "suspended", expected_revision: 1, reason: "Support review." },
      expect.stringMatching(/^[0-9a-f-]{36}$/u),
    );
    expect(onRefresh).toHaveBeenCalledOnce();
  });

  it("keeps closed terminal and permits restricted closure", () => {
    permissions.add("user.state.manage");
    const { rerender } = render(
      <AdminUserStateManagement onRefresh={vi.fn()} user={{ ...user, status: "closed" }} />,
    );
    expect(screen.queryByRole("button", { name: "状態を変更" })).toBeNull();
    rerender(<AdminUserStateManagement onRefresh={vi.fn()} user={{ ...user, status: "restricted" }} />);
    expect(screen.getByRole("button", { name: "状態を変更" })).toBeVisible();
  });

  it("sends an explicit forced closure with revision and idempotency", async () => {
    permissions.add("user.state.manage");
    render(<AdminUserStateManagement onRefresh={vi.fn()} user={user} />);
    fireEvent.click(screen.getByRole("button", { name: "状態を変更" }));
    fireEvent.change(screen.getByLabelText("変更後の状態"), { target: { value: "closed" } });
    expect(screen.getByLabelText("退会種別")).toHaveValue("normal");
    fireEvent.change(screen.getByLabelText("退会種別"), { target: { value: "forced" } });
    fireEvent.change(screen.getByLabelText("変更理由"), { target: { value: "Synthetic policy violation" } });
    fireEvent.click(screen.getByRole("button", { name: "確認して変更" }));
    await waitFor(() => expect(update).toHaveBeenCalledWith(user.id,
      { status: "closed", closure_kind: "forced", expected_revision: 1, reason: "Synthetic policy violation" }, expect.any(String)));
  });

  it("shows permanent financial closure without a reactivation action", () => {
    permissions.add("user.state.manage");
    render(<AdminUserStateManagement onRefresh={vi.fn()} user={{ ...user, status: "suspended",
      account_closure: { completed_at: "2026-10-28T00:00:00Z", closed_by: "self", closure_kind: "financial",
        financial_suspended_at: "2026-10-29T00:00:00Z", forfeited_points: 12500, forfeited_prizes: 3 } }} />);
    expect(screen.getByText("金融処理・再利用禁止停止")).toBeVisible();
    expect(screen.getByText("12500")).toBeVisible();
    expect(screen.queryByRole("button", { name: "状態を変更" })).toBeNull();
  });
});

const user: AdminUserDetail = {
  created_at: "2026-08-03T00:00:00Z",
  display_name: "Synthetic User",
  email: "user@example.test",
  email_verified_at: "2026-08-03T00:00:00Z",
  sms_verified: false,
  phone: null,
  verified_at: null,
  id: uuid("1"),
  point_balance: { free_balance: 200, paid_balance: 100, total_balance: 300 },
  state_revision: 1,
  status: "active",
  tag_assignment_revision: 1,
  tags: [],
  updated_at: "2026-08-03T01:00:00Z",
};

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
