import type { Metadata } from "next";

import { ContactManagementWorkspace } from "@/components/contacts/contact-management-workspace";

export const metadata: Metadata = { title: "お問い合わせ" };

export default function ContactsPage() {
  return <ContactManagementWorkspace mode="list" />;
}
