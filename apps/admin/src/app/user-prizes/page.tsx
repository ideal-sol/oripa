import type { Metadata } from "next";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminUserPrizeList } from "@/components/user-prizes/admin-user-prize-list";

export const metadata: Metadata = { title: "保有景品一覧" };

export default function AdminUserPrizesPage() {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="shipping.request.manage">
        <AdminUserPrizeList />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
