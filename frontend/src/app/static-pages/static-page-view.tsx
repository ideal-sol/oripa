import Link from "next/link";
import { notFound } from "next/navigation";
import PublicHeader from "../public-header";
import { fetchPublicStaticPage } from "@/lib/api";

export default async function StaticPageView({ slug }: { slug: string }) {
  const page = await fetchPublicStaticPage(slug)
    .then((response) => response.data)
    .catch(() => null);

  if (!page) {
    notFound();
  }

  return (
    <main className="public-shell">
      <PublicHeader />

      <article className="announcement-detail">
        <div className="announcement-detail-head">
          <Link href="/" className="public-secondary-link light">トップへ戻る</Link>
          <h1>{page.title}</h1>
        </div>
        <div className="announcement-body">
          {page.body.split(/\r?\n/).map((line, index) => (
            <p key={`${index}-${line}`}>{line || "\u00a0"}</p>
          ))}
        </div>
      </article>
    </main>
  );
}
