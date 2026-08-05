import type { Metadata } from "next";
import { redirect } from "next/navigation";

export const metadata: Metadata = { title: "バナー登録" };

export default function BannerCreatePage() {
  redirect("/banners#banner-create");
}
