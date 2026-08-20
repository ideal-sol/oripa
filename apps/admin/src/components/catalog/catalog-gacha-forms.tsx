"use client";

import { LoaderCircle, Plus, Trash2, X } from "lucide-react";
import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";

import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import { catalogProblemMessage } from "@/components/catalog/catalog-api-error-boundary";
import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import type {
  AdminCatalogAssetReference,
  AdminCatalogCategory,
  AdminCatalogGacha,
  AdminCatalogGachaVersion,
  AdminCatalogPresentationAsset,
  AdminCatalogPrize,
  AdminCatalogTag,
} from "@/lib/admin-api/generated";

export interface GachaMasterDraft {
  categoryId: string;
  code: string;
  slug: string;
  tagIds: string[];
}

export interface GachaVersionPrizeDraft {
  initialInventory: number;
  prizeId: string;
  sortOrder: number;
}

export interface GachaVersionDraft {
  description: string | null;
  notices: string | null;
  presentationAssetId: string | null;
  pricePoints: number;
  prizes: GachaVersionPrizeDraft[];
  publishEndAt: string | null;
  publishStartAt: string;
  title: string;
  totalCount: number;
}

export interface GachaCoreDraft {
  allowedDrawCounts: Array<1 | 5 | 10 | 100 | 1000>;
  audienceCode: "all_users" | "first_time_users" | "line_users";
  categoryId: string;
  dailyDrawLimit: number;
  firstTimeEligibleDays: number;
  description: string | null;
  notices: string | null;
  presentationAssetId: string | null;
  pricePoints: number;
  publishEndAt: string | null;
  publishStartAt: string;
  tagIds: string[];
  title: string;
  thumbnailFile: File | null;
  totalCount: number;
  managementStatus: "draft" | "published" | "scheduled" | "sales_paused" | "unpublished";
}

