"use client";

import { AlertCircle, LoaderCircle, X } from "lucide-react";

import { useAdminAuth } from "./admin-auth-provider";

export function AuthError() {
  const { clearError, error } = useAdminAuth();
  if (!error) return null;
  return (
    <div className="notice notice-error" role="alert">
      <AlertCircle size={18} aria-hidden="true" />
      <div>
        <strong>{error.message}</strong>
        {error.retryAfter ? <p>{error.retryAfter}秒後に再試行できます。</p> : null}
        {error.requestId ? <p>Request ID: {error.requestId}</p> : null}
      </div>
      <button className="icon-button" type="button" onClick={clearError} aria-label="エラーを閉じる">
        <X size={17} aria-hidden="true" />
      </button>
    </div>
  );
}

export function AuthLoading({ label = "確認中" }: { label?: string }) {
  return (
    <div className="loading-state" role="status" aria-live="polite">
      <LoaderCircle className="spin" size={20} aria-hidden="true" />
      <span>{label}</span>
    </div>
  );
}
