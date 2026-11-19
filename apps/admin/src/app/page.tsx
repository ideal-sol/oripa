import { DashboardHome } from "@/components/shell/dashboard-home";
import { AdminShell } from "@/components/shell/admin-shell";

export default function AdminHomePage() {
  return (
    <AdminShell>
      <p className="mb-3 text-xs text-slate-500" data-testid="draft-preview-probe">
        Draft Preview 検証 B
      </p>
      <DashboardHome />
    </AdminShell>
  );
}
