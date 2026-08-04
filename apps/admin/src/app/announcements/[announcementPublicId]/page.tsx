import type { Metadata } from "next";

import { AnnouncementManagementWorkspace } from "@/components/announcements/announcement-management-workspace";

export const metadata: Metadata = { title: "お知らせ 編集" };

export default async function AnnouncementEditPage({
  params,
}: {
  params: Promise<{ announcementPublicId: string }>;
}) {
  const { announcementPublicId } = await params;
  return (
    <AnnouncementManagementWorkspace
      announcementId={announcementPublicId}
      mode="edit"
    />
  );
}
