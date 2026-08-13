import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  AdminUserTagManagement,
  AdminUserTagSection,
} from "@/components/users/admin-user-tag-management";
import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminPermissionCode,
  AdminUserDetail,
  AdminUserTag,
} from "@/lib/admin-api/generated";

const permissions = new Set<AdminPermissionCode>();

vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: (permission: AdminPermissionCode) => permissions.has(permission),
    permissions,
    role: permissions.has("user.tag.manage") ? "admin" : "operator",
    status: "ready",
  }),
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: ({ onSuccess, open }: { onSuccess?: () => Promise<void> | void; open: boolean }) =>
    open ? <button onClick={() => void onSuccess?.()} type="button">本人確認を完了</button> : null,
}));

beforeEach(() => {
  permissions.clear();
  permissions.add("user.tag.read");
  vi.spyOn(AdminApiClient.prototype, "listUserTags").mockResolvedValue({
    items: tags(),
    next_cursor: null,
    request_id: uuid("9"),
  });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("Admin User Tag management", () => {
  it("renders the tag master and keeps Operator read-only", async () => {
    render(<AdminUserTagManagement />);
    expect(await screen.findByText("VIP")).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "タグ名", "状態", "更新日", "編集",
    ]);
    expect(screen.queryByRole("button", { name: "作成" })).toBeNull();
    expect(screen.queryByRole("button", { name: "VIPを編集" })).toBeNull();
    expect(screen.getAllByText("閲覧のみ")).toHaveLength(3);
  });

  it("creates and edits tags through fresh authentication and revision OCC", async () => {
    permissions.add("user.tag.manage");
    const create = vi.spyOn(AdminApiClient.prototype, "createUserTag").mockResolvedValue({
      data: tags()[0], idempotent_replay: false, request_id: uuid("9"),
    });
    const update = vi.spyOn(AdminApiClient.prototype, "updateUserTag").mockResolvedValue({
      data: { ...tags()[0], is_active: false, revision: 2 },
      idempotent_replay: false,
      request_id: uuid("9"),
    });
    render(<AdminUserTagManagement />);
    await screen.findByText("VIP");

    fireEvent.change(screen.getByLabelText("タグ名"), { target: { value: "Campaign" } });
    fireEvent.click(screen.getByRole("button", { name: "作成" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][0]).toEqual({ name: "Campaign", is_active: true });

    fireEvent.click(screen.getByRole("button", { name: "VIPを編集" }));
    const dialog = screen.getByRole("dialog", { name: "会員タグ編集" });
    fireEvent.click(within(dialog).getByLabelText("有効"));
    fireEvent.click(within(dialog).getByRole("button", { name: "更新" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][1]).toMatchObject({
      expected_revision: 1,
      is_active: false,
      name: "VIP",
    });
  });

  it("shows retained inactive tags and prevents assigning inactive tags", async () => {
    permissions.add("user.tag.manage");
    const refresh = vi.fn();
    const detach = vi.spyOn(AdminApiClient.prototype, "detachUserTag").mockResolvedValue({
      data: { revision: 4, tags: [], user_id: uuid("1") },
      idempotent_replay: false,
      request_id: uuid("9"),
    });
    render(<AdminUserTagSection onRefresh={refresh} user={userDetail()} />);
    expect(screen.getByText("Legacy（無効）")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "タグを管理" }));
    const dialog = await screen.findByRole("dialog", { name: "会員タグを管理" });
    expect(within(dialog).getByRole("button", { name: "付与不可" })).toBeDisabled();
    fireEvent.click(within(dialog).getByRole("button", { name: "解除" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));
    await waitFor(() => expect(detach).toHaveBeenCalledOnce());
    expect(detach.mock.calls[0][2]).toEqual({ expected_revision: 3 });
    expect(refresh).toHaveBeenCalledOnce();
  });

  it("assigns an active tag and hides management from Operator", async () => {
    const operatorUser = { ...userDetail(), tags: [] };
    const { rerender } = render(<AdminUserTagSection onRefresh={vi.fn()} user={operatorUser} />);
    expect(screen.queryByRole("button", { name: "タグを管理" })).toBeNull();

    permissions.add("user.tag.manage");
    const refresh = vi.fn();
    const assign = vi.spyOn(AdminApiClient.prototype, "assignUserTag").mockResolvedValue({
      data: { revision: 4, tags: [], user_id: uuid("1") },
      idempotent_replay: false,
      request_id: uuid("9"),
    });
    rerender(<AdminUserTagSection onRefresh={refresh} user={operatorUser} />);
    fireEvent.click(screen.getByRole("button", { name: "タグを管理" }));
    const dialog = await screen.findByRole("dialog", { name: "会員タグを管理" });
    fireEvent.click(within(dialog).getByRole("button", { name: "付与" }));
    fireEvent.click(screen.getByRole("button", { name: "本人確認を完了" }));
    await waitFor(() => expect(assign).toHaveBeenCalledOnce());
    expect(assign.mock.calls[0][2]).toEqual({ expected_revision: 3 });
  });
});

function tags(): AdminUserTag[] {
  return [
    {
      created_at: "2026-08-10T00:00:00Z",
      id: uuid("2"),
      is_active: true,
      name: "VIP",
      revision: 1,
      updated_at: "2026-08-10T00:00:00Z",
    },
    {
      created_at: "2026-08-10T00:00:00Z",
      id: uuid("3"),
      is_active: false,
      name: "Legacy",
      revision: 2,
      updated_at: "2026-08-10T00:00:00Z",
    },
    {
      created_at: "2026-08-10T00:00:00Z",
      id: uuid("4"),
      is_active: false,
      name: "Paused",
      revision: 1,
      updated_at: "2026-08-10T00:00:00Z",
    },
  ];
}

function userDetail(): AdminUserDetail {
  return {
    created_at: "2026-08-10T00:00:00Z",
    display_name: "Synthetic User",
    email: "synthetic@example.test",
    email_verified_at: "2026-08-10T00:00:00Z",
    id: uuid("1"),
    point_balance: { free_balance: 0, paid_balance: 0, total_balance: 0 },
    state_revision: 1,
    status: "active",
    tag_assignment_revision: 3,
    tags: [{
      assigned_at: "2026-08-10T01:00:00Z",
      id: uuid("3"),
      is_active: false,
      name: "Legacy",
    }],
    updated_at: "2026-08-10T01:00:00Z",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
