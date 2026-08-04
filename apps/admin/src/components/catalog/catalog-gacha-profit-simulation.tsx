"use client";

import { ArrowLeft, Calculator, LoaderCircle, RotateCcw } from "lucide-react";
import Link from "next/link";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminCatalogGacha,
  AdminCatalogGachaVersion,
  AdminCatalogProbabilityVersion,
  AdminGachaVersionPrize,
} from "@/lib/admin-api/generated";
import {
  simulateGachaProfit,
  validateProfitSimulationInput,
  type ProfitSimulationInput,
  type ProfitSimulationResult,
} from "@/lib/catalog/gacha-profit-simulation";

type LoadedData = {
  gacha: AdminCatalogGacha;
  version: AdminCatalogGachaVersion;
  prizes: AdminGachaVersionPrize[];
  probability: AdminCatalogProbabilityVersion | null;
};

type ViewState =
  | { kind: "loading" }
  | { kind: "empty"; gacha: AdminCatalogGacha }
  | { kind: "error"; message: string }
  | { kind: "ready"; data: LoadedData };

type SimulationForm = {
  price: string;
  totalCount: string;
  minimumGuaranteeCost: string;
  targetMarginRate: string;
};

const number = new Intl.NumberFormat("ja-JP");

export function CatalogGachaProfitSimulation({ gachaId }: { gachaId: string }) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <ProfitSimulationWorkspace gachaId={gachaId} key={gachaId} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function ProfitSimulationWorkspace({ gachaId }: { gachaId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [view, setView] = useState<ViewState>({ kind: "loading" });
  const [revision, setRevision] = useState(0);
  const [form, setForm] = useState<SimulationForm | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [result, setResult] = useState<ProfitSimulationResult | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    void loadSimulationData(client, gachaId, controller.signal)
      .then((loaded) => {
        if (controller.signal.aborted) return;
        if (loaded === null) return;
        if ("empty" in loaded) {
          setView({ kind: "empty", gacha: loaded.empty });
          return;
        }
        const initialForm = {
          minimumGuaranteeCost: "10",
          price: String(loaded.version.price_points),
          targetMarginRate: "",
          totalCount: String(loaded.version.total_count),
        };
        setForm(initialForm);
        setResult(calculate(loaded, initialForm));
        setView({ kind: "ready", data: loaded });
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) {
          setView({ kind: "error", message: errorMessage(cause) });
        }
      });
    return () => controller.abort();
  }, [client, gachaId, revision]);

  const retry = useCallback(() => {
    setView({ kind: "loading" });
    setForm(null);
    setResult(null);
    setErrors({});
    setRevision((value) => value + 1);
  }, []);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (view.kind !== "ready" || form === null) return;
    const nextErrors = validateProfitSimulationInput(form);
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;
    setResult(calculate(view.data, form));
  }

  const title = view.kind === "ready"
    ? view.data.version.title
    : view.kind === "empty"
      ? view.gacha.current_version?.title ?? view.gacha.code
      : "利益シミュレーション";

  return (
    <section className="workspace catalog-profit-simulation">
      <nav aria-label="パンくず" className="breadcrumb">
        <ol>
          <li><Link href="/">ダッシュボード</Link></li>
          <li><span aria-hidden="true">/</span><Link href="/catalog/gachas">ガチャ一覧</Link></li>
          <li><span aria-hidden="true">/</span><Link href={`/catalog/gachas/${gachaId}`}>{title}</Link></li>
          <li aria-current="page"><span aria-hidden="true">/</span>利益シミュレーション</li>
        </ol>
      </nav>
      <AdminPageHeader
        action={(
          <Link className="secondary-button" href={`/catalog/gachas/${gachaId}`}>
            <ArrowLeft aria-hidden="true" size={17} />
            ガチャ詳細へ
          </Link>
        )}
        description="完売時売上と最大原価から粗利を計算します。入力内容と結果は保存されません。"
        eyebrow="Gacha"
        title="利益シミュレーション"
      />
      {view.kind === "loading" ? <SimulationState loading message="試算データを読み込んでいます。" /> : null}
      {view.kind === "error" ? <SimulationState error message={view.message} retry={retry} /> : null}
      {view.kind === "empty" ? (
        <SimulationState message="編集可能なDraft Versionがないため試算できません。" />
      ) : null}
      {view.kind === "ready" && form && result ? (
        <>
          <section className="catalog-profit-panel" aria-labelledby="simulation-input-heading">
            <div className="catalog-profit-heading">
              <div>
                <span className="eyebrow">Draft Version {view.data.version.version_number}</span>
                <h2 id="simulation-input-heading">{view.data.version.title}</h2>
              </div>
              <code title={view.data.version.id}>{shortId(view.data.version.id)}</code>
            </div>
            <form className="catalog-profit-form" noValidate onSubmit={submit}>
              <SimulationField
                error={errors.price}
                label="1口価格（pt）"
                name="price"
                onChange={(value) => setForm({ ...form, price: value })}
                value={form.price}
              />
              <SimulationField
                error={errors.totalCount}
                label="総口数"
                name="totalCount"
                onChange={(value) => setForm({ ...form, totalCount: value })}
                value={form.totalCount}
              />
              <SimulationField
                error={errors.minimumGuaranteeCost}
                label="保証原価（円／口）"
                name="minimumGuaranteeCost"
                onChange={(value) => setForm({ ...form, minimumGuaranteeCost: value })}
                value={form.minimumGuaranteeCost}
              />
              <SimulationField
                error={errors.targetMarginRate}
                label="目標粗利率（%・任意）"
                name="targetMarginRate"
                onChange={(value) => setForm({ ...form, targetMarginRate: value })}
                step="0.01"
                value={form.targetMarginRate}
              />
              <button className="primary-button catalog-profit-submit" type="submit">
                <Calculator aria-hidden="true" size={17} />
                再計算
              </button>
            </form>
            <p className="catalog-profit-note">
              景品原価は登録済み景品{number.format(view.data.prizes.length)}件の総在庫を使用します。
            </p>
          </section>
          <SimulationResults result={result} />
        </>
      ) : null}
    </section>
  );
}

