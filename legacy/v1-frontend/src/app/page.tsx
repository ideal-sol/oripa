import { headers } from "next/headers";
import { cookies } from "next/headers";
import Image from "next/image";
import Link from "next/link";
import { redirect } from "next/navigation";
import { ReactNode } from "react";
import AdminDashboard from "./admin-dashboard";
import PublicHeader from "./public-header";
import TopBannerSlider, { TopBannerItem } from "./top-banner-slider";
import { fetchApiHealth, fetchPublicAnnouncements, fetchPublicGachas, fetchPublicTopBanners, PublicGachaListItem, PublicTopBanner } from "@/lib/api";


function topBannerItems(banners: PublicTopBanner[]): TopBannerItem[] {
  return banners
    .filter((banner) => banner.is_active && banner.image_url)
    .sort((a, b) => a.sort_order - b.sort_order)
    .map((banner, index) => ({
      key: String(banner.id),
      href: banner.link_url || "#",
      imageUrl: banner.image_url,
      title: "トップバナー" + (index + 1),
    }));
}

type HomeProps = {
  searchParams?: Promise<{
    tab?: string | string[];
    view?: string | string[];
    id?: string | string[];
    gacha?: string | string[];
    category?: string | string[];
  }>;
};

export default async function Home({ searchParams }: HomeProps) {
  const host = (await headers()).get("host") ?? "";
  const params = await searchParams;

  if (host.startsWith("admin.")) {
    const sessionCookie = (await cookies()).get("oripa_admin_session")?.value;
    const initialSession = parseSessionCookie(sessionCookie);
    const tab = typeof params?.tab === "string" ? params.tab : undefined;
    const view = typeof params?.view === "string" ? params.view : undefined;
    const id = typeof params?.id === "string" ? params.id : undefined;

    if (!tab && !view && !id) {
      redirect("/admin/guide");
    }

    return <AdminDashboard initialSession={initialSession} initialTab={tab} initialGachaView={view} initialGachaEntityId={id} />;
  }

  const [health, gachaList, announcements, topBanners] = await Promise.all([
    fetchApiHealth().catch((error: Error) => ({
      app: "error",
      db: "error",
      redis: "error",
      storage: "error",
      timestamp: error.message,
    })),
    fetchPublicGachas().catch(() => ({ data: [] })),
    fetchPublicAnnouncements().catch(() => ({ data: [] })),
    fetchPublicTopBanners().catch(() => ({ data: [] })),
  ]);
  const selectedCategory = typeof params?.category === "string" ? params.category : "all";
  const categories = categoryOptions(gachaList.data);
  const filteredGachas = selectedCategory && selectedCategory !== "all"
    ? gachaList.data.filter((gacha) => gacha.category.slug === selectedCategory)
    : gachaList.data;
  const bannerItems = topBannerItems(topBanners.data);

  return (
    <main className="public-shell">
      <PublicHeader />

      <TopBannerSlider items={bannerItems} />

      <section className="category-panel" aria-label="カテゴリー">
        <div className="public-section-head">
          <h2>カテゴリーを選択</h2>
          <span>{filteredGachas.length.toLocaleString("ja-JP")}件</span>
        </div>
        <div className="category-tabs">
          <Link className={selectedCategory === "all" ? "active" : ""} href="/">すべて</Link>
          {categories.map((category) => (
            <Link
              className={selectedCategory === category.slug ? "active" : ""}
              href={`/?category=${category.slug}`}
              key={category.slug}
            >
              {category.name}
            </Link>
          ))}
        </div>
      </section>

      <section className="gacha-showcase" id="gachas">
        <div className="public-section-head">
          <h2>開催中ガチャ</h2>
          <span>{filteredGachas.length.toLocaleString("ja-JP")} items</span>
        </div>
        {filteredGachas.length === 0 ? (
          <div className="public-empty">現在このカテゴリのガチャはありません。</div>
        ) : (
          <div className="public-gacha-grid">
            {filteredGachas.map((gacha) => (
              <PublicGachaCard key={gacha.id} gacha={gacha} selectedCategory={selectedCategory} />
            ))}
          </div>
        )}
      </section>

      <section className="information-panel" id="information">
        <div className="public-section-head">
          <h2>INFORMATION</h2>
          <span>News</span>
        </div>
        {announcements.data.length === 0 ? (
          <div className="public-empty">現在お知らせはありません。</div>
        ) : (
          <div className="information-list">
            {announcements.data.map((announcement) => (
              <Link href={`/announcements/${announcement.id}`} key={announcement.id}>
                <time>{formatShortDate(announcement.published_at ?? announcement.created_at)}</time>
                <strong>{announcement.title}</strong>
              </Link>
            ))}
          </div>
        )}
      </section>

      <footer className="public-footer">
        <div>
          <Image className="public-footer-logo" src="/lp-logo.png" alt="Luxe Pack" width={296} height={71} unoptimized />
          <span>© Luxe Pack</span>
        </div>
        <nav aria-label="フッター">
          <a href="/terms">利用規約</a>
          <a href="/point-terms">ポイント利用規約</a>
          <a href="/privacy">プライバシーポリシー</a>
          <a href="/commercial-law">特定商取引法に基づく表記</a>
          <a href="/antique-dealer">古物営業法に基づく表示</a>
          <a href="/return-policy">返品・キャンセルポリシー</a>
          <a href="/shipping-policy">配送ポリシー</a>
          <a href="/oripa-notice">オリパ販売に関する表示</a>
          <a href="/contact-info">お問い合わせ窓口</a>
          <a href="/contact">お問い合わせ</a>
        </nav>
      </footer>

      <section className="status-strip" aria-label="system status">
        <div className="status-grid">
          <StatusItem label="Laravel" value={health.app} />
          <StatusItem label="PostgreSQL" value={health.db} />
          <StatusItem label="Redis" value={health.redis} />
          <StatusItem label="MinIO Storage" value={health.storage} />
        </div>
      </section>
    </main>
  );
}

