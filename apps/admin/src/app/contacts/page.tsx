import type { Metadata } from "next";

import { ContactManagementWorkspace } from "@/components/contacts/contact-management-workspace";
import { initialListFilter, type PageSearchParams } from "@/lib/list-filter";

export const metadata: Metadata = { title: "お問い合わせ" };

const STATUS_FILTERS = ["all", "new", "in_progress", "replied", "closed"] as const;

export default async function ContactsPage({ searchParams }: { searchParams: Promise<PageSearchParams> }) {
  const query = await searchParams;
  return <ContactManagementWorkspace initialStatus={initialListFilter(query.status, STATUS_FILTERS, "new")} mode="list" />;
}
