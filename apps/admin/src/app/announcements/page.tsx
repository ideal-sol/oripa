import type { Metadata } from "next";

import { AnnouncementManagementWorkspace } from "@/components/announcements/announcement-management-workspace";
import { initialListFilter, type PageSearchParams } from "@/lib/list-filter";

export const metadata: Metadata = { title: "お知らせ 一覧" };

const STATUS_FILTERS = ["published,draft", "published", "draft", "archived", "all"] as const;

export default async function AnnouncementsPage({ searchParams }: { searchParams: Promise<PageSearchParams> }) {
  const query = await searchParams;
  return <AnnouncementManagementWorkspace initialStatus={initialListFilter(query.status, STATUS_FILTERS, "published,draft")} mode="list" />;
}