export function CatalogGachaCoreForm({
  current,
  mode = "create",
  onCancel,
  onSubmit,
}: {
  current?: AdminCatalogGacha;
  mode?: "create" | "edit";
  onCancel: () => void;
  onSubmit: (draft: GachaCoreDraft) => Promise<void>;
}) {
  const initial = useMemo<GachaCoreDraft>(() => ({
    allowedDrawCounts: current?.current_version?.allowed_draw_counts ?? [1, 5, 10],
    audienceCode: current?.current_version?.audience_code ?? "all_users",
    categoryId: current?.category.id ?? "",
    dailyDrawLimit: current?.current_version?.daily_draw_limit ?? 0,
    firstTimeEligibleDays: current?.current_version?.first_time_eligible_days ?? 7,
    description: current?.current_version?.description ?? null,
    notices: current?.current_version?.notices ?? null,
    presentationAssetId: current?.current_version?.presentation_asset?.id ?? null,
    pricePoints: current?.current_version?.price_points ?? 1,
    publishEndAt: current?.current_version?.publish_end_at ?? null,
    publishStartAt: current?.current_version?.publish_start_at ?? "",
    tagIds: current?.tags.map((tag) => tag.id) ?? [],
    title: current?.current_version?.title ?? "",
    thumbnailFile: null,
    totalCount: current?.current_version?.total_count ?? 1,
    managementStatus: current?.publication_status ?? "draft",
  }), [current]);
  const [draft, setDraft] = useState<GachaCoreDraft>(initial);
  const [categories, setCategories] = useState<AdminCatalogCategory[]>([]);
  const [tags, setTags] = useState<AdminCatalogTag[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [currentTime, setCurrentTime] = useState<number | null>(null);
  const postPublished = mode === "edit" && current?.first_published_at != null;
  const scheduled = current?.publication_status === "scheduled";
  const scheduledStartReached = scheduled
    && currentTime !== null
    && Date.parse(current?.current_version?.publish_start_at ?? "") <= currentTime;
  const dirty = draft.thumbnailFile !== null || JSON.stringify({
    ...draft,
    thumbnailFile: null,
  }) !== JSON.stringify(initial);

  useEffect(() => {
    const timer = window.setTimeout(() => setCurrentTime(Date.now()), 0);
    return () => window.clearTimeout(timer);
  }, [current?.id, current?.current_version?.publish_start_at]);

  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    Promise.all([
      client.listCatalogCategories(
        { direction: "asc", limit: 100, sort: "sort_order", visibility: "visible" },
        controller.signal,
      ),
      client.listCatalogTags(
        { direction: "asc", limit: 100, sort: "sort_order", visibility: "visible" },
        controller.signal,
      ),
    ])
      .then(([categoryResponse, tagResponse]) => {
        setCategories(categoryResponse.items.filter((item) => !item.is_archived));
        setTags(tagResponse.items.filter((item) => !item.is_archived));
      })
      .catch(() => setErrors({ form: "登録用の選択肢を取得できませんでした。" }));
    return () => controller.abort();
  }, []);
  useDirtyGuard(dirty);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const nextErrors: Record<string, string> = {};
    if (!draft.title.trim()) nextErrors.title = "ガチャタイトルは必須です。";
    if (!draft.categoryId) nextErrors.category = "カテゴリを選択してください。";
    if (!draft.thumbnailFile && !draft.presentationAssetId) {
      nextErrors.asset = "サムネイル画像を選択してください。";
    }
    if (
      draft.thumbnailFile &&
      (!(["image/gif", "image/jpeg", "image/png", "image/webp"] as string[])
        .includes(draft.thumbnailFile.type) || draft.thumbnailFile.size > 5 * 1024 * 1024)
    ) {
      nextErrors.asset = "サムネイルはGIF、JPEG、PNG、WebPの5 MB以下にしてください。";
    }
    if (!Number.isSafeInteger(draft.pricePoints) || draft.pricePoints < 1) {
      nextErrors.price = "消費ポイントは1以上の整数です。";
    }
    if (!Number.isSafeInteger(draft.totalCount) || draft.totalCount < 1) {
      nextErrors.total = "総口数は1以上の整数です。";
    }
    if (!Number.isSafeInteger(draft.dailyDrawLimit) || draft.dailyDrawLimit < 0) {
      nextErrors.daily = "1日規定回数は0以上の整数です。";
    }
    if (!Number.isSafeInteger(draft.firstTimeEligibleDays) || draft.firstTimeEligibleDays < 1) {
      nextErrors.firstTimeDays = "初回ユーザー日数は1以上の整数です。";
    }
    if (!draft.allowedDrawCounts.includes(1)) {
      nextErrors.drawCounts = "1回ガチャは必須です。";
    }
    const startsAt = Date.parse(draft.publishStartAt);
    const endsAt = draft.publishEndAt ? Date.parse(draft.publishEndAt) : null;
    if (!Number.isFinite(startsAt)) nextErrors.start = "開始日時は必須です。";
    if (endsAt !== null && (!Number.isFinite(endsAt) || endsAt <= startsAt)) {
      nextErrors.end = "終了日時は開始日時より後にしてください。";
    }
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;
    setSubmitting(true);
    try {
      await onSubmit({
        ...draft,
        description: draft.description?.normalize("NFC").trim() || null,
        notices: draft.notices?.normalize("NFC").trim() || null,
        publishEndAt: draft.publishEndAt
          ? new Date(draft.publishEndAt).toISOString()
          : null,
        publishStartAt: new Date(draft.publishStartAt).toISOString(),
        tagIds: [...draft.tagIds].sort(),
        title: draft.title.normalize("NFC").trim(),
      });
    } catch (cause) {
      setErrors({
        form: cause instanceof AdminApiError
          ? catalogProblemMessage(cause)
          : `${mode === "create" ? "登録" : "保存"}できませんでした。入力内容を確認してください。`,
      });
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="catalog-core-form-card" aria-labelledby="gacha-core-heading">
      <header>
        <span className="eyebrow">下書きガチャ</span>
        <h2 id="gacha-core-heading">{mode === "create" ? "ガチャ登録" : "ガチャ編集"}</h2>
        <p>{mode === "create" ? "作成時の状態は下書きです。公開操作は登録後の管理画面で行います。" : postPublished ? "公開後は表示情報と終了日時だけを変更できます。販売条件と抽選条件は変更できません。" : "変更は編集中データへ保存され、公開済み内容には直接反映されません。"}</p>
      </header>
      <form className="catalog-mutation-form" onSubmit={submit}>
        <TextField label="ガチャタイトル" maxLength={191} onChange={(title) => setDraft({ ...draft, title })} value={draft.title} />
        <FieldError message={errors.title} />
        <div className="catalog-form-grid">
          <label>カテゴリ<select required value={draft.categoryId} onChange={(event) => setDraft({ ...draft, categoryId: event.target.value })}><option value="">選択してください</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <GachaThumbnailField
            current={current?.current_version?.presentation_asset ?? null}
            onChange={(thumbnailFile) => setDraft({ ...draft, thumbnailFile })}
            required={mode === "create"}
          />
        </div>
        <FieldError message={errors.category ?? errors.asset} />
        <fieldset className="catalog-choice-fieldset"><legend>タグ</legend>{tags.length === 0 ? <p>選択可能なタグはありません。</p> : null}{tags.map((tag) => <label className="catalog-checkbox" key={tag.id}><input type="checkbox" checked={draft.tagIds.includes(tag.id)} onChange={(event) => setDraft({ ...draft, tagIds: event.target.checked ? [...draft.tagIds, tag.id] : draft.tagIds.filter((id) => id !== tag.id) })} />{tag.name}</label>)}</fieldset>
        <div className="catalog-form-grid">
          <NumberField disabled={postPublished} label="消費ポイント" min={1} onChange={(pricePoints) => setDraft({ ...draft, pricePoints })} value={draft.pricePoints} />
          <NumberField disabled={postPublished} label="総口数" min={1} onChange={(totalCount) => setDraft({ ...draft, totalCount })} value={draft.totalCount} />
          <NumberField disabled={postPublished} label="1日規定回数（0は無制限・JST 0時リセット）" min={0} onChange={(dailyDrawLimit) => setDraft({ ...draft, dailyDrawLimit })} value={draft.dailyDrawLimit} />
          {mode === "create" ? <label>状態<input disabled value="下書き" /></label> : <label>状態<select value={draft.managementStatus} onChange={(event) => setDraft({ ...draft, managementStatus: event.target.value as GachaCoreDraft["managementStatus"] })}>{managementStatusOptions(current?.publication_status, scheduledStartReached).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>}
        </div>
        <FieldError message={errors.price ?? errors.total ?? errors.daily} />
        <fieldset className="catalog-choice-fieldset">
          <legend>許可する抽選回数</legend>
          {([1, 5, 10, 100, 1000] as const).map((count) => (
            <label className="catalog-checkbox" key={count}>
              <input
                checked={draft.allowedDrawCounts.includes(count)}
                disabled={count === 1 || postPublished}
                onChange={(event) => setDraft({
                  ...draft,
                  allowedDrawCounts: event.target.checked
                    ? ([...draft.allowedDrawCounts, count].sort((left, right) => left - right) as GachaCoreDraft["allowedDrawCounts"])
                    : draft.allowedDrawCounts.filter((item) => item !== count),
                })}
                type="checkbox"
              />
              {count}回
            </label>
          ))}
        </fieldset>
        <FieldError message={errors.drawCounts} />
        <label>会員ランク<select disabled={postPublished} value={draft.audienceCode} onChange={(event) => setDraft({ ...draft, audienceCode: event.target.value as GachaCoreDraft["audienceCode"] })}><option value="all_users">すべてのユーザー</option><option value="first_time_users">初回ユーザー</option><option value="line_users">LINEユーザー</option></select></label>
        {draft.audienceCode === "first_time_users" ? <><NumberField disabled={postPublished} label="新規登録後の日数（1日＝24時間）" min={1} onChange={(firstTimeEligibleDays) => setDraft({ ...draft, firstTimeEligibleDays })} value={draft.firstTimeEligibleDays} /><FieldError message={errors.firstTimeDays} /></> : null}
        <div className="catalog-form-grid">
          <DateTimeField disabled={postPublished && (!scheduled || scheduledStartReached)} label="開始日時（Asia/Tokyo）" onChange={(publishStartAt) => setDraft({ ...draft, publishStartAt })} value={draft.publishStartAt} />
          <DateTimeField label="終了日時（Asia/Tokyo）" onChange={(publishEndAt) => setDraft({ ...draft, publishEndAt: publishEndAt || null })} required={false} value={draft.publishEndAt ?? ""} />
        </div>
        <FieldError message={errors.start ?? errors.end} />
        <TextArea label="説明" onChange={(description) => setDraft({ ...draft, description })} value={draft.description ?? ""} />
        <TextArea label="注意事項" onChange={(notices) => setDraft({ ...draft, notices })} value={draft.notices ?? ""} />
        {errors.form ? <FormError message={errors.form} /> : null}
        <div className="catalog-dialog-actions"><button className="secondary-button" disabled={submitting} onClick={onCancel} type="button">取り消し</button><button className="primary-button" disabled={submitting} type="submit">{submitting ? <LoaderCircle className="spin" size={16} aria-hidden="true" /> : null}{mode === "create" ? "下書きを登録" : "編集内容を保存"}</button></div>
      </form>
    </section>
  );
}

