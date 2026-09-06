import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AgencyWorkspace } from "@/components/agencies/agency-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminAgency, AdminPermissionCode } from "@/lib/admin-api/generated";

const permissions = new Set<AdminPermissionCode>();
const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({
  hasPermission: (permission: AdminPermissionCode) => permissions.has(permission), permissions, status: "ready",
}) }));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({ FreshMfaDialog: ({ open, onSuccess }: { open: boolean; onSuccess: () => void }) => open ? <button onClick={onSuccess}>本人確認を完了</button> : null }));

const agency: AdminAgency = {
  id: "01900000-0000-7000-8000-000000000001", company_name: "QA代理店", contact_name: "QA担当者",
  email: "agency@example.test", phone: "03-1234-5678", address: "サンプル住所", memo: "QAメモ",
  login_id: "000012", advertising_code: "ABC123xy", status: "active", revision: 1,
  created_at: "2026-09-06T00:00:00Z", updated_at: "2026-09-06T00:00:00Z",
};
const result = { data: agency, idempotent_replay: false, request_id: agency.id };

beforeEach(() => {
  permissions.clear(); permissions.add("agency.read"); push.mockClear();
  vi.spyOn(AdminApiClient.prototype, "listAgencies").mockResolvedValue({ items: [agency], next_cursor: null, request_id: agency.id });
  vi.spyOn(AdminApiClient.prototype, "getAgency").mockResolvedValue({ data: agency, request_id: agency.id });
  vi.spyOn(AdminApiClient.prototype, "issueAgencyIdentifiers").mockResolvedValue({ login_id: agency.login_id, advertising_code: agency.advertising_code, issuance_token: "synthetic-issuance", request_id: agency.id });
});
afterEach(() => vi.restoreAllMocks());

