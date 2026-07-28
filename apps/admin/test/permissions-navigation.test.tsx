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

  it("shows only modules granted by effective permission codes", () => {
    permissionState.permissions = new Set([
      "catalog.read",
      "shipping.request.manage",
      "content.read",
      "contact.read",
    ]);
    render(<AdminNavigation />);

    expect(screen.getByRole("link", { name: "ダッシュボード" })).toBeVisible();
    expect(screen.getByRole("link", { name: "カタログ" })).toBeVisible();
    expect(screen.getByRole("link", { name: "景品・配送" })).toBeVisible();
    expect(screen.queryByRole("link", { name: "QA Draw" })).toBeNull();
    expect(screen.queryByRole("link", { name: "レポート" })).toBeNull();
  });

  it("marks nested module paths active and keeps registry values unique", () => {
    pathname.value = "/shipping/requests";
    permissionState.permissions = new Set(["shipping.request.manage"]);
    render(<AdminNavigation />);

    expect(screen.getByRole("link", { name: "景品・配送" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(new Set(ADMIN_NAVIGATION.map((item) => item.id)).size).toBe(
      ADMIN_NAVIGATION.length,
    );
    expect(new Set(ADMIN_NAVIGATION.map((item) => item.path)).size).toBe(
      ADMIN_NAVIGATION.length,
    );
    for (const item of ADMIN_NAVIGATION) {
      if (item.permission) {
        expect(ADMIN_PERMISSION_CODES).toContain(item.permission);
      }
    }
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
    ]);
  });
});
