import Link from "next/link";

import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { StatusBadge } from "@/components/catalog/status-badge";
import type { CatalogResource } from "@/lib/catalog/catalog-registry";

export interface CatalogTableRow {
  id: string;
  code: string;
  name: string;
  secondary: string;
  visible: boolean;
  asset: {
    id: string;
    media_type: "image" | "video";
    mime_type: string;
    alt_text: string | null;
    public_path: string | null;
    is_public: boolean;
  } | null;
  archived?: boolean;
}

export function CatalogDataTable({
  resource,
  rows,
}: {
  resource: CatalogResource;
  rows: CatalogTableRow[];
}) {
  return (
    <div className="catalog-table-wrap">
      <table className="catalog-table">
        <thead>
          <tr>
            <th scope="col">Preview</th>
            <th scope="col">名称</th>
            <th scope="col">Code／種別</th>
            <th scope="col">状態</th>
            <th scope="col">
              <span className="sr-only">詳細</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              <td>
                <PublicAssetPreview asset={row.asset} />
              </td>
              <td>
                <strong>{row.name}</strong>
                <small>{row.secondary}</small>
              </td>
              <td>
                <code>{row.code}</code>
              </td>
              <td>
                <StatusBadge archived={row.archived} visible={row.visible} />
              </td>
              <td>
                <Link
                  aria-label={`${row.name}の詳細`}
                  className="table-link"
                  href={`/catalog/${resource}/${row.id}`}
                >
                  詳細
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
