import type { Metadata } from "next";

import { PointPurchaseManagementWorkspace } from "@/components/point-purchases/point-purchase-management-workspace";

export const metadata: Metadata = { title: "ポイント購入 編集" };

export default async function PurchasePlanEditPage({ params }: { params: Promise<{ planPublicId: string }> }) {
  const { planPublicId } = await params;
  return <PointPurchaseManagementWorkspace mode="edit" planId={planPublicId} />;
}
