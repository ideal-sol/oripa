"use client";

import { ChevronRight, LoaderCircle, Play, RefreshCw } from "lucide-react";
import { useEffect, useState } from "react";

import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminQaDrawCount,
  AdminQaExecutionDetail,
  AdminQaExecutionPreflight,
  AdminQaExecutionSummary,
  AdminQaPlanDetail,
} from "@/lib/admin-api/generated";

type Mutation = () => Promise<void>;

export function QaExecutionControls({
  busy,
  client,
  onError,
  plan,
  runMutation,
}: {
  busy: boolean;
  client: AdminApiClient;
  onError: (cause: unknown) => AdminApiError;
  plan: AdminQaPlanDetail;
  runMutation: (operation: Mutation) => Promise<void>;
}) {
  const assignments = plan.assignments.filter((item) => item.status === "assigned");
  const [assignmentId, setAssignmentId] = useState(assignments[0]?.id ?? "");
  const [drawCount, setDrawCount] = useState<AdminQaDrawCount>(1);
  const [preflight, setPreflight] = useState<AdminQaExecutionPreflight | null>(null);
  const [result, setResult] = useState<{
    detail: AdminQaExecutionDetail;
    replay: boolean;
  } | null>(null);
  const assignment = assignments.find((item) => item.id === assignmentId);
  const request = assignment
    ? {
        assignment_id: assignment.id,
        assignment_revision: assignment.revision,
        draw_count: drawCount,
        plan_revision: plan.revision,
      }
    : null;

  async function validate() {
    if (!request) return;
    try {
      setPreflight(await client.preflightQaExecution(plan.id, request));
      setResult(null);
    } catch (cause) {
      onError(cause);
    }
  }

  async function execute() {
    if (
      !request ||
      !preflight?.valid ||
      !window.confirm(
        `${drawCount}回のQA Drawを実行します。Point、在庫、販売口数、User Prizeへ実影響があります。続行しますか。`,
      )
    ) {
      return;
    }
    await runMutation(async () => {
      const response = await client.executeQaDraw(
        plan.id,
        request,
        crypto.randomUUID(),
      );
      setResult({
        detail: response.data,
        replay: response.idempotent_replay,
      });
      setPreflight(null);
    });
  }

  return (
    <section className="qa-card" aria-labelledby="qa-execution-heading">
      <div className="qa-section-heading">
        <div>
          <span className="eyebrow">Real Domain Operation</span>
          <h3 id="qa-execution-heading">QA Draw実行</h3>
        </div>
      </div>
      <p className="qa-impact-warning" role="note">
        Mockではありません。通常Drawと同じPoint、在庫、販売口数、User Prizeへ反映されます。
      </p>
      <div className="qa-form-grid">
        <label>
          <span>Test User Assignment</span>
          <select
            disabled={busy || assignments.length === 0}
            onChange={(event) => {
              setAssignmentId(event.target.value);
              setPreflight(null);
            }}
            value={assignmentId}
          >
            {assignments.map((item) => (
              <option key={item.id} value={item.id}>{item.user_id}</option>
            ))}
          </select>
        </label>
        <label>
          <span>実行回数</span>
          <select
            disabled={busy}
            onChange={(event) => {
              setDrawCount(Number(event.target.value) as AdminQaDrawCount);
              setPreflight(null);
            }}
            value={drawCount}
          >
            {[1, 5, 10, 100, 1000].map((count) => (
              <option key={count} value={count}>{count}回</option>
            ))}
          </select>
        </label>
      </div>
      <div className="qa-actions">
        <button
          className="secondary-button"
          disabled={busy || !request}
          onClick={validate}
          type="button"
        >
          <RefreshCw aria-hidden="true" size={16} />
          実行前検証
        </button>
        <button
          className="primary-button"
          disabled={busy || !preflight?.valid}
          onClick={execute}
          type="button"
        >
          {busy ? (
            <LoaderCircle aria-hidden="true" className="spin" size={16} />
          ) : (
            <Play aria-hidden="true" size={16} />
          )}
          QA Drawを実行
        </button>
      </div>
      {preflight ? (
        <div
          aria-live="polite"
          className={preflight.valid ? "qa-valid" : "qa-invalid"}
        >
          <strong>{preflight.valid ? "実行可能です" : "実行できません"}</strong>
          <p>
            必要Point {preflight.required_points.toLocaleString()} / 利用可能Point{" "}
            {preflight.available_points.toLocaleString()} / 販売残{" "}
            {preflight.remaining_sales_count.toLocaleString()}
          </p>
          {preflight.validation_codes.length > 0 ? (
            <ul>
              {preflight.validation_codes.map((code) => <li key={code}>{code}</li>)}
            </ul>
          ) : null}
        </div>
      ) : null}
      {result ? <ExecutionResult detail={result.detail} replay={result.replay} /> : null}
    </section>
  );
}

