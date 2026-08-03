"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminUserCollection,
  AdminUserDetail,
  AdminUserGachaHistoryCollection,
} from "@/lib/admin-api/generated";

export type AdminUserReadMode = "list" | "detail" | "history";
export type AdminUserReadData =
  | { kind: "list"; value: AdminUserCollection }
  | { kind: "detail"; value: AdminUserDetail }
  | { kind: "history"; value: AdminUserGachaHistoryCollection };

export function useAdminUserReadModel({
  mode,
  userPublicId,
}: {
  mode: AdminUserReadMode;
  userPublicId?: string;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [revision, setRevision] = useState(0);
  const [data, setData] = useState<AdminUserReadData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    void load(client, mode, userPublicId, controller.signal)
      .then((next) => {
        if (!controller.signal.aborted) setData(next);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) {
          setData(null);
          setError(message(reason));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, mode, revision, userPublicId]);

  const retry = useCallback(() => {
    setData(null);
    setError(null);
    setLoading(true);
    setRevision((value) => value + 1);
  }, []);
  const loadMore = useCallback(async () => {
    if (loadingMore || !data) return;
    const cursor = data.kind === "detail" ? null : data.value.next_cursor;
    if (!cursor) return;
    setLoadingMore(true);
    setError(null);
    try {
      if (data.kind === "list") {
        const next = await client.listAdminUsers(cursor);
        setData({
          kind: "list",
          value: { ...next, items: [...data.value.items, ...next.items] },
        });
      } else if (data.kind === "history" && userPublicId) {
        const next = await client.listAdminUserGachaHistory(userPublicId, cursor);
        setData({
          kind: "history",
          value: { ...next, items: [...data.value.items, ...next.items] },
        });
      }
    } catch (reason: unknown) {
      setError(message(reason));
    } finally {
      setLoadingMore(false);
    }
  }, [client, data, loadingMore, userPublicId]);

  return { data, error, loadMore, loading, loadingMore, retry };
}

async function load(
  client: AdminApiClient,
  mode: AdminUserReadMode,
  userPublicId: string | undefined,
  signal: AbortSignal,
): Promise<AdminUserReadData> {
  if (mode === "list") {
    return { kind: "list", value: await client.listAdminUsers(undefined, signal) };
  }
  if (!userPublicId) throw new Error("ユーザーIDが必要です。");
  if (mode === "detail") {
    const response = await client.getAdminUser(userPublicId, signal);
    return { kind: "detail", value: response.data };
  }
  return {
    kind: "history",
    value: await client.listAdminUserGachaHistory(userPublicId, undefined, signal),
  };
}

function message(reason: unknown): string {
  if (reason instanceof AdminApiError && reason.status === 404) {
    return "指定されたユーザーは存在しません。";
  }
  return reason instanceof Error
    ? reason.message
    : "ユーザー情報を取得できませんでした。";
}
