import React, { useState, useMemo } from "react";

/**
 * オリパ 商品設計プランナー
 *
 * 入力:
 *   - ランク(S/A/B)ごとの 商品数 と 仕入合計額(円)
 *   - 粗利率(原価に乗せる割合, 既定20%)
 *   - 1回あたりのポイント単価
 *   - 1ポイントの円換算(既定1円)
 *
 * 算出:
 *   - 仕入原価合計
 *   - 目標総売上 = 原価 × (1 + 粗利率)
 *   - 総口数 = 総売上 ÷ 1回あたりの売上(円)
 *   - 損益分岐の消化率
 *   - 消化率ごとの想定利益(単純按分 / 最悪ケースの両方)
 */

const yen = (n) =>
  isFinite(n) ? "¥" + Math.round(n).toLocaleString("ja-JP") : "—";
const num = (n) =>
  isFinite(n) ? Math.round(n).toLocaleString("ja-JP") : "—";
const pct = (n) => (isFinite(n) ? (n * 100).toFixed(1) + "%" : "—");

const RANK_META = {
  S: { label: "S", tone: "#c8a24a", bg: "rgba(200,162,74,0.10)" },
  A: { label: "A", tone: "#7b8cad", bg: "rgba(123,140,173,0.10)" },
  B: { label: "B", tone: "#8a8f99", bg: "rgba(138,143,153,0.08)" },
};

