import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface PointHistoryQuery {
  limit?: number;
  cursor?: string;
}

export type PointReadProblemCode = Schemas["PointReadProblemCode"];

export interface StorefrontCurrentUserPointClient {
  getWallet(): Promise<StorefrontResponse<Schemas["WalletBalance"]>>;
  listPointLedgerEntries(
    query?: PointHistoryQuery,
  ): Promise<StorefrontResponse<Schemas["PointHistoryCollection"]>>;
}

function queryString(query: PointHistoryQuery): string {
  const parameters = new URLSearchParams();
  if (query.limit !== undefined) {
    if (!Number.isSafeInteger(query.limit) || query.limit < 1 || query.limit > 100) {
      throw new TypeError("limit must be an integer from 1 through 100");
    }
    parameters.set("limit", String(query.limit));
  }
  if (query.cursor !== undefined) {
    parameters.set("cursor", query.cursor);
  }
  const encoded = parameters.toString();
  return encoded === "" ? "" : `?${encoded}`;
}

export function createStorefrontCurrentUserPointClient(
  transport: StorefrontTransport,
): StorefrontCurrentUserPointClient {
  return {
    getWallet: () => transport.request({ path: "/me/wallet" }),
    listPointLedgerEntries: (query = {}) =>
      transport.request({ path: `/me/point-ledgers${queryString(query)}` }),
  };
}
