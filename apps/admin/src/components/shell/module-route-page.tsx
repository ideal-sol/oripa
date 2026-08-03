"use client";

import type { ReactNode } from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminShell } from "@/components/shell/admin-shell";
import { ModulePlaceholder } from "@/components/shell/module-placeholder";
import {
  type AdminRouteId,
  navigationItem,
} from "@/lib/permissions/admin-navigation";

export function ModuleRoutePage({ routeId }: { routeId: AdminRouteId }) {
  const item = navigationItem(routeId);
  if (!item.permission && !item.ownerOnly) {
    throw new Error("A module route requires a permission.");
  }
  return (
    <AdminShell>
      {item.ownerOnly ? (
        <OwnerRouteBoundary>
          <ModulePlaceholder item={item} />
        </OwnerRouteBoundary>
      ) : (
        <ProtectedAdminRoute permission={item.permission!}>
          <ModulePlaceholder item={item} />
        </ProtectedAdminRoute>
      )}
    </AdminShell>
  );
}

function OwnerRouteBoundary({ children }: { children: ReactNode }) {
  const { role, status } = usePermissions();
  if (status !== "ready" || role !== "owner") {
    return (
      <section className="module-state">
        <h1>アクセスできません</h1>
        <p>このPreviewページを表示する権限がありません。</p>
      </section>
    );
  }
  return children;
}
