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
  const activeItem = [...items]
    .filter((item) =>
      item.path === "/"
        ? pathname === "/"
        : pathname === item.path || pathname.startsWith(`${item.path}/`),
    )
    .sort((left, right) => right.path.length - left.path.length)[0];
  const sections = [
    { id: "overview", label: "メニュー" },
    { id: "operations", label: "業務管理" },
    { id: "support", label: "設定" },
  ] as const;

  return (
    <nav aria-label="管理ナビゲーション">
      {sections.map((section) => {
        const sectionItems = items.filter((item) => item.section === section.id);
        if (!sectionItems.length) return null;
        return (
          <div className="nav-section" key={section.id}>
            <span className="nav-section-label">{section.label}</span>
            {sectionItems.map((item) => {
              const active = activeItem?.id === item.id;
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
          </div>
        );
      })}
    </nav>
  );
}