export default function OripaPricingPlanner() {
  const [ranks, setRanks] = useState({
    S: { count: 3, cost: 150000 },
    A: { count: 10, cost: 100000 },
    B: { count: 50, cost: 50000 },
  });
  const [marginPct, setMarginPct] = useState(20); // 原価に乗せる%
  const [pointPrice, setPointPrice] = useState(1000); // 1回あたりポイント
  const [yenPerPoint, setYenPerPoint] = useState(1); // 1ポイント=何円

  const setRank = (key, field, value) => {
    const v = value === "" ? 0 : Math.max(0, Number(value));
    setRanks((r) => ({ ...r, [key]: { ...r[key], [field]: v } }));
  };

  const calc = useMemo(() => {
    const totalCost =
      ranks.S.cost + ranks.A.cost + ranks.B.cost; // 仕入原価合計
    const totalPrizes = ranks.S.count + ranks.A.count + ranks.B.count;
    const margin = marginPct / 100;
    const targetRevenue = totalCost * (1 + margin); // 目標総売上(円)
    const revenuePerDraw = pointPrice * yenPerPoint; // 1回あたり売上(円)

    // 総口数 = 総売上 ÷ 1回あたり売上 (切り上げ: 目標売上を下回らないように)
    const drawCountRaw =
      revenuePerDraw > 0 ? targetRevenue / revenuePerDraw : NaN;
    const drawCount = isFinite(drawCountRaw) ? Math.ceil(drawCountRaw) : NaN;

    // 全口完売時の実売上(総口数を整数に丸めた結果)
    const fullRevenue = isFinite(drawCount)
      ? drawCount * revenuePerDraw
      : NaN;
    const fullProfit = fullRevenue - totalCost;

    // 損益分岐の消化率: 売上×x = 原価(全額) → x = 原価 / 全完売売上
    const breakEvenWorst =
      isFinite(fullRevenue) && fullRevenue > 0
        ? totalCost / fullRevenue
        : NaN;

    // 消化率テーブル
    const rates = [0.1, 0.25, 0.5, 0.75, 0.9, 1.0];
    const rows = rates.map((x) => {
      const soldDraws = isFinite(drawCount) ? Math.round(drawCount * x) : NaN;
      const revenue = isFinite(fullRevenue) ? fullRevenue * x : NaN;
      // 単純按分: 景品も消化率どおり出る前提
      const profitProrated = revenue - totalCost * x;
      // 最悪ケース: 景品は全部出る前提(原価全額)
      const profitWorst = revenue - totalCost;
      return { x, soldDraws, revenue, profitProrated, profitWorst };
    });

    return {
      totalCost,
      totalPrizes,
      targetRevenue,
      revenuePerDraw,
      drawCount,
      fullRevenue,
      fullProfit,
      breakEvenWorst,
      rows,
    };
  }, [ranks, marginPct, pointPrice, yenPerPoint]);

  return (
    <div style={S.page}>
      <style>{CSS}</style>

      <header style={S.header}>
        <div style={S.kicker}>商品設計 / 値付けシミュレーション</div>
        <h1 style={S.h1}>オリパ プランナー</h1>
        <p style={S.lede}>
          ランクごとの仕入と1回あたりのポイントを入れると、総口数と
          消化率ごとの想定利益を試算します。
        </p>
      </header>

      <div style={S.grid}>
        {/* ── 入力 ── */}
        <section style={S.card}>
          <h2 style={S.h2}>仕入の内訳</h2>
          <div style={S.tableHead}>
            <span>ランク</span>
            <span style={{ textAlign: "right" }}>商品数</span>
            <span style={{ textAlign: "right" }}>仕入合計(円)</span>
          </div>
          {["S", "A", "B"].map((k) => (
            <div key={k} style={S.rankRow}>
              <span
                style={{
                  ...S.rankBadge,
                  color: RANK_META[k].tone,
                  background: RANK_META[k].bg,
                  borderColor: RANK_META[k].tone,
                }}
              >
                {RANK_META[k].label}
              </span>
              <input
                className="np-input"
                type="number"
                min="0"
                value={ranks[k].count}
                onChange={(e) => setRank(k, "count", e.target.value)}
              />
              <input
                className="np-input"
                type="number"
                min="0"
                step="1000"
                value={ranks[k].cost}
                onChange={(e) => setRank(k, "cost", e.target.value)}
              />
            </div>
          ))}

          <div style={S.subtotal}>
            <span>合計</span>
            <span style={{ textAlign: "right" }}>
              {num(calc.totalPrizes)} 点
            </span>
            <span style={{ textAlign: "right", fontWeight: 700 }}>
              {yen(calc.totalCost)}
            </span>
          </div>

          <hr style={S.hr} />

          <div style={S.field}>
            <label style={S.label}>
              粗利率(原価に乗せる%)
              <span style={S.hint}>売上 = 原価 × (1 + この値)</span>
            </label>
            <div style={S.inlineInput}>
              <input
                className="np-input wide"
                type="number"
                min="0"
                step="1"
                value={marginPct}
                onChange={(e) =>
                  setMarginPct(Math.max(0, Number(e.target.value || 0)))
                }
              />
              <span style={S.unit}>%</span>
            </div>
          </div>

          <div style={S.field}>
            <label style={S.label}>1回あたりのポイント単価</label>
            <div style={S.inlineInput}>
              <input
                className="np-input wide"
                type="number"
                min="1"
                step="100"
                value={pointPrice}
                onChange={(e) =>
                  setPointPrice(Math.max(0, Number(e.target.value || 0)))
                }
              />
              <span style={S.unit}>pt / 回</span>
            </div>
          </div>

          <div style={S.field}>
            <label style={S.label}>
              1ポイントの円換算
              <span style={S.hint}>通常 1pt = 1円</span>
            </label>
            <div style={S.inlineInput}>
              <input
                className="np-input wide"
                type="number"
                min="0"
                step="0.1"
                value={yenPerPoint}
                onChange={(e) =>
                  setYenPerPoint(Math.max(0, Number(e.target.value || 0)))
                }
              />
              <span style={S.unit}>円 / pt</span>
            </div>
          </div>
        </section>

        {/* ── 主要結果 ── */}
        <section style={{ ...S.card, ...S.resultCard }}>
          <h2 style={S.h2}>算出結果</h2>

          <div style={S.bigStat}>
            <div style={S.bigLabel}>必要な総口数</div>
            <div style={S.bigValue}>
              {num(calc.drawCount)}
              <span style={S.bigUnit}>口</span>
            </div>
            <div style={S.bigSub}>
              1回 {num(pointPrice)}pt（{yen(calc.revenuePerDraw)}）で販売した場合
            </div>
          </div>

          <div style={S.statGrid}>
            <Stat label="仕入原価合計" value={yen(calc.totalCost)} />
            <Stat
              label={`目標総売上 (粗利${marginPct}%)`}
              value={yen(calc.targetRevenue)}
            />
            <Stat
              label="完売時の実売上"
              value={yen(calc.fullRevenue)}
              sub="総口数を整数に丸めた結果"
            />
            <Stat
              label="完売時の利益"
              value={yen(calc.fullProfit)}
              accent="#2f7d54"
            />
            <Stat
              label="損益分岐の消化率"
              value={pct(calc.breakEvenWorst)}
              sub="景品全部出る前提(最悪)で原価を回収できる点"
              accent="#b06a2c"
            />
          </div>
        </section>
      </div>

      {/* ── 消化率テーブル ── */}
      <section style={S.card}>
        <h2 style={S.h2}>消化率ごとの想定利益</h2>
        <p style={S.note}>
          <b>単純按分</b>=景品も消化率どおりに出る前提（楽観）。
          <b>最悪ケース</b>=景品が全部出た前提で原価が全額かかる想定（保守）。
          実際の利益はこの2つの間に収まります。
        </p>
        <div style={S.tableWrap}>
          <table style={S.table}>
            <thead>
              <tr>
                <th style={S.th}>消化率</th>
                <th style={S.thR}>販売口数</th>
                <th style={S.thR}>売上</th>
                <th style={S.thR}>利益(単純按分)</th>
                <th style={S.thR}>利益(最悪ケース)</th>
              </tr>
            </thead>
            <tbody>
              {calc.rows.map((r) => (
                <tr key={r.x} style={r.x === 1 ? S.trFull : undefined}>
                  <td style={S.td}>
                    <span style={S.ratePill}>{pct(r.x)}</span>
                  </td>
                  <td style={S.tdR}>{num(r.soldDraws)}</td>
                  <td style={S.tdR}>{yen(r.revenue)}</td>
                  <td style={{ ...S.tdR, color: profitColor(r.profitProrated) }}>
                    {yen(r.profitProrated)}
                  </td>
                  <td style={{ ...S.tdR, color: profitColor(r.profitWorst) }}>
                    {yen(r.profitWorst)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p style={S.disclaimer}>
          ※ 概算ツールです。決済手数料・送料・景品交換による還元等は含みません。
          最終的な値付けは実コストを反映してご判断ください。
        </p>
      </section>
    </div>
  );
}

function Stat({ label, value, sub, accent }) {
  return (
    <div style={S.stat}>
      <div style={S.statLabel}>{label}</div>
      <div style={{ ...S.statValue, color: accent || "#1a1c20" }}>{value}</div>
      {sub && <div style={S.statSub}>{sub}</div>}
    </div>
  );
}

const profitColor = (v) =>
  !isFinite(v) ? "#9aa0aa" : v < 0 ? "#c0392b" : "#2f7d54";

/* ───────────────── styles ───────────────── */

const CSS = `
  .np-input {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 11px;
    border: 1px solid #d8d4ca;
    border-radius: 8px;
    font-size: 15px;
    font-variant-numeric: tabular-nums;
    text-align: right;
    background: #fff;
    color: #1a1c20;
    transition: border-color .15s, box-shadow .15s;
  }
  .np-input:focus {
    outline: none;
    border-color: #c8a24a;
    box-shadow: 0 0 0 3px rgba(200,162,74,.18);
  }
  .np-input.wide { max-width: 140px; }
  @media (max-width: 760px) {
    .np-input.wide { max-width: 100%; }
  }
`;

const S = {
  page: {
    fontFamily:
      "'Hiragino Kaku Gothic ProN','Yu Gothic',YuGothic,Meiryo,system-ui,sans-serif",
    background: "#f6f4ee",
    color: "#1a1c20",
    minHeight: "100%",
    padding: "28px 20px 48px",
    maxWidth: 980,
    margin: "0 auto",
    lineHeight: 1.55,
  },
  header: { marginBottom: 24 },
  kicker: {
    fontSize: 12,
    letterSpacing: "0.16em",
    color: "#a07c2f",
    fontWeight: 700,
    textTransform: "uppercase",
    marginBottom: 6,
  },
  h1: {
    fontFamily: "'Yu Mincho','Hiragino Mincho ProN',serif",
    fontSize: 34,
    fontWeight: 700,
    margin: "0 0 8px",
    letterSpacing: "0.02em",
  },
  lede: { margin: 0, color: "#5b5f68", fontSize: 14.5, maxWidth: 620 },
  grid: {
    display: "grid",
    gridTemplateColumns: "1fr 1fr",
    gap: 18,
    marginBottom: 18,
  },
  card: {
    background: "#fffdf8",
    border: "1px solid #e7e2d6",
    borderRadius: 14,
    padding: "20px 22px",
    boxShadow: "0 1px 2px rgba(40,30,10,0.03)",
  },
  resultCard: { background: "#fbf8ef" },
  h2: {
    fontSize: 16,
    fontWeight: 700,
    margin: "0 0 16px",
    letterSpacing: "0.02em",
  },
  tableHead: {
    display: "grid",
    gridTemplateColumns: "64px 1fr 1fr",
    gap: 10,
    fontSize: 12,
    color: "#8a8f99",
    fontWeight: 600,
    padding: "0 2px 8px",
  },
  rankRow: {
    display: "grid",
    gridTemplateColumns: "64px 1fr 1fr",
    gap: 10,
    alignItems: "center",
    marginBottom: 9,
  },
  rankBadge: {
    display: "inline-flex",
    alignItems: "center",
    justifyContent: "center",
    width: 38,
    height: 34,
    borderRadius: 8,
    border: "1px solid",
    fontWeight: 800,
    fontSize: 17,
    fontFamily: "'Yu Mincho',serif",
  },
  subtotal: {
    display: "grid",
    gridTemplateColumns: "64px 1fr 1fr",
    gap: 10,
    alignItems: "center",
    marginTop: 12,
    paddingTop: 12,
    borderTop: "1px dashed #ddd7c8",
    fontSize: 14,
    color: "#3a3d44",
  },
  hr: { border: 0, borderTop: "1px solid #ece7da", margin: "18px 0" },
  field: { marginBottom: 14 },
  label: {
    display: "flex",
    flexDirection: "column",
    fontSize: 13.5,
    fontWeight: 600,
    color: "#3a3d44",
    marginBottom: 6,
  },
  hint: { fontWeight: 400, fontSize: 12, color: "#9aa0aa", marginTop: 2 },
  inlineInput: { display: "flex", alignItems: "center", gap: 8 },
  unit: { fontSize: 13, color: "#7a7f88" },
  bigStat: {
    background: "#1f2330",
    color: "#fff",
    borderRadius: 12,
    padding: "18px 20px",
    marginBottom: 16,
  },
  bigLabel: {
    fontSize: 12.5,
    color: "#c8cdd8",
    letterSpacing: "0.08em",
    fontWeight: 600,
  },
  bigValue: {
    fontSize: 44,
    fontWeight: 800,
    lineHeight: 1.05,
    margin: "4px 0 2px",
    fontVariantNumeric: "tabular-nums",
    fontFamily: "'Yu Mincho',serif",
  },
  bigUnit: { fontSize: 18, fontWeight: 600, marginLeft: 6, color: "#c8a24a" },
  bigSub: { fontSize: 12.5, color: "#9aa1b0" },
  statGrid: {
    display: "grid",
    gridTemplateColumns: "1fr 1fr",
    gap: 10,
  },
  stat: {
    background: "#fff",
    border: "1px solid #ece7da",
    borderRadius: 10,
    padding: "11px 13px",
  },
  statLabel: { fontSize: 12, color: "#7a7f88", marginBottom: 4 },
  statValue: {
    fontSize: 19,
    fontWeight: 700,
    fontVariantNumeric: "tabular-nums",
  },
  statSub: { fontSize: 11, color: "#9aa0aa", marginTop: 3 },
  note: {
    fontSize: 13,
    color: "#5b5f68",
    margin: "0 0 14px",
    lineHeight: 1.7,
  },
  tableWrap: { overflowX: "auto" },
  table: { width: "100%", borderCollapse: "collapse", fontSize: 14 },
  th: {
    textAlign: "left",
    padding: "10px 12px",
    borderBottom: "2px solid #e7e2d6",
    fontSize: 12.5,
    color: "#7a7f88",
    fontWeight: 700,
    whiteSpace: "nowrap",
  },
  thR: {
    textAlign: "right",
    padding: "10px 12px",
    borderBottom: "2px solid #e7e2d6",
    fontSize: 12.5,
    color: "#7a7f88",
    fontWeight: 700,
    whiteSpace: "nowrap",
  },
  td: { padding: "11px 12px", borderBottom: "1px solid #f0ece1" },
  tdR: {
    padding: "11px 12px",
    borderBottom: "1px solid #f0ece1",
    textAlign: "right",
    fontVariantNumeric: "tabular-nums",
    fontWeight: 600,
    whiteSpace: "nowrap",
  },
  trFull: { background: "rgba(200,162,74,0.07)" },
  ratePill: {
    display: "inline-block",
    fontWeight: 700,
    fontVariantNumeric: "tabular-nums",
  },
  disclaimer: {
    fontSize: 11.5,
    color: "#9aa0aa",
    marginTop: 14,
    marginBottom: 0,
    lineHeight: 1.6,
  },
};
