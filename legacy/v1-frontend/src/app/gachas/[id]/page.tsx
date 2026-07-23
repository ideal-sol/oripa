import Image from "next/image";
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
            <div className="image-fill image-contain" role="img" aria-label={gacha.title}><Image className="optimized-image-contain" src={gacha.main_image_url} alt="" fill sizes="(max-width: 900px) 100vw, 620px" /></div>
          ) : (
            <div className="media-placeholder">LP</div>
          )}
        </div>
        <div className="gacha-detail-side">
          <header className="detail-top">
            <div className="detail-badges">
              <span className="public-kicker">{gacha.category.name ?? "Gacha"}</span>
              {gacha.tags.slice(0, 3).map((tag) => (
                <span className="detail-tag" key={tag.id}>#{tag.name}</span>
              ))}
            </div>
            <h1>{gacha.title}</h1>
            <p>{gacha.description ?? "景品ラインナップを確認できます。"}</p>
          </header>

          <section className="detail-purchase-card" aria-label="販売状況">
            <div className="price-box">
              <span>1回</span>
              <strong><Image src="/coin.png" alt="" width={26} height={26} aria-hidden="true" />{gacha.price.toLocaleString("ja-JP")}</strong>
            </div>
            <div className="detail-metrics">
              <MetricLite label="残り" value={`${gacha.remaining_count.toLocaleString("ja-JP")}口`} />
              <MetricLite label="販売" value={`${gacha.sold_count.toLocaleString("ja-JP")} / ${gacha.total_count.toLocaleString("ja-JP")}`} />
              <MetricLite label="最低保証" value={gacha.minimum_guarantee.type === "point" ? `${gacha.minimum_guarantee.value}pt` : "景品"} />
              <MetricLite label="1日上限" value={gacha.daily_draw_limit ? `${gacha.daily_draw_limit.toLocaleString("ja-JP")}回` : "なし"} />
            </div>
            <div className="detail-stock-meter">
              <div>
                <span>残り口数</span>
                <strong>{gacha.remaining_count.toLocaleString("ja-JP")} / {gacha.total_count.toLocaleString("ja-JP")}</strong>
              </div>
              <Progress sold={gacha.sold_count} total={gacha.total_count} />
            </div>
          </section>

          <DrawPanel gachaId={gacha.id} price={gacha.price} remainingCount={gacha.remaining_count} dailyDrawLimit={gacha.daily_draw_limit} />
        </div>
      </div>

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
                    {prize.image_url ? <div className="image-fill" role="img" aria-label={prize.name}><Image className="optimized-image" src={prize.image_url} alt="" fill sizes="(max-width: 760px) 50vw, 260px" /></div> : <span>LP</span>}
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
  const remaining = Math.max(0, total - sold);
  const percentage = total > 0 ? Math.min(100, Math.max(0, (remaining / total) * 100)) : 0;
  const tone = percentage >= 50 ? "high" : percentage >= 20 ? "middle" : "low";

  return (
    <div className="public-progress">
      <span className={`public-progress-${tone}`} style={{ width: `${percentage}%` }} />
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
