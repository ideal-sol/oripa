"use client";

import { LockKeyhole, Minus, Plus, X } from "lucide-react";
import { type FormEvent, type KeyboardEvent, useEffect, useMemo, useRef, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

type PointType = "paid" | "free";
type Direction = "grant" | "deduct";

export function AdminUserPointAdjustmentModal({
  displayName,
  freeBalance,
  onClose,
  onSuccess,
  open,
  paidBalance,
  userPublicId,
}: {
  displayName: string | null;
  freeBalance: number;
  onClose: () => void;
  onSuccess: () => void;
  open: boolean;
  paidBalance: number;
  userPublicId: string;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [pointType, setPointType] = useState<PointType>("paid");
  const [direction, setDirection] = useState<Direction>("grant");
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [freshOpen, setFreshOpen] = useState(false);
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());
  const panelRef = useRef<HTMLElement>(null);
  const amountRef = useRef<HTMLInputElement>(null);
  const previousFocus = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (!open) return;
    previousFocus.current = document.activeElement as HTMLElement | null;
    window.setTimeout(() => amountRef.current?.focus(), 0);
    return () => previousFocus.current?.focus();
  }, [open]);

  const parsedAmount = /^\d+$/.test(amount) ? Number(amount) : Number.NaN;
  const currentBalance = pointType === "paid" ? paidBalance : freeBalance;
  const expectedBalance = Number.isSafeInteger(parsedAmount) && parsedAmount > 0
    ? direction === "grant"
      ? currentBalance + parsedAmount
      : currentBalance - parsedAmount
    : null;
  const validation = validate(parsedAmount, expectedBalance, reason, currentPassword);

  if (!open) return null;
  if (freshOpen) {
    return (
      <FreshMfaDialog
        onClose={() => setFreshOpen(false)}
        onSuccess={() => {
          setFreshOpen(false);
          window.setTimeout(() => amountRef.current?.focus(), 0);
        }}
        open
      />
    );
  }

  function resetRequestIdentity() {
    setIdempotencyKey(crypto.randomUUID());
    setError(null);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (validation || submitting || expectedBalance === null) {
      setError(validation ?? "入力内容を確認してください。");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await client.adjustAdminUserPoints(
        userPublicId,
        {
          point_type: pointType,
          direction,
          amount: parsedAmount,
          reason: reason.trim(),
          current_password: currentPassword,
        },
        idempotencyKey,
      );
      setCurrentPassword("");
      setAmount("");
      setReason("");
      setIdempotencyKey(crypto.randomUUID());
      onSuccess();
      onClose();
    } catch (cause: unknown) {
      setCurrentPassword("");
      if (cause instanceof AdminApiError && cause.requiresFreshMfa) {
        setFreshOpen(true);
        setError("本人確認の有効期限が切れました。再認証後にもう一度実行してください。");
      } else {
        setError(errorMessage(cause));
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <div
        className="dialog-backdrop"
        onKeyDown={(event) => {
          if (event.key === "Escape" && !submitting) onClose();
          if (event.key === "Tab") trapFocus(event, panelRef.current);
        }}
        role="presentation"
      >
        <section
          aria-describedby="point-adjustment-impact"
          aria-labelledby="point-adjustment-title"
          aria-modal="true"
          className="dialog-panel point-adjustment-dialog"
          ref={panelRef}
          role="dialog"
        >
          <header className="dialog-header">
            <div>
              <span className="eyebrow">Financial operation</span>
              <h2 id="point-adjustment-title">ポイント調整</h2>
            </div>
            <button
              aria-label="ポイント調整を閉じる"
              className="icon-button"
              disabled={submitting}
              onClick={onClose}
              type="button"
            >
              <X aria-hidden="true" size={19} />
            </button>
          </header>
          <p id="point-adjustment-impact" className="point-adjustment-impact">
            この操作は実際のWalletとPoint Ledgerへ即時反映されます。
          </p>
          <dl className="point-adjustment-target">
            <div><dt>対象ユーザー</dt><dd>{displayName ?? "未設定"}</dd></div>
            <div><dt>有償P</dt><dd>{formatPoints(paidBalance)}</dd></div>
            <div><dt>無償P</dt><dd>{formatPoints(freeBalance)}</dd></div>
          </dl>
          <form className="point-adjustment-form" onSubmit={submit}>
            <fieldset>
              <legend>ポイント種別</legend>
              <div className="segmented-control" aria-label="ポイント種別">
                {(["paid", "free"] as const).map((value) => (
                  <button
                    aria-pressed={pointType === value}
                    key={value}
                    onClick={() => {
                      setPointType(value);
                      resetRequestIdentity();
                    }}
                    type="button"
                  >
                    {value === "paid" ? "有償P" : "無償P"}
                  </button>
                ))}
              </div>
            </fieldset>
            <fieldset>
              <legend>調整方法</legend>
              <div className="segmented-control" aria-label="調整方法">
                {(["grant", "deduct"] as const).map((value) => (
                  <button
                    aria-pressed={direction === value}
                    key={value}
                    onClick={() => {
                      setDirection(value);
                      resetRequestIdentity();
                    }}
                    type="button"
                  >
                    {value === "grant" ? <Plus aria-hidden="true" size={16} /> : <Minus aria-hidden="true" size={16} />}
                    {value === "grant" ? "加算" : "減算"}
                  </button>
                ))}
              </div>
            </fieldset>
            <label>
              <span>調整ポイント数</span>
              <input
                inputMode="numeric"
                min="1"
                onChange={(event) => {
                  setAmount(event.target.value);
                  resetRequestIdentity();
                }}
                pattern="[0-9]+"
                ref={amountRef}
                required
                type="text"
                value={amount}
              />
            </label>
            <div className="point-adjustment-balance-preview" aria-live="polite">
              <span>実行前残高</span><strong>{formatPoints(currentBalance)}</strong>
              <span>実行後予定残高</span>
              <strong className={expectedBalance !== null && expectedBalance < 0 ? "is-negative" : undefined}>
                {expectedBalance === null ? "入力待ち" : formatPoints(expectedBalance)}
              </strong>
            </div>
            <label>
              <span>調整理由</span>
              <textarea
                maxLength={500}
                onChange={(event) => {
                  setReason(event.target.value);
                  resetRequestIdentity();
                }}
                required
                rows={3}
                value={reason}
              />
            </label>
            <label>
              <span>現在の管理者パスワード</span>
              <span className="input-shell">
                <LockKeyhole aria-hidden="true" size={18} />
                <input
                  autoComplete="current-password"
                  maxLength={128}
                  onChange={(event) => setCurrentPassword(event.target.value)}
                  required
                  type="password"
                  value={currentPassword}
                />
              </span>
            </label>
            {error ? <p className="point-adjustment-error" role="alert">{error}</p> : null}
            <div className="dialog-actions">
              <button className="secondary-button" disabled={submitting} onClick={onClose} type="button">
                キャンセル
              </button>
              <button className="primary-button" disabled={submitting || Boolean(validation)} type="submit">
                {submitting ? "実行中" : "内容を確認して実行"}
              </button>
            </div>
          </form>
        </section>
      </div>
    </>
  );
}

function validate(
  amount: number,
  expectedBalance: number | null,
  reason: string,
  password: string,
): string | null {
  if (!Number.isSafeInteger(amount) || amount < 1) return "ポイント数は正の整数で入力してください。";
  if (expectedBalance === null || !Number.isSafeInteger(expectedBalance)) {
    return "調整後残高が対応範囲を超えています。";
  }
  if (expectedBalance < 0) return "調整後残高を負数にはできません。";
  if (!reason.trim()) return "調整理由を入力してください。";
  if (/[\u0000-\u001f\u007f<>]/u.test(reason)) return "調整理由に使用できない文字が含まれています。";
  if (!password) return "現在の管理者パスワードを入力してください。";
  return null;
}

function errorMessage(cause: unknown): string {
  if (!(cause instanceof AdminApiError)) return "ポイント調整を実行できませんでした。";
  if (cause.status === 401) return "現在の管理者パスワードを確認してください。";
  if (cause.status === 403) return "ポイント調整を実行する権限がありません。";
  if (cause.status === 409 && cause.code === "POINT_ADJUSTMENT_INSUFFICIENT_BALANCE") {
    return "選択したポイント種別の残高が不足しています。最新残高を確認してください。";
  }
  if (cause.status === 409) return "他の更新と競合しました。最新残高を再取得してください。";
  if (cause.status === 429) return "操作回数の上限に達しました。時間を置いて再試行してください。";
  return "ポイント調整を実行できませんでした。";
}

function formatPoints(value: number): string {
  return `${new Intl.NumberFormat("ja-JP").format(value)} pt`;
}

function trapFocus(event: KeyboardEvent, panel: HTMLElement | null) {
  const focusable = panel?.querySelectorAll<HTMLElement>(
    'button:not(:disabled), input:not(:disabled), textarea:not(:disabled), [href], [tabindex]:not([tabindex="-1"])',
  );
  if (!focusable?.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}
