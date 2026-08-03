import type { Metadata } from "next";

import { OwnerPreviewRoutePage } from "@/components/shell/owner-preview-route-page";

export const metadata: Metadata = { title: "ポイント購入 登録" };

export default function PurchasePlanCreatePage() {
  return <OwnerPreviewRoutePage routeId="purchase-plans-create" />;
}
