"use client";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";
import { ModulePlaceholder } from "@/components/shell/module-placeholder";
import {
  type AdminRouteId,
  navigationItem,
} from "@/lib/permissions/admin-navigation";

export function ModuleRoutePage({ routeId }: { routeId: AdminRouteId }) {
  const item = navigationItem(routeId);
  if (!item.permission) {
    throw new Error("A module route requires a permission.");
  }
  return (
    <AdminShell>
      <ProtectedAdminRoute permission={item.permission}>
        <ModulePlaceholder item={item} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