function PublicGachaCard({ gacha, selectedCategory }: { gacha: PublicGachaListItem; selectedCategory: string }) {
  const href = selectedCategory && selectedCategory !== "all"
    ? `/gachas/${gacha.id}?category=${selectedCategory}`
    : `/gachas/${gacha.id}`;
  const soldOut = isGachaSoldOut(gacha);

  return (
    <GachaTileLink className={`public-gacha-card ${soldOut ? "sold-out" : ""}`} href={href} soldOut={soldOut}>
      <div className="gacha-card-head">
        <span className="gacha-card-badge">{gacha.category.name ?? "Gacha"}</span>
        {gacha.tags.slice(0, 2).map((tag) => (
          <span className="gacha-card-tag" key={tag.id}>#{tag.name}</span>
        ))}
      </div>
      <div className="gacha-card-media">
        {gacha.main_image_url ? <div className="image-fill image-contain"><Image className="optimized-image-contain" src={gacha.main_image_url} alt="" fill sizes="(max-width: 760px) 100vw, 590px" /></div> : <span>LP</span>}
      </div>
      <div className="gacha-card-body">
        <strong>{gacha.title}</strong>
        <div className="gacha-card-purchase">
          <span className="gacha-card-price">
            <Image className="gacha-card-price-icon" src="/coin.png" alt="" width={22} height={22} aria-hidden="true" />
            {gacha.price.toLocaleString("ja-JP")}<small>/1回</small>
          </span>
          <span className="gacha-card-remaining">{soldOut ? "完売しました" : `残り ${gacha.remaining_count.toLocaleString("ja-JP")} / ${gacha.total_count.toLocaleString("ja-JP")}`}</span>
        </div>
        <Progress sold={gacha.sold_count} total={gacha.total_count} />
      </div>
    </GachaTileLink>
  );
}

function GachaTileLink({
  children,
  className,
  href,
  soldOut,
}: {
  children: ReactNode;
  className: string;
  href: string;
  soldOut: boolean;
}) {
  if (soldOut) {
    return (
      <div className={className} aria-disabled="true">
        {children}
      </div>
    );
  }

  return <Link className={className} href={href}>{children}</Link>;
}

function MetricLite({ label, value }: { label: string; value: string }) {
  return (
    <div className="metric-lite">
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function Progress({ sold, total }: { sold: number; total: number }) {
  const remaining = Math.max(0, total - sold);
  const percentage = total > 0 ? Math.min(100, Math.max(0, (remaining / total) * 100)) : 0;
  const tone = percentage >= 50 ? "high" : percentage >= 20 ? "middle" : "low";

  return (
    <div className="public-progress">
      <span className={`public-progress-${tone}`} style={{ width: `${percentage}%` }} />
    </div>
  );
}

function categoryOptions(gachas: PublicGachaListItem[]) {
  const categories = new Map<string, string>();

  gachas.forEach((gacha) => {
    if (gacha.category.slug && gacha.category.name) {
      categories.set(gacha.category.slug, gacha.category.name);
    }
  });

  return [...categories.entries()].map(([slug, name]) => ({ slug, name }));
}

function pointLabel(value: number) {
  return `${value.toLocaleString("ja-JP")}pt`;
}

function isGachaSoldOut(gacha: PublicGachaListItem) {
  return gacha.status === "sold_out" || gacha.remaining_count <= 0;
}

function formatShortDate(value: string | null) {
  if (!value) {
    return "-";
  }

  const date = new Date(value);

  return `${date.getFullYear()}.${String(date.getMonth() + 1).padStart(2, "0")}.${String(date.getDate()).padStart(2, "0")}`;
}

function parseSessionCookie(value?: string) {
  if (!value) {
    return null;
  }

  const candidates = [
    value,
    safeDecode(value),
    safeDecode(safeDecode(value)),
  ];

  for (const candidate of candidates) {
    try {
      return JSON.parse(candidate);
    } catch {
      // Try the next decoding level.
    }
  }

  return null;
}

function safeDecode(value: string) {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

function StatusItem({ label, value }: { label: string; value: string }) {
  return (
    <div className="status-item">
      <span className="label">{label}</span>
      <span className="value">{value}</span>
    </div>
  );
}
