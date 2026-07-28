import {
  Boxes,
  CircleGauge,
  ClipboardCheck,
  FileBarChart,
  FileText,
  Gift,
  MessagesSquare,
  PackageSearch,
  type LucideProps,
} from "lucide-react";
import type { ComponentType } from "react";

import type { AdminNavigationIcon } from "@/lib/permissions/admin-navigation";

const icons = {
  dashboard: CircleGauge,
  catalog: Boxes,
  qa: ClipboardCheck,
  shipping: PackageSearch,
  reports: FileBarChart,
  content: FileText,
  contacts: MessagesSquare,
} satisfies Record<AdminNavigationIcon, ComponentType<LucideProps>>;

export function NavigationIcon({
  name,
  ...props
}: LucideProps & { name: AdminNavigationIcon }) {
  const Icon = icons[name] ?? Gift;
  return <Icon {...props} />;
}
