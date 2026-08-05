import type { Metadata } from "next";

import { PageManagementWorkspace } from "@/components/pages/page-management-workspace";

export const metadata: Metadata = { title: "ページ新規登録" };

export default function NewPageSettingsPage() {
  return <PageManagementWorkspace mode="create" />;
}
