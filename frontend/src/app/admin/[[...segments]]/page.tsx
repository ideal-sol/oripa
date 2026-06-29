import { cookies, headers } from "next/headers";
import { redirect } from "next/navigation";
import AdminDashboard from "../../admin-dashboard";

type AdminPageProps = {
  params?: Promise<{
    segments?: string[];
  }>;
};

type AdminSession = {
  access_token: string;
  admin: {
    id: number;
    name: string;
    email: string;
  };
};

type AdminRouteState = {
  tab: string;
  gachaView?: string;
  gachaEntityId?: string;
  subView?: string;
  entityId?: string;
  parentEntityId?: string;
};

export default async function AdminPage({ params }: AdminPageProps) {
  const host = (await headers()).get("host") ?? "";

  if (!host.startsWith("admin.")) {
    redirect("/");
  }

  const resolvedParams = await params;
  const segments = resolvedParams?.segments ?? [];
  const routeState = routeStateFromSegments(segments);
  const sessionCookie = (await cookies()).get("oripa_admin_session")?.value;
  const initialSession = parseSessionCookie(sessionCookie);

  return (
    <AdminDashboard
      initialSession={initialSession}
      initialTab={routeState.tab}
      initialGachaView={routeState.gachaView}
      initialGachaEntityId={routeState.gachaEntityId}
      initialSubView={routeState.subView}
      initialEntityId={routeState.entityId}
      initialParentEntityId={routeState.parentEntityId}
    />
  );
}

function routeStateFromSegments(segments: string[]): AdminRouteState {
  const [section, second, third, fourth, fifth] = segments;

  if (!section || section === "guide") {
    return { tab: "guide" };
  }

  if (section === "announcements") {
    if (second === "new") {
      return { tab: "announcements", subView: "new" };
    }

    if (second && third === "edit") {
      return { tab: "announcements", subView: "edit", entityId: second };
    }

    return { tab: "announcements" };
  }

  if (section === "contacts") {
    if (second && third === "edit") {
      return { tab: "contacts", subView: "edit", entityId: second };
    }

    return { tab: "contacts" };
  }

  if (section === "gachas") {
    return routeStateFromGachaSegments([second, third, fourth]);
  }

  if (section === "users") {
    if (second) {
      return { tab: "users", subView: "detail", entityId: second };
    }

    return { tab: "users" };
  }

  if (section === "draws") {
    return { tab: "draws" };
  }

  if (section === "prizes") {
    return { tab: "prizes" };
  }

  if (section === "shipping") {
    if (second && third === "items" && fourth && fifth === "edit") {
      return { tab: "shipping", subView: "edit", parentEntityId: second, entityId: fourth };
    }

    return { tab: "shipping" };
  }

  if (section === "sales" || section === "payments") {
    return { tab: "sales" };
  }

  if (section === "purchase-plans") {
    if (second === "new") {
      return { tab: "purchasePlans", subView: "new" };
    }

    if (second && third === "edit") {
      return { tab: "purchasePlans", subView: "edit", entityId: second };
    }

    return { tab: "purchasePlans" };
  }

  if (section === "points") {
    if (second === "new") {
      return { tab: "points", subView: "new" };
    }

    return { tab: "points" };
  }

  if (section === "settings") {
    if (second === "pages") {
      return { tab: "settings", subView: "pages" };
    }

    if (second === "rank-assets" && !third) {
      return { tab: "settings", subView: "rank-assets" };
    }

    if (second === "rank-assets" && third === "new") {
      return { tab: "settings", subView: "rank-asset-new" };
    }

    if (second === "rank-assets" && third && fourth === "edit") {
      return { tab: "settings", subView: "rank-asset-edit", entityId: third };
    }

    if (second === "static-pages" && third && fourth === "edit") {
      return { tab: "settings", subView: "edit", entityId: third };
    }

    if (second === "referral") {
      return { tab: "settings", subView: "referral" };
    }

    if (second === "line") {
      return { tab: "settings", subView: "line" };
    }

    return { tab: "settings" };
  }

  return { tab: "gachas" };
}

function routeStateFromGachaSegments([first, second, third]: (string | undefined)[]): AdminRouteState {
  if (!first) {
    return { tab: "gachas", gachaView: "gacha-list" };
  }

  if (first === "new") {
    return { tab: "gachas", gachaView: "gacha-new" };
  }

  if (first === "categories") {
    if (second === "new") {
      return { tab: "gachas", gachaView: "category-new" };
    }

    if (second && third === "edit") {
      return { tab: "gachas", gachaView: "category-edit", gachaEntityId: second };
    }

    return { tab: "gachas", gachaView: "category-list" };
  }

  if (first === "tags") {
    if (second === "new") {
      return { tab: "gachas", gachaView: "tag-new" };
    }

    if (second && third === "edit") {
      return { tab: "gachas", gachaView: "tag-edit", gachaEntityId: second };
    }

    return { tab: "gachas", gachaView: "tag-list" };
  }

  if (first === "top-banners") {
    if (second === "new") {
      return { tab: "gachas", gachaView: "top-banner-new" };
    }

    if (second && third === "edit") {
      return { tab: "gachas", gachaView: "top-banner-edit", gachaEntityId: second };
    }

    return { tab: "gachas", gachaView: "top-banner-list" };
  }

  if (first === "ranks") {
    if (second === "new") {
      return { tab: "gachas", gachaView: "rank-new" };
    }

    if (second && third === "edit") {
      return { tab: "gachas", gachaView: "rank-edit", gachaEntityId: second };
    }

    return { tab: "gachas", gachaView: "rank-list" };
  }

  if (first === "prizes") {
    if (second === "new") {
      return { tab: "gachas", gachaView: "prize-new" };
    }

    if (second && third === "edit") {
      return { tab: "gachas", gachaView: "prize-edit", gachaEntityId: second };
    }

    return { tab: "gachas", gachaView: "prize-list" };
  }

  if (second === "edit") {
    return { tab: "gachas", gachaView: "gacha-edit", gachaEntityId: first };
  }

  return { tab: "gachas", gachaView: "gacha-list" };
}

function parseSessionCookie(raw?: string): AdminSession | null {
  if (!raw) {
    return null;
  }

  try {
    const parsed = JSON.parse(decodeURIComponent(raw)) as Partial<AdminSession>;

    if (typeof parsed.access_token === "string" && parsed.admin?.email) {
      return parsed as AdminSession;
    }
  } catch {
    return null;
  }

  return null;
}