function GachaThumbnailField({
  current,
  onChange,
  required,
}: {
  current: AdminCatalogAssetReference | null;
  onChange: (file: File | null) => void;
  required: boolean;
}) {
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const previewReader = useRef<FileReader | null>(null);

  const selectFile = (selected: File | null) => {
    previewReader.current?.abort();
    onChange(selected);
    if (!selected) {
      setPreviewUrl(null);
      return;
    }
    const reader = new FileReader();
    previewReader.current = reader;
    reader.addEventListener("load", () => {
      setPreviewUrl(typeof reader.result === "string" ? reader.result : null);
    });
    reader.addEventListener("error", () => setPreviewUrl(null));
    reader.readAsDataURL(selected);
  };

  return (
    <label className="catalog-thumbnail-field">
      サムネイル画像
      {previewUrl ? (
        // Browser-local preview generated from the selected file.
        // eslint-disable-next-line @next/next/no-img-element
        <img alt="選択したサムネイルのPreview" className="asset-preview" src={previewUrl} />
      ) : (
        <PublicAssetPreview asset={current} />
      )}
      <input
        accept="image/gif,image/jpeg,image/png,image/webp"
        aria-describedby="gacha-thumbnail-help"
        onChange={(event) => selectFile(event.target.files?.[0] ?? null)}
        required={required && current === null}
        type="file"
      />
      <span id="gacha-thumbnail-help" className="field-hint">
        GIF、JPEG、PNG、WebP（最大5 MB）
      </span>
    </label>
  );
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="form-field-error" role="alert">{message}</p> : null;
}

