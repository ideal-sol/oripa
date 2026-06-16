import PublicHeader from "../../public-header";
import PointHistoryClient from "./point-history-client";

export default function PointHistoryPage() {
  return (
    <main className="public-shell">
      <PublicHeader />

      <PointHistoryClient />
    </main>
  );
}
