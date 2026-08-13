import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AdminPermissionCode, AdminUserDetail } from "@/lib/admin-api/generated";

const getMode = vi.fn();
const saveMode = vi.fn();
const disableMode = vi.fn();
const permissions = new Set<AdminPermissionCode>();

vi.mock("@/lib/admin-api/client", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/lib/admin-api/client")>();
  return {
    ...original,
    AdminApiClient: class {
      disableQaTestUser = disableMode;
      getQaTestUserMode = getMode;
      saveQaTestUser = saveMode;
    },
  };
});
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: (permission: AdminPermissionCode) => permissions.has(permission),
    permissions,
    role: "owner",
    status: "ready",
  }),
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: ({ onSuccess, open }: { onSuccess: () => Promise<void>; open: boolean }) =>
    open ? <button onClick={() => void onSuccess()} type="button">本人確認を完了</button> : null,
}));

import { AdminUserQaTestMode } from "@/components/users/admin-user-qa-test-mode";

describe("Admin User QA test mode", () => {
  beforeEach(() => {
    permissions.clear();
    getMode.mockReset();
    saveMode.mockReset();
    disableMode.mockReset();
    getMode.mockResolvedValue({ mode: null, user_id: user.id });
    saveMode.mockResolvedValue({ data: mode(), idempotent_replay: false });
    disableMode.mockResolvedValue({
      data: { ...mode(), is_active: false, is_enabled: false },
      idempotent_replay: false,
    });
  });

  it("keeps the QA control unavailable without the canonical permission", () => {
    render(<AdminUserQaTestMode user={user} />);
    expect(screen.queryByRole("heading", { name: "テストユーザー" })).toBeNull();
    expect(getMode).not.toHaveBeenCalled();
  });

  it("enables an active User indefinitely through fresh authentication", async () => {
    permissions.add("qa.draw.manage");
    render(<AdminUserQaTestMode user={user} />);
    const reason = await screen.findByLabelText("設定理由");

    fireEvent.change(reason, {
      target: { value: "  Presentation QA  " },
    });
    fireEvent.click(screen.getByRole("button", { name: "ONにする" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));

    await waitFor(() => expect(saveMode).toHaveBeenCalledOnce());
    expect(saveMode).toHaveBeenCalledWith(
      user.id,
      { reason: "Presentation QA", revision: undefined },
      expect.stringMatching(/^[0-9a-f-]{36}$/u),
    );
    expect(getMode).toHaveBeenCalledTimes(2);
  });

  it("shows an indefinite active mode, supports OFF, and rejects non-active enablement", async () => {
    permissions.add("qa.draw.manage");
    getMode.mockResolvedValue({ mode: mode(), user_id: user.id });
    const { rerender } = render(<AdminUserQaTestMode user={user} />);
    expect(await screen.findByText("ON（無期限）")).toBeVisible();
    getMode.mockResolvedValue({ mode: null, user_id: user.id });
    fireEvent.click(screen.getByRole("button", { name: "OFFにする" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));
    await waitFor(() => expect(disableMode).toHaveBeenCalledOnce());
    expect(disableMode).toHaveBeenCalledWith(
      user.id,
      3,
      expect.stringMatching(/^[0-9a-f-]{36}$/u),
    );
    rerender(<AdminUserQaTestMode user={{ ...user, status: "suspended" }} />);
    expect(await screen.findByText("有効なUserだけをONにできます。")).toBeVisible();
    expect(screen.getByRole("button", { name: "ONにする" })).toBeDisabled();
  });
});

const user: AdminUserDetail = {
  created_at: "2026-08-13T00:00:00Z",
  display_name: "QA User",
  email: "qa-user@example.test",
  email_verified_at: "2026-08-13T00:00:00Z",
  id: uuid("1"),
  point_balance: { free_balance: 2000, paid_balance: 0, total_balance: 2000 },
  state_revision: 1,
  status: "active",
  tag_assignment_revision: 1,
  tags: [],
  updated_at: "2026-08-13T00:00:00Z",
};

function mode() {
  return {
    disabled_at: null,
    ends_at: null,
    id: uuid("2"),
    is_active: true,
    is_enabled: true,
    reason: "Presentation QA",
    revision: 3,
    starts_at: "2026-08-13T00:00:00Z",
    updated_at: "2026-08-13T00:00:00Z",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
