import { DashboardHome } from "@/components/shell/dashboard-home";
import { AdminShell } from "@/components/shell/admin-shell";

export default function AdminHomePage() {
  return (
    <AdminShell>
      <DashboardHome />
    </AdminShell>
  );
}
