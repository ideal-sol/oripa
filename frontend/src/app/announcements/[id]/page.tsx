import Image from "next/image";
import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import PublicHeader from "../../public-header";
import { fetchPublicAnnouncement } from "@/lib/api";

const ANNOUNCEMENT_FALLBACK_IMAGE = "/lp-logo.png";

type AnnouncementDetailPageProps = {
  params: Promise<{ id: string }>;
};

export async function generateMetadata({ params }: AnnouncementDetailPageProps): Promise<Metadata> {
  const announcement = await loadAnnouncement(params);

  if (!announcement) {
    return {};
  }

  return {
    title: announcement.title,
    robots: announcement.category === "lp"
      ? {
          index: false,
          follow: false,
          noarchive: true,
        }
      : undefined,
  };
}

export default async function AnnouncementDetailPage({ params }: AnnouncementDetailPageProps) {
  const announcement = await loadAnnouncement(params);

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
          <span><Image className={announcement.thumbnail_url ? "optimized-image" : "optimized-image-contain"} src={announcement.thumbnail_url ?? ANNOUNCEMENT_FALLBACK_IMAGE} alt="" fill sizes="(max-width: 760px) 100vw, 920px" /></span>
        </div>
        <div className="announcement-body" dangerouslySetInnerHTML={{ __html: announcement.body_html }} />
      </article>
    </main>
  );
}

async function loadAnnouncement(params: Promise<{ id: string }>) {
  const { id } = await params;
  const announcementId = Number(id);

  if (!Number.isInteger(announcementId) || announcementId <= 0) {
    return null;
  }

  return fetchPublicAnnouncement(announcementId)
    .then((response) => response.data)
    .catch(() => null);
}

function formatDate(value: string | null) {
  if (!value) {
    return "-";
  }

  const date = new Date(value);

  return `${date.getFullYear()}.${String(date.getMonth() + 1).padStart(2, "0")}.${String(date.getDate()).padStart(2, "0")}`;
}
