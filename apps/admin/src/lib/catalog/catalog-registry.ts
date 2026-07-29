import {
  Boxes,
  Dices,
  Image,
  Medal,
  PackageSearch,
  Tags,
  type LucideIcon,
} from "lucide-react";

export type CatalogResource =
  | "categories"
  | "gachas"
  | "tags"
  | "ranks"
  | "prizes"
  | "presentation-assets";

export interface CatalogSection {
  resource: CatalogResource;
  label: string;
  description: string;
  path: `/catalog/${CatalogResource}`;
  icon: LucideIcon;
  sortOptions: readonly { label: string; value: string }[];
  supportsMediaType: boolean;
}

export const CATALOG_SECTIONS: readonly CatalogSection[] = [
  {
    resource: "gachas",
    label: "Gacha",
    description: "Gacha MasterとDraft Versionを管理します。",
    path: "/catalog/gachas",
    icon: Dices,
    sortOptions: [
      { label: "作成日時", value: "created_at" },
      { label: "Code", value: "code" },
      { label: "状態", value: "state" },
    ],
    supportsMediaType: false,
  },
  {
    resource: "categories",
    label: "Category",
    description: "ガチャ分類の表示情報を参照します。",
    path: "/catalog/categories",
    icon: Boxes,
    sortOptions: [
      { label: "表示順", value: "sort_order" },
      { label: "名称", value: "name" },
      { label: "Code", value: "code" },
    ],
    supportsMediaType: false,
  },
  {
    resource: "tags",
    label: "Tag",
    description: "検索・特集用Tagを参照します。",
    path: "/catalog/tags",
    icon: Tags,
    sortOptions: [
      { label: "表示順", value: "sort_order" },
      { label: "名称", value: "name" },
      { label: "Code", value: "code" },
    ],
    supportsMediaType: false,
  },
  {
    resource: "ranks",
    label: "Rank",
    description: "景品Rankと表示順を参照します。",
    path: "/catalog/ranks",
    icon: Medal,
    sortOptions: [
      { label: "表示順", value: "sort_order" },
      { label: "名称", value: "name" },
      { label: "Code", value: "code" },
    ],
    supportsMediaType: false,
  },
  {
    resource: "prizes",
    label: "Prize",
    description: "景品MasterとRank、公開Assetを参照します。",
    path: "/catalog/prizes",
    icon: PackageSearch,
    sortOptions: [
      { label: "名称", value: "name" },
      { label: "Code", value: "code" },
      { label: "Rank", value: "rank" },
    ],
    supportsMediaType: false,
  },
  {
    resource: "presentation-assets",
    label: "Presentation Asset",
    description: "公開可能な画像・動画Assetを安全に確認します。",
    path: "/catalog/presentation-assets",
    icon: Image,
    sortOptions: [
      { label: "作成日時", value: "created_at" },
      { label: "Media種別", value: "media_type" },
    ],
    supportsMediaType: true,
  },
] as const;

export function catalogSection(resource: string): CatalogSection | null {
  return CATALOG_SECTIONS.find((item) => item.resource === resource) ?? null;
}
