import type { Metadata } from "next";

import { CatalogGachaWorkspace } from "@/components/catalog/catalog-gacha-workspace";

export const metadata: Metadata = { title: "ガチャ 登録" };

export default function GachaCreatePage() {
  return <CatalogGachaWorkspace createMode />;
}
