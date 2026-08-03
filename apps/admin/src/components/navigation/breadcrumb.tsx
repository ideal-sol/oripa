import { ChevronRight } from "lucide-react";
import Link from "next/link";

import type { AdminNavigationItem } from "@/lib/permissions/admin-navigation";
import { navigationGroupForRoute } from "@/lib/permissions/admin-navigation";

export function Breadcrumb({ item }: { item: AdminNavigationItem }) {
  const group = navigationGroupForRoute(item.id);
  return (
    <nav aria-label="パンくず" className="breadcrumb">
      <ol>
        <li>
          <Link href="/">ダッシュボード</Link>
        </li>
        {group ? (
          <li>
            <ChevronRight size={14} aria-hidden="true" />
            <span>{group.label}</span>
          </li>
        ) : null}
        {item.path !== "/" ? (
          <li aria-current="page">
            <ChevronRight size={14} aria-hidden="true" />
            <span>{item.label}</span>
          </li>
        ) : null}
      </ol>
    </nav>
  );
}
