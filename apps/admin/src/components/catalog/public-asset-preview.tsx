"use client";

import { FileWarning } from "lucide-react";
import { useState } from "react";

import type { AdminCatalogAssetReference } from "@/lib/admin-api/generated";
import { assetDeliveryUrl } from "@/lib/asset-delivery";
import { useAssetPublicOrigin } from "./asset-delivery-provider";

function safePublicPath(path: string | null): path is string {
  return path !== null && path.startsWith("/") && !path.startsWith("//");
}

export function assetContentPath(id: string): string {
  return `/admin/api/v2/catalog/presentation-assets/${id}/content`;
}

export function PublicAssetPreview({
  asset,
  allowAuthenticatedContent = false,
}: {
  asset: AdminCatalogAssetReference | null;
  allowAuthenticatedContent?: boolean;
}) {
  const [failedAssetId, setFailedAssetId] = useState<string | null>(null);
  const origin = useAssetPublicOrigin();
  const publicPath = asset && safePublicPath(asset.public_path)
    ? asset.public_path
    : null;
  const source = assetDeliveryUrl(publicPath, origin,
    asset && allowAuthenticatedContent ? assetContentPath(asset.id) : publicPath);
  if (!asset || failedAssetId === asset.id || !source) {
    return (
      <div className="asset-fallback" role="img" aria-label="Previewなし">
        <FileWarning size={22} aria-hidden="true" />
        <span>Previewなし</span>
      </div>
    );
  }
  if (asset.media_type === "video") {
    return (
      <video
        aria-label={asset.alt_text ?? "Presentation video"}
        className="asset-preview"
        controls
        onError={() => setFailedAssetId(asset.id)}
        preload="metadata"
        src={source}
      />
    );
  }
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      alt={asset.alt_text ?? ""}
      className="asset-preview"
      onError={() => setFailedAssetId(asset.id)}
      src={source}
    />
  );
}

export { safePublicPath };
