import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { CatalogWorkspace } from "@/components/catalog/catalog-workspace";
import { catalogSection } from "@/lib/catalog/catalog-registry";

export const metadata: Metadata = { title: "カタログ参照" };

export default async function CatalogResourcePage({
  params,
}: {
  params: Promise<{ segments: string[] }>;
}) {
  const { segments } = await params;
  if (segments.length < 1 || segments.length > 2) notFound();
  const section = catalogSection(segments[0]);
  if (!section) notFound();

  return <CatalogWorkspace id={segments[1]} resource={section.resource} />;
}
