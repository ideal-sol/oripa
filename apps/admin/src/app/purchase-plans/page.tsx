import type { Metadata } from "next";

import { PointPurchaseManagementWorkspace } from "@/components/point-purchases/point-purchase-management-workspace";
import { initialListFilter, type PageSearchParams } from "@/lib/list-filter";

export const metadata: Metadata = { title: "ポイント購入 一覧" };

const STATUS_FILTERS = ["all", "published", "draft"] as const;

export default async function PurchasePlansPage({ searchParams }: { searchParams: Promise<PageSearchParams> }) {
  const query = await searchParams;
  return <PointPurchaseManagementWorkspace initialStatus={initialListFilter(query.status, STATUS_FILTERS, "published")} mode="list" />;
}
