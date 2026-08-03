import type { Metadata } from "next";

import { AdminShell } from "@/components/shell/admin-shell";
import { AdminUserReadWorkspace } from "@/components/users/admin-user-read-workspace";

export const metadata: Metadata = { title: "ユーザー 一覧" };

export default function UsersPage() {
  return (
    <AdminShell>
      <AdminUserReadWorkspace mode="list" />
    </AdminShell>
  );
}
