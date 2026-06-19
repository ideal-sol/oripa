import Link from "next/link";
import { fetchPublicGacha, fetchPublicGachas, PublicGachaDetail, PublicGachaListItem } from "@/lib/api";
import DrawPanel from "./draw-panel";
import PublicHeader from "../../public-header";

type GachaDetailPageProps = {
  params: Promise<{ id: string }>;
};

export default async function GachaDetailPage({ params }: GachaDetailPageProps) {
  const { id } = await params;
  const [gacha, gachas] = await Promise.all([
    fetchPublicGacha(Number(id)).then((response) => response.data),
    fetchPublicGachas().catch(() => ({ data: [] as PublicGachaListItem[] })),
  ]);
  const recommendedGachas = sameCategoryRecommendations(gacha, gachas.data);

  return (
    <main className="public-shell">
      <PublicHeader />

      <GachaDetailView gacha={gacha} recommendedGachas={recommendedGachas} />
    </main>
  );
}

function GachaDetailView({ gacha, recommendedGachas }: { gacha: PublicGachaDetail; recommendedGachas: PublicGachaListItem[] }) {
  return (
    <article className="detail-surface gacha-detail-page">
      <div className="gacha-detail-hero">
        <div className="gacha-detail-media">
          {gacha.main_image_url ? (
            <div className="image-fill image-contain" style={{ backgroundImage: `url(${gacha.main_image_url})` }} role="img" aria-label={gacha.title} />
          ) : (
            <div className="media-placeholder">LP</div>
          )}
        </div>
        <header className="detail-top">
          <div>
            <span className="public-kicker">{gacha.category.name ?? "Gacha"}</span>
            <h1>{gacha.title}</h1>
            <p>{gacha.description ?? "景品ラインナップを確認できます。"}</p>
          </div>
          <div className="price-box">
            <span>1回</span>
            <strong>{pointLabel(gacha.price)}</strong>
          </div>
        </header>
      </div>

      <div className="detail-metrics">
        <MetricLite label="残り" value={`${gacha.remaining_count.toLocaleString("ja-JP")}口`} />
        <MetricLite label="販売" value={`${gacha.sold_count.toLocaleString("ja-JP")} / ${gacha.total_count.toLocaleString("ja-JP")}`} />
        <MetricLite label="最低保証" value={gacha.minimum_guarantee.type === "point" ? `${gacha.minimum_guarantee.value}pt` : "景品"} />
      </div>

      <Progress sold={gacha.sold_count} total={gacha.total_count} />

      <DrawPanel gachaId={gacha.id} price={gacha.price} remainingCount={gacha.remaining_count} />

      <div className="stage-panel">
        <div>
          <h3>現在ステージ</h3>
          <p>{gacha.current_stage ? stageRangeLabel(gacha.current_stage) : "ステージ未設定"}</p>
        </div>
        {gacha.next_stage && (
          <span>次: {gacha.next_stage.name} / {stageRangeLabel(gacha.next_stage)}</span>
        )}
      </div>

      <div className="rank-list">
        {gacha.ranks.length === 0 ? (
          <div className="public-empty">表示中の景品はありません。</div>
        ) : gacha.ranks.map((rank) => (
          <section className="rank-section" key={rank.id}>
            <div className="rank-title">
              <h3>{rank.display_name}</h3>
            </div>
            <div className="public-prize-grid">
              {rank.prizes.map((prize) => (
                <div className="public-prize-card" key={prize.id}>
                  <div className="prize-image">
                    {prize.image_url ? <div className="image-fill" style={{ backgroundImage: `url(${prize.image_url})` }} role="img" aria-label={prize.name} /> : <span>LP</span>}
                  </div>
                  <div>
                    <strong>{prize.name}</strong>
                    <small>{prize.condition} / 残 {prize.remaining_win_count.toLocaleString("ja-JP")}</small>
                  </div>
                </div>
              ))}
            </div>
          </section>
        ))}
      </div>

      {gacha.caution && <p className="caution-box">{gacha.caution}</p>}

      <section className="recommended-gachas">
        <div className="public-section-head">
          <h2>おすすめガチャ</h2>
          <span>{gacha.category.name ?? "同じカテゴリー"}</span>
        </div>
        {recommendedGachas.length === 0 ? (
          <div className="public-empty">現在おすすめできる同カテゴリーのガチャはありません。</div>
        ) : (
          <div className="public-gacha-grid">
            {recommendedGachas.map((recommended) => (
              <RecommendedGachaCard gacha={recommended} key={recommended.id} />
            ))}
          </div>
        )}
      </section>
    </article>
  );
}

function RecommendedGachaCard({ gacha }: { gacha: PublicGachaListItem }) {
  const soldOut = isGachaSoldOut(gacha);
  const children = (
    <>
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
    </>
  );

  if (soldOut) {
    return <div className="public-gacha-card sold-out" aria-disabled="true">{children}</div>;
  }

  return <Link className="public-gacha-card" href={`/gachas/${gacha.id}`}>{children}</Link>;
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

function pointLabel(value: number) {
  return `${value.toLocaleString("ja-JP")}pt`;
}

function stageRangeLabel(stage: { name: string; min_draw_number: number; max_draw_number: number | null }) {
  return `${stage.name} ${stage.min_draw_number.toLocaleString("ja-JP")} - ${stage.max_draw_number?.toLocaleString("ja-JP") ?? "LAST"}口`;
}

function sameCategoryRecommendations(gacha: PublicGachaDetail, gachas: PublicGachaListItem[]) {
  const categorySlug = gacha.category.slug;

  return gachas
    .filter((item) => item.id !== gacha.id)
    .filter((item) => categorySlug ? item.category.slug === categorySlug : item.category.id === gacha.category.id)
    .slice(0, 4);
}

function isGachaSoldOut(gacha: PublicGachaListItem) {
  return gacha.status === "sold_out" || gacha.remaining_count <= 0;
}
