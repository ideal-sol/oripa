import { ArrowRight } from "lucide-react";
import Link from "next/link";

import { NavigationIcon } from "@/components/navigation/navigation-icon";
import type { AdminNavigationItem } from "@/lib/permissions/admin-navigation";

export function DashboardModuleCard({ item }: { item: AdminNavigationItem }) {
  return (
    <Link className="module-link" href={item.path}>
      <NavigationIcon name={item.icon} size={22} aria-hidden="true" />
      <span>
        <strong>{item.label}</strong>
        <small>モジュールを開く</small>
      </span>
      <ArrowRight size={18} aria-hidden="true" />
    </Link>
  );
}
