import type { Metadata } from "next";

import { PointPurchaseManagementWorkspace } from "@/components/point-purchases/point-purchase-management-workspace";

export const metadata: Metadata = { title: "ポイント購入 登録" };

export default function PurchasePlanCreatePage() {
  return <PointPurchaseManagementWorkspace mode="create" />;
}
