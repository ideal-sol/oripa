import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: vi.fn(() => ({
    permissions: new Set(["identity.line.read", "identity.line.manage"]),
  })),
}));
vi.mock("@/components/navigation/breadcrumb", () => ({
  Breadcrumb: () => <nav aria-label="breadcrumb" />,
}));
vi.mock("@/components/shell/admin-page-header", () => ({
  AdminPageHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: ({ open }: { open: boolean }) =>
    open ? <div role="dialog">Fresh MFA</div> : null,
}));

import { LineMessagingSettings } from "@/components/line/line-messaging-settings";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

const setting = {
  id: "01910191-0191-7191-8191-019101910191",
  blocked_count: 4,
  friend_add_url: "https://line.me/R/ti/p/example",
  friends_count: 25,
  linked_follow_message: "完了しました",
  login_relative_path: "/login",
  pending_follow_message: "{login_url} からログイン",
  reward_enabled: false,
  reward_expiration_days: 180,
  reward_point_amount: 0,
  revision: 1,
  updated_at: "2026-07-29T00:00:00Z",
};

function permissionContext(manage: boolean): ReturnType<typeof usePermissions> {
  const permissions = new Set(
    manage
      ? (["identity.line.read", "identity.line.manage"] as const)
      : (["identity.line.read"] as const),
  );
  return {
    error: null,
    hasPermission: (permission) => permissions.has(permission as "identity.line.read"),
    permissions,
    requestId: setting.id,
    retry: vi.fn(),
    role: manage ? "admin" : "operator",
    status: "ready",
  };
}

describe("LINE Messaging settings", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.mocked(usePermissions).mockReturnValue(permissionContext(true));
  });

  it("previews and saves the two messages through the server contract", async () => {
    vi.spyOn(AdminApiClient.prototype, "getLineMessagingSetting").mockResolvedValue({
      data: setting,
      request_id: setting.id,
    });
    const preview = vi.spyOn(
      AdminApiClient.prototype,
      "previewLineMessagingSetting",
    ).mockResolvedValue({
      linked_follow_message: "更新済み",
      pending_follow_message: "/login からログイン",
      reward_enabled: true,
      reward_expiration_days: 365,
      reward_point_amount: 500,
      request_id: setting.id,
    });
    const update = vi.spyOn(
      AdminApiClient.prototype,
      "updateLineMessagingSetting",
    ).mockResolvedValue({
      data: { ...setting, linked_follow_message: "更新済み", revision: 2 },
      idempotent_replay: false,
      request_id: setting.id,
    });

    render(<LineMessagingSettings />);
    const linked = await screen.findByRole("textbox", {
      name: "ログイン済みユーザー向け",
    });
    expect(screen.getByText("25人")).toBeVisible();
    expect(screen.getByText("4人")).toBeVisible();
    expect(screen.getByLabelText("LINE友だち追加URL")).toHaveValue(
      "https://line.me/R/ti/p/example",
    );
    fireEvent.change(linked, { target: { value: "更新済み" } });
    fireEvent.click(screen.getByRole("checkbox", {
      name: "ポイント付与を有効にする",
    }));
    fireEvent.change(screen.getByLabelText("付与ポイント数"), {
      target: { value: "500" },
    });
    fireEvent.change(screen.getByLabelText("有効期限日数"), {
      target: { value: "365" },
    });
    fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));

    await waitFor(() => expect(preview).toHaveBeenCalledWith(
      {
        linked_follow_message: "更新済み",
        pending_follow_message: "{login_url} からログイン",
        reward_enabled: true,
        reward_expiration_days: 365,
        reward_point_amount: 500,
      },
    ));
    expect(await screen.findByText("/login からログイン")).toBeVisible();

    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][0]).toMatchObject({
      expected_revision: 1,
      friend_add_url: "https://line.me/R/ti/p/example",
      linked_follow_message: "更新済み",
      reward_enabled: true,
      reward_expiration_days: 365,
      reward_point_amount: 500,
    });
    expect(update.mock.calls[0][1]).toMatch(/^[0-9a-f-]{36}$/u);
  });

  it("requires a positive bounded reward when enabled and resets it when disabled", async () => {
    vi.spyOn(AdminApiClient.prototype, "getLineMessagingSetting").mockResolvedValue({
      data: setting,
      request_id: setting.id,
    });

    render(<LineMessagingSettings />);
    const toggle = await screen.findByRole("checkbox", {
      name: "ポイント付与を有効にする",
    });
    fireEvent.click(toggle);
    expect(screen.getByRole("button", { name: "保存" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("付与ポイント数"), {
      target: { value: "1000001" },
    });
    expect(screen.getByRole("alert")).toHaveTextContent("1～1,000,000");
    fireEvent.change(screen.getByLabelText("付与ポイント数"), {
      target: { value: "100" },
    });
    expect(screen.getByRole("button", { name: "保存" })).toBeEnabled();
    fireEvent.click(toggle);
    expect(screen.getByLabelText("付与ポイント数")).toHaveValue(0);
    expect(screen.getByLabelText("付与ポイント数")).toBeDisabled();
  });

  it("opens the shared Fresh MFA boundary for a stale session", async () => {
    vi.spyOn(AdminApiClient.prototype, "getLineMessagingSetting").mockResolvedValue({
      data: setting,
      request_id: setting.id,
    });
    vi.spyOn(
      AdminApiClient.prototype,
      "updateLineMessagingSetting",
    ).mockRejectedValue(
      new AdminApiError(
        403,
        "FRESH_AUTHENTICATION_REQUIRED",
        setting.id,
        null,
        false,
      ),
    );

    render(<LineMessagingSettings />);
    const linked = await screen.findByRole("textbox", {
      name: "ログイン済みユーザー向け",
    });
    fireEvent.change(linked, { target: { value: "更新済み" } });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));

    expect(await screen.findByRole("dialog")).toHaveTextContent("Fresh MFA");
  });

  it("keeps Operator access read-only without exposing save actions", async () => {
    vi.mocked(usePermissions).mockReturnValue(permissionContext(false));
    vi.spyOn(AdminApiClient.prototype, "getLineMessagingSetting").mockResolvedValue({
      data: setting,
      request_id: setting.id,
    });

    render(<LineMessagingSettings />);

    expect(await screen.findByLabelText("LINE友だち追加URL")).toBeDisabled();
    expect(screen.getByLabelText("ログイン済みユーザー向け")).toBeDisabled();
    expect(screen.getByLabelText("ポイント付与を有効にする")).toBeDisabled();
    expect(screen.queryByRole("button", { name: "保存" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "プレビュー" })).not.toBeInTheDocument();
  });
});
