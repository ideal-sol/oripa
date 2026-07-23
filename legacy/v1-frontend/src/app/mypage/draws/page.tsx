import PublicHeader from "../../public-header";
import DrawHistoryClient from "./draw-history-client";

export default function DrawHistoryPage() {
  return (
    <main className="public-shell">
      <PublicHeader />

      <DrawHistoryClient />
    </main>
  );
}
