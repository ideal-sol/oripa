import type { Metadata } from "next";
import { PreRegistrationSettings } from "@/components/settings/pre-registration-settings";

export const metadata: Metadata = { title: "各種設定 事前登録特典設定" };

export default function PreRegistrationPage() {
  return <PreRegistrationSettings />;
}
