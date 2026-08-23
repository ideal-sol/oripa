import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CatalogGachaWorkspace } from "@/components/catalog/catalog-gacha-workspace";
import { CatalogGachaUsageHistory } from "@/components/catalog/catalog-gacha-usage-history";
import { CatalogGachaProfitSimulation } from "@/components/catalog/catalog-gacha-profit-simulation";
import { CatalogProbabilityWorkspace } from "@/components/catalog/catalog-probability-workspace";
import { CatalogWorkspace } from "@/components/catalog/catalog-workspace";
import { RankEffectSettingsWorkspace } from "@/components/catalog/rank-effect-settings-workspace";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { catalogSection } from "@/lib/catalog/catalog-registry";
import { initialListFilter, type PageSearchParams } from "@/lib/admin-api/client";

export const metadata: Metadata = { title: "カタログ参照" };

export default async function CatalogResourcePage({
  params,
  searchParams,
}: {
  params: Promise<{ segments: string[] }>;
  searchParams: Promise<PageSearchParams>;
}) {
  const { segments } = await params;
  const query = await searchParams;
  if (segments[0] === "gachas") {
    if (segments.length === 1) {
      return <CatalogGachaWorkspace initialStatus={initialListFilter(query.status, ["published,draft", "published", "draft", "sales_paused", "unpublished", "all"] as const, "published,draft")} />;
    }
    if (segments.length === 2) {
      return (
        <CatalogGachaWorkspace
          gachaId={segments[1]}
          key={segments[1]}
        />
      );
    }
    if (segments.length === 3 && segments[2] === "history") {
      return <CatalogGachaUsageHistory gachaId={segments[1]} />;
    }
    if (segments.length === 4 && segments[2] === "history") {
      return (
        <CatalogGachaUsageHistory
          drawRequestId={segments[3]}
          gachaId={segments[1]}
        />
      );
    }
    if (segments.length === 3 && segments[2] === "profit-simulation") {
      return <CatalogGachaProfitSimulation gachaId={segments[1]} />;
    }
    if (segments.length === 3 && segments[2] === "product-design-planner") {
      return <GachaScaffold gachaId={segments[1]} title="商品設計プランナー" />;
    }
    if (segments.length === 4 && segments[2] === "versions") {
      return (
        <CatalogGachaWorkspace
          gachaId={segments[1]}
          key={`${segments[1]}:${segments[3]}`}
          versionId={segments[3]}
        />
      );
    }
    if (
      (segments.length === 5 || segments.length === 6) &&
      segments[2] === "versions" &&
      segments[4] === "probability-versions"
    ) {
      return (
        <CatalogProbabilityWorkspace
          gachaId={segments[1]}
          gachaVersionId={segments[3]}
          key={segments.join(":")}
          probabilityVersionId={segments[5]}
        />
      );
    }
    notFound();
  }
  if (segments[0] === "presentation-assets") {
    if (segments.length === 1) {
      return <RankEffectSettingsWorkspace initialVisibility={initialListFilter(query.visibility, ["all", "visible", "hidden"] as const, "visible")} mode="list" />;
    }
    if (segments.length === 2 && segments[1] === "new") {
      return <RankEffectSettingsWorkspace mode="create" />;
    }
    if (segments.length === 3 && segments[2] === "edit") {
      return <RankEffectSettingsWorkspace id={segments[1]} mode="edit" />;
    }
    notFound();
  }
  if (segments.length < 1 || segments.length > 2) notFound();
  const section = catalogSection(segments[0]);
  if (!section || section.resource === "gachas") notFound();

  return <CatalogWorkspace id={segments[1]} resource={section.resource} />;
}

function GachaScaffold({ gachaId, title }: { gachaId: string; title: string }) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <div className="workspace">
          <AdminPageHeader eyebrow="Gacha" title={title} description={`対象Gacha: ${gachaId}`} />
          <section className="module-state">
            <h2>{title}</h2>
            <p>詳細画面は後続Taskで実装します。</p>
          </section>
        </div>
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
