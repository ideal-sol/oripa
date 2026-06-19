import { headers } from "next/headers";
import { cookies } from "next/headers";
import Link from "next/link";
import { redirect } from "next/navigation";
import { ReactNode } from "react";
import AdminDashboard from "./admin-dashboard";
import PublicHeader from "./public-header";
import { fetchApiHealth, fetchPublicAnnouncements, fetchPublicGachas, PublicGachaListItem } from "@/lib/api";

const ANNOUNCEMENT_FALLBACK_IMAGE = "/logo.png";

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

  const [health, gachaList, announcements] = await Promise.all([
    fetchApiHealth().catch((error: Error) => ({
      app: "error",
      db: "error",
      redis: "error",
      storage: "error",
      timestamp: error.message,
    })),
    fetchPublicGachas().catch(() => ({ data: [] })),
    fetchPublicAnnouncements().catch(() => ({ data: [] })),
  ]);
  const selectedCategory = typeof params?.category === "string" ? params.category : "all";
  const categories = categoryOptions(gachaList.data);
  const filteredGachas = selectedCategory && selectedCategory !== "all"
    ? gachaList.data.filter((gacha) => gacha.category.slug === selectedCategory)
    : gachaList.data;
  const heroGachas = filteredGachas.length > 0 ? filteredGachas.slice(0, 4) : gachaList.data.slice(0, 4);
  const activeGachaCount = gachaList.data.filter((gacha) => !isGachaSoldOut(gacha)).length;
  const sliderItems = topSliderItems(gachaList.data, announcements.data);

  return (
    <main className="public-shell">
      <PublicHeader />

      {sliderItems.length > 0 ? (
        <section className="top-slider" aria-label="トップスライド">
          <div className="top-slider-track" style={{ ["--slide-count" as string]: sliderItems.length }}>
            {sliderItems.map((item, index) => (
              <Link className="top-slider-item" href={item.href} key={`${item.key}-${index}`}>
                <span className={`top-slider-image ${item.isFallback ? "logo-fallback" : ""}`} style={{ backgroundImage: `url("${item.imageUrl}")` }} />
                <span className="top-slider-caption">
                  <small>{item.typeLabel}</small>
                  <strong>{item.title}</strong>
                </span>
              </Link>
            ))}
          </div>
        </section>
      ) : (
        <section className="public-hero">
          <div className="hero-media">
            {heroGachas.length > 0 ? (
              <div className="hero-gacha-tiles">
                {heroGachas.map((gacha) => (
                  <GachaTileLink href={`/gachas/${gacha.id}`} className="hero-gacha-tile" key={gacha.id} soldOut={isGachaSoldOut(gacha)}>
                    {gacha.main_image_url ? (
                      <span className="image-fill image-contain" style={{ backgroundImage: `url(${gacha.main_image_url})` }} />
                    ) : (
                      <span className="media-placeholder">LP</span>
                    )}
                    {isGachaSoldOut(gacha) && <span className="sold-out-overlay">SOLD OUT</span>}
                    <strong>{gacha.title}</strong>
                  </GachaTileLink>
                ))}
              </div>
            ) : (
              <div className="media-placeholder">LP</div>
            )}
          </div>
          <div className="hero-copy">
            <span className="public-kicker">Online oripa</span>
            <h1>Luxe Pack オンラインオリパ</h1>
            <p>カテゴリから公開中のガチャを選択し、価格、残り口数、景品ラインナップを確認できます。</p>
            <div className="hero-actions">
              <a className="public-primary-link" href="#gachas">公開中ガチャを見る</a>
              <a className="public-secondary-link" href="#gachas">ガチャ一覧</a>
            </div>
            <div className="hero-stats">
              <MetricLite label="公開中" value={`${activeGachaCount.toLocaleString("ja-JP")}件`} />
              <MetricLite label="カテゴリ" value={`${categories.length.toLocaleString("ja-JP")}種`} />
              <MetricLite label="確認項目" value="確率・景品" />
            </div>
          </div>
        </section>
      )}

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
          <h2>INFOMATION</h2>
          <span>News</span>
        </div>
        {announcements.data.length === 0 ? (
          <div className="public-empty">現在お知らせはありません。</div>
        ) : (
          <div className="information-list">
            {announcements.data.map((announcement) => (
              <Link href={`/announcements/${announcement.id}`} key={announcement.id}>
                <span className={`information-thumb ${announcement.thumbnail_url ? "" : "logo-fallback"}`}>
                  <span style={{ backgroundImage: `url("${announcement.thumbnail_url ?? ANNOUNCEMENT_FALLBACK_IMAGE}")` }} />
                </span>
                <time>{formatShortDate(announcement.published_at ?? announcement.created_at)}</time>
                <strong>{announcement.title}</strong>
              </Link>
            ))}
          </div>
        )}
      </section>

      <footer className="public-footer">
        <div>
          <strong>Luxe Pack</strong>
          <span>© Luxe Pack</span>
        </div>
        <nav aria-label="フッター">
          <a href="/terms">利用規約</a>
          <a href="/privacy">プライバシーポリシー</a>
          <a href="/commercial-law">特定商取引法に基づく表記</a>
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
      <div className="gacha-card-media">
        {gacha.main_image_url ? <div className="image-fill image-contain" style={{ backgroundImage: `url(${gacha.main_image_url})` }} /> : <span>LP</span>}
        <span className="gacha-card-badge">{gacha.category.name ?? "Gacha"}</span>
        <span className="gacha-card-price">{pointLabel(gacha.price)}</span>
        <span className="gacha-card-remaining">残り {gacha.remaining_count.toLocaleString("ja-JP")}口</span>
        {soldOut && <span className="sold-out-overlay">SOLD OUT</span>}
      </div>
      <div className="gacha-card-body">
        <strong>{gacha.title}</strong>
        <div className="gacha-card-meta">
          <small>{gacha.total_count.toLocaleString("ja-JP")}口</small>
          <small>{gacha.sold_count.toLocaleString("ja-JP")}口販売済み</small>
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
  const percentage = total > 0 ? Math.min(100, Math.max(0, (sold / total) * 100)) : 0;

  return (
    <div className="public-progress">
      <span style={{ width: `${percentage}%` }} />
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

function topSliderItems(gachas: PublicGachaListItem[], announcements: { id: number; title: string; thumbnail_url: string | null; show_on_top_slider: boolean; published_at: string | null; created_at: string | null }[]) {
  const items = [
    ...gachas
      .filter((gacha) => gacha.show_on_top_slider && gacha.main_image_url)
      .map((gacha) => ({
        key: `gacha-${gacha.id}`,
        href: isGachaSoldOut(gacha) ? "/#gachas" : `/gachas/${gacha.id}`,
        imageUrl: gacha.main_image_url as string,
        isFallback: false,
        title: gacha.title,
        typeLabel: "Gacha",
        date: gacha.start_at ?? "",
      })),
    ...announcements
      .filter((announcement) => announcement.show_on_top_slider)
      .map((announcement) => ({
        key: `announcement-${announcement.id}`,
        href: `/announcements/${announcement.id}`,
        imageUrl: announcement.thumbnail_url ?? ANNOUNCEMENT_FALLBACK_IMAGE,
        isFallback: !announcement.thumbnail_url,
        title: announcement.title,
        typeLabel: "News",
        date: announcement.published_at ?? announcement.created_at ?? "",
      })),
  ].sort((a, b) => b.date.localeCompare(a.date));

  return items.length === 0 ? [] : [...items, ...items];
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
