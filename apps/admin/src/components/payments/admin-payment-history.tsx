"use client";

import {
  ChevronLeft,
  ChevronRight,
  LoaderCircle,
  RefreshCw,
} from "lucide-react";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";

import { AdminPageHeader } from "@/components/shell/admin-page-header";
import {
  AdminApiClient,
  AdminApiError,
  type AdminPaymentMethodFilter,
  type AdminPaymentQuery,
  type AdminPaymentStatusFilter,
} from "@/lib/admin-api/client";
import type {
  AdminPayment,
  AdminPaymentMethod,
  AdminPaymentStatus,
} from "@/lib/admin-api/generated";

const statusOptions: Array<{ label: string; value: AdminPaymentStatusFilter }> = [
  { label: "すべて", value: "all" },
  { label: "作成済み", value: "created" },
  { label: "支払操作待ち", value: "requires_action" },
  { label: "未払い", value: "processing" },
  { label: "決済成功", value: "succeeded" },
  { label: "失敗", value: "failed" },
  { label: "キャンセル", value: "canceled" },
  { label: "期限切れ", value: "expired" },
];

const methodOptions: Array<{ label: string; value: AdminPaymentMethodFilter }> = [
  { label: "すべて", value: "all" },
  { label: "クレジットカード", value: "credit_card" },
  { label: "PayPay", value: "paypay" },
  { label: "コンビニ決済", value: "konbini" },
  { label: "銀行振込", value: "virtual_account" },
];

const currency = new Intl.NumberFormat("ja-JP", {
  currency: "JPY",
  currencyDisplay: "symbol",
  style: "currency",
});

const tokyoDateTime = new Intl.DateTimeFormat("ja-JP", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Tokyo",
});

