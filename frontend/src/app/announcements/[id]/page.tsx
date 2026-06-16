import Link from "next/link";
import { notFound } from "next/navigation";
import PublicHeader from "../../public-header";
import { fetchPublicAnnouncement } from "@/lib/api";

const ANNOUNCEMENT_FALLBACK_IMAGE = "/logo.png";

type AnnouncementDetailPageProps = {
  params: Promise<{ id: string }>;
};

export default async function AnnouncementDetailPage({ params }: AnnouncementDetailPageProps) {
  const { id } = await params;
  const announcementId = Number(id);

  if (!Number.isInteger(announcementId) || announcementId <= 0) {
    notFound();
  }

  const announcement = await fetchPublicAnnouncement(announcementId)
    .then((response) => response.data)
    .catch(() => null);

  if (!announcement) {
    notFound();
  }

  return (
    <main className="public-shell">
      <PublicHeader />

      <article className="announcement-detail">
        <div className="announcement-detail-head">
          <Link href="/#information" className="public-secondary-link light">一覧へ戻る</Link>
          <time>{formatDate(announcement.published_at ?? announcement.created_at)}</time>
          <h1>{announcement.title}</h1>
        </div>
        <div className={`announcement-main-image ${announcement.thumbnail_url ? "" : "logo-fallback"}`}>
          <span style={{ backgroundImage: `url("${announcement.thumbnail_url ?? ANNOUNCEMENT_FALLBACK_IMAGE}")` }} />
        </div>
        <div className="announcement-body">
          {announcement.body.split(/\r?\n/).map((line, index) => (
            <p key={`${index}-${line}`}>{line || "\u00a0"}</p>
          ))}
        </div>
      </article>
    </main>
  );
}

function formatDate(value: string | null) {
  if (!value) {
    return "-";
  }

  const date = new Date(value);

  return `${date.getFullYear()}.${String(date.getMonth() + 1).padStart(2, "0")}.${String(date.getDate()).padStart(2, "0")}`;
}
