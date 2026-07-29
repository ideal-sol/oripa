import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CatalogGachaWorkspace } from "@/components/catalog/catalog-gacha-workspace";
import { CatalogWorkspace } from "@/components/catalog/catalog-workspace";
import { catalogSection } from "@/lib/catalog/catalog-registry";

export const metadata: Metadata = { title: "カタログ参照" };

export default async function CatalogResourcePage({
  params,
}: {
  params: Promise<{ segments: string[] }>;
}) {
  const { segments } = await params;
  if (segments[0] === "gachas") {
    if (segments.length === 1) return <CatalogGachaWorkspace />;
    if (segments.length === 2) {
      return (
        <CatalogGachaWorkspace
          gachaId={segments[1]}
          key={segments[1]}
        />
      );
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
    notFound();
  }
  if (segments.length < 1 || segments.length > 2) notFound();
  const section = catalogSection(segments[0]);
  if (!section || section.resource === "gachas") notFound();

  return <CatalogWorkspace id={segments[1]} resource={section.resource} />;
}
