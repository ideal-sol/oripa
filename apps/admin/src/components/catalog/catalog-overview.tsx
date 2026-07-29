"use client";

import { ArrowRight } from "lucide-react";
import Link from "next/link";

import { CatalogSectionNavigation } from "@/components/catalog/catalog-section-navigation";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { CATALOG_SECTIONS } from "@/lib/catalog/catalog-registry";
import { navigationItem } from "@/lib/permissions/admin-navigation";

export function CatalogOverview() {
  const navigation = navigationItem("catalog");
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <div className="workspace">
          <Breadcrumb item={navigation} />
          <AdminPageHeader
            description="公開・抽選設定に使われるMaster情報を参照します。変更操作はこの画面では行えません。"
            eyebrow="Catalog"
            title="カタログ参照"
          />
          <CatalogSectionNavigation />
          <section className="catalog-overview-grid" aria-label="Catalog分類">
            {CATALOG_SECTIONS.map((section) => {
              const Icon = section.icon;
              return (
                <Link href={section.path} key={section.resource}>
                  <Icon size={21} aria-hidden="true" />
                  <span>
                    <strong>{section.label}</strong>
                    <small>{section.description}</small>
                  </span>
                  <ArrowRight size={17} aria-hidden="true" />
                </Link>
              );
            })}
          </section>
        </div>
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