export function CatalogGachaMasterForm({
  current,
  mode,
  onCancel,
  onSubmit,
}: {
  current?: AdminCatalogGacha;
  mode: "create" | "edit";
  onCancel: () => void;
  onSubmit: (draft: GachaMasterDraft) => Promise<void>;
}) {
  const initial = useMemo<GachaMasterDraft>(
    () => ({
      categoryId: current?.category.id ?? "",
      code: current?.code ?? "",
      slug: current?.slug ?? "",
      tagIds: current?.tags.map((tag) => tag.id) ?? [],
    }),
    [current],
  );
  const [draft, setDraft] = useState(initial);
  const [categories, setCategories] = useState<AdminCatalogCategory[]>([]);
  const [tags, setTags] = useState<AdminCatalogTag[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const dirty = JSON.stringify(draft) !== JSON.stringify(initial);

  useEffect(() => heading.current?.focus(), []);
  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    Promise.all([
      client.listCatalogCategories(
        { direction: "asc", limit: 100, sort: "sort_order", visibility: "visible" },
        controller.signal,
      ),
      client.listCatalogTags(
        { direction: "asc", limit: 100, sort: "sort_order", visibility: "visible" },
        controller.signal,
      ),
    ])
      .then(([categoryResponse, tagResponse]) => {
        setCategories(categoryResponse.items.filter((item) => !item.is_archived));
        setTags(tagResponse.items.filter((item) => !item.is_archived));
      })
      .catch(() => setError("Category／Tagの選択肢を取得できませんでした。"));
    return () => controller.abort();
  }, []);
  useDirtyGuard(dirty);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (
      !draft.categoryId ||
      (mode === "create" &&
        (!/^[a-z][a-z0-9_-]{0,63}$/.test(draft.code) ||
          !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(draft.slug)))
    ) {
      setError("Code、Slug、Categoryを確認してください。");
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit({
        ...draft,
        code: draft.code.normalize("NFC").trim(),
        slug: draft.slug.normalize("NFC").trim(),
        tagIds: [...draft.tagIds].sort(),
      });
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
    <DialogShell
      busy={submitting}
      heading={mode === "create" ? "ガチャ基本情報を新規作成" : "ガチャ基本情報を編集"}
      headingRef={heading}
      onCancel={cancel}
    >
      <form className="catalog-mutation-form" onSubmit={submit}>
        {mode === "create" ? (
          <>
            <TextField
              label="Code"
              maxLength={64}
              onChange={(code) => setDraft({ ...draft, code })}
              value={draft.code}
            />
            <TextField
              label="Slug"
              maxLength={191}
              onChange={(slug) => setDraft({ ...draft, slug })}
              value={draft.slug}
            />
          </>
        ) : (
          <p className="catalog-immutable-code">
            Code <code>{draft.code}</code> ／ Slug <code>{draft.slug}</code>
          </p>
        )}
        <label>
          Category
          <select
            onChange={(event) => setDraft({ ...draft, categoryId: event.target.value })}
            required
            value={draft.categoryId}
          >
            <option value="">選択してください</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name} ({category.code})
              </option>
            ))}
          </select>
        </label>
        <fieldset className="catalog-choice-fieldset">
          <legend>Tag</legend>
          {tags.length === 0 ? <p>選択可能なTagはありません。</p> : null}
          {tags.map((tag) => (
            <label className="catalog-checkbox" key={tag.id}>
              <input
                checked={draft.tagIds.includes(tag.id)}
                onChange={(event) =>
                  setDraft({
                    ...draft,
                    tagIds: event.target.checked
                      ? [...draft.tagIds, tag.id]
                      : draft.tagIds.filter((id) => id !== tag.id),
                  })
                }
                type="checkbox"
              />
              {tag.name}
            </label>
          ))}
        </fieldset>
        <FormActions
          dirty={dirty}
          submitting={submitting}
          onCancel={cancel}
        />
        {error ? <FormError message={error} /> : null}
      </form>
    </DialogShell>
  );
}

