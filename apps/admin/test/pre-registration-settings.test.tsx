import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const permissionState = { permissions: new Set(["pre_registration.settings.read", "pre_registration.settings.manage"]) };
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => permissionState }));
vi.mock("@/components/navigation/breadcrumb", () => ({ Breadcrumb: () => <nav /> }));
vi.mock("@/components/shell/admin-page-header", () => ({ AdminPageHeader: ({ title }: { title: string }) => <h1>{title}</h1> }));

import { PreRegistrationSettings } from "@/components/settings/pre-registration-settings";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminPreRegistrationResponse } from "@/lib/admin-api/generated";

const setting: AdminPreRegistrationResponse["data"] = {
  is_enabled: true, cutoff_at: "2026-11-01T03:00:00Z", point_amount: 500, revision: 2,
  snapshot_amount: null, started_at: null, completed_at: null,
  summary: { applicants: 2, email_pending: 0, sms_pending: 0, eligible: 2, pending: 2, activated: 0, failed: 0, excluded: 0, granted_points: 0 },
  results: [],
};

describe("pre-registration settings", () => {
  afterEach(() => { vi.restoreAllMocks(); permissionState.permissions = new Set(["pre_registration.settings.read", "pre_registration.settings.manage"]); });

  it("previews cutoff impact and requires confirmation before saving JST input", async () => {
    vi.spyOn(AdminApiClient.prototype, "getPreRegistration").mockResolvedValue({ data: setting });
    const preview = vi.spyOn(AdminApiClient.prototype, "previewPreRegistration").mockResolvedValue({ affected_applicants: 1 });
    const update = vi.spyOn(AdminApiClient.prototype, "updatePreRegistration").mockResolvedValue({ data: { ...setting, revision: 3 } });
    render(<PreRegistrationSettings />);
    const cutoff = await screen.findByLabelText("締切・OPEN日時（日本時間）");
    expect(cutoff).toHaveValue("2026-11-01T12:00");
    fireEvent.change(cutoff, { target: { value: "2026-10-31T12:00" } });
    fireEvent.click(screen.getByRole("button", { name: "変更内容を確認" }));
    expect(await screen.findByText(/対象外となる申込：1人/)).toBeVisible();
    expect(update).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "確定する" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(preview.mock.calls[0][0].cutoff_at).toBe("2026-10-31T03:00:00.000Z");
    expect(update.mock.calls[0][0].expected_revision).toBe(2);
  });

  it("keeps operator read-only and locks the snapshot amount during partial processing", async () => {
    permissionState.permissions = new Set(["pre_registration.settings.read"]);
    vi.spyOn(AdminApiClient.prototype, "getPreRegistration").mockResolvedValue({ data: { ...setting, snapshot_amount: 500, started_at: setting.cutoff_at } });
    render(<PreRegistrationSettings />);
    expect(await screen.findByLabelText("付与する無償ポイント")).toBeDisabled();
    expect(screen.queryByRole("button", { name: /一括切替を確認/ })).toBeNull();
    expect(screen.getByText(/全件完了まで付与額は変更できません/)).toBeVisible();
  });

  it("retains idempotency key after uncertain network failure and never reports a grant early", async () => {
    vi.spyOn(AdminApiClient.prototype, "getPreRegistration").mockResolvedValue({ data: setting });
    const activate = vi.spyOn(AdminApiClient.prototype, "activatePreRegistration")
      .mockRejectedValueOnce(new AdminApiError(0, "NETWORK_ERROR", null, null, true))
      .mockResolvedValueOnce({ data: { ...setting, started_at: setting.cutoff_at, snapshot_amount: 500,
        summary: { ...setting.summary, activated: 1, pending: 0, failed: 1, granted_points: 500 },
        results: [{ user_id: "00000000-0000-4000-8000-000000000001", status: "failed", reason: "temporary_failure" }] } });
    render(<PreRegistrationSettings />);
    fireEvent.click(await screen.findByRole("button", { name: /一括切替を確認/ }));
    expect(activate).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "確定する" }));
    await screen.findByRole("alert");
    expect(screen.queryByText(/切替結果を更新しました/)).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "確定する" }));
    await waitFor(() => expect(activate).toHaveBeenCalledTimes(2));
    expect(activate.mock.calls[0]).toEqual(activate.mock.calls[1]);
    expect(await screen.findByText("一時的な失敗。再実行できます。")).toBeVisible();
    expect(screen.getByLabelText("付与する無償ポイント")).toBeDisabled();
  });

  it("does not automatically retry an expired session or introduce action-level Fresh prompts", async () => {
    vi.spyOn(AdminApiClient.prototype, "getPreRegistration").mockResolvedValue({ data: setting });
    const activate = vi.spyOn(AdminApiClient.prototype, "activatePreRegistration").mockRejectedValue(new AdminApiError(401, "AUTHENTICATION_REQUIRED", null, null, false));
    render(<PreRegistrationSettings />);
    fireEvent.click(await screen.findByRole("button", { name: /一括切替を確認/ }));
    fireEvent.click(screen.getByRole("button", { name: "確定する" }));
    await screen.findByRole("alert");
    expect(screen.queryByLabelText("認証コード")).toBeNull();
    expect(activate).toHaveBeenCalledOnce();
  });
});
