import type { Metadata } from "next";

import { AnnouncementManagementWorkspace } from "@/components/announcements/announcement-management-workspace";

export const metadata: Metadata = { title: "お知らせ 登録" };

export default function AnnouncementCreatePage() {
  return <AnnouncementManagementWorkspace mode="create" />;
}
