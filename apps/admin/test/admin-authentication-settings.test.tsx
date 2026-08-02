import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const policy = {
  active_owner_count: 1,
  id: "01910191-0191-7191-8191-019101910191",
  invitation_required: false,
  mfa_enrolled_admin_count: 1,
  mfa_required: false,
  revision: 1,
  updated_at: "2026-08-17T00:00:00Z",
};
const api = {
  createAdminAccount: vi.fn(),
  getAuthenticationPolicy: vi.fn(),
  updateAuthenticationPolicy: vi.fn(),
};

vi.mock("@/lib/admin-api/client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/admin-api/client")>(
    "@/lib/admin-api/client",
  );
  return {
    ...actual,
    AdminApiClient: class {
      createAdminAccount = api.createAdminAccount;
      getAuthenticationPolicy = api.getAuthenticationPolicy;
      updateAuthenticationPolicy = api.updateAuthenticationPolicy;
    },
  };
});

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: () => null,
}));

import { AdminAuthenticationSettings } from "@/components/auth/admin-authentication-settings";

describe("AdminAuthenticationSettings", () => {
  beforeEach(() => {
    api.getAuthenticationPolicy.mockReset().mockResolvedValue({ data: policy });
    api.updateAuthenticationPolicy.mockReset().mockResolvedValue({
      data: { ...policy, mfa_required: true, revision: 2 },
      idempotent_replay: false,
    });
    api.createAdminAccount.mockReset().mockResolvedValue({
      data: {
        admin: { id: policy.id, role: "admin", state: "active" },
        invitation_expires_at: null,
        invitation_token: null,
      },
    });
  });

  it("loads canonical policy and submits toggles with password and revision", async () => {
    render(<AdminAuthenticationSettings />);

    expect(await screen.findByRole("heading", { name: "ログイン要件" })).toBeInTheDocument();
    expect(screen.getAllByText("1人", { selector: "dd" })).toHaveLength(2);
    fireEvent.click(screen.getByRole("checkbox", { name: "多要素認証を必須にする" }));
    fireEvent.change(screen.getByLabelText("現在のパスワード"), {
      target: { value: "current owner password" },
    });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    fireEvent.click(screen.getByRole("button", { name: "変更を確定" }));

    await waitFor(() => expect(api.updateAuthenticationPolicy).toHaveBeenCalledOnce());
    expect(api.updateAuthenticationPolicy.mock.calls[0][0]).toEqual({
      current_password: "current owner password",
      expected_revision: 1,
      invitation_required: false,
      mfa_required: true,
    });
    expect(api.updateAuthenticationPolicy.mock.calls[0][1]).toMatch(/^[0-9a-f-]{36}$/u);
    expect(screen.getByText("認証設定を保存しました。")).toBeInTheDocument();
  });

  it("switches direct creation form to invitation mode from canonical policy", async () => {
    api.getAuthenticationPolicy.mockResolvedValueOnce({
      data: { ...policy, invitation_required: true },
    });
    api.createAdminAccount.mockResolvedValueOnce({
      data: {
        admin: { id: policy.id, role: "operator", state: "invited" },
        invitation_expires_at: "2026-08-17T00:30:00Z",
        invitation_token: "a".repeat(64),
      },
    });
    render(<AdminAuthenticationSettings />);

    await screen.findByRole("heading", { name: "管理者を追加" });
    expect(screen.queryByLabelText("一時パスワード")).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("メールアドレス"), {
      target: { value: "operator@example.test" },
    });
    fireEvent.change(screen.getByLabelText("Role"), { target: { value: "operator" } });
    fireEvent.click(screen.getByRole("button", { name: "管理者を追加" }));

    await waitFor(() => expect(api.createAdminAccount).toHaveBeenCalledWith({
      email: "operator@example.test",
      role: "operator",
    }));
    expect(screen.getByText("a".repeat(64))).toBeInTheDocument();
  });
});
