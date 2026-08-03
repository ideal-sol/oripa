import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "ポイント購入 一覧" };

export default function PurchasePlansPage() {
  return <ModuleRoutePage routeId="purchase-plans" />;
}
