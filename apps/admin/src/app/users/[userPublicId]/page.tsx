import type { Metadata } from "next";

import { AdminShell } from "@/components/shell/admin-shell";
import { AdminUserReadWorkspace } from "@/components/users/admin-user-read-workspace";

export const metadata: Metadata = { title: "ユーザー詳細" };

export default async function AdminUserDetailPage({
  params,
}: {
  params: Promise<{ userPublicId: string }>;
}) {
  const { userPublicId } = await params;
  return (
    <AdminShell>
      <AdminUserReadWorkspace mode="detail" userPublicId={userPublicId} />
    </AdminShell>
  );
}
