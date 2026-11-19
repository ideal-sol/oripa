import { LineChatWorkspace } from "@/components/line/line-chat-workspace";
import { LineAdminPage } from "@/components/line/line-admin-page";
export default async function LineChatDetailPage({ params }: { params: Promise<{ conversationId: string }> }) {
  const { conversationId } = await params;
  return <LineAdminPage kind="line-chat" title="個別チャット"><LineChatWorkspace key={conversationId} conversationId={conversationId} /></LineAdminPage>;
}
