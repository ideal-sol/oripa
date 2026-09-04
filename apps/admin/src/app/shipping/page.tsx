import type { Metadata } from "next";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminShippingList } from "@/components/shipping/admin-shipping-management";
import {
  ADMIN_SHIPPING_STATUS_FILTERS,
  initialListFilter,
  type PageSearchParams,
} from "@/lib/admin-api/client";

export const metadata: Metadata = { title: "景品・配送" };

export default async function ShippingPage({
  searchParams,
}: {
  searchParams: Promise<PageSearchParams>;
}) {
  const query = await searchParams;
  const value = (name: string) => {
    const candidate = query[name];
    return Array.isArray(candidate) ? candidate[0] : candidate;
  };

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="shipping.request.manage">
        <AdminShippingList
          initialDateFrom={value("date_from") ?? ""}
          initialDateTo={value("date_to") ?? ""}
          initialStatus={initialListFilter(
            query.status,
            ADMIN_SHIPPING_STATUS_FILTERS,
            "requested",
          )}
        />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
