import type { Metadata } from "next";

import { QaManagementWorkspace } from "@/components/qa/qa-management-workspace";

export const metadata: Metadata = { title: "QA Plan管理" };

export default function QaPage() {
  return <QaManagementWorkspace />;
}
