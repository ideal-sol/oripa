import type { Metadata } from "next";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminShippingDetail } from "@/components/shipping/admin-shipping-management";

export const metadata: Metadata = { title: "配送詳細" };

export default async function ShippingDetailPage({
  params,
}: {
  params: Promise<{ shippingRequestId: string }>;
}) {
  const { shippingRequestId } = await params;

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="shipping.request.manage">
        <AdminShippingDetail shippingRequestId={shippingRequestId} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}
