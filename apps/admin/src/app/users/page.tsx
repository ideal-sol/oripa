import type { Metadata } from "next";

import { AdminShell } from "@/components/shell/admin-shell";
import { AdminUserReadWorkspace } from "@/components/users/admin-user-read-workspace";
import {
  ADMIN_USER_STATUS_FILTERS,
  initialListFilter,
  type PageSearchParams,
} from "@/lib/admin-api/client";

export const metadata: Metadata = { title: "ユーザー 一覧" };

export default async function UsersPage({
  searchParams,
}: {
  searchParams: Promise<PageSearchParams>;
}) {
  const query = await searchParams;
  return (
    <AdminShell>
      <AdminUserReadWorkspace
        initialFilters={{
          date_from: initialValue(query.date_from),
          date_to: initialValue(query.date_to),
          status: initialListFilter(query.status, ADMIN_USER_STATUS_FILTERS, "active"),
          user_id: initialValue(query.user_id),
        }}
        mode="list"
      />
    </AdminShell>
  );
}

function initialValue(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value)?.trim() ?? "";
}
