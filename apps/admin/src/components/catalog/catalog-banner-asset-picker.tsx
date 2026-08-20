"use client";

import Image from "next/image";
import { useEffect, useRef, useState } from "react";

import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminBannerCategory, AdminManagedBanner } from "@/lib/admin-api/generated";

export interface CatalogBannerAssetSelection {
  assetId: string | null;
  bannerId: string | null;
  changed: boolean;
}

export function CatalogBannerAssetPicker({
  assetId,
  disabled = false,
  onSelectionChange,
}: {
  assetId: string | null;
  disabled?: boolean;
  onSelectionChange: (selection: CatalogBannerAssetSelection) => void;
}) {
  const [categories, setCategories] = useState<AdminBannerCategory[]>([]);
  const [categoryId, setCategoryId] = useState("");
  const [banners, setBanners] = useState<AdminManagedBanner[]>([]);
  const [selectedBannerId, setSelectedBannerId] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [resolution, setResolution] = useState<"idle" | "unresolved">("idle");
  const [error, setError] = useState<string | null>(null);
  const selectionChanged = useRef(false);

  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    client.listBannerCategories(controller.signal)
      .then(async (response) => {
        setCategories(response.items);
        if (assetId === null || selectionChanged.current) return;

        const candidates = (
          await Promise.all(
            response.items.map((category) =>
              listAllBannersForCategory(client, category.id, controller.signal),
            ),
          )
        ).flat();
        const matches = candidates.filter((banner) => banner.asset.id === assetId);
        if (controller.signal.aborted || selectionChanged.current) return;
        if (matches.length === 1) {
          setLoading(true);
          setCategoryId(matches[0].category.id);
          setSelectedBannerId(matches[0].id);
          setBanners(candidates.filter((banner) => banner.category.id === matches[0].category.id));
          return;
        }
        setResolution("unresolved");
      })
      .catch(() => {
        if (!controller.signal.aborted) setError("選択肢を取得できませんでした。");
      });
    return () => controller.abort();
  }, [assetId]);

  useEffect(() => {
    if (!categoryId) return;
    const controller = new AbortController();
    listAllBannersForCategory(new AdminApiClient(), categoryId, controller.signal)
      .then((items) => {
        if (!controller.signal.aborted) setBanners(items);
      })
      .catch(() => {
        if (!controller.signal.aborted) setError("バナー候補を取得できませんでした。");
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [categoryId]);

  function selectCategory(nextCategoryId: string) {
    selectionChanged.current = true;
    setCategoryId(nextCategoryId);
    setBanners([]);
    setSelectedBannerId(null);
    setLoading(Boolean(nextCategoryId));
    setResolution("idle");
    setError(null);
    onSelectionChange({ assetId: null, bannerId: null, changed: true });
  }

  function selectBanner(banner: AdminManagedBanner) {
    selectionChanged.current = true;
    setSelectedBannerId(banner.id);
    onSelectionChange({ assetId: banner.asset.id, bannerId: banner.id, changed: true });
  }

  return (
    <>
      <label>
        Banner Category
        <select
          disabled={disabled}
          onChange={(event) => selectCategory(event.target.value)}
          value={categoryId}
        >
          <option value="">選択してください</option>
          {categories.map((category) => (
            <option key={category.id} value={category.id}>{category.name}</option>
          ))}
        </select>
      </label>
      <fieldset className="catalog-banner-picker">
        <legend>Banner</legend>
        {resolution === "unresolved" ? (
          <p className="catalog-banner-picker-note">
            既存のPresentation Assetに対応するBannerを一意に特定できませんでした。
            変更しなければ既存の値は保持されます。
          </p>
        ) : null}
        {!categoryId ? (
          <p className="catalog-banner-picker-note">先にBanner Categoryを選択してください。</p>
        ) : loading ? (
          <p className="catalog-banner-picker-note">Banner候補を取得しています。</p>
        ) : banners.length === 0 ? (
          <p className="catalog-banner-picker-note">このCategoryに選択可能なBannerはありません。</p>
        ) : (
          <div aria-label="Banner候補" className="catalog-banner-options">
            {banners.map((banner) => (
              <button
                aria-pressed={selectedBannerId === banner.id}
                className="catalog-banner-option"
                disabled={disabled}
                key={banner.id}
                onClick={() => selectBanner(banner)}
                type="button"
              >
                <Image alt="" height={56} src={banner.asset.public_url} unoptimized width={96} />
                <span>{banner.title}</span>
              </button>
            ))}
          </div>
        )}
        {error ? <p className="form-field-error" role="alert">{error}</p> : null}
      </fieldset>
    </>
  );
}

async function listAllBannersForCategory(
  client: AdminApiClient,
  categoryId: string,
  signal: AbortSignal,
): Promise<AdminManagedBanner[]> {
  const banners: AdminManagedBanner[] = [];
  let cursor: string | undefined;
  do {
    const response = await client.listManagedBanners({ category_id: categoryId, cursor }, signal);
    banners.push(...response.items);
    cursor = response.next_cursor ?? undefined;
  } while (cursor);
  return banners;
}
