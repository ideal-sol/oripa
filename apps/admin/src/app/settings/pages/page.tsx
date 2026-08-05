import type { Metadata } from "next";

import { PageManagementWorkspace } from "@/components/pages/page-management-workspace";

export const metadata: Metadata = { title: "ページ設定" };

export default function PageSettingsPage() {
  return <PageManagementWorkspace mode="list" />;
}
