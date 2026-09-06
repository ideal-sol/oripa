import { AgencyWorkspace } from "@/components/agencies/agency-workspace";

export default async function AgencyEditPage({ params }: { params: Promise<{ agencyPublicId: string }> }) {
  const { agencyPublicId } = await params;
  return <AgencyWorkspace agencyId={agencyPublicId} mode="edit" />;
}
