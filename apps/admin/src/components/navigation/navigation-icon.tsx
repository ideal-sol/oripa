import {
  Bell,
  Boxes,
  CircleGauge,
  ClipboardCheck,
  CreditCard,
  Dices,
  FileBarChart,
  FileText,
  Gift,
  Images,
  MessagesSquare,
  MessageSquareText,
  PackageSearch,
  Settings,
  Settings2,
  UsersRound,
  type LucideProps,
} from "lucide-react";
import type { ComponentType } from "react";

import type { AdminNavigationIcon } from "@/lib/permissions/admin-navigation";

const icons = {
  dashboard: CircleGauge,
  users: UsersRound,
  catalog: Boxes,
  gacha: Dices,
  prize: Gift,
  qa: ClipboardCheck,
  shipping: PackageSearch,
  purchase: CreditCard,
  reports: FileBarChart,
  content: FileText,
  announcements: Bell,
  banners: Images,
  contacts: MessagesSquare,
  settings: Settings,
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
