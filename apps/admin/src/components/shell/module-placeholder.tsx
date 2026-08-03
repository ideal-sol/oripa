import { Construction } from "lucide-react";

import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import {
  type AdminNavigationItem,
  navigationGroupForRoute,
} from "@/lib/permissions/admin-navigation";

export function ModulePlaceholder({ item }: { item: AdminNavigationItem }) {
  const group = navigationGroupForRoute(item.id);
  const title = group ? `${group.label} ${item.label}` : item.label;
  return (
    <section className="workspace">
      <Breadcrumb item={item} />
      <AdminPageHeader
        eyebrow="Administration"
        title={title}
        description={`${title}の概要`}
      />
      <section className="module-placeholder" aria-labelledby={`${item.id}-status`}>
        <Construction size={26} aria-hidden="true" />
        <h2 id={`${item.id}-status`}>{title}</h2>
        <p>詳細画面は後続Taskで実装します。</p>
      </section>
    </section>
  );
}
