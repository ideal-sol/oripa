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
          <Link href="/catalog">カタログ</Link>
        </li>
        <li aria-current={detail ? undefined : "page"}>
          {detail ? <Link href={section.path}>{section.label}</Link> : section.label}
        </li>
        {detail ? <li aria-current="page">{detail}</li> : null}
      </ol>
    </nav>
  );
}
