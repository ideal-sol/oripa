"use client";

import type { ReactNode } from "react";

import { usePermissions } from "@/components/permissions/permission-provider";
import type { AdminPermissionCode } from "@/lib/admin-api/generated";

export function PermissionGate({
  children,
  fallback = null,
  permission,
}: {
  children: ReactNode;
  fallback?: ReactNode;
  permission: AdminPermissionCode;
}) {
  const { hasPermission } = usePermissions();
  return hasPermission(permission) ? children : fallback;
}
