import Link from "next/link";

import {
  CATALOG_SECTIONS,
  type CatalogResource,
} from "@/lib/catalog/catalog-registry";

export function CatalogSectionNavigation({
  active,
}: {
  active?: CatalogResource;
}) {
  return (
    <nav aria-label="Catalog分類" className="catalog-tabs">
      <Link className={!active ? "active" : ""} href="/catalog">
        Overview
      </Link>
      {CATALOG_SECTIONS.map((section) => (
        <Link
          aria-current={active === section.resource ? "page" : undefined}
          className={active === section.resource ? "active" : ""}
          href={section.path}
          key={section.resource}
        >
          {section.label}
        </Link>
      ))}
    </nav>
  );
}
