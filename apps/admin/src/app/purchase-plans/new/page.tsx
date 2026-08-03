import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "ポイント購入 登録" };

export default function PurchasePlanCreatePage() {
  return <ModuleRoutePage routeId="purchase-plans-create" />;
}
