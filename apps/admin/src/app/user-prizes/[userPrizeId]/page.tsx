import type { Metadata } from "next";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminUserPrizeDetail } from "@/components/user-prizes/admin-user-prize-detail";

export const metadata: Metadata = { title: "保有景品詳細" };

export default async function AdminUserPrizeDetailPage({
  params,
}: {
  params: Promise<{ userPrizeId: string }>;
}) {
  const { userPrizeId } = await params;
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="shipping.request.manage">
        <AdminUserPrizeDetail userPrizeId={userPrizeId} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
