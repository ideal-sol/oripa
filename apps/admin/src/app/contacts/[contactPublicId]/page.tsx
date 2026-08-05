import type { Metadata } from "next";

import { ContactManagementWorkspace } from "@/components/contacts/contact-management-workspace";

export const metadata: Metadata = { title: "お問い合わせ詳細" };

export default async function ContactDetailPage({
  params,
}: {
  params: Promise<{ contactPublicId: string }>;
}) {
  const { contactPublicId } = await params;
  return <ContactManagementWorkspace contactId={contactPublicId} mode="detail" />;
}
