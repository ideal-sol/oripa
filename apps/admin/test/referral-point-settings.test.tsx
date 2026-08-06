import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const permissionState = {
  permissions: new Set(["referral.settings.read", "referral.settings.manage"]),
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
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: ({ open }: { open: boolean }) =>
    open ? <div role="dialog">Fresh MFA</div> : null,
}));

import { ReferralPointSettings } from "@/components/settings/referral-point-settings";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

const setting = {
  applies_to: "future_referrals_only" as const,
  grant_condition: "referred_user_sms_verified" as const,
  grant_timing: "on_sms_verification_completion" as const,
  id: "01910191-0191-7191-8191-019101910191",
  is_enabled: true,
  referred_user_point_amount: 50,
  referrer_point_amount: 100,
  revision: 1,
  reward_expiration_days: 180,
  updated_at: "2026-08-06T00:00:00Z",
};

describe("Referral point settings", () => {
  afterEach(() => {
    permissionState.permissions = new Set([
      "referral.settings.read",
      "referral.settings.manage",
    ]);
    vi.restoreAllMocks();
  });

  it("renders V1-order fields and saves separate rewards through the canonical API", async () => {
    vi.spyOn(AdminApiClient.prototype, "getReferralPointSetting").mockResolvedValue({
      data: setting,
      request_id: setting.id,
    });
    const update = vi.spyOn(
      AdminApiClient.prototype,
      "updateReferralPointSetting",
    ).mockResolvedValue({
      data: { ...setting, referred_user_point_amount: 75, referrer_point_amount: 250, revision: 2 },
      idempotent_replay: false,
      request_id: setting.id,
    });

    render(<ReferralPointSettings />);
    expect(await screen.findByRole("heading", { name: "紹介ポイント設定" })).toBeVisible();
    fireEvent.change(screen.getByLabelText("紹介者へ付与するポイント"), {
      target: { value: "250" },
    });
    fireEvent.change(screen.getByLabelText("紹介されたユーザーへ付与するポイント"), {
      target: { value: "75" },
    });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));

    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][0]).toEqual({
      expected_revision: 1,
      is_enabled: true,
      referred_user_point_amount: 75,
      referrer_point_amount: 250,
      reward_expiration_days: 180,
    });
    expect(update.mock.calls[0][1]).toMatch(/^[0-9a-f-]{36}$/u);
    expect(await screen.findByText("設定を保存しました。")).toBeVisible();
    expect(screen.getByText("変更後に成立する紹介")).toBeVisible();
  });

  it("keeps Operator read-only and validates bounded integer values", async () => {
    permissionState.permissions = new Set(["referral.settings.read"]);
    vi.spyOn(AdminApiClient.prototype, "getReferralPointSetting").mockResolvedValue({
      data: setting,
      request_id: setting.id,
    });
    render(<ReferralPointSettings />);

    expect(await screen.findByLabelText("紹介者へ付与するポイント")).toBeDisabled();
    expect(screen.queryByRole("button", { name: "保存" })).toBeNull();
  });

  it("opens the Fresh MFA boundary and exposes revision conflicts", async () => {
    vi.spyOn(AdminApiClient.prototype, "getReferralPointSetting").mockResolvedValue({
      data: setting,
      request_id: setting.id,
    });
    vi.spyOn(AdminApiClient.prototype, "updateReferralPointSetting")
      .mockRejectedValueOnce(new AdminApiError(
        403,
        "FRESH_AUTHENTICATION_REQUIRED",
        setting.id,
        null,
        false,
      ))
      .mockRejectedValueOnce(new AdminApiError(
        409,
        "REFERRAL_SETTING_REVISION_CONFLICT",
        setting.id,
        null,
        false,
      ));
    render(<ReferralPointSettings />);
    const points = await screen.findByLabelText("紹介者へ付与するポイント");
    fireEvent.change(points, { target: { value: "125" } });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    expect(await screen.findByRole("dialog")).toHaveTextContent("Fresh MFA");
  });
});
