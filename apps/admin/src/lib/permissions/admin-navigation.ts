import type { AdminPermissionCode } from "@/lib/admin-api/generated";

export type AdminRouteId =
  | "dashboard"
  | "catalog"
  | "qa"
  | "shipping"
  | "reports"
  | "content"
  | "contacts"
  | "line-settings";

export type AdminNavigationIcon =
  | "dashboard"
  | "catalog"
  | "qa"
  | "shipping"
  | "reports"
  | "content"
  | "contacts"
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
    label: "カタログ",
    path: "/catalog",
    permission: "catalog.read",
    icon: "catalog",
    section: "operations",
    sortOrder: 20,
    implementation: "available",
    freshMfaBoundary: "module-actions",
  },
  {
    id: "qa",
    label: "QA Draw",
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
    id: "line-settings",
    label: "LINE設定",
    path: "/settings/line",
    permission: "identity.line.manage",
    icon: "line-settings",
    section: "support",
    sortOrder: 80,
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
    (item) => item.permission === null || permissions.has(item.permission),
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
