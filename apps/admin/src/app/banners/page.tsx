import type { Metadata } from "next";

import { BannerManagementWorkspace } from "@/components/banners/banner-management-workspace";
import { initialListFilter, type PageSearchParams } from "@/lib/list-filter";

export const metadata: Metadata = { title: "バナー管理" };

const STATUS_FILTERS = ["all", "published", "draft"] as const;

export default async function BannersPage({ searchParams }: { searchParams: Promise<PageSearchParams> }) {
  const query = await searchParams;
  return <BannerManagementWorkspace initialStatus={initialListFilter(query.status, STATUS_FILTERS, "published")} />;
}
