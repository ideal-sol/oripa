import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "お知らせ 一覧" };

export default function AnnouncementsPage() {
  return <ModuleRoutePage routeId="announcements" />;
}
