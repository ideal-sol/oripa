import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "カタログ" };

export default function CatalogPage() {
  return <ModuleRoutePage routeId="catalog" />;
}
