import Link from "next/link";

import type { AdminNavigationItem } from "@/lib/permissions/admin-navigation";

export function Breadcrumb({ item }: { item: AdminNavigationItem }) {
  return (
    <nav aria-label="パンくず" className="breadcrumb">
      <ol>
        <li>
          <Link href="/">ダッシュボード</Link>
        </li>
        {item.path !== "/" ? (
          <li aria-current="page">
            <span>{item.label}</span>
          </li>
        ) : null}
      </ol>
    </nav>
  );
}
