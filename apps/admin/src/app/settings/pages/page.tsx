import type { Metadata } from "next";

import { PageManagementWorkspace } from "@/components/pages/page-management-workspace";
import { initialListFilter, type PageSearchParams } from "@/lib/list-filter";

export const metadata: Metadata = { title: "ページ設定" };

const STATUS_FILTERS = ["published,draft", "published", "draft"] as const;

export default async function PageSettingsPage({ searchParams }: { searchParams: Promise<PageSearchParams> }) {
  const query = await searchParams;
  return <PageManagementWorkspace initialStatus={initialListFilter(query.status, STATUS_FILTERS, "published,draft")} mode="list" />;
}