export function CatalogGachaVersionForm({
  current,
  mode,
  onCancel,
  onSubmit,
}: {
  current?: AdminCatalogGachaVersion;
  mode: "create" | "edit";
  onCancel: () => void;
  onSubmit: (draft: GachaVersionDraft) => Promise<void>;
}) {
  const initial = useMemo(() => versionDraft(current), [current]);
  const [draft, setDraft] = useState(initial);
  const [assets, setAssets] = useState<AdminCatalogPresentationAsset[]>([]);
  const [prizes, setPrizes] = useState<AdminCatalogPrize[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const dirty = JSON.stringify(draft) !== JSON.stringify(initial);

  useEffect(() => heading.current?.focus(), []);
  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    Promise.all([
      client.listCatalogPresentationAssets(
        { direction: "desc", limit: 100, sort: "created_at", visibility: "visible" },
        controller.signal,
      ),
      client.listCatalogPrizes(
        { direction: "asc", limit: 100, sort: "rank", visibility: "visible" },
        controller.signal,
      ),
    ])
      .then(([assetResponse, prizeResponse]) => {
        setAssets(assetResponse.items.filter((item) => !item.is_archived));
        setPrizes(prizeResponse.items.filter((item) => !item.is_archived));
      })
      .catch(() => setError("景品／表示素材の選択肢を取得できませんでした。"));
    return () => controller.abort();
  }, []);
  useDirtyGuard(dirty);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (!validVersionDraft(draft)) {
      setError("必須項目、公開期間、景品の重複と数量を確認してください。");
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit(normalizeVersionDraft(draft));
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

  function updatePrize(index: number, patch: Partial<GachaVersionPrizeDraft>) {
    setDraft({
      ...draft,
      prizes: draft.prizes.map((item, itemIndex) =>
        itemIndex === index ? { ...item, ...patch } : item,
      ),
    });
  }

  return (
    <DialogShell
      busy={submitting}
      heading={mode === "create" ? "下書きバージョンを新規作成" : "下書きバージョンを編集"}
      headingRef={heading}
      onCancel={cancel}
      wide
    >
      <form className="catalog-mutation-form" onSubmit={submit}>
        <TextField
          label="タイトル"
          maxLength={191}
          onChange={(title) => setDraft({ ...draft, title })}
          value={draft.title}
        />
        <TextArea
          label="説明"
          onChange={(description) => setDraft({ ...draft, description })}
          value={draft.description ?? ""}
        />
        <TextArea
          label="注意事項"
          onChange={(notices) => setDraft({ ...draft, notices })}
          value={draft.notices ?? ""}
        />
        <div className="catalog-form-grid">
          <NumberField
            label="消費ポイント"
            min={1}
            onChange={(pricePoints) => setDraft({ ...draft, pricePoints })}
            value={draft.pricePoints}
          />
          <NumberField
            label="販売口数"
            min={1}
            onChange={(totalCount) => setDraft({ ...draft, totalCount })}
            value={draft.totalCount}
          />
        </div>
        <label>
            表示素材
          <select
            onChange={(event) =>
              setDraft({ ...draft, presentationAssetId: event.target.value || null })
            }
            value={draft.presentationAssetId ?? ""}
          >
            <option value="">未設定</option>
            {assets.map((asset) => (
              <option key={asset.id} value={asset.id}>
                {asset.alt_text ?? asset.media_type}
              </option>
            ))}
          </select>
        </label>
        <div className="catalog-form-grid">
          <DateTimeField
            label="公開開始"
            onChange={(publishStartAt) => setDraft({ ...draft, publishStartAt })}
            value={draft.publishStartAt}
          />
          <DateTimeField
            label="公開終了"
            onChange={(publishEndAt) =>
              setDraft({ ...draft, publishEndAt: publishEndAt || null })
            }
            required={false}
            value={draft.publishEndAt ?? ""}
          />
        </div>
        <fieldset className="catalog-prize-fieldset">
          <div className="catalog-fieldset-heading">
            <legend>景品構成</legend>
            <button
              className="secondary-button"
              onClick={() =>
                setDraft({
                  ...draft,
                  prizes: [
                    ...draft.prizes,
                    {
                      initialInventory: 0,
                      prizeId: "",
                      sortOrder: nextSortOrder(draft.prizes),
                    },
                  ],
                })
              }
              type="button"
            >
              <Plus size={16} aria-hidden="true" />
              景品追加
            </button>
          </div>
          {draft.prizes.map((item, index) => (
            <div className="catalog-prize-row" key={`${index}-${item.prizeId}`}>
              <label>
                景品
                <select
                  onChange={(event) => updatePrize(index, { prizeId: event.target.value })}
                  required
                  value={item.prizeId}
                >
                  <option value="">選択してください</option>
                  {prizes.map((prize) => (
                    <option key={prize.id} value={prize.id}>
                      {prize.rank.code} / {prize.name}
                    </option>
                  ))}
                </select>
              </label>
              <NumberField
                label="初期在庫"
                min={0}
                onChange={(initialInventory) => updatePrize(index, { initialInventory })}
                value={item.initialInventory}
              />
              <NumberField
                label="表示順"
                min={0}
                onChange={(sortOrder) => updatePrize(index, { sortOrder })}
                value={item.sortOrder}
              />
              <button
                aria-label={`${index + 1}行目を削除`}
                className="icon-button"
                disabled={draft.prizes.length === 1}
                onClick={() =>
                  setDraft({
                    ...draft,
                    prizes: draft.prizes.filter((_, itemIndex) => itemIndex !== index),
                  })
                }
                type="button"
              >
                <Trash2 size={17} aria-hidden="true" />
              </button>
            </div>
          ))}
        </fieldset>
        <FormActions
          dirty={dirty}
          submitting={submitting}
          onCancel={cancel}
        />
        {error ? <FormError message={error} /> : null}
      </form>
    </DialogShell>
  );
}

