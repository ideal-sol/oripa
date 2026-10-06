"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useRef, useState } from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { CatalogApiErrorBoundary } from "@/components/catalog/catalog-api-error-boundary";
import { CatalogGachaFormCard, GachaThumbnailField, gachaThumbnailError, uploadGachaThumbnail } from "@/components/catalog/catalog-gacha-forms";
import { ExternalIdField } from "@/components/catalog/external-id-field";
import { CatalogBannerAssetPicker } from "@/components/catalog/catalog-prize-asset-mutation-form";
import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminCatalogCategory, AdminCatalogGacha, AdminCatalogPresentationAsset, AdminCatalogRank, AdminCatalogTag,
  AdminCompositionPrize, AdminGachaComposition, AdminGachaType, AdminGachaVersionPrize,
} from "@/lib/admin-api/generated";
import { emptyGachaComposition, gachaTypeLabels, jstInput, jstTimestamp, percentageTotal } from "@/lib/catalog/login-gacha";

export function LoginGachaWorkspace({ type = "login_daily", sourceId, copy = false }: {
  type?: AdminGachaType; sourceId?: string; copy?: boolean;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const router = useRouter();
  const { expireSession } = useAdminAuth();
  const { hasPermission } = usePermissions();
  const [draft, setDraft] = useState<AdminGachaComposition>(() => emptyGachaComposition(type));
  const [prizeKeys, setPrizeKeys] = useState<string[]>([]);
  const [gacha, setGacha] = useState<AdminCatalogGacha | null>(null);
  const [ranks, setRanks] = useState<AdminCatalogRank[]>([]);
  const [assets, setAssets] = useState<AdminCatalogPresentationAsset[]>([]);
  const [defaultVideoId, setDefaultVideoId] = useState<string | null>(null);
  const noticeDefault = useRef<{ type: AdminGachaType; value: string | null } | null>(null);
  const [categories, setCategories] = useState<AdminCatalogCategory[]>([]);
  const [tags, setTags] = useState<AdminCatalogTag[]>([]);
  const [inventory, setInventory] = useState<AdminGachaVersionPrize[]>([]);
  const [adjustments, setAdjustments] = useState<Record<string, { quantity: string; reason: string }>>({});
  const [loading, setLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<AdminApiError | null>(null);
  const [notice, setNotice] = useState("");
  const [reload, setReload] = useState(0);
  const [thumbnailFile, setThumbnailFile] = useState<File | null>(null);
  const thumbnailUpload = useRef<{ file: File; key: string; asset?: AdminCatalogPresentationAsset } | null>(null);
  const externalIdTouched = useRef(new Set<string>());
  const pending = useRef<{ fingerprint: string; key: string } | null>(null);
  const canManage = hasPermission("catalog.manage");
  const locked = !canManage || !!gacha && !copy && (gacha.first_published_at !== null || gacha.publication_status !== "draft");
  const login = draft.gacha_type !== "standard";
  const total = percentageTotal(draft.prizes.map((prize) => prize.percentage));

  useEffect(() => {
    const controller = new AbortController();
    async function load() {
      const [rankResponse, assetResponse, categoryResponse, tagResponse] = await Promise.all([
        client.listCatalogRanks({ limit: 100, status: "active" }, controller.signal),
        client.listCatalogPresentationAssets({ limit: 100, archive: "active" }, controller.signal),
        client.listCatalogCategories({ limit: 100, archive: "active" }, controller.signal),
        client.listCatalogTags({ limit: 100, archive: "active" }, controller.signal),
      ]);
      let nextDraft = emptyGachaComposition(type);
      if (!sourceId && !copy) {
        if (noticeDefault.current?.type !== type) {
          const defaults = await client.getGachaNoticeDefaults(controller.signal);
          if (controller.signal.aborted) return;
          noticeDefault.current = { type, value: defaults.data[type === "standard" ? "standard" : "login"].default_notices };
        }
        nextDraft.notices = noticeDefault.current.value;
      }
      let defaultVideo: string | null = null;
      if (!sourceId) {
        let cursor: string | undefined;
        do {
          const videos = await client.listRankEffects({ media_type: "video", visibility: "visible", limit: 100, cursor }, controller.signal);
          defaultVideo = videos.items.find((video) => video.is_default)?.id ?? defaultVideo;
          cursor = videos.next_cursor ?? undefined;
        } while (cursor && !defaultVideo);
        nextDraft.ranks = rankResponse.items.map((rank) => ({
          rank_id: rank.id, rank_revision_number: null, video_asset_id: defaultVideo,
        }));
      }
      let current: AdminCatalogGacha | null = null;
      let currentInventory: AdminGachaVersionPrize[] = [];
      if (sourceId) {
        const [composition, source] = await Promise.all([
          client.getGachaComposition(sourceId, copy, controller.signal), client.getCatalogGacha(sourceId, controller.signal),
        ]);
        nextDraft = composition.data;
        current = source.data;
        if (!copy && current.current_version) {
          currentInventory = (await client.listGachaVersionPrizes(sourceId, current.current_version.id, controller.signal)).items;
        }
      }
      const [selectedRanks, selectedAssets, selectedCategories, selectedTags] = await Promise.all([
        includeReferences(rankResponse.items, nextDraft.ranks.map((rank) => rank.rank_id), (identifier) => client.getCatalogRank(identifier, controller.signal)),
        includeReferences(assetResponse.items, [nextDraft.presentation_asset_id, ...nextDraft.prizes.map((prize) => prize.presentation_asset_id),
          ...nextDraft.ranks.map((rank) => rank.video_asset_id)].filter((identifier): identifier is string => !!identifier),
        (identifier) => client.getCatalogPresentationAsset(identifier, controller.signal)),
        includeReferences(categoryResponse.items, nextDraft.category_id ? [nextDraft.category_id] : [], (identifier) => client.getCatalogCategory(identifier, controller.signal)),
        includeReferences(tagResponse.items, nextDraft.tag_ids, (identifier) => client.getCatalogTag(identifier, controller.signal)),
      ]);
      if (controller.signal.aborted) return;
      setRanks(selectedRanks); setAssets(selectedAssets); setCategories(selectedCategories); setTags(selectedTags);
      setDefaultVideoId(defaultVideo);
      setThumbnailFile(null); thumbnailUpload.current = null;
      setPrizeKeys(nextDraft.prizes.map(() => crypto.randomUUID()));
      setDraft(nextDraft); setGacha(current); setInventory(currentInventory); setAdjustments({}); setLoading(false); setLoadFailed(false);
    }
    load().catch((cause: unknown) => {
      if (controller.signal.aborted) return;
      const next = normalizeError(cause);
      if (next.isSessionExpired) expireSession();
      setError(next); setLoading(false); setLoadFailed(true);
    });
    return () => controller.abort();
  }, [client, sourceId, copy, type, reload, expireSession]);

  function mutationKey(fingerprint: string): string {
    if (pending.current?.fingerprint !== fingerprint) pending.current = { fingerprint, key: crypto.randomUUID() };
    return pending.current.key;
  }

  async function act(action: () => Promise<void>) {
    setBusy(true); setError(null); setNotice("");
    try {
      await action();
      pending.current = null;
    } catch (cause) {
      const next = normalizeError(cause);
      if (!next.retryable) pending.current = null;
      if (next.isSessionExpired) expireSession();
      setError(next);
    } finally {
      setBusy(false);
    }
  }

  function updatePrize(index: number, values: Partial<AdminCompositionPrize>) {
    setDraft((current) => ({ ...current, prizes: current.prizes.map((prize, position) => position === index ? { ...prize, ...values } : prize) }));
  }

  function addPrize() {
    const rank = ranks[0];
    if (!rank) return;
    setPrizeKeys((current) => [...current, crypto.randomUUID()]);
    setDraft((current) => ({
      ...current,
      ranks: current.ranks.some((item) => item.rank_id === rank.id) ? current.ranks : [...current.ranks, { rank_id: rank.id, rank_revision_number: null, video_asset_id: defaultVideoId }],
      prizes: [...current.prizes, { name: "", presentation_asset_id: "", rank_id: rank.id, exchange_points: current.minimum_exchange_points ?? 0,
        cost_price: 0, initial_inventory: 1, shipping_only: false, percentage: login ? "" : null }],
    }));
  }

  async function save() {
    if (!draft.publish_start_at || (login && !total.valid) || locked) return;
    if (draft.prizes.some((prize) => !prize.presentation_asset_id)) {
      setNotice("選択したBanner CategoryからBannerを選択してください。");
      return;
    }
    const thumbnailError = gachaThumbnailError(thumbnailFile, draft.presentation_asset_id);
    if (thumbnailError) { setNotice(thumbnailError); return; }
    if (sourceId && !copy && typeof gacha?.current_version?.revision !== "number") {
      setNotice("リビジョンを取得できません。再読み込みしてください。");
      return;
    }
    const body = { ...draft, publish_start_at: draft.publish_start_at, ranks: draft.ranks.filter((rank) => draft.prizes.some((prize) => prize.rank_id === rank.rank_id))
      .map(({ rank_id, rank_revision_number, video_asset_id }) => ({ rank_id, rank_revision_number, video_asset_id })) };
    if (draft.prizes.length === 0 || draft.prizes.some((prize) => !Number.isSafeInteger(prize.exchange_points) || !Number.isSafeInteger(prize.initial_inventory)
      || !Number.isSafeInteger(prize.cost_price) || (draft.gacha_type === "login_daily" && prize.exchange_points < (draft.minimum_exchange_points ?? 0)))) {
      setNotice("景品、交換ポイント、初期在庫を確認してください。");
      return;
    }
    await act(async () => {
      if (thumbnailFile) {
        if (thumbnailUpload.current?.file !== thumbnailFile) {
          thumbnailUpload.current = { file: thumbnailFile, key: crypto.randomUUID() };
        }
        const upload = thumbnailUpload.current;
        if (!upload.asset) upload.asset = (await uploadGachaThumbnail(client, thumbnailFile, upload.key)).data;
        body.presentation_asset_id = upload.asset.id;
      }
      const key = mutationKey(JSON.stringify(body));
      const result = sourceId && !copy && gacha?.current_version
        ? await client.updateGachaComposition(sourceId, { composition: body, expected_revision: gacha.revision, expected_version_revision: gacha.current_version.revision! }, key)
        : await client.createGachaComposition(body, key);
      router.push(`/catalog/gachas/${result.data.public_code ?? result.data.id}`);
      setReload((value) => value + 1);
    });
  }

  async function lifecycle(operation: "publish" | "pause" | "resume" | "cancel") {
    if (!sourceId || !gacha?.current_version) return;
    const version = gacha.current_version;
    if (typeof version.revision !== "number") {
      setNotice("リビジョンを取得できません。再読み込みしてください。");
      return;
    }
    const versionRevision = version.revision;
    if (!window.confirm(operation === "publish" ? "保存済みの構成を開始日時に従って公開・予約します。公開後は構成を変更できません。" : operation === "pause" ? "販売を停止しますか？停止中は在庫も編集できません。" : operation === "cancel" ? "公開予約を取り消しますか？" : "販売を再開しますか？在庫0の場合は補充まで非表示・抽選不可です。")) return;
    await act(async () => {
      const key = mutationKey(`${operation}:${gacha.revision}`);
      if (operation === "publish") {
        const body = { expected_revision: versionRevision, expected_gacha_revision: gacha.revision };
        if (Date.parse(version.publish_start_at) > Date.now()) {
          await client.scheduleGachaVersionPublish(sourceId, version.id, { ...body, scheduled_for: version.publish_start_at }, key);
        } else {
          const preflight = await client.preflightGachaVersionPublish(sourceId, version.id, { expected_revision: body.expected_revision }, `${key}-preflight`);
          if (!preflight.data.publishable) { setNotice(preflight.data.blocking_reasons.map((reason) => reason.message).join(" / ")); return; }
          await client.publishGachaVersionImmediately(sourceId, version.id, body, key);
        }
      } else if (operation === "cancel") {
        const schedule = (await client.getGachaPublishState(sourceId)).data.publish_schedule;
        if (!schedule) { setNotice("公開予約がありません。再読み込みしてください。"); return; }
        await client.cancelGachaVersionPublishSchedule(sourceId, version.id, schedule.id,
          { expected_gacha_revision: gacha.revision, expected_version_revision: versionRevision, expected_schedule_revision: schedule.revision }, key);
      } else if (operation === "pause") {
        await client.pauseGachaSales(sourceId, { expected_gacha_revision: gacha.revision, reason_code: "operations_review" }, key);
      } else {
        await client.resumeGachaSales(sourceId, { expected_gacha_revision: gacha.revision }, key);
      }
      setReload((value) => value + 1);
    });
  }

  const videoOptions = assets.filter((asset) => asset.media_type === "video" && asset.is_public);

  return <AdminShell><ProtectedAdminRoute permission={sourceId && !copy ? "catalog.read" : "catalog.manage"}><div className="workspace">
    <AdminPageHeader eyebrow="ガチャ管理" title={`${gachaTypeLabels[draft.gacha_type]}${copy ? "をコピー" : sourceId ? "の管理" : "の登録"}`}
      description={draft.gacha_type === "signup_once" ? "開始日時以降に本登録が完了したユーザーが、期限なしで1回だけ無料で引けます。" : login ? "ログイン済みユーザーがJSTの1日に1回。残り在庫で確率は変わりません。" : "コピー元とは独立した新しい下書きを作成します。"} />
    <Link className="table-link" href="/catalog/gachas">ガチャ一覧に戻る</Link>
    {copy ? <p role="status">コピーは未保存です。公開期間を入力して保存するまでガチャは作成されません。</p> : null}
    {loading ? <p role="status">読み込み中…</p> : loadFailed && error ? <CatalogApiErrorBoundary error={error} retry={() => { setLoading(true); setError(null); setReload((value) => value + 1); }} /> : <>
      {error ? <CatalogApiErrorBoundary error={error} retry={() => setReload((value) => value + 1)} /> : null}
      {notice ? <p role="alert">{notice}</p> : null}
      {gacha && !copy ? <section className="catalog-detail"><p>状態: {gacha.publication_status} / {locked ? "構成は参照専用" : "下書き編集中"}</p>
        {canManage ? <Link className="secondary-button" href={`/catalog/gachas/${sourceId}/copy`}>コピーして新規登録</Link> : null}
        {hasPermission("catalog.publish") ? <>
          {gacha.publication_status === "draft" ? <button type="button" className="primary-button" disabled={busy} onClick={() => void lifecycle("publish")}>保存済み構成を公開・予約</button> : null}
          {gacha.publication_status === "scheduled" ? <button type="button" className="secondary-button" disabled={busy} onClick={() => void lifecycle("cancel")}>公開予約を取り消す</button> : null}
          {gacha.publication_status === "published" ? <button type="button" className="secondary-button" disabled={busy} onClick={() => void lifecycle("pause")}>販売停止</button> : null}
          {gacha.publication_status === "sales_paused" ? <button type="button" className="secondary-button" disabled={busy} onClick={() => void lifecycle("resume")}>販売再開</button> : null}
        </> : null}
      </section> : null}
      <CatalogGachaFormCard title={sourceId && !copy ? "ガチャ編集" : "ガチャ登録"} readOnly={locked}
        description={locked ? "公開後は構成を変更できません。サムネイルも参照専用です。" : "作成時の状態は下書きです。公開操作は登録後の管理画面で行います。"}>
      <form className="catalog-mutation-form catalog-sectioned-form" onSubmit={(event) => { event.preventDefault(); void save(); }}>
        <fieldset disabled={locked || busy}><legend>基本情報</legend>
          <label>ガチャ名<input required maxLength={191} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} /></label>
          <GachaThumbnailField key={`${sourceId ?? "new"}:${reload}`} current={assets.find((asset) => asset.id === draft.presentation_asset_id) ?? null}
            disabled={locked || busy} required={!draft.presentation_asset_id} onChange={(file) => { setThumbnailFile(file); setNotice(""); }} />
          <div className="catalog-form-grid">
          {draft.gacha_type !== "signup_once" ? <label>消費ポイント<input required type="number" min={login ? 0 : 1} step={1} value={draft.price_points} onChange={(event) => setDraft({ ...draft, price_points: Number(event.target.value) })} /></label> : <p>消費ポイント: 無料（0）</p>}
          {draft.gacha_type === "login_daily" ? <label>最低保証（交換ポイント）<input required type="number" min={0} step={1} value={draft.minimum_exchange_points ?? 0} onChange={(event) => setDraft({ ...draft, minimum_exchange_points: Number(event.target.value) })} /></label> : null}
          </div>
          {login ? <p className="field-hint">抽選回数: 1回固定 / {draft.gacha_type === "login_daily" ? "1ユーザー1日1回（JST）" : "1ユーザー1回・利用期限なし・公開開始日時以降の新規登録完了ユーザー"}</p> : null}
          {!login ? <><label>総口数<input required type="number" min={1} step={1} value={draft.total_count ?? 1} onChange={(event) => setDraft({ ...draft, total_count: Number(event.target.value) })} /></label>
            <label>カテゴリ<select required value={draft.category_id ?? ""} onChange={(event) => setDraft({ ...draft, category_id: event.target.value })}><option value="">選択してください</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
            <label>タグ<select multiple value={draft.tag_ids} onChange={(event) => setDraft({ ...draft, tag_ids: Array.from(event.target.selectedOptions, (option) => option.value) })}>{tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select></label>
            <label>1日規定回数（0は無制限）<input type="number" min={0} step={1} value={draft.daily_draw_limit} onChange={(event) => setDraft({ ...draft, daily_draw_limit: Number(event.target.value) })} /></label>
            <label>対象ユーザー<select value={draft.audience_code} onChange={(event) => setDraft({ ...draft, audience_code: event.target.value as AdminGachaComposition["audience_code"] })}><option value="all_users">すべて</option><option value="first_time_users">初回ユーザー</option><option value="line_users">LINEユーザー</option></select></label>
            {draft.audience_code === "first_time_users" ? <label>初回ユーザー期間（日）<input type="number" min={1} max={365} value={draft.first_time_eligible_days} onChange={(event) => setDraft({ ...draft, first_time_eligible_days: Number(event.target.value) })} /></label> : null}
            <fieldset><legend>許可抽選回数</legend>{([1, 5, 10, 100, 1000] as const).map((count) => <label key={count}><input type="checkbox" checked={draft.allowed_draw_counts.includes(count)} onChange={(event) => setDraft({ ...draft, allowed_draw_counts: event.target.checked ? [...draft.allowed_draw_counts, count].sort((first, second) => first - second) : draft.allowed_draw_counts.filter((value) => value !== count) })} />{count}回</label>)}</fieldset>
          </> : null}
          <div className="catalog-form-grid">
          <label>公開開始日時（JST）<input required type="datetime-local" value={jstInput(draft.publish_start_at)} onChange={(event) => setDraft({ ...draft, publish_start_at: jstTimestamp(event.target.value) })} /></label>
          <label>公開終了日時（JST・任意）<input type="datetime-local" value={jstInput(draft.publish_end_at)} onChange={(event) => setDraft({ ...draft, publish_end_at: jstTimestamp(event.target.value) })} /></label>
          </div>
          <label>説明<textarea value={draft.description ?? ""} maxLength={10000} onChange={(event) => setDraft({ ...draft, description: event.target.value || null })} /></label>
          <label>注意事項<textarea value={draft.notices ?? ""} maxLength={10000} onChange={(event) => setDraft({ ...draft, notices: event.target.value || null })} /></label>
        </fieldset>
        <fieldset disabled={locked || busy}><legend>景品と演出</legend>
          {draft.prizes.map((prize, index) => <section className="catalog-prize-fieldset" key={prizeKeys[index]}>
            <h3>景品 {index + 1}</h3>
            <ExternalIdField value={prize.external_id ?? ""} onChange={(value) => { externalIdTouched.current.add(prizeKeys[index]); updatePrize(index, { external_id: value }); }} />
            <label>景品名<input required maxLength={191} value={prize.name} onChange={(event) => updatePrize(index, { name: event.target.value })} /></label>
            <CatalogBannerAssetPicker assetId={prize.presentation_asset_id || null} disabled={locked || busy}
              onSelectionChange={(selection) => updatePrize(index, { presentation_asset_id: selection.assetId ?? "", ...(!externalIdTouched.current.has(prizeKeys[index]) && !prize.external_id?.trim() && selection.externalId ? { external_id: selection.externalId } : {}) })} />
            <label>ランク<select aria-label="ランク" required value={prize.rank_id} onChange={(event) => {
              const rankId = event.target.value;
              setDraft((current) => ({ ...current, prizes: current.prizes.map((item, position) => position === index ? { ...item, rank_id: rankId } : item),
                ranks: current.ranks.some((rank) => rank.rank_id === rankId) ? current.ranks : [...current.ranks, { rank_id: rankId, rank_revision_number: null, video_asset_id: defaultVideoId }] }));
            }}>{ranks.map((rank) => <option key={rank.id} value={rank.id}>{draft.ranks.find((item) => item.rank_id === rank.id)?.presentation?.name ?? rank.rank_name}</option>)}</select></label>
            <label>交換ポイント<input required type="number" min={draft.minimum_exchange_points ?? 0} step={1} value={prize.exchange_points} onChange={(event) => updatePrize(index, { exchange_points: Number(event.target.value) })} /></label>
            <label>原価<input required type="number" min={0} step={1} value={prize.cost_price} onChange={(event) => updatePrize(index, { cost_price: Number(event.target.value) })} /></label>
            <label>初期在庫<input required type="number" min={0} max={2147483647} step={1} value={prize.initial_inventory} onChange={(event) => updatePrize(index, { initial_inventory: Number(event.target.value) })} /></label>
            {login ? <label>固定当選確率（%・小数10桁まで）<input required type="text" inputMode="decimal" value={prize.percentage ?? ""} onChange={(event) => updatePrize(index, { percentage: event.target.value })} /></label>
              : <label><input type="checkbox" checked={prize.shipping_only} onChange={(event) => updatePrize(index, { shipping_only: event.target.checked })} />発送専用</label>}
            {!locked ? <button type="button" className="secondary-button" onClick={() => {
              setPrizeKeys((current) => current.filter((_, position) => position !== index));
              setDraft({ ...draft, prizes: draft.prizes.filter((_, position) => position !== index) });
            }}>景品を削除</button> : null}
          </section>)}
          {!locked ? <button type="button" className="secondary-button" disabled={!ranks.length} onClick={addPrize}>景品を追加</button> : null}
          {draft.ranks.filter((rank) => draft.prizes.some((prize) => prize.rank_id === rank.rank_id)).map((rank) => <label className="catalog-rank-video-control" key={rank.rank_id}>
            {rank.presentation?.name ?? ranks.find((master) => master.id === rank.rank_id)?.rank_name ?? "ランク"}の演出動画
            <select aria-label={`${rank.presentation?.name ?? ranks.find((master) => master.id === rank.rank_id)?.rank_name ?? "ランク"}の演出動画`} required value={rank.video_asset_id ?? ""} onChange={(event) => setDraft({ ...draft, ranks: draft.ranks.map((item) => item.rank_id === rank.rank_id ? { ...item, video_asset_id: event.target.value || null } : item) })}>
              <option value="">選択してください</option>{videoOptions.map((asset) => <option key={asset.id} value={asset.id}>{asset.alt_text ?? asset.id}</option>)}
            </select>
            {rank.rank_revision_number ? <span>参照ランクリビジョン: {rank.rank_revision_number}</span> : null}
            {rank.video_asset_id
              ? <PublicAssetPreview allowAuthenticatedContent asset={assets.find((asset) => asset.id === rank.video_asset_id) ?? null} />
              : <span>未設定</span>}
            {(rank.presentation ? [rank.presentation.lineup_image, rank.presentation.result_image] : []).map((asset, index) =>
              <PublicAssetPreview allowAuthenticatedContent key={`${asset.id}:${index}`} asset={{ id: asset.id, public_path: asset.path, mime_type: asset.mime_type, alt_text: asset.alt_text, media_type: "image", is_public: true }} />)}
          </label>)}
        </fieldset>
        {login ? <p role="status">確率合計: {total.label} {total.valid ? "（100%・保存可能）" : "（各景品を正の値、合計を厳密に100%にしてください）"}</p> : null}
        {!locked ? <div className="catalog-dialog-actions"><button type="button" className="secondary-button" disabled={busy} onClick={() => router.push("/catalog/gachas")}>取り消し</button><button type="submit" className="primary-button" disabled={busy || (login && !total.valid) || !draft.prizes.length}>構成を一括保存</button></div> : null}
      </form>
      </CatalogGachaFormCard>
      {sourceId && !copy && gacha?.first_published_at ? <section className="catalog-detail"><h2>運用在庫</h2><p>初期在庫は変更しません。いずれかの景品が0になるとTOP非表示・抽選停止になり、補充で自動復帰します。販売停止中は編集できません。在庫0でも販売再開でき、補充までは非表示・抽選不可です。</p>
        {inventory.map((prize) => <form key={prize.id} className="catalog-toolbar" onSubmit={(event) => {
          event.preventDefault();
          const change = adjustments[prize.id];
          if (!change || !Number.isSafeInteger(Number(change.quantity))) return;
          void act(async () => {
            const body = { expected_revision: prize.inventory_revision ?? 0, available_quantity: Number(change.quantity), reason: change.reason };
            await client.updateLoginGachaInventory(sourceId, prize.id, body, mutationKey(JSON.stringify({ prize: prize.id, ...body })));
            setReload((value) => value + 1);
          });
        }}><strong>{prize.name}</strong><span>当選済み: {prize.awarded_inventory ?? 0}</span>
          <label>利用可能在庫<input required disabled={busy || !canManage || gacha.publication_status !== "published"} type="number" min={0} max={2147483647} step={1} value={adjustments[prize.id]?.quantity ?? prize.available_inventory} onChange={(event) => setAdjustments({ ...adjustments, [prize.id]: { quantity: event.target.value, reason: adjustments[prize.id]?.reason ?? "" } })} /></label>
          <label>調整理由<input required maxLength={500} disabled={busy || !canManage || gacha.publication_status !== "published"} value={adjustments[prize.id]?.reason ?? ""} onChange={(event) => setAdjustments({ ...adjustments, [prize.id]: { quantity: adjustments[prize.id]?.quantity ?? String(prize.available_inventory), reason: event.target.value } })} /></label>
          <button type="submit" className="secondary-button" disabled={busy || !canManage || gacha.publication_status !== "published"}>在庫を調整</button>
        </form>)}
      </section> : null}
    </>}
  </div></ProtectedAdminRoute></AdminShell>;
}

function normalizeError(cause: unknown): AdminApiError {
  return cause instanceof AdminApiError ? cause : new AdminApiError(500, "ADMIN_API_UNAVAILABLE", null, null, true);
}

async function includeReferences<Value extends { id: string }>(items: Value[], identifiers: string[], read: (identifier: string) => Promise<{ data: Value }>): Promise<Value[]> {
  const missing = [...new Set(identifiers)].filter((identifier) => !items.some((item) => item.id === identifier));
  const result = [...items];
  for (let offset = 0; offset < missing.length; offset += 16) {
    const responses = await Promise.all(missing.slice(offset, offset + 16).map(read));
    result.push(...responses.map((response) => response.data));
  }
  return result;
}
