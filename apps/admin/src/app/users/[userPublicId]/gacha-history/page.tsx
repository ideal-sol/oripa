import type { Metadata } from "next";

import { AdminShell } from "@/components/shell/admin-shell";
import { AdminUserReadWorkspace } from "@/components/users/admin-user-read-workspace";

export const metadata: Metadata = { title: "ユーザーガチャ履歴" };

export default async function AdminUserGachaHistoryPage({
  params,
}: {
  params: Promise<{ userPublicId: string }>;
}) {
  const { userPublicId } = await params;
  return (
    <AdminShell>
      <AdminUserReadWorkspace mode="history" userPublicId={userPublicId} />
    </AdminShell>
  );
}
