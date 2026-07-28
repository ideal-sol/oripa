import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "お問い合わせ" };

export default function ContactsPage() {
  return <ModuleRoutePage routeId="contacts" />;
}
