"use client";

import {
  Archive,
  CheckCircle2,
  ChevronRight,
  CirclePause,
  CirclePlay,
  LoaderCircle,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  UserMinus,
  UserPlus,
  Users,
  X,
} from "lucide-react";
import {
  type FormEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminQaPlanCreate,
  AdminQaPlanDetail,
  AdminQaPlanSummary,
  AdminQaPlanUpdate,
  AdminQaPreflight,
  AdminQaTestUser,
} from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";
import {
  QaExecutionControls,
  QaExecutionsPanel,
} from "@/components/qa/qa-execution-panel";

type View = "plans" | "users" | "executions";
type Mutation = () => Promise<void>;

const EMPTY_CREATE: AdminQaPlanCreate = {
  ends_at: null,
  gacha_id: "",
  items: [
    {
      fixed_image_asset_id: null,
      fixed_video_asset_id: null,
      prize_id: "",
      quantity: 1,
      sort_order: 1,
    },
  ],
  reason: "",
  starts_at: null,
  title: "",
  user_id: "",
};

export function QaManagementWorkspace() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { expireSession } = useAdminAuth();
  const navigation = navigationItem("qa");
  const [view, setView] = useState<View>("plans");
  const [error, setError] = useState<AdminApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const retryAfterFreshMfa = useRef<Mutation | null>(null);

  const reportError = useCallback((cause: unknown) => {
    const normalized = asApiError(cause);
    if (normalized.isSessionExpired) expireSession();
    setError(normalized);
    return normalized;
  }, [expireSession]);

  const runMutation = useCallback(async (operation: Mutation) => {
    setBusy(true);
    setError(null);
    try {
      await operation();
      retryAfterFreshMfa.current = null;
    } catch (cause) {
      const normalized = reportError(cause);
      if (normalized.requiresFreshMfa) {
        retryAfterFreshMfa.current = operation;
        setFreshMfaOpen(true);
      }
    } finally {
      setBusy(false);
    }
  }, [reportError]);

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="qa.draw.manage">
        <div className="workspace qa-workspace">
          <Breadcrumb item={navigation} />
          <AdminPageHeader
            action={<ShieldCheck aria-hidden="true" size={28} />}
            description="QA PlanとQA Test Userの設定を管理します。"
            eyebrow="QA Operations"
            title="QA Plan管理"
          />
          <div aria-label="QA管理" className="qa-tabs" role="tablist">
            <button
              aria-selected={view === "plans"}
              onClick={() => setView("plans")}
              role="tab"
              type="button"
            >
              QA Plan
            </button>
            <button
              aria-selected={view === "users"}
              onClick={() => setView("users")}
              role="tab"
              type="button"
            >
              Test User
            </button>
            <button
              aria-selected={view === "executions"}
              onClick={() => setView("executions")}
              role="tab"
              type="button"
            >
              実行結果
            </button>
          </div>
          {error ? <QaError error={error} onDismiss={() => setError(null)} /> : null}
          {view === "plans" ? (
            <QaPlansPanel
              busy={busy}
              client={client}
              onError={reportError}
              runMutation={runMutation}
            />
          ) : view === "users" ? (
            <QaTestUsersPanel
              busy={busy}
              client={client}
              onError={reportError}
              runMutation={runMutation}
            />
          ) : (
            <QaExecutionsPanel client={client} onError={reportError} />
          )}
        </div>
        <FreshMfaDialog
          onClose={() => {
            retryAfterFreshMfa.current = null;
            setFreshMfaOpen(false);
          }}
          onSuccess={async () => {
            setFreshMfaOpen(false);
            const retry = retryAfterFreshMfa.current;
            if (retry) await runMutation(retry);
          }}
          open={freshMfaOpen}
        />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function QaPlansPanel({
  busy,
  client,
  onError,
  runMutation,
}: {
  busy: boolean;
  client: AdminApiClient;
  onError: (cause: unknown) => AdminApiError;
  runMutation: (operation: Mutation) => Promise<void>;
}) {
  const [plans, setPlans] = useState<AdminQaPlanSummary[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null]);
  const [cursorIndex, setCursorIndex] = useState(0);
  const [query, setQuery] = useState("");
  const [submittedQuery, setSubmittedQuery] = useState("");
  const [status, setStatus] = useState<"all" | "active" | "paused" | "completed" | "disabled">("all");
  const [selected, setSelected] = useState<AdminQaPlanDetail | null>(null);
  const [preflight, setPreflight] = useState<AdminQaPreflight | null>(null);
  const [loading, setLoading] = useState(true);
  const [formMode, setFormMode] = useState<"create" | "edit" | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    client.listQaPlans({
        cursor: cursorStack[cursorIndex] ?? undefined,
        limit: 20,
        q: submittedQuery || undefined,
        status,
      }, controller.signal)
      .then((result) => {
        setPlans(result.items);
        setNextCursor(result.next_cursor);
      })
      .catch((cause) => {
        if (!controller.signal.aborted) onError(cause);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, cursorIndex, cursorStack, onError, reload, status, submittedQuery]);

  async function openPlan(id: string) {
    setLoading(true);
    try {
      setSelected(await client.getQaPlan(id));
      setPreflight(null);
      setFormMode(null);
    } catch (cause) {
      onError(cause);
    } finally {
      setLoading(false);
    }
  }

  async function mutatePlan(
    action: "enable" | "disable" | "archive",
    label: string,
  ) {
    if (!selected || !window.confirm(`${label}を実行しますか。`)) return;
    await runMutation(async () => {
      const result = await client.transitionQaPlan(
        selected.id,
        action,
        selected.revision,
        crypto.randomUUID(),
      );
      setSelected(await client.getQaPlan(result.data.id));
      setPreflight(null);
      setReload((value) => value + 1);
    });
  }

  async function saveCreate(input: AdminQaPlanCreate) {
    await runMutation(async () => {
      const result = await client.createQaPlan(input, crypto.randomUUID());
      setSelected(await client.getQaPlan(result.data.id));
      setFormMode(null);
      setReload((value) => value + 1);
    });
  }

  async function saveUpdate(input: AdminQaPlanUpdate) {
    if (!selected) return;
    await runMutation(async () => {
      const result = await client.updateQaPlan(
        selected.id,
        input,
        crypto.randomUUID(),
      );
      setSelected(await client.getQaPlan(result.data.id));
      setFormMode(null);
      setPreflight(null);
      setReload((value) => value + 1);
    });
  }

  async function assign(userId: string) {
    if (!selected) return;
    await runMutation(async () => {
      const result = await client.assignQaTestUser(
        selected.id,
        userId,
        selected.revision,
        crypto.randomUUID(),
      );
      setSelected(await client.getQaPlan(result.data.id));
      setPreflight(null);
    });
  }

  async function unassign(userId: string) {
    if (!selected || !window.confirm("このTest Userの割当を解除しますか。")) return;
    await runMutation(async () => {
      const result = await client.unassignQaTestUser(
        selected.id,
        userId,
        selected.revision,
        crypto.randomUUID(),
      );
      setSelected(await client.getQaPlan(result.data.id));
      setPreflight(null);
    });
  }

  if (formMode) {
    return (
      <QaPlanForm
        busy={busy}
        initial={formMode === "edit" ? selected : null}
        mode={formMode}
        onCancel={() => setFormMode(null)}
        onCreate={saveCreate}
        onUpdate={saveUpdate}
      />
    );
  }

  if (selected) {
    return (
      <section className="qa-detail">
        <header className="qa-section-heading">
          <div>
            <span className={`qa-status is-${selected.status}`}>{selected.status}</span>
            <h2>{selected.title}</h2>
            <code>{selected.code}</code>
          </div>
          <div className="qa-actions">
            <button
              className="secondary-button"
              onClick={() => setSelected(null)}
              type="button"
            >
              一覧へ
            </button>
            {selected.execution_count === 0 && !selected.archived_at ? (
              <button
                className="secondary-button"
                onClick={() => setFormMode("edit")}
                type="button"
              >
                編集
              </button>
            ) : null}
          </div>
        </header>
        <dl className="qa-definition-grid">
          <Definition label="User Public ID" value={selected.user_id} />
          <Definition label="Gacha Public ID" value={selected.gacha_id} />
          <Definition label="Revision" value={String(selected.revision)} />
          <Definition label="実行履歴" value={`${selected.execution_count}件`} />
          <Definition label="開始" value={formatJst(selected.starts_at)} />
          <Definition label="終了" value={formatJst(selected.ends_at)} />
        </dl>
        <section className="qa-card">
          <h3>Reason</h3>
          <p>{selected.reason}</p>
        </section>
        {selected.status === "active" && !selected.archived_at ? (
          <QaExecutionControls
            busy={busy}
            client={client}
            onError={onError}
            plan={selected}
            runMutation={runMutation}
          />
        ) : null}
        <section className="qa-card">
          <div className="qa-section-heading">
            <div>
              <span className="eyebrow">Validation</span>
              <h3>Server Preflight</h3>
            </div>
            <button
              className="secondary-button"
              disabled={busy}
              onClick={async () => {
                try {
                  setPreflight(await client.preflightQaPlan(selected.id));
                } catch (cause) {
                  onError(cause);
                }
              }}
              type="button"
            >
              <CheckCircle2 size={16} aria-hidden="true" />
              検証
            </button>
          </div>
          {preflight ? (
            <div aria-live="polite" className={preflight.valid ? "qa-valid" : "qa-invalid"}>
              <strong>{preflight.valid ? "実行設定は有効です" : "実行設定を確認してください"}</strong>
              <p>
                Test User {preflight.assigned_test_user_count}人 / 残り{" "}
                {preflight.remaining_draw_count}回
              </p>
              {preflight.validation_codes.length > 0 ? (
                <ul>
                  {preflight.validation_codes.map((code) => <li key={code}>{code}</li>)}
                </ul>
              ) : null}
            </div>
          ) : null}
        </section>
        <section className="qa-card">
          <div className="qa-section-heading">
            <div>
              <span className="eyebrow">Assignment</span>
              <h3>Test User割当</h3>
            </div>
            {!selected.archived_at ? (
              <CandidateAssignment
                client={client}
                disabled={busy}
                onAssign={assign}
                onError={onError}
              />
            ) : null}
          </div>
          {selected.assignments.length === 0 ? (
            <p className="qa-empty">割当はありません。</p>
          ) : (
            <ul className="qa-assignment-list">
              {selected.assignments.map((assignment) => (
                <li key={assignment.id}>
                  <div>
                    <code>{assignment.user_id}</code>
                    <span>{assignment.status}</span>
                  </div>
                  {assignment.status === "assigned" && selected.execution_count === 0 ? (
                    <button
                      aria-label={`${assignment.user_id}の割当解除`}
                      className="icon-button"
                      disabled={busy}
                      onClick={() => unassign(assignment.user_id)}
                      title="割当解除"
                      type="button"
                    >
                      <UserMinus size={17} />
                    </button>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </section>
        <section className="qa-card">
          <h3>指定景品</h3>
          <div className="qa-table-scroll">
            <table className="qa-table">
              <thead>
                <tr>
                  <th>順序</th>
                  <th>Prize Public ID</th>
                  <th>設定数</th>
                  <th>消費済み</th>
                </tr>
              </thead>
              <tbody>
                {selected.items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.sort_order}</td>
                    <td><code>{item.prize_id}</code></td>
                    <td>{item.quantity}</td>
                    <td>{item.consumed_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
        {!selected.archived_at ? (
          <div className="qa-actions qa-danger-actions">
            {selected.status === "active" ? (
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() => mutatePlan("disable", "一時停止")}
                type="button"
              >
                <CirclePause size={17} aria-hidden="true" />
                一時停止
              </button>
            ) : selected.status === "paused" ? (
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() => mutatePlan("enable", "有効化")}
                type="button"
              >
                <CirclePlay size={17} aria-hidden="true" />
                有効化
              </button>
            ) : null}
            {selected.execution_count === 0 ? (
              <button
                className="danger-button"
                disabled={busy}
                onClick={() => mutatePlan("archive", "Archive")}
                type="button"
              >
                <Archive size={17} aria-hidden="true" />
                Archive
              </button>
            ) : null}
          </div>
        ) : null}
      </section>
    );
  }

  return (
    <section>
      <div className="qa-section-heading">
        <form
          className="qa-search"
          onSubmit={(event) => {
            event.preventDefault();
            setCursorStack([null]);
            setCursorIndex(0);
            setSubmittedQuery(query);
          }}
        >
          <label>
            <span className="sr-only">Plan検索</span>
            <Search size={17} aria-hidden="true" />
            <input
              onChange={(event) => setQuery(event.target.value)}
              placeholder="CodeまたはTitle"
              value={query}
            />
          </label>
          <select
            aria-label="Plan状態"
            onChange={(event) => {
              setStatus(event.target.value as typeof status);
              setCursorStack([null]);
              setCursorIndex(0);
            }}
            value={status}
          >
            <option value="all">すべて</option>
            <option value="active">Active</option>
            <option value="paused">Paused</option>
            <option value="completed">Completed</option>
            <option value="disabled">Disabled</option>
          </select>
          <button className="secondary-button" type="submit">検索</button>
        </form>
        <button className="primary-button" onClick={() => setFormMode("create")} type="button">
          <Plus size={17} aria-hidden="true" />
          新規Plan
        </button>
      </div>
      {loading ? (
        <QaLoading />
      ) : plans.length === 0 ? (
        <p className="qa-empty">条件に一致するQA Planはありません。</p>
      ) : (
        <div className="qa-table-scroll">
          <table className="qa-table">
            <thead>
              <tr>
                <th>Code / Title</th>
                <th>Status</th>
                <th>Test User</th>
                <th>期間</th>
                <th><span className="sr-only">詳細</span></th>
              </tr>
            </thead>
            <tbody>
              {plans.map((plan) => (
                <tr key={plan.id}>
                  <td><strong>{plan.title}</strong><code>{plan.code}</code></td>
                  <td><span className={`qa-status is-${plan.status}`}>{plan.status}</span></td>
                  <td><code>{plan.user_id}</code></td>
                  <td>{formatJst(plan.starts_at)} - {formatJst(plan.ends_at)}</td>
                  <td>
                    <button
                      aria-label={`${plan.title}の詳細`}
                      className="icon-button"
                      onClick={() => openPlan(plan.id)}
                      title="詳細"
                      type="button"
                    >
                      <ChevronRight size={18} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <nav aria-label="QA Planページ" className="cursor-pagination">
        <button
          className="secondary-button"
          disabled={cursorIndex === 0}
          onClick={() => setCursorIndex((value) => value - 1)}
          type="button"
        >
          前へ
        </button>
        <button
          className="secondary-button"
          disabled={!nextCursor}
          onClick={() => {
            if (!nextCursor) return;
            setCursorStack((current) => [...current.slice(0, cursorIndex + 1), nextCursor]);
            setCursorIndex((value) => value + 1);
          }}
          type="button"
        >
          次へ
        </button>
      </nav>
    </section>
  );
}

function QaPlanForm({
  busy,
  initial,
  mode,
  onCancel,
  onCreate,
  onUpdate,
}: {
  busy: boolean;
  initial: AdminQaPlanDetail | null;
  mode: "create" | "edit";
  onCancel: () => void;
  onCreate: (input: AdminQaPlanCreate) => Promise<void>;
  onUpdate: (input: AdminQaPlanUpdate) => Promise<void>;
}) {
  const [draft, setDraft] = useState<AdminQaPlanCreate>(() =>
    initial
      ? {
          ends_at: initial.ends_at,
          gacha_id: initial.gacha_id,
          items: initial.items.map((item) => ({
            fixed_image_asset_id: item.fixed_image_asset_id,
            fixed_video_asset_id: item.fixed_video_asset_id,
            prize_id: item.prize_id,
            quantity: item.quantity,
            sort_order: item.sort_order,
          })),
          reason: initial.reason,
          starts_at: initial.starts_at,
          title: initial.title,
          user_id: initial.user_id,
        }
      : structuredClone(EMPTY_CREATE),
  );
  const [initialFingerprint] = useState(() => JSON.stringify(draft));
  const dirty = JSON.stringify(draft) !== initialFingerprint;

  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (mode === "create") {
      await onCreate(draft);
    } else if (initial) {
      await onUpdate({
        ends_at: draft.ends_at,
        reason: draft.reason,
        revision: initial.revision,
        starts_at: draft.starts_at,
        title: draft.title,
      });
    }
  }

  return (
    <form className="qa-form" onSubmit={submit}>
      <header className="qa-section-heading">
        <div>
          <span className="eyebrow">QA Plan</span>
          <h2>{mode === "create" ? "新規作成" : "Plan編集"}</h2>
        </div>
      </header>
      {mode === "create" ? (
        <div className="qa-form-grid">
          <QaInput
            label="Test User Public ID"
            onChange={(value) => setDraft({ ...draft, user_id: value })}
            required
            value={draft.user_id}
          />
          <QaInput
            label="Gacha Public ID"
            onChange={(value) => setDraft({ ...draft, gacha_id: value })}
            required
            value={draft.gacha_id}
          />
        </div>
      ) : null}
      <QaInput
        label="Title"
        maxLength={191}
        onChange={(value) => setDraft({ ...draft, title: value })}
        required
        value={draft.title}
      />
      <label>
        <span>Reason</span>
        <textarea
          maxLength={500}
          onChange={(event) => setDraft({ ...draft, reason: event.target.value })}
          required
          rows={4}
          value={draft.reason}
        />
      </label>
      <div className="qa-form-grid">
        <QaDateInput
          label="開始日時（Asia/Tokyo）"
          onChange={(value) => setDraft({ ...draft, starts_at: value })}
          value={draft.starts_at}
        />
        <QaDateInput
          label="終了日時（Asia/Tokyo）"
          onChange={(value) => setDraft({ ...draft, ends_at: value })}
          value={draft.ends_at}
        />
      </div>
      {mode === "create" ? (
        <fieldset className="qa-items-fieldset">
          <legend>指定景品</legend>
          {draft.items.map((item, index) => (
            <div className="qa-item-row" key={item.sort_order}>
              <QaInput
                label={`Prize Public ID ${index + 1}`}
                onChange={(value) => {
                  const items = [...draft.items];
                  items[index] = { ...item, prize_id: value };
                  setDraft({ ...draft, items });
                }}
                required
                value={item.prize_id}
              />
              <label>
                <span>Quantity</span>
                <input
                  min={1}
                  onChange={(event) => {
                    const items = [...draft.items];
                    items[index] = { ...item, quantity: Number(event.target.value) };
                    setDraft({ ...draft, items });
                  }}
                  required
                  type="number"
                  value={item.quantity}
                />
              </label>
              {draft.items.length > 1 ? (
                <button
                  aria-label={`景品${index + 1}を削除`}
                  className="icon-button"
                  onClick={() =>
                    setDraft({
                      ...draft,
                      items: draft.items
                        .filter((_, itemIndex) => itemIndex !== index)
                        .map((entry, itemIndex) => ({ ...entry, sort_order: itemIndex + 1 })),
                    })
                  }
                  title="景品を削除"
                  type="button"
                >
                  <X size={17} />
                </button>
              ) : null}
            </div>
          ))}
          <button
            className="secondary-button"
            onClick={() =>
              setDraft({
                ...draft,
                items: [
                  ...draft.items,
                  {
                    fixed_image_asset_id: null,
                    fixed_video_asset_id: null,
                    prize_id: "",
                    quantity: 1,
                    sort_order: draft.items.length + 1,
                  },
                ],
              })
            }
            type="button"
          >
            <Plus size={16} aria-hidden="true" />
            景品を追加
          </button>
        </fieldset>
      ) : (
        <p className="qa-form-note">実行景品の変更は既存QA Draw Contractでは許可されません。</p>
      )}
      <div className="qa-actions">
        <button className="secondary-button" disabled={busy} onClick={onCancel} type="button">
          キャンセル
        </button>
        <button className="primary-button" disabled={busy || !dirty} type="submit">
          {busy ? <LoaderCircle className="spin" size={16} aria-hidden="true" /> : null}
          保存
        </button>
      </div>
    </form>
  );
}

function QaTestUsersPanel({
  busy,
  client,
  onError,
  runMutation,
}: {
  busy: boolean;
  client: AdminApiClient;
  onError: (cause: unknown) => AdminApiError;
  runMutation: (operation: Mutation) => Promise<void>;
}) {
  const [users, setUsers] = useState<AdminQaTestUser[]>([]);
  const [candidate, setCandidate] = useState<AdminQaTestUser | null>(null);
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState<AdminQaTestUser | null>(null);
  const [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    client
      .listQaTestUsers({ limit: 50 }, controller.signal)
      .then((result) => setUsers(result.items))
      .catch((cause) => {
        if (!controller.signal.aborted) onError(cause);
      })
      .finally(() => setLoading(false));
    return () => controller.abort();
  }, [client, onError, reload]);

  async function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      const result = await client.searchQaTestUserCandidates({ limit: 1, q: query });
      setCandidate(result.items[0] ?? null);
    } catch (cause) {
      onError(cause);
    }
  }

  async function save(
    user: AdminQaTestUser,
    input: { reason: string },
  ) {
    await runMutation(async () => {
      const result = await client.saveQaTestUser(
        user.user_id,
        { ...input, revision: user.revision ?? undefined },
        crypto.randomUUID(),
      );
      setSelected(result.data);
      setCandidate(result.data);
      setReload((value) => value + 1);
    });
  }

  async function disable(user: AdminQaTestUser) {
    if (user.revision === null || !window.confirm("QA Test User Modeを無効化しますか。")) {
      return;
    }
    await runMutation(async () => {
      const result = await client.disableQaTestUser(
        user.user_id,
        user.revision!,
        crypto.randomUUID(),
      );
      setSelected(result.data);
      setReload((value) => value + 1);
    });
  }

  return (
    <section className="qa-users-layout">
      <div>
        <form className="qa-search" onSubmit={search}>
          <label>
            <span className="sr-only">User Public ID</span>
            <Search size={17} aria-hidden="true" />
            <input
              onChange={(event) => setQuery(event.target.value)}
              placeholder="User Public IDで候補検索"
              required
              value={query}
            />
          </label>
          <button className="secondary-button" type="submit">検索</button>
        </form>
        {candidate ? (
          <button
            className="qa-candidate"
            onClick={() => setSelected(candidate)}
            type="button"
          >
            <Users size={18} aria-hidden="true" />
            <span><code>{candidate.user_id}</code><small>{candidate.user_state}</small></span>
            <ChevronRight size={17} aria-hidden="true" />
          </button>
        ) : query ? <p className="qa-empty">候補は見つかりません。</p> : null}
        <h2 className="qa-subheading">登録済みTest User</h2>
        {loading ? <QaLoading /> : users.length === 0 ? (
          <p className="qa-empty">登録済みTest Userはありません。</p>
        ) : (
          <ul className="qa-user-list">
            {users.map((user) => (
              <li key={user.user_id}>
                <button onClick={() => setSelected(user)} type="button">
                  <span><code>{user.user_id}</code><small>{user.is_active ? "Active" : "Inactive"}</small></span>
                  <ChevronRight size={17} aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
      <div>
        {selected ? (
          <QaTestUserForm
            busy={busy}
            key={`${selected.user_id}:${selected.mode_id ?? "new"}:${selected.revision ?? 0}`}
            onDisable={() => disable(selected)}
            onSave={(input) => save(selected, input)}
            user={selected}
          />
        ) : (
          <p className="qa-empty">Test Userを選択してください。</p>
        )}
      </div>
    </section>
  );
}

function QaTestUserForm({
  busy,
  onDisable,
  onSave,
  user,
}: {
  busy: boolean;
  onDisable: () => void;
  onSave: (input: { reason: string }) => Promise<void>;
  user: AdminQaTestUser;
}) {
  const [reason, setReason] = useState(user.reason ?? "");

  return (
    <form
      className="qa-form qa-user-form"
      onSubmit={async (event) => {
        event.preventDefault();
        await onSave({ reason });
      }}
    >
      <header>
        <span className="eyebrow">QA Test User</span>
        <h2>{user.mode_id ? "Mode更新" : "Mode有効化"}</h2>
        <code>{user.user_id}</code>
      </header>
      <QaInput
        label="Reason"
        maxLength={500}
        onChange={setReason}
        required
        value={reason}
      />
      <p className="qa-form-note">有効化すると、管理者が無効化するまで継続します。</p>
      <div className="qa-actions">
        {user.mode_id && user.is_enabled ? (
          <button className="danger-button" disabled={busy} onClick={onDisable} type="button">
            無効化
          </button>
        ) : null}
        <button className="primary-button" disabled={busy || !reason} type="submit">
          {user.mode_id ? "更新" : "有効化"}
        </button>
      </div>
    </form>
  );
}

function CandidateAssignment({
  client,
  disabled,
  onAssign,
  onError,
}: {
  client: AdminApiClient;
  disabled: boolean;
  onAssign: (userId: string) => Promise<void>;
  onError: (cause: unknown) => AdminApiError;
}) {
  const [query, setQuery] = useState("");
  const [candidate, setCandidate] = useState<AdminQaTestUser | null>(null);
  return (
    <div className="qa-assignment-search">
      <form
        onSubmit={async (event) => {
          event.preventDefault();
          try {
            const result = await client.searchQaTestUserCandidates({ limit: 1, q: query });
            setCandidate(result.items[0] ?? null);
          } catch (cause) {
            onError(cause);
          }
        }}
      >
        <input
          aria-label="割当候補User Public ID"
          onChange={(event) => setQuery(event.target.value)}
          placeholder="User Public ID"
          required
          value={query}
        />
        <button aria-label="割当候補を検索" className="icon-button" type="submit">
          <Search size={17} />
        </button>
      </form>
      {candidate ? (
        <button
          className="secondary-button"
          disabled={disabled || !candidate.is_active}
          onClick={() => onAssign(candidate.user_id)}
          type="button"
        >
          <UserPlus size={16} aria-hidden="true" />
          割当
        </button>
      ) : null}
    </div>
  );
}

function QaInput({
  label,
  maxLength,
  onChange,
  required = false,
  value,
}: {
  label: string;
  maxLength?: number;
  onChange: (value: string) => void;
  required?: boolean;
  value: string;
}) {
  return (
    <label>
      <span>{label}</span>
      <input
        maxLength={maxLength}
        onChange={(event) => onChange(event.target.value)}
        required={required}
        value={value}
      />
    </label>
  );
}

function QaDateInput({
  label,
  onChange,
  required = false,
  value,
}: {
  label: string;
  onChange: (value: string | null) => void;
  required?: boolean;
  value: string | null;
}) {
  return (
    <label>
      <span>{label}</span>
      <input
        onChange={(event) => onChange(event.target.value ? jstInputToUtc(event.target.value) : null)}
        required={required}
        type="datetime-local"
        value={utcToJstInput(value)}
      />
    </label>
  );
}

function Definition({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>;
}

function QaLoading() {
  return (
    <div className="qa-loading" role="status">
      <LoaderCircle className="spin" size={20} aria-hidden="true" />
      読み込んでいます
    </div>
  );
}

function QaError({
  error,
  onDismiss,
}: {
  error: AdminApiError;
  onDismiss: () => void;
}) {
  return (
    <section className="qa-error" role="alert">
      <div>
        <strong>操作を完了できませんでした</strong>
        <p>
          {error.status === 409
            ? "最新状態と競合しています。再取得してから操作してください。"
            : error.status === 429
              ? `操作回数の上限です。${error.retryAfter ?? 0}秒後に再試行してください。`
              : "入力または現在の状態を確認してください。"}
        </p>
      </div>
      <button aria-label="エラーを閉じる" className="icon-button" onClick={onDismiss} type="button">
        <X size={17} />
      </button>
    </section>
  );
}

function asApiError(cause: unknown): AdminApiError {
  return cause instanceof AdminApiError
    ? cause
    : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}

function formatJst(value: string | null): string {
  if (!value) return "未設定";
  return new Intl.DateTimeFormat("ja-JP", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Tokyo",
  }).format(new Date(value));
}

function utcToJstInput(value: string | null): string {
  if (!value) return "";
  const parts = new Intl.DateTimeFormat("en-CA", {
    day: "2-digit",
    hour: "2-digit",
    hour12: false,
    minute: "2-digit",
    month: "2-digit",
    timeZone: "Asia/Tokyo",
    year: "numeric",
  }).formatToParts(new Date(value));
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((candidate) => candidate.type === type)?.value ?? "";
  return `${part("year")}-${part("month")}-${part("day")}T${part("hour")}:${part("minute")}`;
}

function jstInputToUtc(value: string): string {
  return new Date(`${value}:00+09:00`).toISOString();
}
