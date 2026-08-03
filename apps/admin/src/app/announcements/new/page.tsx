import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "お知らせ 登録" };

export default function AnnouncementCreatePage() {
  return <ModuleRoutePage routeId="announcements-create" />;
}