export function AdminPaymentHistory({
  initialMethod = "all",
  initialStatus = "all",
  userPublicId,
}: {
  initialMethod?: AdminPaymentMethodFilter;
  initialStatus?: AdminPaymentStatusFilter;
  userPublicId?: string;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [status, setStatus] = useState<AdminPaymentStatusFilter>(initialStatus);
  const [method, setMethod] = useState<AdminPaymentMethodFilter>(initialMethod);
  const [items, setItems] = useState<AdminPayment[]>([]);
  const [cursor, setCursor] = useState<string | undefined>();
  const [cursorHistory, setCursorHistory] = useState<Array<string | undefined>>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    const query: AdminPaymentQuery = {
      cursor,
      limit: 20,
      payment_method: method === "all" ? undefined : method,
      status: status === "all" ? undefined : status,
    };
    const request = userPublicId
      ? client.listAdminUserPayments(userPublicId, query, controller.signal)
      : client.listAdminPayments(query, controller.signal);
    void request
      .then((response) => {
        if (controller.signal.aborted) return;
        setItems(response.data);
        setNextCursor(response.pagination.next_cursor);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(paymentHistoryError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, cursor, method, reload, status, userPublicId]);

  function beginLoad() {
    setLoading(true);
    setError(null);
  }

  function resetCursor() {
    setCursor(undefined);
    setCursorHistory([]);
  }

  const content = (
    <>
      {!userPublicId ? (
        <div className="admin-payment-filters" aria-label="決済履歴フィルター">
          <label>
            <span>決済状態</span>
            <select
              aria-label="決済状態"
              value={status}
              onChange={(event) => {
                beginLoad();
                resetCursor();
                setStatus(event.target.value as AdminPaymentStatusFilter);
              }}
            >
              {statusOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <label>
            <span>支払方法</span>
            <select
              aria-label="支払方法"
              value={method}
              onChange={(event) => {
                beginLoad();
                resetCursor();
                setMethod(event.target.value as AdminPaymentMethodFilter);
              }}
            >
              {methodOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <button
            className="text-button"
            onClick={() => {
              beginLoad();
              resetCursor();
              setStatus("all");
              setMethod("all");
              setReload((value) => value + 1);
            }}
            type="button"
          >
            条件を解除
          </button>
        </div>
      ) : null}

      {error ? (
        <section className="module-state is-error" role="alert">
          <h2>決済履歴を取得できませんでした</h2>
          <p>{error}</p>
          <button
            className="secondary-button"
            onClick={() => {
              beginLoad();
              setReload((value) => value + 1);
            }}
            type="button"
          >
            <RefreshCw aria-hidden="true" size={16} />再取得
          </button>
        </section>
      ) : loading ? (
        <section aria-live="polite" className="module-state" role="status">
          <LoaderCircle aria-hidden="true" className="spin" size={22} />
          <p>決済履歴を読み込んでいます。</p>
        </section>
      ) : items.length === 0 ? (
        <section className="module-state">
          <h2>{status === "all" && method === "all"
            ? "決済履歴はありません"
            : "検索条件に一致する決済はありません"}</h2>
          <p>Canonical Payment履歴を変更せず表示しています。</p>
        </section>
      ) : (
        <PaymentCollection
          canGoBack={cursorHistory.length > 0}
          canGoNext={nextCursor !== null}
          includeUser={!userPublicId}
          items={items}
          onBack={() => {
            beginLoad();
            const history = [...cursorHistory];
            setCursor(history.pop());
            setCursorHistory(history);
          }}
          onNext={() => {
            beginLoad();
            setCursorHistory((current) => [...current, cursor]);
            setCursor(nextCursor ?? undefined);
          }}
        />
      )}
    </>
  );

  if (userPublicId) {
    return (
      <section
        aria-labelledby="user-payment-history-heading"
        className="admin-user-summary admin-payment-embedded"
      >
        <div className="admin-user-section-heading">
          <div>
            <h2 id="user-payment-history-heading">決済履歴</h2>
            <p>このユーザーの全Canonical Payment状態を新しい順に表示します。</p>
          </div>
        </div>
        {content}
      </section>
    );
  }

  return (
    <div className="workspace admin-payment-workspace">
      <AdminPageHeader
        description="全ユーザーのCanonical Payment状態を確認します。"
        eyebrow="Payments"
        title="決済履歴"
      />
      {content}
    </div>
  );
}

function PaymentCollection({
  canGoBack,
  canGoNext,
  includeUser,
  items,
  onBack,
  onNext,
}: {
  canGoBack: boolean;
  canGoNext: boolean;
  includeUser: boolean;
  items: AdminPayment[];
  onBack: () => void;
  onNext: () => void;
}) {
  return (
    <section aria-label="決済履歴一覧" className="admin-payment-list-section">
      <div className="admin-payment-table-region" tabIndex={0}>
        <table className="admin-payment-table">
          <thead>
            <tr>
              <th scope="col">Payment</th>
              {includeUser ? <th scope="col">User</th> : null}
              <th scope="col">支払方法</th>
              <th scope="col">金額</th>
              <th scope="col">状態</th>
              <th scope="col">作成日時</th>
              <th scope="col">決済関連日時</th>
            </tr>
          </thead>
          <tbody>
            {items.map((payment) => (
              <tr key={payment.id}>
                <td><PublicId value={payment.id} /></td>
                {includeUser ? (
                  <td>
                    <strong>{payment.user.display_name ?? "未設定"}</strong>
                    <Link href={`/users/${payment.user.id}`}>
                      <PublicId value={payment.user.id} />
                    </Link>
                  </td>
                ) : null}
                <td>{paymentMethodLabel(payment.method)}</td>
                <td>{formatAmount(payment)}</td>
                <td><PaymentStatusBadge status={payment.status} /></td>
                <td>{formatJst(payment.created_at)}</td>
                <td>{formatJst(payment.succeeded_at ?? payment.expires_at ?? payment.updated_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <nav aria-label="決済履歴ページ" className="admin-payment-pagination">
        <button
          className="secondary-button"
          disabled={!canGoBack}
          onClick={onBack}
          type="button"
        >
          <ChevronLeft aria-hidden="true" size={16} />前へ
        </button>
        <button
          className="secondary-button"
          disabled={!canGoNext}
          onClick={onNext}
          type="button"
        >
          次へ<ChevronRight aria-hidden="true" size={16} />
        </button>
      </nav>
    </section>
  );
}

export function PaymentStatusBadge({ status }: { status: AdminPaymentStatus }) {
  return (
    <span className={`status-pill admin-payment-status is-${status}`}>
      {paymentStatusLabel(status)}
    </span>
  );
}

export function paymentStatusLabel(status: AdminPaymentStatus): string {
  return statusOptions.find((option) => option.value === status)?.label ?? status;
}

export function paymentMethodLabel(method: AdminPaymentMethod): string {
  return methodOptions.find((option) => option.value === method)?.label ?? method;
}

function formatAmount(payment: AdminPayment): string {
  return payment.amount.currency === "JPY"
    ? currency.format(payment.amount.amount)
    : String(payment.amount.amount);
}

function formatJst(value: string): string {
  return tokyoDateTime.format(new Date(value));
}

function PublicId({ value }: { value: string }) {
  return <code title={value}>{`${value.slice(0, 8)}…`}</code>;
}

function paymentHistoryError(reason: unknown): string {
  if (reason instanceof AdminApiError) {
    if (reason.status === 404) return "指定されたユーザーは存在しません。";
    if (reason.status === 403) return "決済履歴を表示する権限がありません。";
    if (reason.status === 422) return "決済履歴の検索条件が正しくありません。";
    return `${reason.message}${reason.requestId ? ` (Request ID: ${reason.requestId})` : ""}`;
  }
  return "通信に失敗しました。時間をおいて再試行してください。";
}
