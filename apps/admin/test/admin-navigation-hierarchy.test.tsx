import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type {
  AdminPermissionCode,
  AdminRole,
} from "@/lib/admin-api/generated";

const pathname = { value: "/" };
const permissionState: {
  permissions: Set<AdminPermissionCode>;
  role: AdminRole;
} = {
  permissions: new Set(),
  role: "owner",
};

vi.mock("next/navigation", () => ({
  usePathname: () => pathname.value,
}));

vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    error: null,
    hasPermission: (permission: AdminPermissionCode) =>
      permissionState.permissions.has(permission),
    permissions: permissionState.permissions,
    requestId: null,
    retry: vi.fn(),
    role: permissionState.role,
    status: "ready",
  }),
}));

import { AdminNavigation } from "@/components/navigation/admin-navigation";
import { ModulePlaceholder } from "@/components/shell/module-placeholder";
import {
  activeNavigationItem,
  ADMIN_NAVIGATION,
  navigationForPermissions,
  navigationItem,
} from "@/lib/permissions/admin-navigation";
import { ADMIN_PERMISSION_CODES } from "@/lib/admin-api/generated";

describe("Admin sidebar hierarchy", () => {
  beforeEach(() => {
    pathname.value = "/";
    permissionState.permissions = new Set(ADMIN_PERMISSION_CODES);
    permissionState.role = "owner";
  });

  it("renders the approved top-level order and all child labels", () => {
    render(<AdminNavigation />);
    const navigation = screen.getByRole("navigation", { name: "管理ナビゲーション" });
    const topLevel = [...navigation.children].map((element) =>
      element.tagName === "A"
        ? element.textContent
        : element.querySelector(":scope > button")?.textContent,
    );
    expect(topLevel).toEqual([
      "ダッシュボード",
      expect.stringContaining("ユーザー"),
      expect.stringContaining("ガチャ"),
      expect.stringContaining("配送"),
      expect.stringContaining("ポイント購入"),
      expect.stringContaining("お知らせ"),
      expect.stringContaining("バナー"),
      expect.stringContaining("お問い合わせ"),
      expect.stringContaining("各種設定"),
    ]);

    const expectedChildren = new Map([
      ["ユーザー", ["一覧", "履歴"]],
      ["ガチャ", ["一覧", "登録", "シミュレーション", "カテゴリ", "タグ", "履歴"]],
      ["配送", ["一覧"]],
      ["ポイント購入", ["一覧", "登録"]],
      ["お知らせ", ["一覧", "登録"]],
      ["バナー", ["一覧", "登録"]],
      ["お問い合わせ", ["一覧"]],
      ["各種設定", ["ページ設定", "ランク演出", "紹介ポイント設定", "LINE設定"]],
    ]);
    for (const [parent, labels] of expectedChildren) {
      const button = screen.getByRole("button", { name: parent });
      fireEvent.click(button);
      const controls = document.getElementById(button.getAttribute("aria-controls")!);
      expect(controls).not.toBeNull();
      expect(within(controls!).getAllByRole("link").map((link) => link.textContent)).toEqual(labels);
    }
  });

  it("uses one-open accordion behavior and exposes control state", () => {
    render(<AdminNavigation />);
    const users = screen.getByRole("button", { name: "ユーザー" });
    const gacha = screen.getByRole("button", { name: "ガチャ" });

    fireEvent.click(users);
    expect(users).toHaveAttribute("aria-expanded", "true");
    expect(gacha).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(gacha);
    expect(users).toHaveAttribute("aria-expanded", "false");
    expect(gacha).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(gacha);
    expect(gacha).toHaveAttribute("aria-expanded", "false");
  });

  it("auto-expands the active group and resolves the longest matching path", () => {
    pathname.value = "/catalog/gachas/history";
    render(<AdminNavigation />);

    expect(screen.getByRole("button", { name: "ガチャ" })).toHaveAttribute(
      "aria-expanded",
      "true",
    );
    const gachaGroup = document.getElementById("admin-nav-gacha")!;
    expect(within(gachaGroup).getByRole("link", { name: "履歴" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(within(gachaGroup).getByRole("link", { name: "一覧" })).not.toHaveAttribute(
      "aria-current",
    );

    const active = activeNavigationItem(
      "/catalog/gachas/new",
      navigationForPermissions(permissionState.permissions, true),
    );
    expect(active?.id).toBe("gachas-create");
  });

  it("shows the User list while keeping owner-only scaffolds hidden", () => {
    permissionState.role = "admin";
    render(<AdminNavigation />);

    fireEvent.click(screen.getByRole("button", { name: "ユーザー" }));
    const userGroup = document.getElementById("admin-nav-users")!;
    expect(within(userGroup).getByRole("link", { name: "一覧" })).toBeVisible();
    expect(within(userGroup).queryByRole("link", { name: "履歴" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "ポイント購入" }));
    const purchaseGroup = document.getElementById("admin-nav-purchase")!;
    expect(within(purchaseGroup).getByRole("link", { name: "一覧" })).toBeVisible();
    expect(within(purchaseGroup).getByRole("link", { name: "登録" })).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "各種設定" }));
    expect(screen.getByRole("link", { name: "紹介ポイント設定" })).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "ガチャ" }));
    expect(screen.queryByRole("link", { name: "シミュレーション" })).toBeNull();
    expect(screen.queryByRole("link", { name: "登録" })).toBeVisible();
  });

  it("requests expansion before operating a compact parent", () => {
    const onRequestExpand = vi.fn();
    render(<AdminNavigation compact onRequestExpand={onRequestExpand} />);
    fireEvent.click(screen.getByRole("button", { name: "ガチャ" }));
    expect(onRequestExpand).toHaveBeenCalledOnce();
    expect(screen.getByRole("button", { name: "ガチャ" })).toHaveAttribute(
      "aria-expanded",
      "true",
    );
  });

  it("renders a breadcrumb and exact scaffold empty state", () => {
    render(<ModulePlaceholder item={navigationItem("users-history")} />);
    expect(screen.getByRole("heading", { name: "ユーザー 履歴", level: 1 })).toBeVisible();
    expect(screen.getByRole("navigation", { name: "パンくず" })).toHaveTextContent(
      "ダッシュボードユーザー履歴",
    );
    expect(screen.getByText("詳細画面は後続Taskで実装します。")).toBeVisible();
  });

  it("does not include removed legacy items in the sidebar registry", () => {
    const serialized = JSON.stringify(ADMIN_NAVIGATION);
    expect(serialized).not.toContain("カタログ概要");
    expect(serialized).not.toContain("景品管理");
    expect(serialized).not.toContain("QA管理");
    expect(serialized).not.toContain("管理者認証");
  });
});
