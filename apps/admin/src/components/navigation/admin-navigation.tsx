"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { NavigationIcon } from "@/components/navigation/navigation-icon";
import { usePermissions } from "@/components/permissions/permission-provider";
import { navigationForPermissions } from "@/lib/permissions/admin-navigation";

export function AdminNavigation({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { permissions, status } = usePermissions();
  const items = navigationForPermissions(
    status === "ready" ? permissions : new Set(),
  );

  return (
    <nav aria-label="管理ナビゲーション">
      {items.map((item) => {
        const active =
          item.path === "/"
            ? pathname === "/"
            : pathname === item.path || pathname.startsWith(`${item.path}/`);
        return (
          <Link
            aria-current={active ? "page" : undefined}
            className={`nav-item ${active ? "active" : ""}`}
            href={item.path}
            key={item.id}
            onClick={onNavigate}
            title={item.label}
          >
            <NavigationIcon name={item.icon} size={19} aria-hidden="true" />
            <span>{item.label}</span>
          </Link>
        );
      })}
    </nav>
  );
}
