import type { Metadata } from "next";

import { OwnerPreviewRoutePage } from "@/components/shell/owner-preview-route-page";

export const metadata: Metadata = { title: "ポイント購入 一覧" };

export default function PurchasePlansPage() {
  return <OwnerPreviewRoutePage routeId="purchase-plans" />;
}
