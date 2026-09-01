import type { AdminPermissionCode } from "@/lib/admin-api/generated";

export type AdminRouteId =
  | "dashboard"
  | "users-list"
  | "users-history"
  | "users-tags"
  | "gachas"
  | "gachas-create"
  | "gachas-simulation"
  | "categories"
  | "tags"
  | "gachas-history"
  | "shipping"
  | "user-prizes"
  | "purchase-plans"
  | "purchase-plans-create"
  | "payments"
  | "announcements"
  | "announcements-create"
  | "banners"
  | "banners-create"
  | "contacts"
  | "page-settings"
  | "rank-settings"
  | "presentation-assets"
  | "referral-settings"
  | "line-settings"
  | "mail-settings"
  | "catalog"
  | "prizes"
  | "qa"
  | "reports"
  | "content"
  | "authentication-settings";

export type AdminNavigationGroupId =
  | "users"
  | "gacha"
  | "payment"
  | "shipping"
  | "purchase"
  | "announcements"
  | "banners"
  | "contacts"
  | "settings";

export type AdminNavigationIcon =
  | "dashboard"
  | "users"
  | "catalog"
  | "gacha"
  | "prize"
  | "qa"
  | "shipping"
  | "purchase"
  | "reports"
  | "content"
  | "announcements"
  | "banners"
  | "contacts"
  | "settings"
  | "authentication-settings"
  | "line-settings";

export interface AdminNavigationItem {
  kind: "link";
  id: AdminRouteId;
  label: string;
  path: `/${string}` | "/";
  permission: AdminPermissionCode | null;
  icon: AdminNavigationIcon;
  implementation: "available" | "scaffold";
  ownerOnly?: true;
  freshMfaBoundary: "none" | "module-actions";
}

export interface AdminNavigationGroup {
  kind: "group";
  id: AdminNavigationGroupId;
  label: string;
  icon: AdminNavigationIcon;
  children: readonly AdminNavigationItem[];
}

export type AdminNavigationNode = AdminNavigationItem | AdminNavigationGroup;

const ADMIN_ROUTE_ITEMS = validateRoutes([
  route("dashboard", "ダッシュボード", "/", null, "dashboard", "available", "none"),
  route("users-list", "一覧", "/users", null, "users", "available", "none"),
  route("users-tags", "会員タグ", "/users/tags", "user.tag.read", "users", "available"),
  route("users-history", "履歴", "/users/history", null, "users", "scaffold", "module-actions", true),
  route("gachas", "一覧", "/catalog/gachas", "catalog.read", "gacha", "available"),
  route("gachas-create", "登録", "/catalog/gachas/new", "catalog.manage", "gacha", "scaffold"),
  route("gachas-simulation", "シミュレーション", "/catalog/gachas/simulation", null, "gacha", "scaffold", "module-actions", true),
  route("categories", "カテゴリ", "/catalog/categories", "catalog.read", "catalog"),
  route("tags", "タグ", "/catalog/tags", "catalog.read", "catalog"),
  route("gachas-history", "履歴", "/catalog/gachas/history", "reporting.financial.read", "reports", "scaffold"),
  route("shipping", "一覧", "/shipping", "shipping.request.manage", "shipping"),
  route("user-prizes", "保有景品", "/user-prizes", "shipping.request.manage", "prize"),
  route("purchase-plans", "一覧", "/purchase-plans", "payment.plan.read", "purchase", "available"),
  route("purchase-plans-create", "登録", "/purchase-plans/new", "payment.plan.manage", "purchase", "available"),
  route("payments", "決済状況", "/payments", "reporting.financial.read", "purchase", "available", "none"),
  route("announcements", "一覧", "/announcements", "content.read", "announcements", "scaffold"),
  route("announcements-create", "登録", "/announcements/new", "content.manage", "announcements", "scaffold"),
  route("banners", "一覧", "/banners", "content.read", "banners", "scaffold"),
  route("banners-create", "登録", "/banners/new", "content.manage", "banners", "scaffold"),
  route("contacts", "一覧", "/contacts", "contact.read", "contacts"),
  route("page-settings", "ページ設定", "/settings/pages", "content.read", "content", "scaffold"),
  route("rank-settings", "ランク設定", "/catalog/ranks", "catalog.read", "catalog"),
  route("presentation-assets", "ランク演出", "/catalog/presentation-assets", "catalog.read", "catalog"),
  route("referral-settings", "紹介ポイント設定", "/settings/referral", "referral.settings.read", "settings"),
  route("line-settings", "LINE設定", "/settings/line", "identity.line.read", "line-settings"),
  route("mail-settings", "メール設定", "/settings/mail", "content.read", "settings"),

  // Existing direct routes remain registered even when they are not in the sidebar.
  route("catalog", "カタログ概要", "/catalog", "catalog.read", "catalog"),
  route("prizes", "景品管理", "/catalog/prizes", "catalog.read", "prize"),
  route("qa", "QA管理", "/qa", "qa.draw.manage", "qa"),
  route("reports", "レポート", "/reports", "reporting.financial.read", "reports", "scaffold"),
  route("content", "コンテンツ", "/content", "content.read", "content", "scaffold"),
  route("authentication-settings", "管理者認証", "/settings/authentication", "identity.admin.manage", "authentication-settings"),
]);

const ROUTES_BY_ID = new Map(ADMIN_ROUTE_ITEMS.map((item) => [item.id, item]));

