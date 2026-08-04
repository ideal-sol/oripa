import type { Metadata } from "next";

import { AnnouncementManagementWorkspace } from "@/components/announcements/announcement-management-workspace";

export const metadata: Metadata = { title: "お知らせ 一覧" };

export default function AnnouncementsPage() {
  return <AnnouncementManagementWorkspace mode="list" />;
}
