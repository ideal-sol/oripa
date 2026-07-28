import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "景品・配送" };

export default function ShippingPage() {
  return <ModuleRoutePage routeId="shipping" />;
}
