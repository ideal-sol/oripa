import type { Metadata } from "next";
import { AdminPaymentDetail } from "@/components/payments/admin-payment-detail";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminShell } from "@/components/shell/admin-shell";

export const metadata: Metadata = { title: "決済詳細", robots: { index: false, follow: false } };

export default async function PaymentDetailPage({ params }: { params: Promise<{ paymentId: string }> }) {
  const { paymentId } = await params;
  return <AdminShell><ProtectedAdminRoute permission="reporting.financial.read">
    <AdminPaymentDetail paymentId={paymentId} />
  </ProtectedAdminRoute></AdminShell>;
}
