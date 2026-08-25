import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AdminPermissionCode } from "@/lib/admin-api/generated";

const pathname = { value: "/" };
const permissionState = {
  error: null as { requestId: string | null; retryAfter: number | null } | null,
  hasPermission: (permission: AdminPermissionCode) =>
    permissionState.permissions.has(permission),
  permissions: new Set<AdminPermissionCode>(),
  requestId: null as string | null,
  retry: vi.fn(),
  role: "operator" as const,
  status: "ready" as
    | "idle"
    | "loading"
    | "ready"
    | "forbidden"
    | "rate_limited"
    | "error",
};

vi.mock("next/navigation", () => ({
  usePathname: () => pathname.value,
}));

vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => permissionState,
}));

import { AdminNavigation } from "@/components/navigation/admin-navigation";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import {
  ADMIN_NAVIGATION,
  navigationLinksForPermissions,
  navigationForPermissions,
} from "@/lib/permissions/admin-navigation";
import { ADMIN_PERMISSION_CODES } from "@/lib/admin-api/generated";

describe("Admin permission navigation", () => {
  beforeEach(() => {
    permissionState.error = null;
    permissionState.permissions = new Set();
    permissionState.retry.mockReset();
    permissionState.status = "ready";
    pathname.value = "/";
  });

  it("shows only groups granted by effective permission codes", () => {
    permissionState.permissions = new Set([
      "catalog.read",
      "shipping.request.manage",
      "content.read",
      "contact.read",
    ]);
    render(<AdminNavigation />);

    expect(screen.getByRole("link", { name: "ダッシュボード" })).toBeVisible();
    expect(screen.getByRole("button", { name: "ガチャ" })).toBeVisible();
    expect(screen.getByRole("button", { name: "配送" })).toBeVisible();
    expect(screen.getByRole("link", { name: "保有景品" })).toHaveAttribute(
      "href",
      "/user-prizes",
    );
    expect(screen.getByRole("button", { name: "お知らせ" })).toBeVisible();
    expect(screen.getByRole("button", { name: "お問い合わせ" })).toBeVisible();
    expect(screen.getByRole("button", { name: "ユーザー" })).toBeVisible();
    expect(screen.queryByRole("link", { name: "景品管理" })).toBeNull();
    expect(screen.queryByRole("link", { name: "QA管理" })).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "ガチャ" }));
    expect(screen.getByRole("link", { name: "一覧" })).toHaveAttribute(
      "href",
      "/catalog/gachas",
    );
    fireEvent.click(screen.getByRole("button", { name: "ユーザー" }));
    expect(screen.getByRole("link", { name: "一覧" })).toHaveAttribute(
      "href",
      "/users",
    );
    expect(screen.queryByRole("link", { name: "履歴" })).toBeNull();
  });

  it("marks the longest nested path active and keeps registry values unique", () => {
    pathname.value = "/catalog/gachas/new";
    permissionState.permissions = new Set(["catalog.read", "catalog.manage"]);
    render(<AdminNavigation />);

    expect(screen.getByRole("button", { name: "ガチャ" })).toHaveAttribute(
      "aria-expanded",
      "true",
    );
    expect(screen.getByRole("link", { name: "登録" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(screen.getByRole("link", { name: "一覧" })).not.toHaveAttribute(
      "aria-current",
    );
    expect(new Set(ADMIN_NAVIGATION.map((node) => node.id)).size).toBe(
      ADMIN_NAVIGATION.length,
    );
    const items = navigationLinksForPermissions(
      new Set(ADMIN_PERMISSION_CODES),
      true,
    );
    expect(new Set(items.map((item) => item.path)).size).toBe(items.length);
    for (const item of items) {
      if (item.permission) {
        expect(ADMIN_PERMISSION_CODES).toContain(item.permission);
      }
    }
  });

  it("places Payment status in its own group for financial readers only", () => {
    permissionState.permissions = new Set(["reporting.financial.read"]);
    render(<AdminNavigation />);

    fireEvent.click(screen.getByRole("button", { name: "決済" }));
    expect(screen.getByRole("link", { name: "決済状況" })).toHaveAttribute(
      "href",
      "/payments",
    );
    expect(screen.queryByRole("button", { name: "ポイント購入" })).toBeNull();
    expect(screen.queryByRole("link", { name: "一覧" })).toBeNull();
    expect(screen.queryByRole("link", { name: "登録" })).toBeNull();
  });

  it("blocks a direct route when its permission is absent", () => {
    render(
      <ProtectedAdminRoute permission="qa.draw.manage">
        <p>restricted content</p>
      </ProtectedAdminRoute>,
    );

    expect(screen.getByRole("heading", { name: "アクセスできません" })).toBeVisible();
    expect(screen.queryByText("restricted content")).toBeNull();
  });

  it("fails closed and safely reports retry timing", () => {
    permissionState.status = "rate_limited";
    permissionState.error = { requestId: null, retryAfter: 45 };
    render(
      <ProtectedAdminRoute permission="catalog.read">
        <p>restricted content</p>
      </ProtectedAdminRoute>,
    );

    expect(screen.getByText("45秒後に再試行できます。")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    expect(permissionState.retry).toHaveBeenCalledOnce();
  });

  it("never exposes modules while the permission request is unavailable", () => {
    expect(navigationForPermissions(new Set())).toEqual([
      expect.objectContaining({ id: "dashboard" }),
      expect.objectContaining({
        children: [expect.objectContaining({ id: "users-list" })],
        id: "users",
      }),
    ]);
  });
});
