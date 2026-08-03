import type { AdminPermissionCode } from "@/lib/admin-api/generated";

export type AdminRouteId =
  | "dashboard"
  | "catalog"
  | "gachas"
  | "prizes"
  | "qa"
  | "shipping"
  | "reports"
  | "content"
  | "contacts"
  | "authentication-settings"
  | "line-settings";

export type AdminNavigationIcon =
  | "dashboard"
  | "catalog"
  | "gacha"
  | "prize"
  | "qa"
  | "shipping"
  | "reports"
  | "content"
  | "contacts"
  | "authentication-settings"
  | "line-settings";

export interface AdminNavigationItem {
  id: AdminRouteId;
  label: string;
  path: `/${string}` | "/";
  permission: AdminPermissionCode | null;
  icon: AdminNavigationIcon;
  section: "overview" | "operations" | "support";
  sortOrder: number;
  implementation: "available" | "planned";
  freshMfaBoundary: "none" | "module-actions";
}

export const ADMIN_NAVIGATION: readonly AdminNavigationItem[] = validateNavigation([
  {
    id: "dashboard",
    label: "ダッシュボード",
    path: "/",
    permission: null,
    icon: "dashboard",
    section: "overview",
    sortOrder: 10,
    implementation: "available",
    freshMfaBoundary: "none",
  },
  {
    id: "catalog",
    label: "カタログ概要",
    path: "/catalog",
    permission: "catalog.read",
    icon: "catalog",
    section: "operations",
    sortOrder: 20,
    implementation: "available",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "gachas",
    label: "ガチャ管理",
    path: "/catalog/gachas",
    permission: "catalog.read",
    icon: "gacha",
    section: "operations",
    sortOrder: 21,
    implementation: "available",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "prizes",
    label: "景品管理",
    path: "/catalog/prizes",
    permission: "catalog.read",
    icon: "prize",
    section: "operations",
    sortOrder: 22,
    implementation: "available",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "qa",
    label: "QA管理",
    path: "/qa",
    permission: "qa.draw.manage",
    icon: "qa",
    section: "operations",
    sortOrder: 30,
    implementation: "available",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "shipping",
    label: "景品・配送",
    path: "/shipping",
    permission: "shipping.request.manage",
    icon: "shipping",
    section: "operations",
    sortOrder: 40,
    implementation: "planned",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "reports",
    label: "レポート",
    path: "/reports",
    permission: "reporting.financial.read",
    icon: "reports",
    section: "operations",
    sortOrder: 50,
    implementation: "planned",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "content",
    label: "コンテンツ",
    path: "/content",
    permission: "content.read",
    icon: "content",
    section: "support",
    sortOrder: 60,
    implementation: "planned",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "contacts",
    label: "お問い合わせ",
    path: "/contacts",
    permission: "contact.read",
    icon: "contacts",
    section: "support",
    sortOrder: 70,
    implementation: "planned",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "authentication-settings",
    label: "管理者認証",
    path: "/settings/authentication",
    permission: "identity.admin.manage",
    icon: "authentication-settings",
    section: "support",
    sortOrder: 80,
    implementation: "available",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "line-settings",
    label: "LINE設定",
    path: "/settings/line",
    permission: "identity.line.manage",
    icon: "line-settings",
    section: "support",
    sortOrder: 90,
    implementation: "available",
    freshMfaBoundary: "module-actions",
  },
]);

export function navigationItem(id: AdminRouteId): AdminNavigationItem {
  const item = ADMIN_NAVIGATION.find((candidate) => candidate.id === id);
  if (!item) {
    throw new Error("Unknown admin route.");
  }
  return item;
}

export function navigationForPermissions(
  permissions: ReadonlySet<AdminPermissionCode>,
): AdminNavigationItem[] {
  return ADMIN_NAVIGATION.filter(
    (item) =>
      item.implementation === "available" &&
      (item.permission === null || permissions.has(item.permission)),
  );
}

function validateNavigation(
  items: AdminNavigationItem[],
): readonly AdminNavigationItem[] {
  const ids = new Set<AdminRouteId>();
  const paths = new Set<string>();
  const sortOrders = new Set<number>();
  for (const item of items) {
    if (
      ids.has(item.id) ||
      paths.has(item.path) ||
      sortOrders.has(item.sortOrder)
    ) {
      throw new Error("Admin navigation registry contains duplicate values.");
    }
    ids.add(item.id);
    paths.add(item.path);
    sortOrders.add(item.sortOrder);
  }
  return Object.freeze([...items].sort((left, right) => left.sortOrder - right.sortOrder));
}