function SimulationResults({ result }: { result: ProfitSimulationResult }) {
  const items = [
    ["完売時売上", money(result.sales.totalSales)],
    ["景品原価総額", money(result.costs.prizeInventoryCost)],
    ["最低保証最大原価", money(result.costs.minimumGuaranteeMaxCost)],
    ["最大原価合計", money(result.costs.maxCost)],
    ["想定利益", money(result.profit.projectedProfit)],
    ["想定粗利率", percent(result.profit.projectedMarginRate)],
    ["目標利益", nullableMoney(result.profit.targetProfit)],
    ["目標差分", nullableMoney(result.profit.gapToTargetProfit)],
  ];
  return (
    <section className="catalog-profit-panel" aria-labelledby="simulation-result-heading">
      <div className="catalog-profit-heading">
        <div>
          <span className="eyebrow">Maximum cost scenario</span>
          <h2 id="simulation-result-heading">計算結果</h2>
        </div>
        {result.profit.meetsTarget !== null ? (
          <span className={`status-badge ${result.profit.meetsTarget ? "success" : "danger"}`}>
            {result.profit.meetsTarget ? "目標達成" : "目標未達"}
          </span>
        ) : null}
      </div>
      <dl className="catalog-profit-result-grid">
        {items.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd className={(label === "想定利益" || label === "目標差分") && value.startsWith("-¥") ? "negative" : ""}>
              {value}
            </dd>
          </div>
        ))}
      </dl>
      <div className="catalog-profit-expected">
        <div className="catalog-profit-heading compact">
          <div>
            <h3>確率ベース期待値</h3>
            <p>選択済み公開確率から1回あたり期待原価と完売時期待利益を計算</p>
          </div>
        </div>
        {result.expected.available ? (
          <>
            <dl className="catalog-profit-result-grid compact">
              <ResultItem label="1回あたり期待原価" value={nullableMoney(result.expected.expectedCostPerDraw)} />
              <ResultItem label="期待原価合計" value={nullableMoney(result.expected.expectedTotalCost)} />
              <ResultItem label="期待利益" value={nullableMoney(result.expected.expectedProfit)} />
              <ResultItem label="期待粗利率" value={percent(result.expected.expectedMarginRate)} />
            </dl>
            {result.expected.stages.length > 0 ? (
              <div className="catalog-profit-stage-list">
                {result.expected.stages.map((stage) => (
                  <div key={stage.stageKey}>
                    <span>{stage.name}</span>
                    <small>{number.format(stage.drawCount)}口</small>
                    <strong>{money(stage.expectedCostPerDraw)} / 回</strong>
                    <strong>{money(stage.expectedTotalCost)}</strong>
                  </div>
                ))}
              </div>
            ) : null}
          </>
        ) : (
          <p className="catalog-profit-empty">公開済み確率がないため、期待原価は未計算です。</p>
        )}
      </div>
      {result.warnings.length > 0 ? (
        <div className="catalog-profit-warnings" role="status">
          {result.warnings.map((warning) => <p key={warning}>{warning}</p>)}
        </div>
      ) : null}
    </section>
  );
}

