import PublicHeader from "../../public-header";
import ShippingHistoryClient from "./shipping-history-client";

export default function ShippingHistoryPage() {
  return (
    <main className="public-shell">
      <PublicHeader />

      <ShippingHistoryClient />
    </main>
  );
}
