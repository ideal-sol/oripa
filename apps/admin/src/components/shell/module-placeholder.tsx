import { Construction } from "lucide-react";

import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import type { AdminNavigationItem } from "@/lib/permissions/admin-navigation";

export function ModulePlaceholder({ item }: { item: AdminNavigationItem }) {
  return (
    <section className="workspace">
      <Breadcrumb item={item} />
      <AdminPageHeader
        eyebrow="Administration"
        title={item.label}
        description="このモジュールは準備中です。"
      />
      <section className="module-placeholder" aria-labelledby={`${item.id}-status`}>
        <Construction size={26} aria-hidden="true" />
        <h2 id={`${item.id}-status`}>準備中</h2>
      </section>
    </section>
  );
}
