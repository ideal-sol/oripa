"use client";

import { FileWarning } from "lucide-react";
import { useState } from "react";

import type { AdminCatalogAssetReference } from "@/lib/admin-api/generated";

function safePublicPath(path: string | null): path is string {
  return path !== null && path.startsWith("/") && !path.startsWith("//");
}

export function PublicAssetPreview({
  asset,
}: {
  asset: AdminCatalogAssetReference | null;
}) {
  const [failed, setFailed] = useState(false);
  if (
    !asset ||
    !asset.is_public ||
    !safePublicPath(asset.public_path) ||
    failed
  ) {
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
        onError={() => setFailed(true)}
        preload="metadata"
        src={asset.public_path}
      />
    );
  }
  return (
    // Public path is validated as a same-origin relative URL.
    // eslint-disable-next-line @next/next/no-img-element
    <img
      alt={asset.alt_text ?? ""}
      className="asset-preview"
      onError={() => setFailed(true)}
      src={asset.public_path}
    />
  );
}

export { safePublicPath };