function SimulationField({
  error,
  label,
  name,
  onChange,
  step = "1",
  value,
}: {
  error?: string;
  label: string;
  name: string;
  onChange: (value: string) => void;
  step?: string;
  value: string;
}) {
  const errorId = `${name}-error`;
  return (
    <label>
      <span>{label}</span>
      <input
        aria-describedby={error ? errorId : undefined}
        aria-invalid={Boolean(error)}
        inputMode={step === "1" ? "numeric" : "decimal"}
        min="0"
        name={name}
        onChange={(event) => onChange(event.target.value)}
        step={step}
        type="number"
        value={value}
      />
      {error ? <small className="form-field-error" id={errorId}>{error}</small> : null}
    </label>
  );
}

function ResultItem({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>;
}

function SimulationState({
  error = false,
  loading = false,
  message,
  retry,
}: {
  error?: boolean;
  loading?: boolean;
  message: string;
  retry?: () => void;
}) {
  return (
    <section className="module-state" role={error ? "alert" : "status"}>
      {loading ? <LoaderCircle aria-hidden="true" className="spin" size={24} /> : null}
      <h2>{error ? "利益シミュレーションを取得できませんでした" : message}</h2>
      {error ? <p>{message}</p> : null}
      {retry ? (
        <button className="secondary-button" onClick={retry} type="button">
          <RotateCcw aria-hidden="true" size={16} />
          再試行
        </button>
      ) : null}
    </section>
  );
}

async function loadSimulationData(
  client: AdminApiClient,
  gachaId: string,
  signal: AbortSignal,
): Promise<LoadedData | { empty: AdminCatalogGacha } | null> {
  const [gachaResponse, versions] = await Promise.all([
    client.getCatalogGacha(gachaId, signal),
    client.listCatalogGachaVersions(
      gachaId,
      { archive: "active", direction: "desc", limit: 100, status: "draft" },
      signal,
    ),
  ]);
  const version = versions.items.find((candidate) => candidate.status === "draft" && !candidate.is_archived);
  if (!version) return { empty: gachaResponse.data };
  const [prizes, selection] = await Promise.all([
    client.listGachaVersionPrizes(gachaId, version.id, signal),
    client.getGachaProbabilitySelection(gachaId, version.id, signal),
  ]);
  const selectedId = selection.data.selected_probability?.id ?? null;
  const probability = selectedId
    ? (await client.getCatalogProbabilityVersion(gachaId, version.id, selectedId, signal)).data
    : null;
  return { gacha: gachaResponse.data, probability, prizes: prizes.items, version };
}

function calculate(data: LoadedData, form: SimulationForm): ProfitSimulationResult {
  const input: ProfitSimulationInput = {
    minimumGuaranteeCost: Number(form.minimumGuaranteeCost),
    price: Number(form.price),
    prizes: data.prizes.map((prize) => ({
      availableInventory: prize.available_inventory,
      costPrice: prize.cost_price,
      id: prize.id,
      totalInventory: prize.total_inventory,
    })),
    probabilityVersionId: data.probability?.id ?? null,
    soldCount: data.gacha.sold_count,
    stages: data.probability?.stages.map((stage) => ({
      code: stage.code,
      entries: stage.entries.map(mapTarget),
      maxDrawNumber: stage.max_draw_number,
      minDrawNumber: stage.min_draw_number,
      minimumGuarantee: stage.minimum_guarantee ? mapTarget(stage.minimum_guarantee) : null,
      name: stage.name,
    })) ?? [],
    targetMarginRate: form.targetMarginRate === "" ? null : Number(form.targetMarginRate),
    totalCount: Number(form.totalCount),
  };
  return simulateGachaProfit(input);
}

function mapTarget(target: {
  point_amount: number | null;
  prize: { id: string } | null;
  probability_ppm: number;
  result_type: "prize" | "point_back";
}) {
  return {
    pointAmount: target.point_amount,
    prizeId: target.prize?.id ?? null,
    probabilityPpm: target.probability_ppm,
    resultType: target.result_type,
  };
}

function money(value: number): string {
  const sign = value < 0 ? "-" : "";
  return `${sign}¥${number.format(Math.abs(value))}`;
}

function nullableMoney(value: number | null): string {
  return value === null ? "-" : money(value);
}

function percent(value: number | null): string {
  return value === null ? "-" : `${number.format(value)}%`;
}

function shortId(value: string): string {
  return `${value.slice(0, 8)}…${value.slice(-4)}`;
}

function errorMessage(cause: unknown): string {
  if (cause instanceof AdminApiError) {
    if (cause.status === 403) return "このガチャを参照する権限がありません。";
    if (cause.status === 404) return "対象ガチャが見つかりません。";
    return cause.requestId ? `Request ID: ${cause.requestId}` : "再試行してください。";
  }
  return "ネットワーク接続を確認して再試行してください。";
}
