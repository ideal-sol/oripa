import {
  Boxes,
  CircleGauge,
  ClipboardCheck,
  FileBarChart,
  FileText,
  Gift,
  MessagesSquare,
  MessageSquareText,
  PackageSearch,
  Settings2,
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
  "authentication-settings": Settings2,
  "line-settings": MessageSquareText,
} satisfies Record<AdminNavigationIcon, ComponentType<LucideProps>>;

export function NavigationIcon({
  name,
  ...props
}: LucideProps & { name: AdminNavigationIcon }) {
  const Icon = icons[name] ?? Gift;
  return <Icon {...props} />;
}
