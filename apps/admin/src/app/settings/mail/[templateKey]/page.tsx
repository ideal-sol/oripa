import type { Metadata } from "next";

import { MailTemplateWorkspace } from "@/components/mail/mail-template-workspace";

export const metadata: Metadata = { title: "メールTemplate編集" };

export default async function EditMailTemplatePage({ params }: { params: Promise<{ templateKey: string }> }) {
  const { templateKey } = await params;
  return <MailTemplateWorkspace templateKey={templateKey} />;
}
