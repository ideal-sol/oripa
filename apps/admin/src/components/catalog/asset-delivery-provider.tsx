"use client";

import { createContext, useContext, type ReactNode } from "react";

const AssetDeliveryContext = createContext<string | null>(null);

export function AssetDeliveryProvider({ origin, children }: { origin: string | null; children: ReactNode }) {
  return <AssetDeliveryContext.Provider value={origin}>{children}</AssetDeliveryContext.Provider>;
}

export function useAssetPublicOrigin() {
  return useContext(AssetDeliveryContext);
}