describe("Agency management", () => {
  it("renders loading, list, cursor navigation and empty states", async () => {
    const listing = vi.mocked(AdminApiClient.prototype.listAgencies);
    listing.mockResolvedValueOnce({ items: [agency], next_cursor: "next", request_id: agency.id })
      .mockResolvedValue({ items: [], next_cursor: null, request_id: agency.id });
    render(<AgencyWorkspace />);
    expect(screen.getByRole("status")).toHaveTextContent("読み込んでいます");
    expect(await screen.findByText("ABC123xy")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "次のページ" }));
    expect(await screen.findByText("代理店はありません。")).toBeVisible();
    expect(listing).toHaveBeenLastCalledWith("next", expect.any(AbortSignal));
    fireEvent.click(screen.getByRole("button", { name: "先頭へ" }));
    await waitFor(() => expect(listing).toHaveBeenLastCalledWith(undefined, expect.any(AbortSignal)));
  });

  it("keeps Operator detail and new/edit routes read-only", async () => {
    const view = render(<AgencyWorkspace agencyId={agency.id} mode="detail" />);
    expect(await screen.findByText("QAメモ")).toBeVisible();
    expect(screen.queryByRole("link", { name: "編集" })).toBeNull();
    for (const label of ["停止", "再有効化", "PW再設定", "ログイン情報を再発行"]) expect(screen.queryByRole("button", { name: label })).toBeNull();
    view.rerender(<AgencyWorkspace mode="new" />);
    expect(screen.getByText("アクセスできません")).toBeVisible();
    view.rerender(<AgencyWorkspace agencyId={agency.id} mode="edit" />);
    expect(screen.getByText("アクセスできません")).toBeVisible();
  });

  it("shows API 403 errors", async () => {
    vi.mocked(AdminApiClient.prototype.listAgencies).mockRejectedValue(new AdminApiError(403, "AUTHORIZATION_DENIED", null, null, false));
    render(<AgencyWorkspace />);
    expect(await screen.findByRole("alert")).toHaveTextContent("権限がありません");
  });

  it("creates with generated identifiers and clears the submitted password", async () => {
    permissions.add("agency.manage");
    const create = vi.spyOn(AdminApiClient.prototype, "createAgency").mockResolvedValue(result);
    render(<AgencyWorkspace mode="new" />);
    await waitFor(() => expect(screen.getByLabelText("Login ID（自動生成）")).toHaveValue("000012"));
    fillCompany();
    const password = screen.getByLabelText("初期PW");
    fireEvent.change(password, { target: { value: "Initial123" } });
    fireEvent.submit(password.closest("form")!);
    expect(await screen.findByRole("alert")).toHaveTextContent("広告コードを発行");
    fireEvent.click(screen.getByRole("button", { name: "広告コード発行" }));
    await waitFor(() => expect(screen.getByLabelText("Advertising Code")).toHaveValue("ABC123xy"));
    expect(screen.getByLabelText("Advertising Code")).toHaveAttribute("readonly");
    fireEvent.submit(password.closest("form")!);
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][0]).toMatchObject({ issuance_token: "synthetic-issuance", password: "Initial123", phone: "03-1234-5678" });
    expect(create.mock.calls[0][0]).not.toHaveProperty("advertising_code");
    await waitFor(() => expect(password).toHaveValue(""));
    expect(push).toHaveBeenCalledWith(`/agencies/${agency.id}`);
  });

  it("edits Login ID with revision and excludes Advertising Code from writes", async () => {
    permissions.add("agency.manage");
    const update = vi.spyOn(AdminApiClient.prototype, "updateAgency").mockResolvedValue(result);
    render(<AgencyWorkspace agencyId={agency.id} mode="edit" />);
    const login = await screen.findByLabelText("Login ID");
    expect(screen.getByLabelText("Advertising Code")).toHaveAttribute("readonly");
    fireEvent.change(login, { target: { value: "000099" } });
    fireEvent.submit(login.closest("form")!);
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][1]).toMatchObject({ login_id: "000099", expected_revision: 1 });
    expect(update.mock.calls[0][1]).not.toHaveProperty("advertising_code");
    expect(update.mock.calls[0][1]).not.toHaveProperty("password");
  });

  for (const [label, method, status, credential] of [
    ["停止", "suspendAgency", "active", false], ["再有効化", "reactivateAgency", "suspended", false],
    ["PW再設定", "resetAgencyPassword", "active", true], ["ログイン情報を再発行", "reissueAgencyLoginInformation", "active", true],
  ] as const) {
    it(`confirms ${label} before changing credentials or status`, async () => {
      permissions.add("agency.manage");
      vi.mocked(AdminApiClient.prototype.getAgency).mockResolvedValue({ data: { ...agency, status }, request_id: agency.id });
      const mutation = vi.spyOn(AdminApiClient.prototype, method).mockResolvedValue(result);
      render(<AgencyWorkspace agencyId={agency.id} mode="detail" />);
      fireEvent.click(await screen.findByRole("button", { name: label }));
      const dialog = screen.getByRole("dialog", { name: label });
      expect(mutation).not.toHaveBeenCalled();
      if (credential) {
        expect(dialog).toHaveTextContent("現在のパスワードは使用できなくなります");
        fireEvent.change(within(dialog).getByLabelText("新しいPW"), { target: { value: "Changed456" } });
        fireEvent.click(within(dialog).getByRole("checkbox"));
      }
      fireEvent.click(within(dialog).getByRole("button", { name: "確認して実行" }));
      await waitFor(() => expect(mutation).toHaveBeenCalledOnce());
      expect(mutation.mock.calls[0][1]).toMatchObject({ expected_revision: 1 });
      if (credential) expect(mutation.mock.calls[0][1]).toHaveProperty("password", "Changed456");
      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    });
  }

  it("offers Fresh MFA without silently repeating a credential mutation", async () => {
    permissions.add("agency.manage");
    const mutation = vi.spyOn(AdminApiClient.prototype, "resetAgencyPassword").mockRejectedValue(new AdminApiError(403, "FRESH_AUTHENTICATION_REQUIRED", null, null, false));
    render(<AgencyWorkspace agencyId={agency.id} mode="detail" />);
    fireEvent.click(await screen.findByRole("button", { name: "PW再設定" }));
    const password = screen.getByLabelText("新しいPW");
    fireEvent.change(password, { target: { value: "Changed456" } });
    fireEvent.click(screen.getByRole("checkbox"));
    fireEvent.click(screen.getByRole("button", { name: "確認して実行" }));
    fireEvent.click(await screen.findByRole("button", { name: "本人確認を完了" }));
    expect(mutation).toHaveBeenCalledOnce();
    expect(password).toHaveValue("");
  });
});

function fillCompany() {
  for (const [label, value] of [["会社名", agency.company_name], ["担当者名", agency.contact_name], ["電話番号", agency.phone], ["担当者メールアドレス", agency.email], ["住所", agency.address]]) {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
  }
}
