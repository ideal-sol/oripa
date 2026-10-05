import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const permissionState = { permissions: new Set(["catalog.read", "catalog.manage"]) };
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => permissionState }));
vi.mock("@/components/navigation/breadcrumb", () => ({ Breadcrumb: () => <nav aria-label="breadcrumb" /> }));

import { GachaNoticeSettings } from "@/components/settings/gacha-notice-settings";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import { navigationItem, navigationGroupForRoute } from "@/lib/permissions/admin-navigation";

const data = { standard: { default_notices: "Standard A", revision: 1 }, login: { default_notices: "Login A", revision: 1 } };
const standardLabel = "通常ガチャのデフォルト注意事項";
const loginLabel = "ログイン・新規登録限定ガチャのデフォルト注意事項";

describe("Gacha notice settings", () => {
  beforeEach(() => {
    vi.spyOn(AdminApiClient.prototype, "getGachaNoticeDefaults").mockResolvedValue({ data, request_id: "request" });
  });
  afterEach(() => { permissionState.permissions = new Set(["catalog.read", "catalog.manage"]); vi.restoreAllMocks(); });

  it("registers the settings route under the existing menu", () => {
    expect(navigationItem("gacha-notice-settings")).toMatchObject({ path: "/settings/gacha-notices", permission: "catalog.read", label: "ガチャ注意事項設定" });
    expect(navigationGroupForRoute("gacha-notice-settings")?.label).toBe("各種設定");
  });

  it("loads both values and uses the returned revisions for consecutive saves", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaNoticeDefaults").mockImplementation(async (body) => ({
      data: { standard: { default_notices: body.standard.default_notices, revision: body.standard.expected_revision + 1 }, login: { default_notices: body.login.default_notices, revision: body.login.expected_revision + 1 } },
      request_id: "request", idempotent_replay: false,
    }));
    render(<GachaNoticeSettings />);
    expect(await screen.findByLabelText(standardLabel)).toHaveValue("Standard A");
    expect(screen.getByLabelText(loginLabel)).toHaveValue("Login A");
    expect(screen.getByLabelText(standardLabel)).toHaveAttribute("maxlength", "10000");
    fireEvent.change(screen.getByLabelText(standardLabel), { target: { value: "Standard B" } });
    fireEvent.change(screen.getByLabelText(loginLabel), { target: { value: "Login B" } });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    expect(screen.getByRole("button", { name: "保存中" })).toBeDisabled();
    await screen.findByText("設定を保存しました。");
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledTimes(2));
    expect(update.mock.calls[1][0]).toEqual({ standard: { default_notices: "Standard B", expected_revision: 2 }, login: { default_notices: "Login B", expected_revision: 2 } });
    expect(update.mock.calls[1][1]).not.toEqual(update.mock.calls[0][1]);
  });

  it("retains the retry key after a network failure and reloads after OCC conflict", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaNoticeDefaults")
      .mockRejectedValueOnce(new AdminApiError(0, "NETWORK_ERROR", null, null, true))
      .mockRejectedValueOnce(new AdminApiError(409, "GACHA_NOTICE_DEFAULT_REVISION_CONFLICT", null, null, false));
    render(<GachaNoticeSettings />);
    await screen.findByLabelText(standardLabel);
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await screen.findByRole("alert");
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await screen.findByText("設定が更新されています。最新内容を再取得してください。");
    expect(update.mock.calls[0][1]).toEqual(update.mock.calls[1][1]);
    expect(screen.getByRole("button", { name: "保存" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "再読み込み" }));
    await waitFor(() => expect(screen.queryByRole("alert")).toBeNull());
    expect(screen.getByRole("button", { name: "保存" })).toBeEnabled();
  });

  it("supports initial load retry and read-only permissions", async () => {
    permissionState.permissions = new Set(["catalog.read"]);
    vi.mocked(AdminApiClient.prototype.getGachaNoticeDefaults).mockRejectedValueOnce(new Error("offline"));
    render(<GachaNoticeSettings />);
    await screen.findByRole("alert");
    fireEvent.click(screen.getByRole("button", { name: "再読み込み" }));
    expect(await screen.findByLabelText(standardLabel)).toBeDisabled();
    expect(screen.getByLabelText(loginLabel)).toBeDisabled();
    expect(screen.queryByRole("button", { name: "保存" })).toBeNull();
  });
});