export function QaExecutionsPanel({
  client,
  onError,
}: {
  client: AdminApiClient;
  onError: (cause: unknown) => AdminApiError;
}) {
  const [items, setItems] = useState<AdminQaExecutionSummary[]>([]);
  const [detail, setDetail] = useState<AdminQaExecutionDetail | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    client.listQaExecutions({ limit: 25 }, controller.signal)
      .then((response) => setItems(response.items))
      .catch((cause) => {
        if (!controller.signal.aborted) onError(cause);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, onError]);

  async function open(id: string) {
    setLoading(true);
    try {
      setDetail(await client.getQaExecution(id));
    } catch (cause) {
      onError(cause);
    } finally {
      setLoading(false);
    }
  }

  if (loading) {
    return (
      <div className="qa-loading" role="status">
        <LoaderCircle aria-hidden="true" className="spin" size={20} />
        読み込んでいます
      </div>
    );
  }
  if (detail) {
    return (
      <section className="qa-detail">
        <button className="secondary-button" onClick={() => setDetail(null)} type="button">
          一覧へ
        </button>
        <ExecutionResult detail={detail} replay={false} />
      </section>
    );
  }
  if (items.length === 0) {
    return <p className="qa-empty">QA Draw Executionはありません。</p>;
  }

  return (
    <div className="qa-table-scroll">
      <table className="qa-table">
        <thead>
          <tr>
            <th>Execution</th>
            <th>Plan / Test User</th>
            <th>回数</th>
            <th>実行日時</th>
            <th><span className="sr-only">詳細</span></th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.id}>
              <td><code>{item.id}</code><span className="qa-status is-completed">{item.status}</span></td>
              <td><code>{item.plan_id}</code><code>{item.user_id}</code></td>
              <td>{item.executed_count.toLocaleString()}回</td>
              <td>{formatJst(item.executed_at)}</td>
              <td>
                <button
                  aria-label={`${item.id}の結果詳細`}
                  className="icon-button"
                  onClick={() => open(item.id)}
                  title="結果詳細"
                  type="button"
                >
                  <ChevronRight aria-hidden="true" size={18} />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ExecutionResult({
  detail,
  replay,
}: {
  detail: AdminQaExecutionDetail;
  replay: boolean;
}) {
  return (
    <section className="qa-card" aria-label="QA Draw実行結果">
      <div className="qa-section-heading">
        <div>
          <span className="qa-status is-completed">{detail.status}</span>
          <h3>実行結果</h3>
          <code>{detail.id}</code>
        </div>
      </div>
      <dl className="qa-definition-grid">
        <Definition label="Plan" value={detail.plan_id} />
        <Definition label="Test User" value={detail.user_id} />
        <Definition label="Assignment" value={detail.assignment_id} />
        <Definition label="Gacha Version" value={detail.gacha_version_id} />
        <Definition label="Draw Request" value={detail.draw_request_id} />
        <Definition label="実行回数" value={`${detail.executed_count}回`} />
        <Definition label="消費Point" value={detail.point_cost_total.toLocaleString()} />
        <Definition
          label="有償 / 無償"
          value={`${detail.consumed_paid_points.toLocaleString()} / ${detail.consumed_free_points.toLocaleString()}`}
        />
        <Definition label="販売口数差分" value={String(detail.sales_count_delta)} />
        <Definition label="景品在庫差分合計" value={String(detail.inventory_prize_delta_total)} />
        <Definition label="実行日時" value={formatJst(detail.executed_at)} />
        <Definition label="Replay" value={replay ? "Canonical Replay" : "No"} />
      </dl>
      <div className="qa-table-scroll">
        <table className="qa-table">
          <thead><tr><th>Prize</th><th>Rank</th><th>数量</th></tr></thead>
          <tbody>
            {detail.prize_counts.map((row, index) => (
              <tr key={row.prize?.id ?? index}>
                <td>{row.prize?.name ?? "不明"}</td>
                <td>{row.rank?.name ?? "不明"}</td>
                <td>{row.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function Definition({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>;
}

function formatJst(value: string): string {
  return new Intl.DateTimeFormat("ja-JP", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Tokyo",
  }).format(new Date(value));
}
