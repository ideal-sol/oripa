import type { Metadata } from "next";

import { AdminPaymentHistory } from "@/components/payments/admin-payment-history";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";
import {
  ADMIN_PAYMENT_METHOD_FILTERS,
  ADMIN_PAYMENT_STATUS_FILTERS,
  initialListFilter,
  type PageSearchParams,
} from "@/lib/admin-api/client";

export const metadata: Metadata = { title: "決済履歴" };

export default async function AdminPaymentHistoryPage({
  searchParams,
}: {
  searchParams: Promise<PageSearchParams>;
}) {
  const query = await searchParams;
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="reporting.financial.read">
        <AdminPaymentHistory
          initialMethod={initialListFilter(
            query.payment_method,
            ADMIN_PAYMENT_METHOD_FILTERS,
            "all",
          )}
          initialStatus={initialListFilter(
            query.status,
            ADMIN_PAYMENT_STATUS_FILTERS,
            "all",
          )}
        />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
