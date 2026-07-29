import type { Metadata } from "next";

import { CatalogOverview } from "@/components/catalog/catalog-overview";

export const metadata: Metadata = { title: "カタログ" };

export default function CatalogPage() {
  return <CatalogOverview />;
}
