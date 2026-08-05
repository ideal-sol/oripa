import type { Metadata } from "next";

import { PageManagementWorkspace } from "@/components/pages/page-management-workspace";

export const metadata: Metadata = { title: "ページ編集" };

export default async function EditPageSettingsPage({
  params,
}: {
  params: Promise<{ pagePublicId: string }>;
}) {
  const { pagePublicId } = await params;
  return <PageManagementWorkspace mode="edit" pageId={pagePublicId} />;
}
