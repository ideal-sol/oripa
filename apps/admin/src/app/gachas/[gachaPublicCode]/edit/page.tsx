import { CatalogGachaWorkspace } from "@/components/catalog/catalog-gacha-workspace";

export default async function GachaMasterEditPage({
  params,
}: {
  params: Promise<{ gachaPublicCode: string }>;
}) {
  const { gachaPublicCode } = await params;

  return <CatalogGachaWorkspace editMode gachaId={gachaPublicCode} />;
}
