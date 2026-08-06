import type { Metadata } from "next";

import { PointPurchaseManagementWorkspace } from "@/components/point-purchases/point-purchase-management-workspace";

export const metadata: Metadata = { title: "ポイント購入 一覧" };

export default function PurchasePlansPage() {
  return <PointPurchaseManagementWorkspace mode="list" />;
}