function DialogShell({
  busy,
  children,
  heading,
  headingRef,
  onCancel,
  wide = false,
}: {
  busy: boolean;
  children: React.ReactNode;
  heading: string;
  headingRef: React.RefObject<HTMLHeadingElement | null>;
  onCancel: () => void;
  wide?: boolean;
}) {
  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="catalog-gacha-form-heading"
        aria-modal="true"
        className={`dialog-panel catalog-mutation-panel${wide ? " is-wide" : ""}`}
        role="dialog"
      >
        <header className="dialog-header">
          <div>
            <span className="eyebrow">ガチャ下書き管理</span>
            <h2 id="catalog-gacha-form-heading" ref={headingRef} tabIndex={-1}>
              {heading}
            </h2>
          </div>
          <button
            aria-label="閉じる"
            className="icon-button"
            disabled={busy}
            onClick={onCancel}
            type="button"
          >
            <X size={18} aria-hidden="true" />
          </button>
        </header>
        {children}
      </section>
    </div>
  );
}

function FormActions({
  dirty,
  onCancel,
  submitting,
}: {
  dirty: boolean;
  onCancel: () => void;
  submitting: boolean;
}) {
  return (
    <div className="catalog-dialog-actions">
      <button
        className="secondary-button"
        disabled={submitting}
        onClick={onCancel}
        type="button"
      >
        取り消し
      </button>
      <button className="primary-button" disabled={submitting || !dirty} type="submit">
        {submitting ? <LoaderCircle className="spin" size={16} aria-hidden="true" /> : null}
        保存
      </button>
    </div>
  );
}

