import type { Metadata } from "next";
import { CardInventoryWorkspace } from "@/components/inventory/card-inventory-workspace";

export const metadata: Metadata = { title: "カード別在庫" };

export default function CardInventoryPage() {
  return <CardInventoryWorkspace />;
}
