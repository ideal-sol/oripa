import type { Metadata } from "next";
import { MemberRankSummary } from "@/components/member-ranks/member-rank-summary";

export const metadata: Metadata = { title: "会員ランク" };

export default function MemberRanksPage() { return <MemberRankSummary />; }
