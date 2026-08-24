import type { Metadata } from "next";

import { MailTemplateWorkspace } from "@/components/mail/mail-template-workspace";

export const metadata: Metadata = { title: "メール設定" };

export default function MailSettingsPage() {
  return <MailTemplateWorkspace />;
}
