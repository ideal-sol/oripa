import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const permissionState = {
  permissions: new Set(["shipping.settings.read", "shipping.settings.manage"]),
};

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => permissionState,
}));
vi.mock("@/components/navigation/breadcrumb", () => ({
  Breadcrumb: () => <nav aria-label="breadcrumb" />,
}));
vi.mock("@/components/shell/admin-page-header", () => ({
  AdminPageHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

import { ShippingMinimumPointSettings } from "@/components/settings/shipping-minimum-point-settings";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

const setting = { id: "01900000-0000-7000-8000-000000000098", minimum_points: "0", revision: 1, updated_by_admin_id: null, updated_at: "2026-10-23T00:00:00Z" };
afterEach(() => { vi.restoreAllMocks(); permissionState.permissions = new Set(["shipping.settings.read", "shipping.settings.manage"]); });
describe("Shipping minimum points", () => {
  it("preserves the maximum integer and requires save confirmation", async () => {
    vi.spyOn(AdminApiClient.prototype, "getShippingMinimumPointSetting").mockResolvedValue({data: setting, request_id: setting.id});
    const save = vi.spyOn(AdminApiClient.prototype, "updateShippingMinimumPointSetting").mockResolvedValue({data: {...setting, minimum_points: "9007199254740991", revision: 2}, request_id: setting.id, idempotent_replay: false});
    render(<ShippingMinimumPointSettings />);
    const input = await screen.findByLabelText(/発送下限ポイント（/);
    fireEvent.change(input, {target: {value: "9007199254740992"}});
    expect(screen.getByRole("button", {name: "保存内容を確認"})).toBeDisabled();
    fireEvent.change(input, {target: {value: "9007199254740991"}});
    fireEvent.click(screen.getByRole("button", {name: "保存内容を確認"}));
    expect(save).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", {name: "変更を確定"}));
    await waitFor(() => expect(save).toHaveBeenCalledOnce());
    expect(save.mock.calls[0][0]).toEqual({minimum_points: "9007199254740991", expected_revision: 1});
    expect(await screen.findByText("設定を保存しました。")).toBeVisible();
  });
  it("renders Operator as read only", async () => {
    permissionState.permissions = new Set(["shipping.settings.read"]);
    vi.spyOn(AdminApiClient.prototype, "getShippingMinimumPointSetting").mockResolvedValue({data: setting, request_id: setting.id});
    render(<ShippingMinimumPointSettings />);
    expect(await screen.findByLabelText(/発送下限ポイント（/)).toBeDisabled();
    expect(screen.queryByRole("button", {name: "保存内容を確認"})).toBeNull();
  });
  it("keeps the idempotency key on uncertain failure and offers revision reload", async () => {
    vi.spyOn(AdminApiClient.prototype, "getShippingMinimumPointSetting").mockResolvedValue({data: setting, request_id: setting.id});
    const save = vi.spyOn(AdminApiClient.prototype, "updateShippingMinimumPointSetting")
      .mockRejectedValueOnce(new Error("network"))
      .mockRejectedValueOnce(new AdminApiError(409, "SHIPPING_SETTING_REVISION_CONFLICT", null, null, false));
    render(<ShippingMinimumPointSettings />);
    fireEvent.change(await screen.findByLabelText(/発送下限ポイント（/), {target: {value: "100"}});
    fireEvent.click(screen.getByRole("button", {name: "保存内容を確認"}));
    fireEvent.click(screen.getByRole("button", {name: "変更を確定"}));
    await screen.findByRole("alert");
    fireEvent.click(screen.getByRole("button", {name: "変更を確定"}));
    await waitFor(() => expect(save).toHaveBeenCalledTimes(2));
    expect(save.mock.calls[0][1]).toEqual(save.mock.calls[1][1]);
    expect(await screen.findByText(/他の操作で設定が変更されました/)).toBeVisible();
  });
});
