import type { Metadata } from "next";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminUserTagManagement } from "@/components/users/admin-user-tag-management";

export const metadata: Metadata = { title: "会員タグ管理" };

export default function UserTagsPage() {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="user.tag.read">
        <AdminUserTagManagement />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
