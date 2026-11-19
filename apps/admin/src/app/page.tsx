import { DashboardHome } from "@/components/shell/dashboard-home";
import { AdminShell } from "@/components/shell/admin-shell";
import { DraftPreviewProbe } from "@/components/shell/draft-preview-probe";

export default function AdminHomePage() {
  return (
    <AdminShell>
      <DraftPreviewProbe />
      <DashboardHome />
    </AdminShell>
  );
}
