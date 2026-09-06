import { AgencyWorkspace } from "@/components/agencies/agency-workspace";

export default async function AgencyDetailPage({ params }: { params: Promise<{ agencyPublicId: string }> }) {
  const { agencyPublicId } = await params;
  return <AgencyWorkspace agencyId={agencyPublicId} mode="detail" />;
}
