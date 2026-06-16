import Link from "next/link";
import PublicHeader from "../../public-header";
import PrizeBoxClient from "./prize-box-client";

export default function PrizeBoxPage() {
  return (
    <main className="public-shell">
      <PublicHeader />

      <PrizeBoxClient />
    </main>
  );
}
