import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AdminPermissionCode, AdminQaGachaGuaranteeCollection } from "@/lib/admin-api/generated";

const getGuarantees = vi.fn();
const saveGuarantee = vi.fn();
const disableGuarantee = vi.fn();
const permissions = new Set<AdminPermissionCode>();

vi.mock("@/lib/admin-api/client", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/lib/admin-api/client")>();
  return {
    ...original,
    AdminApiClient: class {
      disableQaGachaGuarantee = disableGuarantee;
      getQaGachaGuarantees = getGuarantees;
      saveQaGachaGuarantee = saveGuarantee;
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

import { CatalogGachaQaGuaranteeManager } from "@/components/catalog/catalog-gacha-qa-guarantee-manager";

describe("Gacha QA guarantee manager", () => {
  beforeEach(() => {
    permissions.clear();
    getGuarantees.mockReset();
    saveGuarantee.mockReset();
    disableGuarantee.mockReset();
    getGuarantees.mockResolvedValue(collection());
    saveGuarantee.mockResolvedValue({ data: collection().items[0], idempotent_replay: false });
    disableGuarantee.mockResolvedValue({
      data: { ...collection().items[0], status: "unassigned" },
      idempotent_replay: false,
    });
  });

  it("hides management without QA permission", () => {
    render(<CatalogGachaQaGuaranteeManager gachaId="A7k9P2x4Qm8" />);
    expect(screen.queryByRole("heading", { name: "テストユーザー設定" })).toBeNull();
    expect(getGuarantees).not.toHaveBeenCalled();
  });

  it("shows active assignments and reports unresolved Published Prize settings", async () => {
    permissions.add("qa.draw.manage");
    getGuarantees.mockResolvedValue({
      ...collection(),
      items: [{ ...collection().items[0], is_resolvable: false, issue_code: "PUBLISHED_PRIZE_UNAVAILABLE" }],
    });
    render(<CatalogGachaQaGuaranteeManager gachaId="A7k9P2x4Qm8" />);
    const table = await screen.findByRole("table");
    expect(within(table).getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "テストユーザー", "保証する景品", "状態", "操作",
    ]);
    expect(within(table).getByText("公開中景品と不整合")).toBeVisible();
  });

  it("saves and disables a User and Prize assignment with OCC and idempotency", async () => {
    permissions.add("qa.draw.manage");
    render(<CatalogGachaQaGuaranteeManager gachaId="A7k9P2x4Qm8" />);
    await screen.findByText("QA User");

    fireEvent.click(screen.getByRole("button", { name: "追加・更新" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));
    await waitFor(() => expect(saveGuarantee).toHaveBeenCalledOnce());
    expect(saveGuarantee).toHaveBeenCalledWith(
      "A7k9P2x4Qm8",
      { prize_id: uuid("3"), revision: 4, user_id: uuid("2") },
      expect.stringMatching(/^[0-9a-f-]{36}$/u),
    );
    expect(getGuarantees).toHaveBeenCalledTimes(2);

    fireEvent.click(screen.getByRole("button", { name: "QA Userの設定を解除" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));
    await waitFor(() => expect(disableGuarantee).toHaveBeenCalledOnce());
    expect(disableGuarantee).toHaveBeenCalledWith(
      "A7k9P2x4Qm8",
      uuid("2"),
      4,
      expect.stringMatching(/^[0-9a-f-]{36}$/u),
    );
  });
});

function collection(): AdminQaGachaGuaranteeCollection {
  return {
    gacha_id: "A7k9P2x4Qm8",
    items: [{
      assigned_at: "2026-08-13T00:00:00Z",
      id: uuid("1"),
      is_resolvable: true,
      issue_code: null,
      prize: { id: uuid("3"), name: "Fixture S景品", rank_name: "S賞" },
      revision: 4,
      status: "assigned",
      unassigned_at: null,
      updated_at: "2026-08-13T00:00:00Z",
      user: { display_name: "QA User", id: uuid("2"), state: "active" },
    }],
    prizes: [{ id: uuid("3"), name: "Fixture S景品", rank_name: "S賞" }],
    test_users: [{ display_name: "QA User", id: uuid("2") }],
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
