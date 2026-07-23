import Link from "next/link";
import PublicHeader from "../../public-header";
import PointPurchaseClient from "./point-purchase-client";

export default function PointPurchasePage() {
  return (
    <main className="public-shell">
      <PublicHeader />

      <PointPurchaseClient />
    </main>
  );
}
