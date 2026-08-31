"use client";

import { LoaderCircle, X } from "lucide-react";
import Image from "next/image";
import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";

import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminBannerCategory,
  AdminCatalogPresentationAsset,
  AdminManagedBanner,
} from "@/lib/admin-api/generated";

export interface CatalogAssetDraft {
  storageIdentifier: string;
  publicPath: string;
  checksumSha256: string;
  mediaType: "image" | "video";
  mimeType: string;
  byteSize: number;
  altText: string | null;
  isPublic: boolean;
}

export type CatalogPrizeAssetDraft = CatalogAssetDraft;

export function CatalogPrizeAssetMutationForm({
  current,
  mode,
  onCancel,
  onSubmit,
}: {
  current?: AdminCatalogPresentationAsset;
  mode: "create" | "edit";
  resource: "presentation-assets";
  onCancel: () => void;
  onSubmit: (draft: CatalogPrizeAssetDraft) => Promise<void>;
}) {
  const initial = useMemo(() => initialDraft(current), [current]);
  const [draft, setDraft] = useState(initial);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const dirty = JSON.stringify(draft) !== JSON.stringify(initial);

  useEffect(() => {
    heading.current?.focus();
  }, []);
  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (!validDraft(draft, mode)) {
      setError("入力内容を確認してください。HTMLや不正なPathは入力できません。");
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit(trimDraft(draft));
    } catch {
      setError("保存できませんでした。画面の案内に従って再試行してください。");
    } finally {
      setSubmitting(false);
    }
  }

  function cancel() {
    if (dirty && !window.confirm("未保存の変更を破棄しますか。")) return;
    onCancel();
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="catalog-prize-asset-heading"
        aria-modal="true"
        className="dialog-panel catalog-mutation-panel"
        role="dialog"
      >
        <header className="dialog-header">
          <div>
            <span className="eyebrow">Catalog Master</span>
            <h2 id="catalog-prize-asset-heading" ref={heading} tabIndex={-1}>
              {mode === "create" ? "新規作成" : "編集"}
            </h2>
          </div>
          <button
            aria-label="閉じる"
            className="icon-button"
            disabled={submitting}
            onClick={cancel}
            type="button"
          >
            <X size={18} aria-hidden="true" />
          </button>
        </header>
        <form className="catalog-mutation-form" onSubmit={submit}>
          {mode === "create" ? (
            <>
              <TextField label="Storage識別子" value={draft.storageIdentifier}
                onChange={(value) => setDraft({ ...draft, storageIdentifier: value })} />
              <TextField label="Public Path" value={draft.publicPath}
                onChange={(value) => setDraft({ ...draft, publicPath: value })} />
              <TextField label="SHA-256" value={draft.checksumSha256} maxLength={64}
                onChange={(value) => setDraft({ ...draft, checksumSha256: value })} />
              <label>
                Media種別
                <select
                  onChange={(event) =>
                    setDraft({
                      ...draft,
                      mediaType: event.target.value as "image" | "video",
                    })
                  }
                  value={draft.mediaType}
                >
                  <option value="image">Image</option>
                  <option value="video">Video</option>
                </select>
              </label>
              <TextField label="MIME Type" value={draft.mimeType}
                onChange={(value) => setDraft({ ...draft, mimeType: value })} />
              <label>
                Byte Size
                <input min={0} onChange={(event) =>
                  setDraft({ ...draft, byteSize: Number(event.target.value) })
                } required type="number" value={draft.byteSize} />
              </label>
            </>
          ) : (
            <p className="catalog-immutable-code">
              Object識別情報は作成後変更できません。
            </p>
          )}
          <TextField label="Alt" required={false} value={draft.altText ?? ""}
            onChange={(value) => setDraft({ ...draft, altText: value || null })} />
          <BooleanField checked={draft.isPublic} label="Public Assetとして公開する"
            onChange={(value) => setDraft({ ...draft, isPublic: value })} />
          {error ? <p aria-live="assertive" className="form-error" role="alert">{error}</p> : null}
          <div className="catalog-dialog-actions">
            <button className="secondary-button" disabled={submitting} onClick={cancel} type="button">
              取り消し
            </button>
            <button className="primary-button" disabled={submitting || !dirty} type="submit">
              {submitting ? <LoaderCircle className="spin" size={16} aria-hidden="true" /> : null}
              保存
            </button>
          </div>
        </form>
      </section>
    </div>
  );
}

function TextField({ label, value, onChange, maxLength = 512, required = true }: {
  label: string; value: string; onChange: (value: string) => void;
  maxLength?: number; required?: boolean;
}) {
  return <label>{label}<input maxLength={maxLength} onChange={(event) =>
    onChange(event.target.value)
  } required={required} value={value} /></label>;
}

function BooleanField({ checked, label, onChange }: {
  checked: boolean; label: string; onChange: (value: boolean) => void;
}) {
  return <label className="catalog-checkbox"><input checked={checked}
    onChange={(event) => onChange(event.target.checked)} type="checkbox" />{label}</label>;
}

function initialDraft(
  current?: AdminCatalogPresentationAsset,
): CatalogPrizeAssetDraft {
  const asset = current;
  return {
    storageIdentifier: "",
    publicPath: "",
    checksumSha256: "",
    mediaType: asset?.media_type ?? "image",
    mimeType: asset?.mime_type ?? "image/png",
    byteSize: asset?.byte_size ?? 0,
    altText: asset?.alt_text ?? null,
    isPublic: asset?.is_public ?? false,
  };
}

function validDraft(draft: CatalogPrizeAssetDraft, mode: "create" | "edit"): boolean {
  return Boolean(
    (mode === "edit" || (
      draft.storageIdentifier && draft.publicPath.startsWith("/") &&
      /^[0-9a-f]{64}$/u.test(draft.checksumSha256) &&
      draft.mimeType.startsWith(`${draft.mediaType}/`) &&
      Number.isSafeInteger(draft.byteSize) && draft.byteSize >= 0
    )) && !/[<>]/u.test(draft.altText ?? ""),
  );
}

function trimDraft(draft: CatalogPrizeAssetDraft): CatalogPrizeAssetDraft {
  return { ...draft, altText: draft.altText?.trim() || null };
}

export function CatalogBannerAssetPicker({
  assetId,
  disabled = false,
  onSelectionChange,
}: {
  assetId: string | null;
  disabled?: boolean;
  onSelectionChange: (selection: {
    assetId: string | null;
    bannerId: string | null;
    changed: boolean;
  }) => void;
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
