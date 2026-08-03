import { ChevronRight } from "lucide-react";
import Link from "next/link";

import type { CatalogSection } from "@/lib/catalog/catalog-registry";

export function CatalogBreadcrumb({
  detail,
  section,
}: {
  detail?: string;
  section: CatalogSection;
}) {
  return (
    <nav aria-label="パンくず" className="breadcrumb">
      <ol>
        <li>
          <Link href="/">ダッシュボード</Link>
        </li>
        <li>
          <ChevronRight size={14} aria-hidden="true" />
          <Link href="/catalog">カタログ</Link>
        </li>
        <li aria-current={detail ? undefined : "page"}>
          <ChevronRight size={14} aria-hidden="true" />
          {detail ? <Link href={section.path}>{section.label}</Link> : section.label}
        </li>
        {detail ? (
          <li aria-current="page">
            <ChevronRight size={14} aria-hidden="true" />
            {detail}
          </li>
        ) : null}
      </ol>
    </nav>
  );
}
