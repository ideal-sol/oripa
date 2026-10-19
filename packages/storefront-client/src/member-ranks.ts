import type { PublicComponents, StorefrontResponse, StorefrontTransport } from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface StorefrontMemberRankClient {
  getMyMemberRank(): Promise<StorefrontResponse<Schemas["MyMemberRankResponse"]>>;
  listMemberRanks(): Promise<StorefrontResponse<Schemas["MemberRankCollection"]>>;
}

export function createStorefrontMemberRankClient(
  transport: StorefrontTransport,
): StorefrontMemberRankClient {
  return {
    getMyMemberRank: () => transport.request({ path: "/me/member-rank" }),
    listMemberRanks: () => transport.request({ path: "/member-ranks" }),
  };
}