function FormError({ message }: { message: string }) {
  return (
    <p aria-live="assertive" className="form-error" role="alert">
      {message}
    </p>
  );
}

function TextField({
  label,
  maxLength,
  onChange,
  value,
}: {
  label: string;
  maxLength: number;
  onChange: (value: string) => void;
  value: string;
}) {
  return (
    <label>
      {label}
      <input
        maxLength={maxLength}
        onChange={(event) => onChange(event.target.value)}
        required
        value={value}
      />
    </label>
  );
}

function TextArea({
  label,
  onChange,
  value,
}: {
  label: string;
  onChange: (value: string | null) => void;
  value: string;
}) {
  return (
    <label>
      {label}
      <textarea
        maxLength={10_000}
        onChange={(event) => onChange(event.target.value || null)}
        rows={3}
        value={value}
      />
    </label>
  );
}

function NumberField({
  disabled = false,
  label,
  min,
  onChange,
  value,
}: {
  disabled?: boolean;
  label: string;
  min: number;
  onChange: (value: number) => void;
  value: number;
}) {
  return (
    <label>
      {label}
      <input
        disabled={disabled}
        min={min}
        onChange={(event) => onChange(Number(event.target.value))}
        required
        step={1}
        type="number"
        value={value}
      />
    </label>
  );
}

function DateTimeField({
  disabled = false,
  label,
  onChange,
  required = true,
  value,
}: {
  disabled?: boolean;
  label: string;
  onChange: (value: string) => void;
  required?: boolean;
  value: string;
}) {
  return (
    <label>
      {label}
      <input
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        required={required}
        type="datetime-local"
        value={toLocalInput(value)}
      />
    </label>
  );
}

