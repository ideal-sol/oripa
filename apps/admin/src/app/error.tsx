"use client";

import { AlertTriangle, RotateCcw } from "lucide-react";
import { useEffect } from "react";

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // Client logging intentionally excludes the Error object and stack trace.
  }, [error]);

  return (
    <main className="full-page-state" role="alert">
      <AlertTriangle size={28} aria-hidden="true" />
      <h1>画面を表示できませんでした</h1>
      <p>安全のため処理を中断しました。</p>
      <button className="secondary-button" onClick={reset} type="button">
        <RotateCcw size={17} aria-hidden="true" />
        再試行
      </button>
    </main>
  );
}