export const ADMIN_NAVIGATION: readonly AdminNavigationNode[] = validateNavigation([
  navigationItem("dashboard"),
  group("users", "ユーザー", "users", ["users-list", "users-tags", "users-history"]),
  group("gacha", "ガチャ", "gacha", [
    "gachas",
    "gachas-create",
    "gachas-simulation",
    "categories",
    "tags",
    "gachas-history",
  ]),
  group("payment", "決済", "purchase", ["payments"]),
  group("shipping", "配送", "shipping", ["shipping"]),
  navigationItem("user-prizes"),
  group("purchase", "ポイント購入", "purchase", [
    "purchase-plans",
    "purchase-plans-create",
  ]),
  group("announcements", "お知らせ", "announcements", [
    "announcements",
    "announcements-create",
  ]),
  group("banners", "バナー", "banners", ["banners", "banners-create"]),
  group("contacts", "お問い合わせ", "contacts", ["contacts"]),
  group("settings", "各種設定", "settings", [
    "page-settings",
    "rank-settings",
    "presentation-assets",
    "referral-settings",
    "line-settings",
    "mail-settings",
  ]),
]);

export function navigationItem(id: AdminRouteId): AdminNavigationItem {
  const item = ROUTES_BY_ID.get(id);
  if (!item) throw new Error("Unknown admin route.");
  return item;
}

export function navigationForPermissions(
  permissions: ReadonlySet<AdminPermissionCode>,
  isOwner = false,
): AdminNavigationNode[] {
  const visible: AdminNavigationNode[] = [];
  for (const node of ADMIN_NAVIGATION) {
    if (node.kind === "link") {
      if (isVisible(node, permissions, isOwner)) visible.push(node);
      continue;
    }
    const children = node.children.filter((item) =>
      isVisible(item, permissions, isOwner),
    );
    if (children.length) visible.push({ ...node, children });
  }
  return visible;
}

export function navigationLinksForPermissions(
  permissions: ReadonlySet<AdminPermissionCode>,
  isOwner = false,
): AdminNavigationItem[] {
  return navigationForPermissions(permissions, isOwner).flatMap((node) =>
    node.kind === "group" ? [...node.children] : [node],
  );
}

export function activeNavigationItem(
  pathname: string,
  nodes: readonly AdminNavigationNode[],
): AdminNavigationItem | null {
  return nodes
    .flatMap((node) => (node.kind === "group" ? [...node.children] : [node]))
    .filter((item) =>
      item.path === "/"
        ? pathname === "/"
        : pathname === item.path || pathname.startsWith(`${item.path}/`),
    )
    .sort((left, right) => right.path.length - left.path.length)[0] ?? null;
}

export function navigationGroupForRoute(
  routeId: AdminRouteId,
): AdminNavigationGroup | null {
  return ADMIN_NAVIGATION.find(
    (node): node is AdminNavigationGroup =>
      node.kind === "group" && node.children.some((item) => item.id === routeId),
  ) ?? null;
}

function isVisible(
  item: AdminNavigationItem,
  permissions: ReadonlySet<AdminPermissionCode>,
  isOwner: boolean,
): boolean {
  if (item.ownerOnly) return isOwner;
  return item.permission === null || permissions.has(item.permission);
}

function route(
  id: AdminRouteId,
  label: string,
  path: AdminNavigationItem["path"],
  permission: AdminPermissionCode | null,
  icon: AdminNavigationIcon,
  implementation: AdminNavigationItem["implementation"] = "available",
  freshMfaBoundary: AdminNavigationItem["freshMfaBoundary"] = "module-actions",
  ownerOnly = false,
): AdminNavigationItem {
  return {
    kind: "link",
    id,
    label,
    path,
    permission,
    icon,
    implementation,
    freshMfaBoundary,
    ...(ownerOnly ? { ownerOnly: true as const } : {}),
  };
}

function group(
  id: AdminNavigationGroupId,
  label: string,
  icon: AdminNavigationIcon,
  routeIds: readonly AdminRouteId[],
): AdminNavigationGroup {
  return {
    kind: "group",
    id,
    label,
    icon,
    children: routeIds.map(navigationItem),
  };
}

function validateRoutes(items: AdminNavigationItem[]): readonly AdminNavigationItem[] {
  const ids = new Set<AdminRouteId>();
  const paths = new Set<string>();
  for (const item of items) {
    if (ids.has(item.id) || paths.has(item.path)) {
      throw new Error("Admin route registry contains duplicate values.");
    }
    ids.add(item.id);
    paths.add(item.path);
  }
  return Object.freeze(items);
}

function validateNavigation(nodes: AdminNavigationNode[]): readonly AdminNavigationNode[] {
  const groupIds = new Set<AdminNavigationGroupId>();
  const routeIds = new Set<AdminRouteId>();
  for (const node of nodes) {
    if (node.kind === "group") {
      if (groupIds.has(node.id)) throw new Error("Duplicate admin navigation group.");
      groupIds.add(node.id);
      for (const item of node.children) {
        if (routeIds.has(item.id)) throw new Error("Duplicate admin navigation route.");
        routeIds.add(item.id);
      }
    } else {
      if (routeIds.has(node.id)) throw new Error("Duplicate admin navigation route.");
      routeIds.add(node.id);
    }
  }
  return Object.freeze(nodes);
}
