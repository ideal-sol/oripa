"use client";

import { createContext, useContext, useEffect, useState, useSyncExternalStore, type ReactNode } from "react";
import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { LineDeliveryMessage } from "@/lib/admin-api/generated";

type Request = { id: string; key: string; messages: LineDeliveryMessage[] };
type Entry = { phase: "submitting" | "unknown" | "rejected" | "registered"; request: Request; error?: string } | { phase: "complete" };
const empty = new Map<string, Entry>();
function freeze<T>(value: T): T {
  if (value && typeof value === "object") { Object.values(value).forEach(freeze); Object.freeze(value); }
  return value;
}
function createMemory() {
  let entries = empty;
  const listeners = new Set<() => void>(); const inFlight = new Set<string>();
  const put = (id: string, entry: Entry) => { entries = new Map(entries).set(id, entry); listeners.forEach((f) => f()); };
  return {
    subscribe: (f: () => void) => { listeners.add(f); return () => { listeners.delete(f); }; },
    snapshot: () => entries,
    async submit(client: AdminApiClient, id: string, messages: LineDeliveryMessage[]) {
      const previous = entries.get(id);
      if (inFlight.has(id) || previous?.phase === "registered") return false;
      const retained = previous && previous.phase !== "complete" && previous.phase !== "rejected";
      const request = retained ? previous.request : freeze({ id, key: crypto.randomUUID(), messages: structuredClone(messages) });
      inFlight.add(id); put(id, { phase: "submitting", request });
      try {
        await client.replyLineChat(id, { request_key: request.key, messages: structuredClone(request.messages) });
        put(id, { phase: "registered", request }); return true;
      } catch (error) {
        // Only a first, definitive validation/auth rejection may be edited anew.
        // An uncertain earlier attempt is retained even if a later retry rejects.
        const rejected = !retained && error instanceof AdminApiError && !error.retryable && [401, 403, 404, 422].includes(error.status);
        put(id, { phase: rejected ? "rejected" : "unknown", request, error: error instanceof AdminApiError ? error.message : "通信結果を確認できませんでした。" });
        return false;
      } finally { inFlight.delete(id); }
    },
    historyToken(id: string) { const e = entries.get(id); return e?.phase === "registered" ? e.request.key : null; },
    historyConfirmed(id: string, key: string | null) { const e = entries.get(id); if (key && e?.phase === "registered" && e.request.key === key) { put(id, { phase: "complete" }); return true; } return false; },
  };
}
const Context = createContext<ReturnType<typeof createMemory> | null>(null);

function Memory({ children }: { children: ReactNode }) {
  const [store] = useState(createMemory);
  const entries = useSyncExternalStore(store.subscribe, store.snapshot, () => empty);
  const outstanding = [...entries.values()].some((e) => e.phase !== "complete" && e.phase !== "rejected");
  useEffect(() => {
    if (!outstanding) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [outstanding]);
  return <Context.Provider value={store}>{outstanding && <p className="notice notice-warning" role="status">LINEチャットに結果未確認または履歴更新待ちの返信があります。再読み込み・終了すると送信要求は復元できません。元の会話で結果・履歴を確認してください。</p>}{children}</Context.Provider>;
}
export function LineChatReplyProvider({ children }: { children: ReactNode }) {
  const { admin, phase } = useAdminAuth();
  return <Memory key={phase === "authenticated" ? `admin:${admin?.id}` : phase}>{children}</Memory>;
}
export function useLineChatReplies() {
  const store = useContext(Context);
  if (!store) throw new Error("LineChatReplyProvider is required.");
  const entries = useSyncExternalStore(store.subscribe, store.snapshot, () => empty);
  return { entries, submit: store.submit, historyToken: store.historyToken, historyConfirmed: store.historyConfirmed };
}
