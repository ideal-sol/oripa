import { operations, type AgencyContact, type AgencyEmailChange, type AgencyLogin, type AgencyPasswordChange, type AgencyProfileResponse, type AgencySession } from "./generated";
import type { AgencyUserAggregate, AgencySalesAggregate } from "./generated";

export class AgencyApiError extends Error {
  constructor(public status: number, public code: string) {
    super(status === 401 ? "ログイン情報を確認して、もう一度ログインしてください。" : status === 409 ? "このメールアドレスは使用できません。" : status === 429 ? "しばらく待ってからお試しください。" : status === 403 ? "現在のパスワード、またはログイン状態を確認してください。" : "入力内容を確認してください。接続できない場合は再度お試しください。");
  }
}

async function request<Result>(operation: keyof typeof operations, input?: unknown, query?: Record<string, string>, signal?: AbortSignal): Promise<Result> {
  const { path, method } = operations[operation];
  const csrf = document.cookie.split("; ").find(value => value.startsWith("__Host-oripa_agency_xsrf="))?.split("=")[1];
  const response = await fetch(query ? `${path}?${new URLSearchParams(query)}` : path, {
    signal,
    method, credentials: "same-origin", cache: "no-store",
    headers: { Accept: "application/json", ...(method === "GET" ? {} : { "Content-Type": "application/json", "X-XSRF-TOKEN": csrf ?? "" }) },
    ...(method === "GET" ? {} : { body: JSON.stringify(input ?? {}) }),
  });
  if (!response.ok) {
    const problem = await response.json().catch(() => ({}));
    throw new AgencyApiError(response.status, problem.code ?? "REQUEST_FAILED");
  }
  return response.json() as Promise<Result>;
}

export const agencyApi = {
  userAggregate: (query: Record<string, string>, signal?: AbortSignal) => request<AgencyUserAggregate>("getAgencyUserAggregate", undefined, query, signal),
  salesAggregate: (query: Record<string, string>, signal?: AbortSignal) => request<AgencySalesAggregate>("getAgencySalesAggregate", undefined, query, signal),
  session: () => request<AgencySession>("agencySession"),
  login: (input: AgencyLogin) => request<AgencyProfileResponse>("agencyLogin", input),
  profile: () => request<AgencyProfileResponse>("agencyProfile"),
  contact: (input: AgencyContact) => request<AgencyProfileResponse>("agencyContact", input),
  email: (input: AgencyEmailChange) => request<AgencyProfileResponse>("agencyEmailChange", input),
  password: (input: AgencyPasswordChange) => request<AgencyProfileResponse>("agencyPasswordChange", input),
  logout: () => request("agencyLogout"),
};