function managementStatusOptions(
  status?: AdminCatalogGacha["publication_status"],
  scheduledStartReached = false,
): Array<{ label: string; value: GachaCoreDraft["managementStatus"] }> {
  switch (status) {
    case "scheduled":
      return scheduledStartReached
        ? [
            { label: "公開中（予約開始済み）", value: "scheduled" },
            { label: "販売停止", value: "sales_paused" },
            { label: "非公開", value: "unpublished" },
          ]
        : [
            { label: "予約公開", value: "scheduled" },
            { label: "予約取消（下書きへ戻す）", value: "draft" },
          ];
    case "published":
      return [
        { label: "公開", value: "published" },
        { label: "販売停止", value: "sales_paused" },
        { label: "非公開", value: "unpublished" },
      ];
    case "sales_paused":
      return [
        { label: "販売停止", value: "sales_paused" },
        { label: "公開（販売再開）", value: "published" },
        { label: "非公開", value: "unpublished" },
      ];
    case "unpublished":
      return [{ label: "非公開", value: "unpublished" }];
    default:
      return [
        { label: "下書き", value: "draft" },
        { label: "予約公開", value: "scheduled" },
        { label: "公開", value: "published" },
      ];
  }
}

function useDirtyGuard(dirty: boolean) {
  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty]);
}

function versionDraft(current?: AdminCatalogGachaVersion): GachaVersionDraft {
  return {
    description: current?.description ?? null,
    notices: current?.notices ?? null,
    presentationAssetId: current?.presentation_asset?.id ?? null,
    pricePoints: current?.price_points ?? 1,
    prizes:
      current?.prizes.map((item) => ({
        initialInventory: item.initial_inventory,
        prizeId: item.prize.id,
        sortOrder: item.sort_order,
      })) ?? [{ initialInventory: 0, prizeId: "", sortOrder: 10 }],
    publishEndAt: current?.publish_end_at ?? null,
    publishStartAt: current?.publish_start_at ?? "",
    title: current?.title ?? "",
    totalCount: current?.total_count ?? 1,
  };
}

function validVersionDraft(draft: GachaVersionDraft): boolean {
  const prizeIds = draft.prizes.map((item) => item.prizeId);
  const sortOrders = draft.prizes.map((item) => item.sortOrder);
  const startsAt = Date.parse(draft.publishStartAt);
  const endsAt = draft.publishEndAt ? Date.parse(draft.publishEndAt) : null;
  return (
    draft.title.trim().length > 0 &&
    Number.isSafeInteger(draft.pricePoints) &&
    draft.pricePoints > 0 &&
    Number.isSafeInteger(draft.totalCount) &&
    draft.totalCount > 0 &&
    draft.prizes.length > 0 &&
    prizeIds.every(Boolean) &&
    new Set(prizeIds).size === prizeIds.length &&
    new Set(sortOrders).size === sortOrders.length &&
    draft.prizes.every(
      (item) =>
        Number.isSafeInteger(item.initialInventory) &&
        item.initialInventory >= 0 &&
        Number.isSafeInteger(item.sortOrder) &&
        item.sortOrder >= 0,
    ) &&
    Number.isFinite(startsAt) &&
    (endsAt === null || (Number.isFinite(endsAt) && endsAt > startsAt))
  );
}

function normalizeVersionDraft(draft: GachaVersionDraft): GachaVersionDraft {
  return {
    ...draft,
    description: draft.description?.normalize("NFC").trim() || null,
    notices: draft.notices?.normalize("NFC").trim() || null,
    prizes: [...draft.prizes].sort(
      (left, right) =>
        left.sortOrder - right.sortOrder || left.prizeId.localeCompare(right.prizeId),
    ),
    publishEndAt: draft.publishEndAt
      ? new Date(draft.publishEndAt).toISOString()
      : null,
    publishStartAt: new Date(draft.publishStartAt).toISOString(),
    title: draft.title.normalize("NFC").trim(),
  };
}

function toLocalInput(value: string): string {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.valueOf())) return value.slice(0, 16);
  const local = new Date(date.valueOf() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
}

function nextSortOrder(items: GachaVersionPrizeDraft[]): number {
  return items.length === 0
    ? 10
    : Math.max(...items.map((item) => item.sortOrder)) + 10;
}
