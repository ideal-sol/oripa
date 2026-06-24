"use client";

import { ChangeEvent, FormEvent, MouseEvent, ReactNode, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";

type ApiCollection<T> = {
  data: T[];
  meta?: PaginationMeta;
};

type PaginationMeta = {
  current_page: number;
  last_page: number;
  from: number | null;
  to: number | null;
  total: number;
};

type User = {
  id: number;
  name: string;
  email: string;
  referral_code?: string | null;
  status: string;
  email_verified_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  point_lots_count?: number;
  point_ledgers_count?: number;
  wallet?: {
    paid_balance: number;
    free_balance: number;
    total_balance: number;
  };
  profile?: {
    id: number;
    last_name: string | null;
    first_name: string | null;
    last_name_kana: string | null;
    first_name_kana: string | null;
    postal_code: string | null;
    prefecture: string | null;
    city: string | null;
    address_line1: string | null;
    address_line2: string | null;
    phone_number: string | null;
    birth_date: string | null;
  } | null;
};

type DrawRequest = {
  id: number;
  user_id: number;
  gacha_id: number;
  draw_count: number;
  status: string;
  consumed_point_total: number;
  results_count?: number;
  user?: User;
  gacha?: { id: number; title: string; slug: string; price: number };
  created_at: string;
};

type UserPrize = {
  id: number;
  user_id: number;
  status: string;
  converted_point: number | null;
  acquired_at: string | null;
  storage_expire_at: string | null;
  user?: User;
  gacha?: { id: number; title: string };
  prize?: {
    id: number;
    name: string;
    exchange_point: number;
    rank?: { display_name: string };
  };
};

type ShippingRequest = {
  id: number;
  user_id: number;
  status: string;
  recipient_name: string;
  postal_code: string | null;
  prefecture: string;
  city: string;
  address_line1: string | null;
  address_line2: string | null;
  phone_number: string | null;
  tracking_number: string | null;
  items_count?: number;
  user?: User;
  items?: ShippingItem[];
  histories?: ShippingHistory[];
  requested_at: string | null;
  shipped_at: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

type ShippingItem = {
  id: number;
  shipping_request_id: number;
  user_prize_id: number;
  status: string;
  tracking_number: string | null;
  shipped_at: string | null;
  user_prize?: UserPrize;
};

type ShippingItemRow = {
  request: ShippingRequest;
  item?: ShippingItem;
};

type ShippingHistory = {
  id: number;
  shipping_request_id: number;
  from_status: string | null;
  to_status: string;
  tracking_number: string | null;
  note: string | null;
  admin_user?: AdminSession["admin"];
  created_at: string | null;
};

type Payment = {
  id: number;
  provider: string;
  provider_payment_id: string;
  status: string;
  amount: number;
  paid_point_amount: number;
  free_point_amount: number;
  user?: User;
  paid_at: string | null;
  created_at: string;
};

type PointPurchasePlan = {
  id: number;
  name: string;
  amount: number;
  paid_point_amount: number;
  free_point_amount: number;
  sort_order: number;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

type PointAdjustment = {
  id: number;
  user_id: number;
  adjustment_type: string;
  point_type: string | null;
  amount: number;
  expire_at: string | null;
  reason: string;
  user?: User;
  created_at: string;
};

type ReferralSetting = {
  id: number;
  reward_point_amount: number;
  reward_expiration_days: number | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

type UserReferral = {
  id: number;
  referrer_user_id: number;
  referred_user_id: number;
  referral_code: string;
  status: string;
  reward_point_amount: number;
  reward_expiration_days: number | null;
  rewarded_at: string | null;
  canceled_at: string | null;
  referrer?: User;
  referred?: User;
  created_at: string | null;
  updated_at: string | null;
};

type ContactRequest = {
  id: number;
  name: string;
  email: string;
  phone: string;
  body: string;
  status: string;
  reply_body: string | null;
  replied_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

type StaticPage = {
  id: number;
  slug: string;
  title: string;
  body: string;
  status: string;
  published_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

type RankAsset = {
  id: number;
  title: string;
  asset_type: "image" | "video";
  url: string;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

type Announcement = {
  id: number;
  title: string;
  body: string;
  thumbnail_url: string | null;
  show_on_top_slider: boolean;
  status: string;
  published_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

type TopBanner = {
  id: number;
  image_url: string;
  link_url: string | null;
  sort_order: number;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

type Gacha = {
  id: number;
  title: string;
  slug: string;
  category_id: number;
  category?: { id: number | null; name: string | null; slug: string | null };
  tags?: GachaTag[];
  tag_ids?: number[];
  price: number;
  total_count: number;
  daily_draw_limit: number | null;
  sold_count: number;
  remaining_count: number;
  probability_mode: string;
  current_probability_version_id: number | null;
  current_probability_version?: {
    id: number;
    version_number: number;
    status: string;
    published_at: string | null;
  } | null;
  minimum_guarantee: {
    type: string;
    value: number;
    cost: number;
  };
  status: string;
  start_at: string | null;
  end_at: string | null;
  description: string | null;
  caution: string | null;
  main_image_url: string | null;
  show_on_top_slider: boolean;
  target_margin: number | null;
  ranks_count?: number;
  prizes_count?: number;
  ranks?: GachaRank[];
};

type GachaCategory = {
  id: number;
  name: string;
  slug: string;
  sort_order: number;
  is_visible: boolean;
};

type GachaTag = {
  id: number;
  name: string;
  slug: string;
  sort_order: number;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};

type GachaReadiness = {
  gacha_id: number;
  ready: boolean;
  checks: {
    key: string;
    label: string;
    passed: boolean;
    message: string | null;
    severity?: "blocking" | "warning";
  }[];
};

type GachaRank = {
  id: number;
  gacha_id: number;
  gacha?: { id: number; title: string; slug: string; status: string };
  rank_key: string;
  display_name: string;
  description: string | null;
  image_url: string | null;
  rank_image_asset_id: number | null;
  rank_image_asset?: RankAsset | null;
  rank_image_asset_ids?: number[];
  rank_image_assets?: RankAsset[];
  draw_video_url: string | null;
  draw_video_asset_id: number | null;
  draw_video_asset?: RankAsset | null;
  draw_video_asset_ids?: number[];
  draw_video_assets?: RankAsset[];
  result_image_url: string | null;
  sort_order: number;
  is_visible: boolean;
  prizes_count?: number;
  prizes?: GachaPrize[];
};

type GachaPrize = {
  id: number;
  gacha_id: number;
  rank_id: number;
  gacha?: { id: number; title: string; slug: string; status: string };
  rank?: { id: number; display_name: string; rank_key: string };
  name: string;
  image_url: string;
  max_win_count: number;
  won_count: number;
  remaining_win_count: number;
  cost_price: number;
  display_price: number | null;
  exchange_point: number | null;
  condition: string;
  is_active: boolean;
  is_visible: boolean;
  sort_order: number;
};

type AssetContext = "gacha" | "rank" | "prize" | "draw-video" | "announcement" | "top-banner";

type UploadedAsset = {
  path: string;
  url: string;
};

type ProbabilityPreview = {
  data: {
    valid: boolean;
    gacha_id: number;
    total_ppm: number;
    stages: ProbabilityStagePayload[];
  };
};

type ProfitSimulation = {
  gacha_id: number;
  sales: {
    price: number;
    total_count: number;
    sold_count: number;
    remaining_count: number;
    total_sales: number;
    sold_sales: number;
    remaining_sales: number;
  };
  costs: {
    prize_inventory_cost: number;
    prize_awarded_cost: number;
    prize_remaining_cost: number;
    minimum_guarantee_max_cost: number;
    max_cost: number;
  };
  profit: {
    projected_profit: number;
    projected_margin_rate: number | null;
    target_margin_rate: number | null;
    target_profit: number | null;
    gap_to_target_profit: number | null;
    meets_target: boolean | null;
  };
  expected: {
    available: boolean;
    probability_version_id: number | null;
    expected_cost_per_draw: number | null;
    expected_total_cost: number | null;
    expected_profit: number | null;
    expected_margin_rate: number | null;
    stages: {
      stage_key: string;
      name: string;
      draw_count: number;
      expected_cost_per_draw: number;
      expected_total_cost: number;
    }[];
  };
  warnings: string[];
};

type ProbabilityMatrix = {
  data: {
    stages: ProbabilityStagePayload[];
  };
};

type ProbabilityStageForm = {
  uid: string;
  stageKey: string;
  name: string;
  minDrawNumber: string;
  maxDrawNumber: string;
  sortOrder: string;
  minimumGuaranteePpm: string;
  rows: Record<string, string>;
};

type ProbabilityStagePayload = {
  stage_key: string;
  name: string;
  condition_type?: string;
  min_draw_number: number;
  max_draw_number: number | null;
  sort_order: number;
  total_ppm?: number;
  minimum_guarantee_ppm?: number;
  prize_count?: number;
  probabilities: ProbabilityRowPayload[];
};

type ProbabilityRowPayload = {
  prize_id?: number | null;
  is_minimum_guarantee?: boolean;
  probability_ppm: number;
};

type AdminSession = {
  access_token: string;
  admin: {
    id: number;
    name: string;
    email: string;
  };
};

type AdminDataCache = {
  savedAt: number;
  announcements: Announcement[];
  contacts: ContactRequest[];
  staticPages: StaticPage[];
  rankAssets: RankAsset[];
  categories: GachaCategory[];
  tags: GachaTag[];
  topBanners: TopBanner[];
  gachas: Gacha[];
  users: User[];
  gachaRanks: GachaRank[];
  gachaPrizes: GachaPrize[];
  draws: DrawRequest[];
  prizes: UserPrize[];
  shipping: ShippingRequest[];
  payments: Payment[];
  purchasePlans: PointPurchasePlan[];
  pointAdjustments: PointAdjustment[];
  referralSetting: ReferralSetting | null;
  referrals: UserReferral[];
};

type TabKey = "guide" | "announcements" | "contacts" | "gachas" | "users" | "draws" | "prizes" | "shipping" | "payments" | "purchasePlans" | "points" | "settings";
type FilterState = Record<string, string>;
type NoticeTone = "success" | "error" | "info";
type AnnouncementView = "list" | "new" | "edit";
type PointView = "list" | "new";
type ContactView = "list" | "edit";
type SettingView = "list" | "pages" | "edit" | "rank-assets" | "rank-asset-new" | "rank-asset-edit" | "referral";
type PurchasePlanView = "list" | "new" | "edit";
type UserManagementView = "list" | "detail";
type ShippingView = "list" | "edit";
type GachaAdminView =
  | "gacha-list"
  | "gacha-new"
  | "gacha-edit"
  | "rank-list"
  | "rank-new"
  | "rank-edit"
  | "category-list"
  | "category-new"
  | "category-edit"
  | "tag-list"
  | "tag-new"
  | "tag-edit"
  | "top-banner-list"
  | "top-banner-new"
  | "top-banner-edit"
  | "prize-list"
  | "prize-new"
  | "prize-edit";

const tabs: { key: TabKey; label: string; short: string; description: string }[] = [
  { key: "guide", label: "操作ガイド", short: "?", description: "管理画面の確認手順" },
  { key: "announcements", label: "お知らせ", short: "I", description: "トップINFOMATION管理" },
  { key: "contacts", label: "お問い合わせ", short: "C", description: "問い合わせ確認と返信" },
  { key: "gachas", label: "ガチャ管理", short: "G", description: "商品・景品・確率設定" },
  { key: "users", label: "ユーザー管理", short: "U", description: "会員情報と利用状況" },
  { key: "shipping", label: "配送", short: "S", description: "配送依頼と追跡" },
  { key: "payments", label: "決済", short: "B", description: "購入・返金・状態" },
  { key: "purchasePlans", label: "購入プラン", short: "L", description: "ポイント購入プラン管理" },
  { key: "points", label: "ポイント", short: "W", description: "付与・減算の記録" },
  { key: "settings", label: "設定", short: "S", description: "規約ページ編集" },
];
const gachaSubViews: { key: GachaAdminView; label: string }[] = [
  { key: "gacha-list", label: "ガチャ一覧" },
  { key: "rank-list", label: "ランク一覧" },
  { key: "category-list", label: "カテゴリ一覧" },
  { key: "tag-list", label: "タグ一覧" },
  { key: "top-banner-list", label: "トップバナー一覧" },
  { key: "prize-list", label: "景品一覧" },
];

const adminApiBase = process.env.NEXT_PUBLIC_ADMIN_API_BASE_URL ?? "/admin/api";
const tabKeys: TabKey[] = [...tabs.map((tab) => tab.key), "draws", "prizes"];
const perPage = 10;
const emptyFilters: Record<TabKey, FilterState> = {
  guide: {},
  announcements: { status: "" },
  contacts: { status: "", email: "" },
  gachas: { status: "" },
  users: { status: "", q: "" },
  draws: { user_id: "", gacha_id: "", status: "" },
  prizes: { user_id: "", gacha_id: "", status: "" },
  shipping: { user_id: "", status: "" },
  payments: { user_id: "", status: "", provider: "" },
  purchasePlans: { is_active: "" },
  points: { user_id: "", adjustment_type: "", point_type: "" },
  settings: {},
};

export default function AdminDashboard({
  initialSession,
  initialTab,
  initialGachaView,
  initialGachaEntityId,
  initialSubView,
  initialEntityId,
  initialParentEntityId,
}: {
  initialSession?: AdminSession | null;
  initialTab?: string;
  initialGachaView?: string;
  initialGachaEntityId?: string;
  initialSubView?: string;
  initialEntityId?: string;
  initialParentEntityId?: string;
}) {
  const router = useRouter();
  const routeEntityId = useMemo(() => parsePositiveInt(initialEntityId), [initialEntityId]);
  const routeParentEntityId = useMemo(() => parsePositiveInt(initialParentEntityId), [initialParentEntityId]);
  const [session, setSession] = useState<AdminSession | null>(initialSession?.access_token ? initialSession : null);
  const [authReady, setAuthReady] = useState(Boolean(initialSession?.access_token));
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [activeTab, setActiveTab] = useState<TabKey>(() => resolveTab(initialTab));
  const [activeAnnouncementView, setActiveAnnouncementView] = useState<AnnouncementView>(() => resolveAnnouncementView(initialSubView));
  const [activePointView, setActivePointView] = useState<PointView>(() => resolvePointView(initialSubView));
  const [activeContactView, setActiveContactView] = useState<ContactView>(() => resolveContactView(initialSubView));
  const [activeSettingView, setActiveSettingView] = useState<SettingView>(() => resolveSettingView(initialSubView));
  const [activePurchasePlanView, setActivePurchasePlanView] = useState<PurchasePlanView>(() => resolvePurchasePlanView(initialSubView));
  const [activeUserView, setActiveUserView] = useState<UserManagementView>(() => resolveUserView(initialSubView));
  const [activeShippingView, setActiveShippingView] = useState<ShippingView>(() => resolveShippingView(initialSubView));
  const [activeGachaView, setActiveGachaView] = useState<GachaAdminView>(() => resolveGachaView(initialGachaView));
  const initialEditId = useMemo(() => parsePositiveInt(initialGachaEntityId), [initialGachaEntityId]);
  const restoredInitialEditRef = useRef(false);
  const restoredRouteRef = useRef("");
  const restoreInitialEditRef = useRef<() => Promise<void> | void>(() => undefined);
  const cachedAdminData = useMemo(() => readAdminDataCache(), []);
  const [loading, setLoading] = useState(false);
  const [hasLoadedAdminData, setHasLoadedAdminData] = useState(Boolean(cachedAdminData));
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<NoticeTone>("info");
  const [draws, setDraws] = useState<DrawRequest[]>(cachedAdminData?.draws ?? []);
  const [users, setUsers] = useState<User[]>(cachedAdminData?.users ?? []);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [selectedUserPrizes, setSelectedUserPrizes] = useState<UserPrize[]>([]);
  const [selectedUserDraws, setSelectedUserDraws] = useState<DrawRequest[]>([]);
  const [selectedUserPayments, setSelectedUserPayments] = useState<Payment[]>([]);
  const [selectedUserPointAdjustments, setSelectedUserPointAdjustments] = useState<PointAdjustment[]>([]);
  const [selectedUserReferrals, setSelectedUserReferrals] = useState<UserReferral[]>([]);
  const [gachas, setGachas] = useState<Gacha[]>(cachedAdminData?.gachas ?? []);
  const [gachaRanks, setGachaRanks] = useState<GachaRank[]>(cachedAdminData?.gachaRanks ?? []);
  const [gachaPrizes, setGachaPrizes] = useState<GachaPrize[]>(cachedAdminData?.gachaPrizes ?? []);
  const [categories, setCategories] = useState<GachaCategory[]>(cachedAdminData?.categories ?? []);
  const [tags, setTags] = useState<GachaTag[]>(cachedAdminData?.tags ?? []);
  const [topBanners, setTopBanners] = useState<TopBanner[]>(cachedAdminData?.topBanners ?? []);
  const [selectedGacha, setSelectedGacha] = useState<Gacha | null>(null);
  const [readiness, setReadiness] = useState<GachaReadiness | null>(null);
  const [prizes, setPrizes] = useState<UserPrize[]>(cachedAdminData?.prizes ?? []);
  const [shipping, setShipping] = useState<ShippingRequest[]>(cachedAdminData?.shipping ?? []);
  const [selectedShippingRequest, setSelectedShippingRequest] = useState<ShippingRequest | null>(null);
  const [selectedShippingItem, setSelectedShippingItem] = useState<ShippingItem | null>(null);
  const [payments, setPayments] = useState<Payment[]>(cachedAdminData?.payments ?? []);
  const [purchasePlans, setPurchasePlans] = useState<PointPurchasePlan[]>(cachedAdminData?.purchasePlans ?? []);
  const [pointAdjustments, setPointAdjustments] = useState<PointAdjustment[]>(cachedAdminData?.pointAdjustments ?? []);
  const [referralSetting, setReferralSetting] = useState<ReferralSetting | null>(cachedAdminData?.referralSetting ?? null);
  const [referrals, setReferrals] = useState<UserReferral[]>(cachedAdminData?.referrals ?? []);
  const [announcements, setAnnouncements] = useState<Announcement[]>(cachedAdminData?.announcements ?? []);
  const [contacts, setContacts] = useState<ContactRequest[]>(cachedAdminData?.contacts ?? []);
  const [selectedContact, setSelectedContact] = useState<ContactRequest | null>(null);
  const [staticPages, setStaticPages] = useState<StaticPage[]>(cachedAdminData?.staticPages ?? []);
  const [rankAssets, setRankAssets] = useState<RankAsset[]>(cachedAdminData?.rankAssets ?? []);
  const [selectedStaticPage, setSelectedStaticPage] = useState<StaticPage | null>(null);
  const [filters, setFilters] = useState<Record<TabKey, FilterState>>(emptyFilters);
  const [pages, setPages] = useState<Record<TabKey, number>>({
    guide: 1,
    announcements: 1,
    contacts: 1,
    gachas: 1,
    users: 1,
    draws: 1,
    prizes: 1,
    shipping: 1,
    payments: 1,
    purchasePlans: 1,
    points: 1,
    settings: 1,
  });
  const [pagination, setPagination] = useState<Record<TabKey, PaginationMeta | null>>({
    guide: null,
    announcements: null,
    contacts: null,
    gachas: null,
    users: null,
    draws: null,
    prizes: null,
    shipping: null,
    payments: null,
    purchasePlans: null,
    points: null,
    settings: null,
  });
  const [pointForm, setPointForm] = useState({
    userId: "",
    adjustmentType: "grant",
    pointType: "paid",
    amount: "",
    expireAt: "",
    reason: "",
  });
  const [userPointForm, setUserPointForm] = useState({
    adjustmentType: "grant",
    pointType: "paid",
    amount: "",
    expireAt: "",
    reason: "",
  });
  const [userStatusForm, setUserStatusForm] = useState({
    status: "active",
  });
  const [purchasePlanForm, setPurchasePlanForm] = useState({
    id: "",
    name: "",
    amount: "",
    paidPointAmount: "",
    freePointAmount: "0",
    sortOrder: "1",
    isActive: true,
    startsAt: "",
    endsAt: "",
  });
  const [contactReplyForm, setContactReplyForm] = useState({
    status: "new",
    replyBody: "",
  });
  const [shippingForm, setShippingForm] = useState({
    status: "requested",
    trackingNumber: "",
    shippedAt: "",
    note: "",
  });
  const [staticPageForm, setStaticPageForm] = useState({
    title: "",
    body: "",
  });
  const [selectedRankAsset, setSelectedRankAsset] = useState<RankAsset | null>(null);
  const [rankAssetForm, setRankAssetForm] = useState({
    title: "",
    assetType: "image",
    url: "",
    isActive: true,
  });
  const [referralSettingForm, setReferralSettingForm] = useState({
    rewardPointAmount: "0",
    rewardExpirationDays: "",
    isActive: true,
  });
  const [referralSearchForm, setReferralSearchForm] = useState({
    userId: "",
  });
  const [announcementForm, setAnnouncementForm] = useState({
    id: "",
    title: "",
    body: "",
    thumbnailUrl: "",
    showOnTopSlider: false,
    status: "draft",
    publishedAt: "",
  });
  const [categoryForm, setCategoryForm] = useState({
    id: "",
    name: "",
    slug: "",
    sortOrder: "1",
    isVisible: true,
  });
  const [tagForm, setTagForm] = useState({
    id: "",
    name: "",
    slug: "",
    sortOrder: "1",
    isActive: true,
  });
  const [topBannerForm, setTopBannerForm] = useState({
    id: "",
    imageUrl: "",
    linkUrl: "",
    sortOrder: "1",
    isActive: true,
  });
  const [gachaForm, setGachaForm] = useState({
    id: "",
    title: "",
    slug: "",
    categoryId: "",
    price: "500",
    totalCount: "10000",
    dailyDrawLimit: "",
    probabilityMode: "single",
    minimumGuaranteeType: "point",
    minimumGuaranteeValue: "10",
    minimumGuaranteeCost: "10",
    status: "draft",
    startAt: "",
    endAt: "",
    description: "",
    caution: "",
    mainImageUrl: "",
    showOnTopSlider: false,
    targetMargin: "",
    tagIds: [] as string[],
  });
  const [rankForm, setRankForm] = useState({
    id: "",
    rankKey: "",
    displayName: "",
    description: "",
    imageUrl: "",
    rankImageAssetId: "",
    rankImageAssetIds: [] as string[],
    drawVideoUrl: "",
    drawVideoAssetId: "",
    drawVideoAssetIds: [] as string[],
    resultImageUrl: "",
    sortOrder: "1",
    isVisible: true,
  });
  const [prizeForm, setPrizeForm] = useState({
    id: "",
    rankId: "",
    name: "",
    imageUrl: "",
    maxWinCount: "",
    costPrice: "",
    displayPrice: "",
    exchangePoint: "",
    condition: "新品",
    isActive: true,
    isVisible: true,
    sortOrder: "1",
  });
  const [probabilityStages, setProbabilityStages] = useState<ProbabilityStageForm[]>([]);
  const [probabilityPreview, setProbabilityPreview] = useState<ProbabilityPreview["data"] | null>(null);
  const [profitSimulation, setProfitSimulation] = useState<ProfitSimulation | null>(null);

  const showMessage = useCallback((tone: NoticeTone, text: string) => {
    setMessageTone(tone);
    setMessage(text);
  }, []);

  const clearMessage = useCallback(() => {
    setMessage("");
    setMessageTone("info");
  }, []);

  useEffect(() => {
    if (authReady) {
      return;
    }

    const timerId = window.setTimeout(() => {
      const raw = window.localStorage.getItem("oripa_admin_session");

      if (!raw) {
        setAuthReady(true);
        return;
      }

      try {
        const parsed = JSON.parse(raw) as Partial<AdminSession>;

        if (typeof parsed.access_token === "string" && parsed.admin?.email) {
          const restoredSession = parsed as AdminSession;
          window.localStorage.setItem("oripa_admin_session", JSON.stringify(restoredSession));
          document.cookie = `oripa_admin_session=${encodeURIComponent(JSON.stringify(restoredSession))}; path=/; max-age=86400; SameSite=Lax; secure`;
          setSession(restoredSession);
        }
      } catch {
        window.localStorage.removeItem("oripa_admin_session");
      } finally {
        setAuthReady(true);
      }
    }, 0);

    return () => window.clearTimeout(timerId);
  }, [authReady]);

  useEffect(() => {
    setActiveTab(resolveTab(initialTab));
    setActiveGachaView(resolveGachaView(initialGachaView));
    setActiveAnnouncementView(resolveAnnouncementView(initialSubView));
    setActivePointView(resolvePointView(initialSubView));
    setActiveContactView(resolveContactView(initialSubView));
    setActiveSettingView(resolveSettingView(initialSubView));
    setActivePurchasePlanView(resolvePurchasePlanView(initialSubView));
    setActiveUserView(resolveUserView(initialSubView));
    setActiveShippingView(resolveShippingView(initialSubView));
    restoredInitialEditRef.current = false;
    restoredRouteRef.current = "";
  }, [
    initialEditId,
    initialGachaView,
    initialSubView,
    initialTab,
    routeEntityId,
    routeParentEntityId,
  ]);

  const setTabData = useCallback(<T,>(tab: TabKey, response: ApiCollection<T>) => {
    if (tab === "announcements") {
      setAnnouncements(response.data as Announcement[]);
    }
    if (tab === "contacts") {
      setContacts(response.data as ContactRequest[]);
    }
    if (tab === "settings") {
      setStaticPages(response.data as StaticPage[]);
    }
    if (tab === "gachas") {
      setGachas(response.data as Gacha[]);
    }
    if (tab === "users") {
      setUsers(response.data as User[]);
    }
    if (tab === "draws") {
      setDraws(response.data as DrawRequest[]);
    }
    if (tab === "prizes") {
      setPrizes(response.data as UserPrize[]);
    }
    if (tab === "shipping") {
      setShipping(response.data as ShippingRequest[]);
    }
    if (tab === "payments") {
      setPayments(response.data as Payment[]);
    }
    if (tab === "purchasePlans") {
      setPurchasePlans(response.data as PointPurchasePlan[]);
    }
    if (tab === "points") {
      setPointAdjustments(response.data as PointAdjustment[]);
    }

    setPagination((current) => ({
      ...current,
      [tab]: response.meta ?? null,
    }));
  }, []);

  const fetchTab = useCallback(async (
    tab: TabKey,
    page: number,
    nextFilters = filters[tab],
    token = session?.access_token,
  ) => {
    if (!token) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<ApiCollection<unknown>>(endpointFor(tab, page, nextFilters), {}, token);
      setTabData(tab, response);
      if (tab === "settings") {
        const assetResponse = await apiRequest<ApiCollection<RankAsset>>("/rank-assets?per_page=100", {}, token);
        setRankAssets(assetResponse.data);
      }
      setPages((current) => ({ ...current, [tab]: response.meta?.current_page ?? page }));
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "データ取得に失敗しました");
    } finally {
      setLoading(false);
    }
  }, [clearMessage, filters, session?.access_token, setTabData, showMessage]);

  const refreshAll = useCallback(async (token = session?.access_token) => {
    if (!token) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const [announcementData, contactData, staticPageData, rankAssetData, referralSettingData, referralData, categoryData, tagData, topBannerData, gachaData, userData, gachaRankData, gachaPrizeData, drawData, prizeData, shippingData, paymentData, purchasePlanData, adjustmentData] = await Promise.all([
        apiRequest<ApiCollection<Announcement>>(endpointFor("announcements", pages.announcements, filters.announcements), {}, token),
        apiRequest<ApiCollection<ContactRequest>>(endpointFor("contacts", pages.contacts, filters.contacts), {}, token),
        apiRequest<ApiCollection<StaticPage>>(endpointFor("settings", pages.settings, filters.settings), {}, token),
        apiRequest<ApiCollection<RankAsset>>("/rank-assets?per_page=100", {}, token),
        apiRequest<{ data: ReferralSetting }>("/referral-settings", {}, token),
        apiRequest<ApiCollection<UserReferral>>("/referrals?per_page=100", {}, token),
        apiRequest<{ data: GachaCategory[] }>("/gacha-categories", {}, token),
        apiRequest<{ data: GachaTag[] }>("/gacha-tags", {}, token),
        apiRequest<{ data: TopBanner[] }>("/top-banners", {}, token),
        apiRequest<ApiCollection<Gacha>>(endpointFor("gachas", pages.gachas, filters.gachas), {}, token),
        apiRequest<ApiCollection<User>>(endpointFor("users", pages.users, filters.users), {}, token),
        apiRequest<ApiCollection<GachaRank>>("/gacha-ranks?per_page=100", {}, token),
        apiRequest<ApiCollection<GachaPrize>>("/gacha-prizes?per_page=100", {}, token),
        apiRequest<ApiCollection<DrawRequest>>(endpointFor("draws", pages.draws, filters.draws), {}, token),
        apiRequest<ApiCollection<UserPrize>>(endpointFor("prizes", pages.prizes, filters.prizes), {}, token),
        apiRequest<ApiCollection<ShippingRequest>>(endpointFor("shipping", pages.shipping, filters.shipping), {}, token),
        apiRequest<ApiCollection<Payment>>(endpointFor("payments", pages.payments, filters.payments), {}, token),
        apiRequest<ApiCollection<PointPurchasePlan>>(endpointFor("purchasePlans", pages.purchasePlans, filters.purchasePlans), {}, token),
        apiRequest<ApiCollection<PointAdjustment>>(endpointFor("points", pages.points, filters.points), {}, token),
      ]);

      setTabData("announcements", announcementData);
      setTabData("contacts", contactData);
      setTabData("settings", staticPageData);
      setRankAssets(rankAssetData.data);
      setReferralSetting(referralSettingData.data);
      setReferralSettingForm({
        rewardPointAmount: String(referralSettingData.data.reward_point_amount),
        rewardExpirationDays: referralSettingData.data.reward_expiration_days === null ? "" : String(referralSettingData.data.reward_expiration_days),
        isActive: referralSettingData.data.is_active,
      });
      setReferrals(referralData.data);
      setCategories(categoryData.data);
      setTags(tagData.data);
      setTopBanners(topBannerData.data);
      setGachaRanks(gachaRankData.data);
      setGachaPrizes(gachaPrizeData.data);
      setTabData("gachas", gachaData);
      setTabData("users", userData);
      setTabData("draws", drawData);
      setTabData("prizes", prizeData);
      setTabData("shipping", shippingData);
      setTabData("payments", paymentData);
      setTabData("purchasePlans", purchasePlanData);
      setTabData("points", adjustmentData);
      writeAdminDataCache({
        announcements: announcementData.data,
        contacts: contactData.data,
        staticPages: staticPageData.data,
        rankAssets: rankAssetData.data,
        referralSetting: referralSettingData.data,
        referrals: referralData.data,
        categories: categoryData.data,
        tags: tagData.data,
        topBanners: topBannerData.data,
        gachas: gachaData.data,
        users: userData.data,
        gachaRanks: gachaRankData.data,
        gachaPrizes: gachaPrizeData.data,
        draws: drawData.data,
        prizes: prizeData.data,
        shipping: shippingData.data,
        payments: paymentData.data,
        purchasePlans: purchasePlanData.data,
        pointAdjustments: adjustmentData.data,
      });
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "データ取得に失敗しました");
    } finally {
      setHasLoadedAdminData(true);
      setLoading(false);
    }
  }, [clearMessage, filters, pages, session?.access_token, setTabData, showMessage]);

  useEffect(() => {
    if (!session) {
      return;
    }

    const timerId = window.setTimeout(() => {
      void refreshAll(session.access_token);
    }, 0);

    return () => window.clearTimeout(timerId);
  }, [refreshAll, session]);

  useEffect(() => {
    if (!session || !initialEditId || restoredInitialEditRef.current || activeTab !== "gachas") {
      return;
    }

    restoredInitialEditRef.current = true;
    void restoreInitialEditRef.current();
  }, [activeGachaView, activeTab, initialEditId, session]);

  useEffect(() => {
    if (!session) {
      return;
    }

    const routeKey = `${activeTab}:${initialSubView ?? ""}:${routeParentEntityId ?? ""}:${routeEntityId ?? ""}`;

    if (restoredRouteRef.current === routeKey) {
      return;
    }

    restoredRouteRef.current = routeKey;

    if (activeTab === "announcements" && activeAnnouncementView === "edit" && routeEntityId) {
      void apiRequest<{ data: Announcement }>(`/announcements/${routeEntityId}`, {}, session.access_token)
        .then((response) => editAnnouncement(response.data))
        .catch((error) => showMessage("error", error instanceof Error ? error.message : "お知らせ詳細の取得に失敗しました"));
    }

    if (activeTab === "contacts" && activeContactView === "edit" && routeEntityId) {
      void apiRequest<{ data: ContactRequest }>(`/contact-requests/${routeEntityId}`, {}, session.access_token)
        .then((response) => editContact(response.data))
        .catch((error) => showMessage("error", error instanceof Error ? error.message : "お問い合わせ詳細の取得に失敗しました"));
    }

    if (activeTab === "purchasePlans" && activePurchasePlanView === "edit" && routeEntityId) {
      void apiRequest<{ data: PointPurchasePlan }>(`/point-purchase-plans/${routeEntityId}`, {}, session.access_token)
        .then((response) => editPurchasePlan(response.data))
        .catch((error) => showMessage("error", error instanceof Error ? error.message : "購入プラン詳細の取得に失敗しました"));
    }

    if (activeTab === "users" && activeUserView === "detail" && routeEntityId) {
      void selectUser(routeEntityId);
    }

    if (activeTab === "shipping" && activeShippingView === "edit" && routeParentEntityId && routeEntityId) {
      void editShippingItem({ id: routeParentEntityId } as ShippingRequest, { id: routeEntityId } as ShippingItem);
    }

    if (activeTab === "settings" && activeSettingView === "edit" && routeEntityId) {
      void apiRequest<{ data: StaticPage }>(`/static-pages/${routeEntityId}`, {}, session.access_token)
        .then((response) => editStaticPage(response.data))
        .catch((error) => showMessage("error", error instanceof Error ? error.message : "設定ページ詳細の取得に失敗しました"));
    }

    if (activeTab === "settings" && activeSettingView === "rank-asset-edit" && routeEntityId) {
      void apiRequest<{ data: RankAsset }>(`/rank-assets/${routeEntityId}`, {}, session.access_token)
        .then((response) => editRankAsset(response.data))
        .catch((error) => showMessage("error", error instanceof Error ? error.message : "ランク演出設定の取得に失敗しました"));
    }
    // URL復元は routeKey で多重実行を止めるため、イベントハンドラ関数は依存に含めない。
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    activeAnnouncementView,
    activeContactView,
    activePurchasePlanView,
    activeSettingView,
    activeShippingView,
    activeTab,
    activeUserView,
    initialSubView,
    routeEntityId,
    routeParentEntityId,
    session,
    showMessage,
  ]);

  const summary = useMemo(() => ({
    gachas: gachas.length,
    draws: draws.length,
    prizes: prizes.length,
    shipping: shipping.length,
    payments: payments.length,
    adjustments: pointAdjustments.length,
    announcements: announcements.length,
    contacts: contacts.length,
    users: users.length,
  }), [announcements.length, contacts.length, draws.length, gachas.length, payments.length, pointAdjustments.length, prizes.length, shipping.length, users.length]);
  const selectedUserReceivedReferral = selectedUser
    ? selectedUserReferrals.find((referral) => referral.referred_user_id === selectedUser.id) ?? null
    : null;
  const selectedUserMadeReferrals = selectedUser
    ? selectedUserReferrals.filter((referral) => referral.referrer_user_id === selectedUser.id)
    : [];

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    clearMessage();

    try {
      const data = await apiRequest<AdminSession>("/login", {
        method: "POST",
        body: JSON.stringify({
          email,
          password,
          device_name: "admin-dashboard",
        }),
      });
      window.localStorage.setItem("oripa_admin_session", JSON.stringify(data));
      document.cookie = `oripa_admin_session=${encodeURIComponent(JSON.stringify(data))}; path=/; max-age=86400; SameSite=Lax; secure`;
      setSession(data);
      setAuthReady(true);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ログインに失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function logout() {
    window.localStorage.removeItem("oripa_admin_session");
    document.cookie = "oripa_admin_session=; path=/; max-age=0; SameSite=Lax; secure";
    setSession(null);
    setAuthReady(true);
    window.location.href = "/admin-logout";
  }

  async function submitPointAdjustment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();

    const payload: Record<string, string | number> = {
      adjustment_type: pointForm.adjustmentType,
      amount: Number(pointForm.amount),
      reason: pointForm.reason,
    };

    if (pointForm.adjustmentType === "grant") {
      payload.point_type = pointForm.pointType;
      if (pointForm.pointType === "free") {
        payload.expire_at = pointForm.expireAt;
      }
    }

    try {
      await apiRequest<PointAdjustment>(`/users/${pointForm.userId}/point-adjustments`, {
        method: "POST",
        body: JSON.stringify(payload),
      }, session.access_token);
      setPointForm({
        userId: "",
        adjustmentType: "grant",
        pointType: "paid",
        amount: "",
        expireAt: "",
        reason: "",
      });
      showMessage("success", "ポイント調整を登録しました");
      await fetchTab("points", 1, filters.points, session.access_token);
      setActivePointView("list");
      router.push(adminPathForTab("points"));
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ポイント調整に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitSelectedUserPointAdjustment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session || !selectedUser) {
      return;
    }

    setLoading(true);
    clearMessage();

    const payload = pointAdjustmentPayload(userPointForm);

    try {
      await apiRequest<PointAdjustment>(`/users/${selectedUser.id}/point-adjustments`, {
        method: "POST",
        body: JSON.stringify(payload),
      }, session.access_token);

      setUserPointForm({
        adjustmentType: "grant",
        pointType: "paid",
        amount: "",
        expireAt: "",
        reason: "",
      });
      await selectUser(selectedUser.id);
      showMessage("success", "ユーザー詳細からポイント調整を登録しました");
      if (activeTab === "points") {
        await fetchTab("points", 1, filters.points, session.access_token);
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ポイント調整に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitPurchasePlan(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();

    const isUpdate = purchasePlanForm.id !== "";

    try {
      const response = await apiRequest<{ data: PointPurchasePlan }>(isUpdate ? `/point-purchase-plans/${purchasePlanForm.id}` : "/point-purchase-plans", {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify({
          name: purchasePlanForm.name,
          amount: Number(purchasePlanForm.amount),
          paid_point_amount: Number(purchasePlanForm.paidPointAmount),
          free_point_amount: Number(purchasePlanForm.freePointAmount),
          sort_order: Number(purchasePlanForm.sortOrder),
          is_active: purchasePlanForm.isActive,
          starts_at: purchasePlanForm.startsAt || null,
          ends_at: purchasePlanForm.endsAt || null,
        }),
      }, session.access_token);

      setPurchasePlanForm(formFromPurchasePlan(response.data));
      showMessage("success", isUpdate ? "購入プランを更新しました" : "購入プランを作成しました");
      await fetchTab("purchasePlans", isUpdate ? pages.purchasePlans : 1, filters.purchasePlans, session.access_token);
      if (!isUpdate) {
        setActivePurchasePlanView("list");
        router.push(adminPathForTab("purchasePlans"));
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "購入プランの保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function resetPurchasePlanForm() {
    setPurchasePlanForm({
      id: "",
      name: "",
      amount: "",
      paidPointAmount: "",
      freePointAmount: "0",
      sortOrder: String((purchasePlans.length + 1) * 10),
      isActive: true,
      startsAt: "",
      endsAt: "",
    });
  }

  function editPurchasePlan(plan: PointPurchasePlan) {
    setPurchasePlanForm(formFromPurchasePlan(plan));
    setActivePurchasePlanView("edit");
    router.push(adminPathForPurchasePlanView("edit", plan.id));
  }

  async function editShippingItem(row: ShippingRequest, item?: ShippingItem) {
    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<{ data: ShippingRequest }>(`/shipping-requests/${row.id}`, {}, session.access_token);
      const targetItem = response.data.items?.find((current) => current.id === item?.id) ?? item ?? null;
      setSelectedShippingRequest(response.data);
      setSelectedShippingItem(targetItem);
      setShippingForm({
        status: targetItem?.status ?? response.data.status,
        trackingNumber: targetItem?.tracking_number ?? response.data.tracking_number ?? "",
        shippedAt: toDateTimeLocal(targetItem?.shipped_at ?? response.data.shipped_at),
        note: "",
      });
      setActiveShippingView("edit");
      if (targetItem) {
        router.push(adminPathForShippingEdit(response.data.id, targetItem.id));
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "配送詳細の取得に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitShippingRequest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session || !selectedShippingRequest || !selectedShippingItem) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<{ data: ShippingItem }>(`/shipping-items/${selectedShippingItem.id}`, {
        method: "PUT",
        body: JSON.stringify({
          status: shippingForm.status,
          tracking_number: shippingForm.trackingNumber || null,
          shipped_at: shippingForm.shippedAt || null,
          note: shippingForm.note || null,
        }),
      }, session.access_token);

      const refreshedRequest = await apiRequest<{ data: ShippingRequest }>(`/shipping-requests/${selectedShippingRequest.id}`, {}, session.access_token);
      const refreshedItem = refreshedRequest.data.items?.find((item) => item.id === response.data.id) ?? response.data;

      setSelectedShippingRequest(refreshedRequest.data);
      setSelectedShippingItem(refreshedItem);
      setShippingForm({
        status: refreshedItem.status,
        trackingNumber: refreshedItem.tracking_number ?? "",
        shippedAt: toDateTimeLocal(refreshedItem.shipped_at),
        note: "",
      });
      showMessage("success", "景品の配送情報を更新しました");
      await fetchTab("shipping", pages.shipping, filters.shipping, session.access_token);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "配送情報の更新に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function backToShippingList() {
    setActiveShippingView("list");
    setSelectedShippingRequest(null);
    setSelectedShippingItem(null);
    setShippingForm({
      status: "requested",
      trackingNumber: "",
      shippedAt: "",
      note: "",
    });
    router.push(adminPathForTab("shipping"));
  }

  async function selectUser(userId: number) {
    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const [userResponse, prizeResponse, drawResponse, paymentResponse, adjustmentResponse, referralResponse] = await Promise.all([
        apiRequest<{ data: User }>(`/users/${userId}`, {}, session.access_token),
        apiRequest<ApiCollection<UserPrize>>(`/user-prizes?per_page=100&user_id=${userId}`, {}, session.access_token),
        apiRequest<ApiCollection<DrawRequest>>(`/draw-requests?per_page=100&user_id=${userId}`, {}, session.access_token),
        apiRequest<ApiCollection<Payment>>(`/payments?per_page=100&user_id=${userId}`, {}, session.access_token),
        apiRequest<ApiCollection<PointAdjustment>>(`/point-adjustments?per_page=100&user_id=${userId}`, {}, session.access_token),
        apiRequest<ApiCollection<UserReferral>>(`/referrals?per_page=100&user_id=${userId}`, {}, session.access_token),
      ]);

      setSelectedUser(userResponse.data);
      setSelectedUserPrizes(prizeResponse.data);
      setSelectedUserDraws(drawResponse.data);
      setSelectedUserPayments(paymentResponse.data);
      setSelectedUserPointAdjustments(adjustmentResponse.data);
      setSelectedUserReferrals(referralResponse.data);
      setUserPointForm({
        adjustmentType: "grant",
        pointType: "paid",
        amount: "",
        expireAt: "",
        reason: "",
      });
      setUserStatusForm({
        status: userResponse.data.status,
      });
      setActiveUserView("detail");
      router.push(adminPathForUserDetail(userResponse.data.id));
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ユーザー詳細の取得に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitSelectedUserStatus(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session || !selectedUser) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<{ data: User }>(`/users/${selectedUser.id}`, {
        method: "PUT",
        body: JSON.stringify({
          status: userStatusForm.status,
        }),
      }, session.access_token);

      setSelectedUser(response.data);
      setUserStatusForm({ status: response.data.status });
      showMessage("success", "ユーザー状態を更新しました");
      await fetchTab("users", pages.users, filters.users, session.access_token);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ユーザー状態の更新に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function moveToUserRelatedList(tab: Extract<TabKey, "points" | "payments" | "prizes" | "draws">, userId: number) {
    const nextFilters = {
      ...emptyFilters[tab],
      user_id: String(userId),
    };

    setActiveTab(tab);
    setActiveUserView("list");
    setFilters((current) => ({
      ...current,
      [tab]: nextFilters,
    }));
    void fetchTab(tab, 1, nextFilters);
    router.push(adminPathForTab(tab));
  }

  async function editUserPrizeFromUserDetail(userPrize: UserPrize) {
    if (!session) {
      return;
    }

    if (!["shipping_requested", "shipped"].includes(userPrize.status)) {
      moveToUserRelatedList("prizes", userPrize.user_id);
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<ApiCollection<ShippingRequest>>(`/shipping-requests?per_page=100&user_id=${userPrize.user_id}`, {}, session.access_token);
      const matched = response.data
        .flatMap((request) => (request.items ?? []).map((item) => ({ request, item })))
        .find(({ item }) => item.user_prize_id === userPrize.id);

      if (!matched) {
        throw new Error("この景品に紐づく配送情報が見つかりませんでした。");
      }

      setActiveTab("shipping");
      setActiveUserView("list");
      router.push(adminPathForShippingEdit(matched.request.id, matched.item.id));

      await editShippingItem(matched.request, matched.item);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "配送編集ページを開けませんでした");
    } finally {
      setLoading(false);
    }
  }

  async function submitAnnouncement(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();

    const isUpdate = announcementForm.id !== "";

    try {
      const response = await apiRequest<{ data: Announcement }>(isUpdate ? `/announcements/${announcementForm.id}` : "/announcements", {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify({
          title: announcementForm.title,
          body: announcementForm.body,
          thumbnail_url: announcementForm.thumbnailUrl || null,
          show_on_top_slider: announcementForm.showOnTopSlider,
          status: announcementForm.status,
          published_at: announcementForm.publishedAt || null,
        }),
      }, session.access_token);

      if (isUpdate) {
        editAnnouncement(response.data);
      } else {
        setAnnouncementForm({
          id: "",
          title: "",
          body: "",
          thumbnailUrl: "",
          showOnTopSlider: false,
          status: "draft",
          publishedAt: "",
        });
        setActiveAnnouncementView("list");
        router.push(adminPathForTab("announcements"));
      }
      showMessage("success", isUpdate ? "お知らせを更新しました" : "お知らせを作成しました");
      await fetchTab("announcements", isUpdate ? pages.announcements : 1, filters.announcements, session.access_token);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "お知らせ保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function editAnnouncement(announcement: Announcement) {
    setAnnouncementForm({
      id: String(announcement.id),
      title: announcement.title,
      body: announcement.body,
      thumbnailUrl: announcement.thumbnail_url ?? "",
      showOnTopSlider: announcement.show_on_top_slider,
      status: announcement.status,
      publishedAt: toDateTimeLocal(announcement.published_at),
    });
    setActiveAnnouncementView("edit");
    router.push(adminPathForAnnouncementView("edit", announcement.id));
  }

  function editContact(contact: ContactRequest) {
    setSelectedContact(contact);
    setContactReplyForm({
      status: contact.status,
      replyBody: contact.reply_body ?? "",
    });
    setActiveContactView("edit");
    router.push(adminPathForContactView("edit", contact.id));
  }

  async function submitContactReply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session || !selectedContact) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<{ data: ContactRequest }>(`/contact-requests/${selectedContact.id}`, {
        method: "PUT",
        body: JSON.stringify({
          status: contactReplyForm.status,
          reply_body: contactReplyForm.replyBody || null,
        }),
      }, session.access_token);

      setSelectedContact(response.data);
      setContactReplyForm({
        status: response.data.status,
        replyBody: response.data.reply_body ?? "",
      });
      showMessage("success", "お問い合わせ対応を保存しました");
      await fetchTab("contacts", pages.contacts, filters.contacts, session.access_token);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "お問い合わせ対応の保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function editStaticPage(page: StaticPage) {
    setSelectedStaticPage(page);
    setStaticPageForm({
      title: page.title,
      body: page.body,
    });
    setActiveSettingView("edit");
    router.push(adminPathForSettingView("edit", page.id));
  }

  function resetRankAssetForm(assetType: "image" | "video" = "image") {
    setSelectedRankAsset(null);
    setRankAssetForm({
      title: "",
      assetType,
      url: "",
      isActive: true,
    });
  }

  function editRankAsset(asset: RankAsset) {
    setSelectedRankAsset(asset);
    setRankAssetForm({
      title: asset.title,
      assetType: asset.asset_type,
      url: asset.url,
      isActive: asset.is_active,
    });
    setActiveSettingView("rank-asset-edit");
    router.push(adminPathForSettingView("rank-asset-edit", asset.id));
  }

  async function submitStaticPage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session || !selectedStaticPage) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<{ data: StaticPage }>(`/static-pages/${selectedStaticPage.id}`, {
        method: "PUT",
        body: JSON.stringify(staticPageForm),
      }, session.access_token);

      setSelectedStaticPage(response.data);
      setStaticPageForm({
        title: response.data.title,
        body: response.data.body,
      });
      showMessage("success", "設定ページを更新しました");
      await fetchTab("settings", 1, filters.settings, session.access_token);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "設定ページの更新に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitRankAsset(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();

    const isUpdate = selectedRankAsset !== null;

    try {
      const response = await apiRequest<{ data: RankAsset }>(isUpdate ? `/rank-assets/${selectedRankAsset.id}` : "/rank-assets", {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify({
          title: rankAssetForm.title,
          asset_type: rankAssetForm.assetType,
          url: rankAssetForm.url,
          is_active: rankAssetForm.isActive,
        }),
      }, session.access_token);

      setSelectedRankAsset(response.data);
      setRankAssetForm({
        title: response.data.title,
        assetType: response.data.asset_type,
        url: response.data.url,
        isActive: response.data.is_active,
      });
      showMessage("success", isUpdate ? "ランク演出設定を更新しました" : "ランク演出設定を作成しました");
      const assetResponse = await apiRequest<ApiCollection<RankAsset>>("/rank-assets?per_page=100", {}, session.access_token);
      setRankAssets(assetResponse.data);
      if (!isUpdate) {
        setActiveSettingView("list");
        router.push(adminPathForTab("settings"));
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ランク演出設定の保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitReferralSetting(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<{ data: ReferralSetting }>("/referral-settings", {
        method: "PUT",
        body: JSON.stringify({
          reward_point_amount: Number(referralSettingForm.rewardPointAmount || 0),
          reward_expiration_days: referralSettingForm.rewardExpirationDays ? Number(referralSettingForm.rewardExpirationDays) : null,
          is_active: referralSettingForm.isActive,
        }),
      }, session.access_token);

      setReferralSetting(response.data);
      setReferralSettingForm({
        rewardPointAmount: String(response.data.reward_point_amount),
        rewardExpirationDays: response.data.reward_expiration_days === null ? "" : String(response.data.reward_expiration_days),
        isActive: response.data.is_active,
      });
      showMessage("success", "紹介ポイント設定を更新しました");
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "紹介ポイント設定の保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function fetchReferralsBySearch() {
    if (!session) {
      return;
    }

    const params = new URLSearchParams({
      per_page: "100",
    });

    if (referralSearchForm.userId.trim()) {
      params.set("user_id", referralSearchForm.userId.trim());
    }

    setLoading(true);
    clearMessage();

    try {
      const response = await apiRequest<ApiCollection<UserReferral>>(`/referrals?${params.toString()}`, {}, session.access_token);
      setReferrals(response.data);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "紹介履歴の取得に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function clearReferralSearch() {
    setReferralSearchForm({ userId: "" });

    if (!session) {
      return;
    }

    void apiRequest<ApiCollection<UserReferral>>("/referrals?per_page=100", {}, session.access_token)
      .then((response) => setReferrals(response.data))
      .catch((error) => showMessage("error", error instanceof Error ? error.message : "紹介履歴の取得に失敗しました"));
  }

  async function uploadImage(context: AssetContext, file: File): Promise<string> {
    if (!session) {
      throw new Error("管理者セッションが切れています。再ログインしてください");
    }

    const formData = new FormData();
    formData.set("context", context);
    formData.set("image", file);

    const response = await apiRequest<{ data: UploadedAsset }>("/assets/images", {
      method: "POST",
      body: formData,
    }, session.access_token);

    return response.data.url;
  }

  async function uploadVideo(context: AssetContext, file: File): Promise<string> {
    if (!session) {
      throw new Error("管理者セッションが切れています。再ログインしてください");
    }

    const formData = new FormData();
    formData.set("context", context);
    formData.set("video", file);

    const response = await apiRequest<{ data: UploadedAsset }>("/assets/videos", {
      method: "POST",
      body: formData,
    }, session.access_token);

    return response.data.url;
  }

  async function submitGacha(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      showMessage("error", "管理者セッションが切れています。再ログインしてください");
      return;
    }

    setLoading(true);
    clearMessage();

    const isUpdate = gachaForm.id !== "";
    const payload = gachaPayload({
      ...gachaForm,
      slug: gachaForm.slug.trim() || makeSlugFallback("gacha"),
    });

    if (!gachaForm.title.trim()) {
      setLoading(false);
      showMessage("error", "ガチャ名を入力してください");
      return;
    }

    if (!gachaForm.categoryId) {
      setLoading(false);
      showMessage("error", "カテゴリを選択してください。カテゴリが未作成の場合は先にカテゴリ名を入力してカテゴリ作成を押してください");
      return;
    }

    try {
      const response = await apiRequest<{ data: Gacha }>(isUpdate ? `/gachas/${gachaForm.id}` : "/gachas", {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify(payload),
      }, session.access_token);

      showMessage("success", isUpdate ? "ガチャを更新しました" : "ガチャを作成しました");
      await fetchTab("gachas", isUpdate ? pages.gachas : 1, filters.gachas, session.access_token);
      changeGachaView("gacha-edit");
      await selectGacha(response.data.id);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ガチャ保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function activateSelectedGacha() {
    if (!session || !selectedGacha || !readiness?.ready) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      await apiRequest<{ data: Gacha }>(`/gachas/${selectedGacha.id}`, {
        method: "PUT",
        body: JSON.stringify(gachaPayload({
          ...gachaForm,
          status: "active",
        })),
      }, session.access_token);
      showMessage("success", "ガチャを稼働中にしました");
      await fetchTab("gachas", pages.gachas, filters.gachas, session.access_token);
      await selectGacha(selectedGacha.id);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "稼働化に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitCategory(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      showMessage("error", "管理者セッションが切れています。再ログインしてください");
      return;
    }

    setLoading(true);
    clearMessage();

    const isUpdate = categoryForm.id !== "";
    const payload = {
      name: categoryForm.name.trim(),
      slug: categoryForm.slug.trim() || makeSlugFallback("category"),
      sort_order: Number(categoryForm.sortOrder),
      is_visible: categoryForm.isVisible,
    };

    if (!payload.name) {
      setLoading(false);
      showMessage("error", "カテゴリ名を入力してください");
      return;
    }

    if (!Number.isInteger(payload.sort_order) || payload.sort_order < 0) {
      setLoading(false);
      showMessage("error", "並び順は0以上の整数で入力してください");
      return;
    }

    try {
      const response = await apiRequest<{ data: GachaCategory }>(isUpdate ? `/gacha-categories/${categoryForm.id}` : "/gacha-categories", {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify(payload),
      }, session.access_token);
      const categoryData = await apiRequest<{ data: GachaCategory[] }>("/gacha-categories", {}, session.access_token);
      setCategories(categoryData.data);
      showMessage("success", isUpdate ? "カテゴリを更新しました" : "カテゴリを作成しました");

      if (isUpdate) {
        setCategoryForm({
          id: String(response.data.id),
          name: response.data.name,
          slug: response.data.slug,
          sortOrder: String(response.data.sort_order),
          isVisible: response.data.is_visible,
        });
      } else {
        changeGachaView("category-list");
        setCategoryForm({
          id: "",
          name: "",
          slug: "",
          sortOrder: String((response.data.sort_order ?? 0) + 1),
          isVisible: true,
        });
      }

      if (!gachaForm.categoryId) {
        setGachaForm((current) => ({ ...current, categoryId: String(response.data.id) }));
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "カテゴリ保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitTag(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      showMessage("error", "管理者セッションが切れています。再ログインしてください");
      return;
    }

    setLoading(true);
    clearMessage();

    const isUpdate = tagForm.id !== "";
    const payload = {
      name: tagForm.name.trim(),
      slug: tagForm.slug.trim() || makeSlugFallback("tag"),
      sort_order: Number(tagForm.sortOrder),
      is_active: tagForm.isActive,
    };

    if (!payload.name) {
      setLoading(false);
      showMessage("error", "タグ名を入力してください");
      return;
    }

    if (!Number.isInteger(payload.sort_order) || payload.sort_order < 0) {
      setLoading(false);
      showMessage("error", "並び順は0以上の整数で入力してください");
      return;
    }

    try {
      const response = await apiRequest<{ data: GachaTag }>(isUpdate ? `/gacha-tags/${tagForm.id}` : "/gacha-tags", {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify(payload),
      }, session.access_token);
      const tagData = await apiRequest<{ data: GachaTag[] }>("/gacha-tags", {}, session.access_token);
      setTags(tagData.data);
      showMessage("success", isUpdate ? "タグを更新しました" : "タグを作成しました");

      if (isUpdate) {
        setTagForm({
          id: String(response.data.id),
          name: response.data.name,
          slug: response.data.slug,
          sortOrder: String(response.data.sort_order),
          isActive: response.data.is_active,
        });
      } else {
        changeGachaView("tag-list");
        setTagForm({
          id: "",
          name: "",
          slug: "",
          sortOrder: String((response.data.sort_order ?? 0) + 1),
          isActive: true,
        });
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "タグ保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitTopBanner(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session) {
      showMessage("error", "管理者セッションが切れています。再ログインしてください");
      return;
    }

    setLoading(true);
    clearMessage();

    const isUpdate = topBannerForm.id !== "";
    const payload = {
      image_url: topBannerForm.imageUrl.trim(),
      link_url: topBannerForm.linkUrl.trim() || null,
      sort_order: Number(topBannerForm.sortOrder),
      is_active: topBannerForm.isActive,
    };

    if (!payload.image_url) {
      setLoading(false);
      showMessage("error", "バナー画像を選択してください");
      return;
    }

    if (!Number.isInteger(payload.sort_order) || payload.sort_order < 0) {
      setLoading(false);
      showMessage("error", "並び順は0以上の整数で入力してください");
      return;
    }

    try {
      const response = await apiRequest<{ data: TopBanner }>(isUpdate ? `/top-banners/${topBannerForm.id}` : "/top-banners", {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify(payload),
      }, session.access_token);
      const bannerData = await apiRequest<{ data: TopBanner[] }>("/top-banners", {}, session.access_token);
      setTopBanners(bannerData.data);
      showMessage("success", isUpdate ? "トップバナーを更新しました" : "トップバナーを作成しました");

      if (isUpdate) {
        setTopBannerForm(formFromTopBanner(response.data));
      } else {
        changeGachaView("top-banner-list");
        setTopBannerForm({
          id: "",
          imageUrl: "",
          linkUrl: "",
          sortOrder: String((response.data.sort_order ?? 0) + 1),
          isActive: true,
        });
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "トップバナー保存に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function bulkUpdateTopBannerStatus(ids: number[], isActive: boolean) {
    if (!session) {
      showMessage("error", "管理者セッションが切れています。再ログインしてください");
      return;
    }

    if (ids.length === 0) {
      showMessage("error", "更新するバナーを選択してください");
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      await apiRequest<{ data: TopBanner[] }>("/top-banners/status", {
        method: "PATCH",
        body: JSON.stringify({
          ids,
          is_active: isActive,
        }),
      }, session.access_token);
      const bannerData = await apiRequest<{ data: TopBanner[] }>("/top-banners", {}, session.access_token);
      setTopBanners(bannerData.data);
      showMessage("success", isActive ? "選択したバナーを有効にしました" : "選択したバナーを無効にしました");
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "トップバナーの一括更新に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function resetGachaForm() {
    setSelectedGacha(null);
    setReadiness(null);
    setProbabilityPreview(null);
    setGachaForm({
      id: "",
      title: "",
      slug: "",
      categoryId: categories[0]?.id ? String(categories[0].id) : "",
      price: "500",
      totalCount: "10000",
      dailyDrawLimit: "",
      probabilityMode: "single",
      minimumGuaranteeType: "point",
      minimumGuaranteeValue: "10",
      minimumGuaranteeCost: "10",
      status: "draft",
      startAt: "",
      endAt: "",
      description: "",
      caution: "",
      mainImageUrl: "",
      showOnTopSlider: false,
      targetMargin: "",
      tagIds: [],
    });
  }

  function resetCategoryForm() {
    setCategoryForm({
      id: "",
      name: "",
      slug: "",
      sortOrder: "1",
      isVisible: true,
    });
  }

  function resetTagForm() {
    setTagForm({
      id: "",
      name: "",
      slug: "",
      sortOrder: "1",
      isActive: true,
    });
  }

  function resetTopBannerForm() {
    setTopBannerForm({
      id: "",
      imageUrl: "",
      linkUrl: "",
      sortOrder: "1",
      isActive: true,
    });
  }

  function editTopBanner(banner: TopBanner) {
    setTopBannerForm(formFromTopBanner(banner));
    changeGachaView("top-banner-edit", banner.id);
  }

  function resetRankForm() {
    setRankForm({
      id: "",
      rankKey: "",
      displayName: "",
      description: "",
      imageUrl: "",
      rankImageAssetId: "",
      rankImageAssetIds: [],
      drawVideoUrl: "",
      drawVideoAssetId: "",
      drawVideoAssetIds: [],
      resultImageUrl: "",
      sortOrder: "1",
      isVisible: true,
    });
  }

  function editRank(rank: GachaRank) {
    setRankForm({
      id: String(rank.id),
      rankKey: rank.rank_key,
      displayName: rank.display_name,
      description: rank.description ?? "",
      imageUrl: rank.image_url ?? "",
      rankImageAssetId: rank.rank_image_asset_id ? String(rank.rank_image_asset_id) : "",
      rankImageAssetIds: rank.rank_image_asset_ids?.length ? rank.rank_image_asset_ids.map(String) : rank.rank_image_asset_id ? [String(rank.rank_image_asset_id)] : [],
      drawVideoUrl: rank.draw_video_url ?? "",
      drawVideoAssetId: rank.draw_video_asset_id ? String(rank.draw_video_asset_id) : "",
      drawVideoAssetIds: rank.draw_video_asset_ids?.length ? rank.draw_video_asset_ids.map(String) : rank.draw_video_asset_id ? [String(rank.draw_video_asset_id)] : [],
      resultImageUrl: rank.result_image_url ?? "",
      sortOrder: String(rank.sort_order),
      isVisible: rank.is_visible,
    });
  }

  function resetPrizeForm(rankId = prizeForm.rankId) {
    setPrizeForm({
      id: "",
      rankId,
      name: "",
      imageUrl: "",
      maxWinCount: "",
      costPrice: "",
      displayPrice: "",
      exchangePoint: "",
      condition: "新品",
      isActive: true,
      isVisible: true,
      sortOrder: "1",
    });
  }

  function editPrize(prize: GachaPrize) {
    setPrizeForm({
      id: String(prize.id),
      rankId: String(prize.rank_id),
      name: prize.name,
      imageUrl: prize.image_url,
      maxWinCount: String(prize.max_win_count),
      costPrice: String(prize.cost_price),
      displayPrice: prize.display_price !== null ? String(prize.display_price) : "",
      exchangePoint: prize.exchange_point !== null ? String(prize.exchange_point) : "",
      condition: prize.condition,
      isActive: prize.is_active,
      isVisible: prize.is_visible,
      sortOrder: String(prize.sort_order),
    });
  }

  async function restoreInitialEdit() {
    if (!session || !initialEditId) {
      return;
    }

    if (activeGachaView === "gacha-edit") {
      await selectGacha(initialEditId);
      return;
    }

    if (activeGachaView === "category-edit") {
      try {
        const response = await apiRequest<{ data: GachaCategory }>(`/gacha-categories/${initialEditId}`, {}, session.access_token);
        const category = response.data;

        setCategoryForm({
          id: String(category.id),
          name: category.name,
          slug: category.slug,
          sortOrder: String(category.sort_order),
          isVisible: category.is_visible,
        });
      } catch (error) {
        showMessage("error", error instanceof Error ? error.message : "カテゴリ詳細の取得に失敗しました");
      }
      return;
    }

    if (activeGachaView === "tag-edit") {
      try {
        const response = await apiRequest<{ data: GachaTag }>(`/gacha-tags/${initialEditId}`, {}, session.access_token);
        const tag = response.data;

        setTagForm({
          id: String(tag.id),
          name: tag.name,
          slug: tag.slug,
          sortOrder: String(tag.sort_order),
          isActive: tag.is_active,
        });
      } catch (error) {
        showMessage("error", error instanceof Error ? error.message : "タグ詳細の取得に失敗しました");
      }
      return;
    }

    if (activeGachaView === "top-banner-edit") {
      try {
        const response = await apiRequest<{ data: TopBanner }>(`/top-banners/${initialEditId}`, {}, session.access_token);

        setTopBannerForm(formFromTopBanner(response.data));
      } catch (error) {
        showMessage("error", error instanceof Error ? error.message : "トップバナー詳細の取得に失敗しました");
      }
      return;
    }

    if (activeGachaView === "rank-edit") {
      try {
        const response = await apiRequest<{ data: GachaRank }>(`/gacha-ranks/${initialEditId}`, {}, session.access_token);
        const rank = response.data;

        await selectGacha(rank.gacha_id);
        editRank(rank);
      } catch (error) {
        showMessage("error", error instanceof Error ? error.message : "ランク詳細の取得に失敗しました");
      }
      return;
    }

    if (activeGachaView === "prize-edit") {
      try {
        const response = await apiRequest<{ data: GachaPrize }>(`/gacha-prizes/${initialEditId}`, {}, session.access_token);
        const prize = response.data;

        await selectGacha(prize.gacha_id);
        editPrize(prize);
      } catch (error) {
        showMessage("error", error instanceof Error ? error.message : "景品詳細の取得に失敗しました");
      }
    }
  }

  async function selectGacha(gachaId: number) {
    if (!session) {
      return;
    }

    setLoading(true);
    clearMessage();
    setProbabilityPreview(null);
    setReadiness(null);

    try {
      const [response, readinessResponse, matrixResponse, simulationResponse] = await Promise.all([
        apiRequest<{ data: Gacha }>(`/gachas/${gachaId}`, {}, session.access_token),
        apiRequest<{ data: GachaReadiness }>(`/gachas/${gachaId}/readiness`, {}, session.access_token),
        apiRequest<ProbabilityMatrix>(`/gachas/${gachaId}/probability-matrix`, {}, session.access_token),
        apiRequest<{ data: ProfitSimulation }>(`/gachas/${gachaId}/profit-simulation`, {}, session.access_token),
      ]);
      setSelectedGacha(response.data);
      setReadiness(readinessResponse.data);
      setProfitSimulation(simulationResponse.data);
      setGachaForm(formFromGacha(response.data));
      setProbabilityStages(probabilityFormsFromMatrix(response.data, matrixResponse.data.stages));
      setPrizeForm((current) => ({
        ...current,
        id: "",
        rankId: response.data.ranks?.[0]?.id ? String(response.data.ranks[0].id) : "",
      }));
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ガチャ詳細の取得に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  restoreInitialEditRef.current = restoreInitialEdit;

  async function submitRank(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session || !selectedGacha) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const isUpdate = rankForm.id !== "";

      const response = await apiRequest<{ data: GachaRank }>(isUpdate ? `/gacha-ranks/${rankForm.id}` : `/gachas/${selectedGacha.id}/ranks`, {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify({
          rank_key: rankForm.rankKey,
          display_name: rankForm.displayName,
          description: rankForm.description || null,
          image_url: rankForm.imageUrl || null,
          rank_image_asset_id: rankForm.rankImageAssetId ? Number(rankForm.rankImageAssetId) : null,
          rank_image_asset_ids: rankForm.rankImageAssetIds.map(Number),
          draw_video_url: rankForm.drawVideoUrl || null,
          draw_video_asset_id: rankForm.drawVideoAssetId ? Number(rankForm.drawVideoAssetId) : null,
          draw_video_asset_ids: rankForm.drawVideoAssetIds.map(Number),
          sort_order: Number(rankForm.sortOrder),
          is_visible: rankForm.isVisible,
        }),
      }, session.access_token);
      showMessage("success", isUpdate ? "ランクを更新しました" : "ランクを作成しました");
      await selectGacha(selectedGacha.id);
      if (isUpdate) {
        editRank(response.data);
      } else {
        resetRankForm();
      }
      const gachaRankData = await apiRequest<ApiCollection<GachaRank>>("/gacha-ranks?per_page=100", {}, session.access_token);
      setGachaRanks(gachaRankData.data);
      await fetchTab("gachas", pages.gachas, filters.gachas, session.access_token);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "ランク作成に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function submitPrize(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!session || !selectedGacha) {
      return;
    }

    setLoading(true);
    clearMessage();

    try {
      const isUpdate = prizeForm.id !== "";
      const locksPrizeFields = isUpdate && selectedGacha.status === "active";
      const payload = {
        ...(isUpdate && !locksPrizeFields ? { rank_id: Number(prizeForm.rankId) } : {}),
        name: prizeForm.name,
        image_url: prizeForm.imageUrl,
        ...(!locksPrizeFields ? {
          max_win_count: Number(prizeForm.maxWinCount),
          cost_price: Number(prizeForm.costPrice),
          display_price: prizeForm.displayPrice ? Number(prizeForm.displayPrice) : null,
          exchange_point: prizeForm.exchangePoint ? Number(prizeForm.exchangePoint) : null,
        } : {}),
        condition: prizeForm.condition,
        is_active: prizeForm.isActive,
        is_visible: prizeForm.isVisible,
        sort_order: Number(prizeForm.sortOrder),
      };

      const response = await apiRequest<{ data: GachaPrize }>(isUpdate ? `/gacha-prizes/${prizeForm.id}` : `/gacha-ranks/${prizeForm.rankId}/prizes`, {
        method: isUpdate ? "PUT" : "POST",
        body: JSON.stringify(payload),
      }, session.access_token);
      showMessage("success", isUpdate ? "景品を更新しました" : "景品を作成しました");
      await selectGacha(selectedGacha.id);
      if (isUpdate) {
        editPrize(response.data);
      } else {
        setPrizeForm({
          id: "",
          rankId: prizeForm.rankId,
          name: "",
          imageUrl: "",
          maxWinCount: "",
          costPrice: "",
          displayPrice: "",
          exchangePoint: "",
          condition: "新品",
          isActive: true,
          isVisible: true,
          sortOrder: prizeForm.sortOrder,
        });
      }
      const gachaPrizeData = await apiRequest<ApiCollection<GachaPrize>>("/gacha-prizes?per_page=100", {}, session.access_token);
      setGachaPrizes(gachaPrizeData.data);
      await fetchTab("gachas", pages.gachas, filters.gachas, session.access_token);
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "景品作成に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  async function previewProbability() {
    await submitProbability("preview");
  }

  async function publishProbability() {
    await submitProbability("publish");
  }

  async function submitProbability(mode: "preview" | "publish") {
    if (!session || !selectedGacha) {
      return;
    }

    const payload = buildProbabilityPayload(selectedGacha, probabilityStages);
    setLoading(true);
    clearMessage();

    try {
      if (mode === "preview") {
        const response = await apiRequest<ProbabilityPreview>(`/gachas/${selectedGacha.id}/probability-versions/preview`, {
          method: "POST",
          body: JSON.stringify(payload),
        }, session.access_token);
        setProbabilityPreview(response.data);
        showMessage("success", "確率設定を検証しました");
      } else {
        await apiRequest(`/gachas/${selectedGacha.id}/probability-versions/publish`, {
          method: "POST",
          body: JSON.stringify({
            ...payload,
            change_reason: "管理画面から公開",
          }),
        }, session.access_token);
        showMessage("success", "確率設定を公開しました");
        setProbabilityPreview(null);
        await selectGacha(selectedGacha.id);
        await fetchTab("gachas", pages.gachas, filters.gachas, session.access_token);
      }
    } catch (error) {
      showMessage("error", error instanceof Error ? error.message : "確率設定に失敗しました");
    } finally {
      setLoading(false);
    }
  }

  function updateFilter(tab: TabKey, name: string, value: string) {
    setFilters((current) => ({
      ...current,
      [tab]: {
        ...current[tab],
        [name]: value,
      },
    }));
  }

  function submitFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void fetchTab(activeTab, 1, filters[activeTab]);
  }

  function clearFilters(tab: TabKey) {
    const nextFilters = { ...emptyFilters[tab] };
    setFilters((current) => ({ ...current, [tab]: nextFilters }));
    void fetchTab(tab, 1, nextFilters);
  }

  function goToPage(tab: TabKey, page: number) {
    void fetchTab(tab, page, filters[tab]);
  }

  function changeGachaView(view: GachaAdminView, id?: number | string) {
    setActiveTab("gachas");
    setActiveGachaView(view);
    router.push(adminPathForGachaView(view, id));
  }

  function changeAdminTab(tab: TabKey) {
    setActiveTab(tab);

    if (tab === "announcements") {
      setActiveAnnouncementView("list");
    }

    if (tab === "contacts") {
      setActiveContactView("list");
    }

    if (tab === "points") {
      setActivePointView("list");
    }

    if (tab === "purchasePlans") {
      setActivePurchasePlanView("list");
    }

    if (tab === "users") {
      setActiveUserView("list");
    }

    if (tab === "shipping") {
      backToShippingList();
    }

    if (tab === "settings") {
      setActiveSettingView("list");
    }

    if (tab === "gachas") {
      changeGachaView("gacha-list");
      return;
    }
    router.push(adminPathForTab(tab));
  }

  if (!authReady) {
    return (
      <main className="admin-shell auth-shell">
        <section className="admin-auth">
          <div className="auth-brand">
            <span className="brand-mark">LP</span>
            <div>
              <h1>Luxe Pack Admin</h1>
              <p>セッション確認中</p>
            </div>
          </div>
        </section>
      </main>
    );
  }

  if (!session) {
    return (
      <main className="admin-shell auth-shell">
        <section className="admin-auth">
          <div className="auth-brand">
            <span className="brand-mark">LP</span>
            <div>
              <h1>Luxe Pack Admin</h1>
              <p>管理者ログイン</p>
            </div>
          </div>
          <form className="stack-form" method="post" action="/admin-login" onSubmit={login}>
            <label>
              <span>メールアドレス</span>
              <input name="email" value={email} onChange={(event) => setEmail(event.target.value)} type="email" autoComplete="email" required />
            </label>
            <label>
              <span>パスワード</span>
              <input name="password" value={password} onChange={(event) => setPassword(event.target.value)} type="password" autoComplete="current-password" required />
            </label>
            <button className="primary-button" type="submit" disabled={loading}>{loading ? "処理中" : "ログイン"}</button>
          </form>
          <Notice tone={messageTone} message={message} />
        </section>
      </main>
    );
  }

  return (
    <main className="admin-shell">
      <aside className="admin-sidebar">
        <div className="sidebar-brand">
          <span className="brand-mark">LP</span>
          <div>
            <strong>Luxe Pack</strong>
            <small>Operations</small>
          </div>
        </div>

        <nav className="admin-tabs" aria-label="管理メニュー">
          {tabs.map((tab) => (
            <div className="nav-group" key={tab.key}>
              <a
                href={adminPathForTab(tab.key)}
                className={activeTab === tab.key ? "active" : ""}
                onClick={(event: MouseEvent<HTMLAnchorElement>) => {
                  event.preventDefault();
                  changeAdminTab(tab.key);
                }}
              >
                <span className="nav-mark">{tab.short}</span>
                <span>
                  <strong>{tab.label}</strong>
                  <small>{tab.description}</small>
                </span>
              </a>
              {tab.key === "gachas" && activeTab === "gachas" && (
                <div className="admin-subtabs">
                  {gachaSubViews.map((view) => (
                    <a
                      key={view.key}
                      href={adminPathForGachaView(view.key)}
                      className={activeGachaView === view.key ? "active" : ""}
                      onClick={(event: MouseEvent<HTMLAnchorElement>) => {
                        event.preventDefault();
                        changeGachaView(view.key);
                      }}
                    >
                      {view.label}
                    </a>
                  ))}
                </div>
              )}
            </div>
          ))}
        </nav>
      </aside>

      <section className="admin-workspace">
        <header className="admin-header">
          <div>
            <p className="section-kicker">Admin console</p>
            <h1>{tabs.find((tab) => tab.key === activeTab)?.label}</h1>
          </div>
          <div className="header-actions">
            <div className="admin-identity">
              <span className="avatar">{session.admin.email.slice(0, 1).toUpperCase()}</span>
              <span>{session.admin.email}</span>
            </div>
            <button className="secondary-button" type="button" onClick={() => void refreshAll()} disabled={loading}>更新</button>
            <a className="ghost-button link-button" href="/admin-logout" onClick={logout}>ログアウト</a>
          </div>
        </header>

        <section className="metric-grid">
          <Metric label="お知らせ" value={summary.announcements} tone="violet" caption="公開情報" loading={!hasLoadedAdminData} />
          <Metric label="お問い合わせ" value={summary.contacts} tone="amber" caption="未対応確認" loading={!hasLoadedAdminData} />
          <Metric label="ガチャ" value={summary.gachas} tone="teal" caption="管理対象" loading={!hasLoadedAdminData} />
          <Metric label="抽選履歴" value={summary.draws} tone="blue" caption="最新10件" loading={!hasLoadedAdminData} />
          <Metric label="景品箱" value={summary.prizes} tone="green" caption="保有景品" loading={!hasLoadedAdminData} />
          <Metric label="配送依頼" value={summary.shipping} tone="amber" caption="対応状況" loading={!hasLoadedAdminData} />
        </section>

        <Notice tone={messageTone} message={message} />

        {!hasLoadedAdminData ? (
          <section className="admin-section">
            <SectionHeader
              title={tabs.find((tab) => tab.key === activeTab)?.label ?? ""}
              description={tabs.find((tab) => tab.key === activeTab)?.description ?? ""}
              loading
            />
            <div className="empty-detail compact">データを読み込んでいます。</div>
          </section>
        ) : (
        <section className="admin-section">
          <SectionHeader
            title={tabs.find((tab) => tab.key === activeTab)?.label ?? ""}
            description={tabs.find((tab) => tab.key === activeTab)?.description ?? ""}
            loading={loading}
          />
          {activeTab !== "guide"
            && activeTab !== "settings"
            && (activeTab !== "gachas" || activeGachaView === "gacha-list")
            && (activeTab !== "announcements" || activeAnnouncementView === "list")
            && (activeTab !== "contacts" || activeContactView === "list")
            && (activeTab !== "purchasePlans" || activePurchasePlanView === "list")
            && (activeTab !== "users" || activeUserView === "list")
            && (activeTab !== "points" || activePointView === "list")
            && (
            <FilterPanel
              tab={activeTab}
              filters={filters[activeTab]}
              onChange={(name, value) => updateFilter(activeTab, name, value)}
              onSubmit={submitFilters}
              onClear={() => clearFilters(activeTab)}
            />
          )}
          {activeTab === "guide" && <OperationGuide />}
          {activeTab === "announcements" && (
            activeAnnouncementView === "list" ? (
              <ListSurface
                title="お知らせ一覧"
                actionLabel="お知らせ登録"
                onAction={() => {
                  setAnnouncementForm({ id: "", title: "", body: "", thumbnailUrl: "", showOnTopSlider: false, status: "draft", publishedAt: "" });
                  setActiveAnnouncementView("new");
                  router.push(adminPathForAnnouncementView("new"));
                }}
              >
                <AnnouncementTable rows={announcements} onEdit={editAnnouncement} />
                <Pagination meta={pagination.announcements} onPage={(page) => goToPage("announcements", page)} />
              </ListSurface>
            ) : (
              <FormSurface
                title={activeAnnouncementView === "edit" ? "お知らせ編集" : "お知らせ登録"}
                backLabel="お知らせ一覧"
                onBack={() => {
                  setActiveAnnouncementView("list");
                  router.push(adminPathForTab("announcements"));
                }}
              >
              <form className="stack-form compact-form" onSubmit={submitAnnouncement}>
                <div className="form-title form-title-row">
                  <span>
                    <strong>{announcementForm.id ? "お知らせ編集" : "お知らせ登録"}</strong>
                    <span>トップのINFOMATIONに表示します</span>
                  </span>
                </div>
                <label>
                  <span>タイトル</span>
                  <input value={announcementForm.title} onChange={(event) => setAnnouncementForm({ ...announcementForm, title: event.target.value })} required />
                </label>
                <label>
                  <span>本文</span>
                  <textarea value={announcementForm.body} onChange={(event) => setAnnouncementForm({ ...announcementForm, body: event.target.value })} required />
                </label>
                <ImageUploadField
                  label="サムネイル画像"
                  value={announcementForm.thumbnailUrl}
                  context="announcement"
                  onChange={(value) => setAnnouncementForm({ ...announcementForm, thumbnailUrl: value })}
                  onUploadImage={uploadImage}
                />
                <label className="check-row">
                  <input type="checkbox" checked={announcementForm.showOnTopSlider} onChange={(event) => setAnnouncementForm({ ...announcementForm, showOnTopSlider: event.target.checked })} />
                  <span>トップのスライドに表示</span>
                </label>
                <SelectField
                  label="状態"
                  value={announcementForm.status}
                  onChange={(value) => setAnnouncementForm({ ...announcementForm, status: value })}
                  options={[
                    ["draft", "下書き"],
                    ["published", "公開"],
                    ["hidden", "非表示"],
                  ]}
                />
                <label>
                  <span>公開日時</span>
                  <input type="datetime-local" value={announcementForm.publishedAt} onChange={(event) => setAnnouncementForm({ ...announcementForm, publishedAt: event.target.value })} />
                </label>
                <button className="primary-button" type="submit" disabled={loading}>{announcementForm.id ? "更新" : "作成"}</button>
              </form>
              </FormSurface>
            )
          )}
          {activeTab === "contacts" && (
            activeContactView === "list" ? (
              <ListSurface
                title="お問い合わせ一覧"
                actionLabel="更新"
                onAction={() => void fetchTab("contacts", pages.contacts, filters.contacts)}
              >
                <ContactRequestTable rows={contacts} onEdit={editContact} />
                <Pagination meta={pagination.contacts} onPage={(page) => goToPage("contacts", page)} />
              </ListSurface>
            ) : (
              <FormSurface title="お問い合わせ詳細・返信" backLabel="お問い合わせ一覧" onBack={() => {
                setActiveContactView("list");
                router.push(adminPathForTab("contacts"));
              }}>
                {selectedContact ? (
                  <div className="contact-admin-detail">
                    <div className="detail-heading">
                      <div>
                        <h3>{selectedContact.name}</h3>
                        <p>{selectedContact.email} / {selectedContact.phone}</p>
                      </div>
                      <StatusBadge value={selectedContact.status} />
                    </div>
                    <div className="contact-message-box">
                      <strong>お問い合わせ内容</strong>
                      <p>{selectedContact.body}</p>
                    </div>
                    <form className="stack-form compact-form" onSubmit={submitContactReply}>
                      <SelectField
                        label="状態"
                        value={contactReplyForm.status}
                        onChange={(value) => setContactReplyForm((current) => ({ ...current, status: value }))}
                        options={[
                          ["new", "未対応"],
                          ["replied", "返信済み"],
                          ["closed", "完了"],
                        ]}
                      />
                      <label>
                        <span>返信内容</span>
                        <textarea value={contactReplyForm.replyBody} onChange={(event) => setContactReplyForm((current) => ({ ...current, replyBody: event.target.value }))} />
                      </label>
                      <button className="primary-button" type="submit" disabled={loading}>保存</button>
                    </form>
                  </div>
                ) : (
                  <div className="empty-detail compact">お問い合わせを選択してください。</div>
                )}
              </FormSurface>
            )
          )}
          {activeTab === "gachas" && (
            <>
              <GachaManagement
                activeView={activeGachaView}
                rows={gachas}
                categories={categories}
                tags={tags}
                topBanners={topBanners}
                gachaRanks={gachaRanks}
                gachaPrizes={gachaPrizes}
                rankAssets={rankAssets}
                categoryForm={categoryForm}
                tagForm={tagForm}
                topBannerForm={topBannerForm}
                selectedGacha={selectedGacha}
                readiness={readiness}
                gachaForm={gachaForm}
                rankForm={rankForm}
                prizeForm={prizeForm}
                probabilityStages={probabilityStages}
                probabilityPreview={probabilityPreview}
                profitSimulation={profitSimulation}
                loading={loading}
                onSelect={async (gachaId) => {
                  changeGachaView("gacha-edit", gachaId);
                  await selectGacha(gachaId);
                }}
                onLoadGacha={selectGacha}
                onChangeView={changeGachaView}
                onCategoryFormChange={(next) => setCategoryForm((current) => ({ ...current, ...next }))}
                onEditCategory={(category) => {
                  setCategoryForm({
                    id: String(category.id),
                    name: category.name,
                    slug: category.slug,
                    sortOrder: String(category.sort_order),
                    isVisible: category.is_visible,
                  });
                  changeGachaView("category-edit", category.id);
                }}
                onResetCategoryForm={() => {
                  resetCategoryForm();
                  changeGachaView("category-new");
                }}
                onSubmitCategory={submitCategory}
                onTagFormChange={(next) => setTagForm((current) => ({ ...current, ...next }))}
                onEditTag={(tag) => {
                  setTagForm({
                    id: String(tag.id),
                    name: tag.name,
                    slug: tag.slug,
                    sortOrder: String(tag.sort_order),
                    isActive: tag.is_active,
                  });
                  changeGachaView("tag-edit", tag.id);
                }}
                onResetTagForm={() => {
                  resetTagForm();
                  changeGachaView("tag-new");
                }}
                onSubmitTag={submitTag}
                onTopBannerFormChange={(next) => setTopBannerForm((current) => ({ ...current, ...next }))}
                onEditTopBanner={editTopBanner}
                onResetTopBannerForm={() => {
                  resetTopBannerForm();
                  changeGachaView("top-banner-new");
                }}
                onSubmitTopBanner={submitTopBanner}
                onBulkUpdateTopBanners={bulkUpdateTopBannerStatus}
                onResetGachaForm={() => {
                  resetGachaForm();
                  changeGachaView("gacha-new");
                }}
                onGachaFormChange={(next) => setGachaForm((current) => ({ ...current, ...next }))}
                onSubmitGacha={submitGacha}
                onActivateGacha={activateSelectedGacha}
                onRankFormChange={(next) => setRankForm((current) => ({ ...current, ...next }))}
                onEditRank={(rank) => {
                  editRank(rank);
                  changeGachaView("rank-edit", rank.id);
                }}
                onResetRankForm={() => {
                  resetRankForm();
                  changeGachaView("rank-new");
                }}
                onPrizeFormChange={(next) => setPrizeForm((current) => ({ ...current, ...next }))}
                onEditPrize={(prize) => {
                  editPrize(prize);
                  changeGachaView("prize-edit", prize.id);
                }}
                onResetPrizeForm={() => {
                  resetPrizeForm(selectedGacha?.ranks?.[0]?.id ? String(selectedGacha.ranks[0].id) : "");
                  changeGachaView("prize-new");
                }}
                onAddProbabilityStage={() => setProbabilityStages((current) => [...current, createEmptyProbabilityStage(selectedGacha, nextProbabilityStageIndex(current))])}
                onRemoveProbabilityStage={(uid) => setProbabilityStages((current) => current.filter((stage) => stage.uid !== uid))}
                onProbabilityStageChange={(uid, next) => setProbabilityStages((current) => current.map((stage) => stage.uid === uid ? { ...stage, ...next } : stage))}
                onProbabilityRowChange={(uid, prizeId, value) => setProbabilityStages((current) => current.map((stage) => stage.uid === uid ? { ...stage, rows: { ...stage.rows, [String(prizeId)]: value } } : stage))}
                onSubmitRank={submitRank}
                onSubmitPrize={submitPrize}
                onPreviewProbability={previewProbability}
                onPublishProbability={publishProbability}
                onUploadImage={uploadImage}
                onUploadVideo={uploadVideo}
              />
              <Pagination meta={pagination.gachas} onPage={(page) => goToPage("gachas", page)} />
            </>
          )}
          {activeTab === "users" && (
            activeUserView === "list" ? (
              <>
                <UserTable rows={users} onDetail={(user) => void selectUser(user.id)} />
                <Pagination meta={pagination.users} onPage={(page) => goToPage("users", page)} />
              </>
            ) : (
              <FormSurface title="ユーザー詳細" backLabel="ユーザー一覧" onBack={() => {
                setActiveUserView("list");
                router.push(adminPathForTab("users"));
              }} wide>
                {selectedUser ? (
                  <div className="user-detail-panel">
                    <div className="detail-heading">
                      <div>
                        <h3>{selectedUser.name}</h3>
                        <p>{selectedUser.email} / ID {selectedUser.id}</p>
                      </div>
                      <StatusBadge value={selectedUser.status} />
                    </div>
                    <div className="mini-metrics">
                      <Metric label="合計残高" value={selectedUser.wallet?.total_balance ?? 0} tone="teal" caption="pt" />
                      <Metric label="有償P" value={selectedUser.wallet?.paid_balance ?? 0} tone="blue" caption="期限なし" />
                      <Metric label="無償P" value={selectedUser.wallet?.free_balance ?? 0} tone="green" caption="期限あり" />
                    </div>
                    <div className="nested-list-section">
                      <div className="subsection-title">
                        <strong>ユーザー状態変更</strong>
                        <span>現在: {statusLabel(selectedUser.status)}</span>
                      </div>
                      <form className="stack-form compact-form" onSubmit={submitSelectedUserStatus}>
                        <div className="inline-fields three">
                          <label>
                            <span>状態</span>
                            <select value={userStatusForm.status} onChange={(event) => setUserStatusForm({ status: event.target.value })}>
                              <option value="active">有効</option>
                              <option value="suspended">停止</option>
                              <option value="withdrawn">退会</option>
                            </select>
                          </label>
                        </div>
                        <button className="primary-button" type="submit" disabled={loading || userStatusForm.status === selectedUser.status}>状態を更新</button>
                      </form>
                    </div>
                    <div className="profile-detail-grid">
                      <DetailItem label="氏名" value={`${selectedUser.profile?.last_name ?? ""} ${selectedUser.profile?.first_name ?? ""}`.trim() || selectedUser.name} />
                      <DetailItem label="カナ" value={`${selectedUser.profile?.last_name_kana ?? ""} ${selectedUser.profile?.first_name_kana ?? ""}`.trim()} />
                      <DetailItem label="電話番号" value={selectedUser.profile?.phone_number ?? ""} />
                      <DetailItem label="生年月日" value={selectedUser.profile?.birth_date ?? ""} />
                      <DetailItem label="郵便番号" value={selectedUser.profile?.postal_code ?? ""} />
                      <DetailItem label="都道府県" value={selectedUser.profile?.prefecture ?? ""} />
                      <DetailItem label="市区町村" value={selectedUser.profile?.city ?? ""} />
                      <DetailItem label="住所1" value={selectedUser.profile?.address_line1 ?? ""} />
                      <DetailItem label="住所2" value={selectedUser.profile?.address_line2 ?? ""} />
                      <DetailItem label="自身の紹介コード" value={selectedUser.referral_code ?? ""} />
                      <DetailItem label="紹介元の紹介コード" value={selectedUserReceivedReferral?.referral_code ?? ""} />
                      <DetailItem label="紹介元ユーザー名" value={userDisplayName(selectedUserReceivedReferral?.referrer)} />
                      <DetailItem label="登録日" value={formatDate(selectedUser.created_at ?? null)} />
                    </div>
                    <div className="nested-list-section">
                      <div className="subsection-title">
                        <strong>ポイント調整</strong>
                        <span>対象ユーザーID {selectedUser.id}</span>
                      </div>
                      <form className="stack-form compact-form" onSubmit={submitSelectedUserPointAdjustment}>
                        <div className="inline-fields three">
                          <label>
                            <span>区分</span>
                            <select value={userPointForm.adjustmentType} onChange={(event) => setUserPointForm((current) => ({ ...current, adjustmentType: event.target.value }))}>
                              <option value="grant">付与</option>
                              <option value="deduct">減算</option>
                            </select>
                          </label>
                          {userPointForm.adjustmentType === "grant" ? (
                            <label>
                              <span>ポイント種別</span>
                              <select value={userPointForm.pointType} onChange={(event) => setUserPointForm((current) => ({ ...current, pointType: event.target.value }))}>
                                <option value="paid">有償</option>
                                <option value="free">無償</option>
                              </select>
                            </label>
                          ) : (
                            <label>
                              <span>ポイント種別</span>
                              <input value="残高から減算" disabled />
                            </label>
                          )}
                          <label>
                            <span>数量</span>
                            <input value={userPointForm.amount} onChange={(event) => setUserPointForm((current) => ({ ...current, amount: event.target.value }))} inputMode="numeric" required />
                          </label>
                        </div>
                        {userPointForm.adjustmentType === "grant" && userPointForm.pointType === "free" && (
                          <label>
                            <span>期限</span>
                            <input value={userPointForm.expireAt} onChange={(event) => setUserPointForm((current) => ({ ...current, expireAt: event.target.value }))} type="datetime-local" required />
                          </label>
                        )}
                        <label>
                          <span>理由</span>
                          <textarea value={userPointForm.reason} onChange={(event) => setUserPointForm((current) => ({ ...current, reason: event.target.value }))} required />
                        </label>
                        <button className="primary-button" type="submit" disabled={loading}>ポイント調整を登録</button>
                      </form>
                    </div>
                    <div className="nested-list-section">
                      <div className="subsection-title">
                        <strong>ポイント調整履歴</strong>
                        <span>{selectedUserPointAdjustments.length.toLocaleString("ja-JP")}件</span>
                      </div>
                      <PointAdjustmentTable rows={selectedUserPointAdjustments} onEdit={(row) => moveToUserRelatedList("points", row.user_id)} />
                    </div>
                    <div className="nested-list-section">
                      <div className="subsection-title">
                        <strong>ポイント購入履歴</strong>
                        <span>{selectedUserPayments.length.toLocaleString("ja-JP")}件</span>
                      </div>
                      <PaymentTable rows={selectedUserPayments} onEdit={(row) => moveToUserRelatedList("payments", row.user?.id ?? selectedUser.id)} />
                    </div>
                    <div className="nested-list-section">
                      <div className="subsection-title">
                        <strong>紹介履歴</strong>
                        <span>{selectedUserMadeReferrals.length.toLocaleString("ja-JP")}件</span>
                      </div>
                      <UserReferralMadeTable rows={selectedUserMadeReferrals} />
                    </div>
                    <div className="nested-list-section">
                      <div className="subsection-title">
                        <strong>ユーザー保有景品</strong>
                        <span>{selectedUserPrizes.length.toLocaleString("ja-JP")}件</span>
                      </div>
                      <PrizeTable rows={selectedUserPrizes} onEdit={(row) => void editUserPrizeFromUserDetail(row)} />
                    </div>
                    <div className="nested-list-section">
                      <div className="subsection-title">
                        <strong>抽選履歴</strong>
                        <span>{selectedUserDraws.length.toLocaleString("ja-JP")}件</span>
                      </div>
                      <DrawTable rows={selectedUserDraws} onEdit={(row) => moveToUserRelatedList("draws", row.user_id)} />
                    </div>
                  </div>
                ) : (
                  <div className="empty-detail compact">ユーザーを選択してください。</div>
                )}
              </FormSurface>
            )
          )}
          {activeTab === "draws" && (
            <>
              <DrawTable rows={draws} />
              <Pagination meta={pagination.draws} onPage={(page) => goToPage("draws", page)} />
            </>
          )}
          {activeTab === "prizes" && (
            <>
              <PrizeTable rows={prizes} />
              <Pagination meta={pagination.prizes} onPage={(page) => goToPage("prizes", page)} />
            </>
          )}
          {activeTab === "shipping" && (
            activeShippingView === "list" ? (
              <>
                <ShippingTable rows={shipping} onEdit={editShippingItem} />
                <Pagination meta={pagination.shipping} onPage={(page) => goToPage("shipping", page)} />
              </>
            ) : (
              <ShippingEditPanel
                request={selectedShippingRequest}
                item={selectedShippingItem}
                form={shippingForm}
                loading={loading}
                onBack={backToShippingList}
                onSubmit={submitShippingRequest}
                onChange={setShippingForm}
              />
            )
          )}
          {activeTab === "payments" && (
            <>
              <PaymentTable rows={payments} />
              <Pagination meta={pagination.payments} onPage={(page) => goToPage("payments", page)} />
            </>
          )}
          {activeTab === "purchasePlans" && (
            activePurchasePlanView === "list" ? (
              <ListSurface
                title="購入プラン一覧"
                actionLabel="購入プラン登録"
                onAction={() => {
                  resetPurchasePlanForm();
                  setActivePurchasePlanView("new");
                  router.push(adminPathForPurchasePlanView("new"));
                }}
              >
                <PointPurchasePlanTable rows={purchasePlans} onEdit={editPurchasePlan} />
                <Pagination meta={pagination.purchasePlans} onPage={(page) => goToPage("purchasePlans", page)} />
              </ListSurface>
            ) : (
              <FormSurface
                title={activePurchasePlanView === "edit" ? "購入プラン編集" : "購入プラン登録"}
                backLabel="購入プラン一覧"
                onBack={() => {
                  setActivePurchasePlanView("list");
                  router.push(adminPathForTab("purchasePlans"));
                }}
              >
                <form className="stack-form compact-form" onSubmit={submitPurchasePlan}>
                  <label>
                    <span>プラン名</span>
                    <input value={purchasePlanForm.name} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, name: event.target.value }))} required />
                  </label>
                  <div className="inline-fields">
                    <label>
                      <span>支払金額</span>
                      <input value={purchasePlanForm.amount} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, amount: event.target.value }))} inputMode="numeric" required />
                    </label>
                    <label>
                      <span>有償ポイント</span>
                      <input value={purchasePlanForm.paidPointAmount} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, paidPointAmount: event.target.value }))} inputMode="numeric" required />
                    </label>
                  </div>
                  <div className="inline-fields">
                    <label>
                      <span>無償ポイント</span>
                      <input value={purchasePlanForm.freePointAmount} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, freePointAmount: event.target.value }))} inputMode="numeric" required />
                    </label>
                    <label>
                      <span>並び順</span>
                      <input value={purchasePlanForm.sortOrder} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, sortOrder: event.target.value }))} inputMode="numeric" required />
                    </label>
                  </div>
                  <div className="inline-fields">
                    <label>
                      <span>開始期限</span>
                      <input type="datetime-local" value={purchasePlanForm.startsAt} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, startsAt: event.target.value }))} />
                    </label>
                    <label>
                      <span>終了期限</span>
                      <input type="datetime-local" value={purchasePlanForm.endsAt} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, endsAt: event.target.value }))} />
                    </label>
                  </div>
                  <label className="check-row">
                    <input type="checkbox" checked={purchasePlanForm.isActive} onChange={(event) => setPurchasePlanForm((current) => ({ ...current, isActive: event.target.checked }))} />
                    <span>有効</span>
                  </label>
                  <button className="primary-button" type="submit" disabled={loading}>{purchasePlanForm.id ? "更新" : "作成"}</button>
                </form>
              </FormSurface>
            )
          )}
          {activeTab === "points" && (
            activePointView === "list" ? (
              <ListSurface
                title="ポイント調整履歴"
                actionLabel="ポイント調整登録"
                onAction={() => {
                  setPointForm({
                    userId: "",
                    adjustmentType: "grant",
                    pointType: "paid",
                    amount: "",
                    expireAt: "",
                    reason: "",
                  });
                  setActivePointView("new");
                  router.push(adminPathForPointView("new"));
                }}
              >
                <PointAdjustmentTable rows={pointAdjustments} />
                <Pagination meta={pagination.points} onPage={(page) => goToPage("points", page)} />
              </ListSurface>
            ) : (
              <FormSurface title="ポイント調整登録" backLabel="ポイント調整履歴" onBack={() => {
                setActivePointView("list");
                router.push(adminPathForTab("points"));
              }}>
              <form className="stack-form compact-form" onSubmit={submitPointAdjustment}>
                <div className="form-title">
                  <strong>ポイント調整</strong>
                  <span>有償は期限なし、無償は期限必須</span>
                </div>
                <label>
                  <span>ユーザーID</span>
                  <input value={pointForm.userId} onChange={(event) => setPointForm({ ...pointForm, userId: event.target.value })} inputMode="numeric" required />
                </label>
                <label>
                  <span>区分</span>
                  <select value={pointForm.adjustmentType} onChange={(event) => setPointForm({ ...pointForm, adjustmentType: event.target.value })}>
                    <option value="grant">付与</option>
                    <option value="deduct">減算</option>
                  </select>
                </label>
                {pointForm.adjustmentType === "grant" && (
                  <label>
                    <span>ポイント種別</span>
                    <select value={pointForm.pointType} onChange={(event) => setPointForm({ ...pointForm, pointType: event.target.value })}>
                      <option value="paid">有償</option>
                      <option value="free">無償</option>
                    </select>
                  </label>
                )}
                <label>
                  <span>数量</span>
                  <input value={pointForm.amount} onChange={(event) => setPointForm({ ...pointForm, amount: event.target.value })} inputMode="numeric" required />
                </label>
                {pointForm.adjustmentType === "grant" && pointForm.pointType === "free" && (
                  <label>
                    <span>期限</span>
                    <input value={pointForm.expireAt} onChange={(event) => setPointForm({ ...pointForm, expireAt: event.target.value })} type="datetime-local" required />
                  </label>
                )}
                <label>
                  <span>理由</span>
                  <textarea value={pointForm.reason} onChange={(event) => setPointForm({ ...pointForm, reason: event.target.value })} required />
                </label>
                <button className="primary-button" type="submit" disabled={loading}>登録</button>
              </form>
              </FormSurface>
            )
          )}
          {activeTab === "settings" && (
            activeSettingView === "list" ? (
              <ListSurface title="設定" actionLabel="更新" onAction={() => void refreshAll()}>
                <div className="toolbar-row">
                  <button className="secondary-button" type="button" onClick={() => {
                    setActiveSettingView("pages");
                    router.push(adminPathForSettingView("pages"));
                  }}>ページ設定</button>
                  <button className="secondary-button" type="button" onClick={() => {
                    setActiveSettingView("rank-assets");
                    router.push(adminPathForSettingView("rank-assets"));
                  }}>ランク演出設定</button>
                  <button className="secondary-button" type="button" onClick={() => {
                    setActiveSettingView("referral");
                    router.push(adminPathForSettingView("referral"));
                  }}>紹介ポイント設定</button>
                </div>
              </ListSurface>
            ) : (
              activeSettingView === "pages" ? (
                <FormSurface title="ページ設定" backLabel="設定" onBack={() => {
                  setActiveSettingView("list");
                  router.push(adminPathForSettingView("list"));
                }} wide>
                  <DataTable
                    headers={["ページ", "URL", "更新日時", "操作"]}
                    rows={staticPages.map((page) => [
                      page.title,
                      <a className="table-link" href={`/${page.slug}`} key="url" target="_blank" rel="noreferrer">/{page.slug}</a>,
                      formatDate(page.updated_at),
                      <button className="secondary-button small-button" type="button" key="edit" onClick={() => editStaticPage(page)}>編集</button>,
                    ])}
                  />
                </FormSurface>
              ) : activeSettingView === "edit" ? (
                <FormSurface title={selectedStaticPage?.title ?? "ページ設定編集"} backLabel="ページ設定" onBack={() => {
                  setActiveSettingView("pages");
                  router.push(adminPathForSettingView("pages"));
                }}>
                  {selectedStaticPage ? (
                  <form className="stack-form compact-form" onSubmit={submitStaticPage}>
                    <label>
                      <span>タイトル</span>
                      <input value={staticPageForm.title} onChange={(event) => setStaticPageForm((current) => ({ ...current, title: event.target.value }))} required />
                    </label>
                    <label>
                      <span>本文内容</span>
                      <textarea className="admin-static-page-body" value={staticPageForm.body} onChange={(event) => setStaticPageForm((current) => ({ ...current, body: event.target.value }))} required />
                    </label>
                    <button className="primary-button" type="submit" disabled={loading}>更新</button>
                  </form>
                  ) : (
                    <div className="empty-detail compact">編集するページを選択してください。</div>
                  )}
                </FormSurface>
              ) : activeSettingView === "rank-assets" ? (
                <ListSurface
                  title="ランク演出設定"
                  actionLabel="演出素材登録"
                  onAction={() => {
                    resetRankAssetForm("image");
                    setActiveSettingView("rank-asset-new");
                    router.push(adminPathForSettingView("rank-asset-new"));
                  }}
                >
                  <div className="toolbar-row">
                    <button className="secondary-button small-button" type="button" onClick={() => {
                      setActiveSettingView("list");
                      router.push(adminPathForSettingView("list"));
                    }}>設定へ戻る</button>
                  </div>
                  <RankAssetTable rows={rankAssets} onEdit={editRankAsset} />
                </ListSurface>
              ) : activeSettingView === "referral" ? (
                <div className="stack-sections">
                  <FormSurface title="紹介ポイント設定" backLabel="設定" onBack={() => {
                    setActiveSettingView("list");
                    router.push(adminPathForSettingView("list"));
                  }}>
                    <form className="stack-form compact-form" onSubmit={submitReferralSetting}>
                      <label>
                        <span>紹介ポイント</span>
                        <input value={referralSettingForm.rewardPointAmount} onChange={(event) => setReferralSettingForm((current) => ({ ...current, rewardPointAmount: event.target.value }))} inputMode="numeric" required />
                      </label>
                      <label>
                        <span>無償ポイント有効期限（日数）</span>
                        <input value={referralSettingForm.rewardExpirationDays} onChange={(event) => setReferralSettingForm((current) => ({ ...current, rewardExpirationDays: event.target.value }))} inputMode="numeric" placeholder="空白なら期限なし" />
                      </label>
                      <label className="check-row">
                        <input type="checkbox" checked={referralSettingForm.isActive} onChange={(event) => setReferralSettingForm((current) => ({ ...current, isActive: event.target.checked }))} />
                        <span>紹介ポイント付与を有効にする</span>
                      </label>
                      <button className="primary-button" type="submit" disabled={loading}>更新</button>
                      {referralSetting ? <p className="inline-note">現在設定: {referralSetting.reward_point_amount.toLocaleString("ja-JP")}pt / 期限 {referralSetting.reward_expiration_days === null ? "なし" : `${referralSetting.reward_expiration_days}日`} / {referralSetting.is_active ? "有効" : "無効"}</p> : null}
                      <p className="inline-note">ポイント付与タイミングはSMS認証完了後に接続予定です。</p>
                    </form>
                  </FormSurface>
                  <ListSurface title="紹介履歴" actionLabel="更新" onAction={() => {
                    void fetchReferralsBySearch();
                  }}>
                    <form className="filter-panel" onSubmit={(event) => {
                      event.preventDefault();
                      void fetchReferralsBySearch();
                    }}>
                      <label>
                        <span>ユーザーID</span>
                        <input value={referralSearchForm.userId} onChange={(event) => setReferralSearchForm({ userId: event.target.value })} inputMode="numeric" placeholder="紹介元・紹介先を検索" />
                      </label>
                      <div className="filter-actions">
                        <button className="secondary-button" type="submit" disabled={loading}>検索</button>
                        <button className="ghost-button" type="button" onClick={clearReferralSearch}>クリア</button>
                      </div>
                    </form>
                    <ReferralTable rows={referrals} />
                  </ListSurface>
                </div>
              ) : (
                <FormSurface title={activeSettingView === "rank-asset-edit" ? "ランク演出設定編集" : "ランク演出設定登録"} backLabel="ランク演出設定" onBack={() => {
                  setActiveSettingView("rank-assets");
                  router.push(adminPathForSettingView("rank-assets"));
                }}>
                  <form className="stack-form compact-form" onSubmit={submitRankAsset}>
                    <label>
                      <span>タイトル</span>
                      <input value={rankAssetForm.title} onChange={(event) => setRankAssetForm((current) => ({ ...current, title: event.target.value }))} required />
                    </label>
                    <label>
                      <span>種別</span>
                      <select value={rankAssetForm.assetType} onChange={(event) => setRankAssetForm((current) => ({ ...current, assetType: event.target.value as "image" | "video", url: "" }))}>
                        <option value="image">画像</option>
                        <option value="video">動画</option>
                      </select>
                    </label>
                    {rankAssetForm.assetType === "image" ? (
                      <ImageUploadField
                        context="rank"
                        label="画像URL"
                        value={rankAssetForm.url}
                        onChange={(value) => setRankAssetForm((current) => ({ ...current, url: value }))}
                        onUploadImage={uploadImage}
                        required
                      />
                    ) : (
                      <VideoUploadField
                        context="draw-video"
                        label="動画URL"
                        value={rankAssetForm.url}
                        onChange={(value) => setRankAssetForm((current) => ({ ...current, url: value }))}
                        onUploadVideo={uploadVideo}
                      />
                    )}
                    <label className="check-row">
                      <input type="checkbox" checked={rankAssetForm.isActive} onChange={(event) => setRankAssetForm((current) => ({ ...current, isActive: event.target.checked }))} />
                      <span>有効</span>
                    </label>
                    <button className="primary-button" type="submit" disabled={loading}>{activeSettingView === "rank-asset-edit" ? "更新" : "作成"}</button>
                  </form>
                </FormSurface>
              )
            )
          )}
        </section>
        )}
      </section>
    </main>
  );
}

function Metric({ label, value, caption, tone, loading = false }: { label: string; value: number; caption: string; tone: string; loading?: boolean }) {
  return (
    <div className={`metric-item tone-${tone}`}>
      <span className="metric-label">{label}</span>
      <strong>{loading ? "-" : value.toLocaleString("ja-JP")}</strong>
      <span className="metric-caption">{loading ? "読み込み中" : caption}</span>
    </div>
  );
}

function OperationGuide() {
  const [activeManual, setActiveManual] = useState<string | null>(null);
  const sections = [
    {
      title: "ガチャ管理",
      tag: "カテゴリ・タグ・ガチャ・ランク・景品",
      description: "カテゴリ、タグ、ガチャ、ランク、景品、確率、限定回数、公開前チェックを扱います。",
      items: [
        "カテゴリ、タグ、ガチャ、ランク、景品はそれぞれ一覧、登録、編集ページに分かれています。",
        "ランク一覧と景品一覧は、対象ガチャを選択してから紐づくデータを確認します。",
        "タグ一覧で表示用タグを作成し、ガチャ登録・編集画面で複数選択できます。",
        "1日の規定回数を入力すると、ユーザーごとの日本時間1日あたりの抽選上限として扱われます。",
        "確率設定は画面上では%で入力し、保存時にバックエンドでppm整数へ変換します。",
        "公開済み確率バージョンは直接編集せず、新しい確率バージョンとして公開します。",
      ],
      fields: [
        {
          name: "確率方式",
          detail: "抽選確率をどの単位で管理するかを選ぶ項目です。単一は販売開始から終了まで同じ確率を使います。販売数ステージは販売済み口数に応じて序盤、中盤、終盤などで確率を変える運用に使います。いずれも実抽選はLaravel側でppm整数として処理します。",
        },
        {
          name: "最低保証",
          detail: "上位景品に当選しなかった場合に返す内容です。ポイントを選ぶと保証値のポイントバック、景品を選ぶと最低保証用景品の排出として扱います。完全ハズレは作らず、抽選結果は必ず景品またはポイントバックになります。",
        },
        {
          name: "保証値",
          detail: "最低保証がポイントの場合に返すポイント数です。例として10を入力すると、最低保証に当たった抽選結果は10ptのポイントバックになります。",
        },
        {
          name: "保証原価",
          detail: "最低保証1回あたりの運営側原価です。利益シミュレーションでは最大原価、単純按分、最悪ケースの確認に使います。保証値と同額にする必要はなく、実際の原価評価に合わせます。",
        },
        {
          name: "目標粗利",
          detail: "完売時売上に対して確保したい粗利率です。公開前チェックでは公開済み確率の期待利益や最大原価シナリオが赤字、未計算、目標未達になっていないかを確認します。",
        },
        {
          name: "1日の規定回数",
          detail: "限定ガチャ用の項目です。空白なら制限なし、数値を入力するとユーザーごとに日本時間の当日0時から23時59分59秒までの抽選回数を制限します。上限超過時はポイント消費も抽選結果作成も行われません。",
        },
        {
          name: "利益シミュレーション",
          detail: "ガチャ編集画面で、登録済みの価格、総口数、景品原価、最低保証原価、目標粗利をもとに利益を確認する機能です。完売時売上、景品原価総額、最低保証最大原価、最大原価合計、想定利益、想定粗利率、目標利益との差分を確認します。公開済み確率がある場合は、確率ベースの1回あたり期待原価、期待原価合計、期待利益、期待粗利率も表示されます。公開前チェックで期待利益が未計算または赤字になる場合は、確率公開後にこの欄で数値を確認してください。",
        },
        {
          name: "商品設計プランナー",
          detail: "ガチャ作成前または編集中に、ランク別の商品数と仕入合計から必要な総口数を試算する補助機能です。粗利率、1回ポイント、円/ptを入力すると、目標総売上、必要口数、完売時利益、損益分岐消化率を確認できます。消化率ごとの想定利益は、景品原価も消化率どおり発生すると見る単純按分と、景品が先にすべて出た前提の最悪ケースの両方で確認します。ここで出た数値は設計補助であり、実際に保存されるのはガチャ基本情報、ランク、景品、確率設定です。",
        },
        {
          name: "確率設定",
          detail: "景品登録後に、各ステージごとに景品別の排出率を%で入力する機能です。画面では%入力ですが、保存・抽選処理ではLaravel側でppm整数に変換します。各ステージは最低保証を含めて合計100%にする必要があります。販売数ステージを使う場合は、開始口数と終了口数の範囲が重複せず、抜けが出ないように設定します。検証ボタンで合計や範囲を確認し、問題がなければ公開ボタンで新しい確率バージョンとして固定します。公開済み確率バージョンは履歴として残り、直接編集しません。",
        },
      ],
      manual: {
        purpose: "販売するガチャと景品構成を作成し、確率公開から稼働化までを管理します。",
        steps: [
          "左メニューのガチャ管理を選択し、子メニューからカテゴリ一覧を開きます。",
          "カテゴリ一覧の登録ボタンを選択し、カテゴリ名、slug、並び順、表示状態を入力して作成します。",
          "タグ一覧を開き、タグ登録ボタンからタグ名、slug、並び順、有効状態を入力して作成します。",
          "子メニューのガチャ一覧を開き、登録ボタンからガチャ新規作成画面へ移動します。",
          "タイトル、カテゴリ、タグ、価格、総口数、最低保証、販売期間、目標粗利、メイン画像、必要に応じて1日の規定回数を入力してガチャを作成します。",
          "設定メニューのランク演出設定で、ランク画像と抽選演出動画をマスタ素材として登録します。",
          "ランク一覧を開き、対象ガチャを選択してS賞、A賞、B賞などのランク名、並び順、表示状態を登録します。",
          "ランク登録または編集画面で、登録済みのランク画像と抽選演出動画を複数選択します。",
          "景品一覧を開き、対象ガチャとランクを選択して景品名、画像、当選上限、原価、表示価格、交換ポイントを登録します。",
          "ガチャ一覧から対象ガチャの編集を開き、商品設計プランナーでランク別商品数、仕入合計、目標粗利から必要口数と消化率別利益を試算します。",
          "利益シミュレーションで最大原価利益、単純按分、最悪ケース、公開済み確率ベースの期待利益を確認します。",
          "確率設定で各景品と最低保証の確率を%で入力し、各ステージ合計が100%になるように調整します。販売数ステージを使う場合はステージ範囲も確認します。",
          "検証ボタンで確率合計、ステージ範囲、最低保証行を確認し、問題がなければ公開ボタンで新しい確率バージョンを公開します。",
          "公開前チェックの不足項目を解消し、すべて通過したら稼働化ボタンでガチャを公開状態にします。",
        ],
        checks: [
          "確率は最低保証を含めて各ステージ合計100%です。",
          "商品設計プランナーは設計補助です。保存・公開判定の正本は登録済みガチャ、景品、公開済み確率、利益シミュレーションです。",
          "利益シミュレーションの期待値は公開済み確率バージョンがないと計算されません。",
          "公開済み確率バージョンは履歴として固定され、直接変更しません。",
          "タグは表示・絞り込み用の分類です。限定回数などの抽選制御はガチャ本体の設定を正とします。",
          "ランクに複数の画像や動画を紐づけた場合、抽選結果ごとに対象素材から1つずつ選ばれます。",
          "1日の規定回数はユーザー単位で判定され、同じガチャを上限以上引くことはできません。",
          "完売ガチャはユーザー側一覧にSOLD OUT表示され、詳細遷移や抽選はできません。",
        ],
      },
    },
    {
      title: "お知らせ・お問い合わせ",
      tag: "告知・顧客対応",
      description: "トップ表示のお知らせ、サムネイル、問い合わせ内容、返信状態を管理します。",
      items: [
        "お知らせ一覧から登録、編集へ移動し、公開状態とトップスライダー表示を管理します。",
        "お知らせサムネイル未設定時はユーザー側でロゴが表示されます。",
        "お問い合わせ一覧から内容確認と返信対応を行います。",
      ],
      manual: {
        purpose: "ユーザーへの告知と問い合わせ対応を管理します。",
        steps: [
          "左メニューのお知らせを選択し、一覧で公開済み、下書き、更新日時を確認します。",
          "お知らせ登録ボタンからタイトル、本文、サムネイル、公開状態を入力します。",
          "トップスライダーに表示する場合は、トップのスライドに表示へチェックを入れます。",
          "登録済みのお知らせを修正する場合は、一覧の編集ボタンから編集ページへ移動します。",
          "左メニューのお問い合わせを選択し、問い合わせ一覧を確認します。",
          "編集ボタンから問い合わせ詳細へ移動し、氏名、メールアドレス、電話番号、問い合わせ内容を確認します。",
          "返信内容を入力して送信し、対応状況を更新します。",
        ],
        checks: [
          "トップスライダーはチェック済みのお知らせとガチャのサムネイルを表示します。",
          "問い合わせ送信時はユーザーへ自動返信メールが送信されます。",
          "管理側の通知は設定済みのDiscord Webhookがある場合のみ送信されます。",
        ],
      },
    },
    {
      title: "ユーザー管理",
      tag: "会員・保有情報",
      description: "ユーザー一覧、詳細、状態変更、保有景品、抽選履歴、購入履歴を確認します。",
      items: [
        "ユーザー一覧から詳細ページへ移動し、プロフィールと状態を確認します。",
        "詳細ページでポイント調整、保有景品、抽選履歴、購入履歴をまとめて確認します。",
        "各一覧の編集ボタンから関連する編集ページへ移動できます。",
      ],
      manual: {
        purpose: "会員の状態、残高、利用履歴、配送関連情報を確認します。",
        steps: [
          "左メニューのユーザー管理を選択します。",
          "ユーザー一覧で氏名、メールアドレス、状態、ポイント残高を確認します。",
          "詳細ボタンを選択し、ユーザー詳細ページへ移動します。",
          "プロフィール欄で氏名、電話番号、配送住所などを確認します。",
          "状態変更セクションで有効、停止などのユーザー状態を必要に応じて変更します。",
          "ポイント調整セクションで補填や減算を行い、理由を残します。",
          "保有景品、抽選履歴、配送、購入履歴の各テーブルを確認します。",
          "各テーブルの編集ボタンを選択すると、対象データの編集または確認ページへ移動します。",
        ],
        checks: [
          "ユーザー状態を変更する場合は、問い合わせや不正利用調査の内容と合わせて確認します。",
          "ポイント調整はポイント台帳に記録されます。",
          "保有景品の編集は配送状態や交換状態と矛盾しないように確認します。",
        ],
      },
    },
    {
      title: "抽選履歴",
      tag: "実行ログ",
      description: "ユーザーごとの抽選リクエストと処理状態を確認します。",
      items: [
        "ステータス、ユーザーID、ガチャIDで対象履歴を絞り込みます。",
        "消費ポイント、抽選回数、処理状態を確認します。",
        "失敗や処理中の履歴がある場合は、関連ユーザーとガチャを確認します。",
      ],
      manual: {
        purpose: "ユーザーが実行した抽選単位の処理状況を確認します。",
        steps: [
          "左メニューの抽選履歴を選択します。",
          "特定ユーザーの履歴を見る場合はユーザーIDを入力します。",
          "特定ガチャの履歴を見る場合はガチャIDを入力します。",
          "処理状態を確認したい場合はステータスを選択して検索します。",
          "一覧のユーザー列で対象ユーザー、ガチャ列で対象ガチャを確認します。",
          "回数列で抽選回数、消費列で消費ポイント合計を確認します。",
          "状態がcompletedの場合は抽選処理が完了しているため、景品箱や抽選結果と照合します。",
          "状態がprocessingの場合は処理中のまま残っていないか、作成日時と合わせて確認します。",
          "状態がfailedの場合はポイント消費、景品付与、関連ログに不整合がないか確認します。",
          "検索条件を外す場合はクリアを選択し、全体の履歴表示へ戻します。",
        ],
        checks: [
          "completedは抽選結果が作成済みです。",
          "processingが長時間残る場合はバックエンド処理状況の確認対象です。",
          "failedはポイント消費や景品付与の状態と合わせて確認します。",
        ],
      },
    },
    {
      title: "景品箱",
      tag: "保有景品",
      description: "ユーザーが獲得した景品の保管、配送依頼、交換状態を確認します。",
      items: [
        "ユーザーIDやガチャIDで対象景品を絞り込みます。",
        "保管期限、交換ポイント、配送依頼状態を確認します。",
        "配送対象の景品は配送ページの申請状況と合わせて確認します。",
      ],
      manual: {
        purpose: "抽選でユーザーに付与された景品の保管状態を確認します。",
        steps: [
          "左メニューの景品箱を選択します。",
          "特定ユーザーの保有景品を確認する場合はユーザーIDを入力します。",
          "特定ガチャで獲得された景品を確認する場合はガチャIDを入力します。",
          "保管中、配送依頼、発送済み、交換済みなどの状態を選択して検索します。",
          "一覧の景品列で景品名、ランク列で当選ランクを確認します。",
          "状態列で現在の保管状態を確認します。",
          "交換P列でポイント交換時に付与されるポイント数を確認します。",
          "期限列で保管期限を確認し、期限切れが近い景品を把握します。",
          "配送依頼中の景品は配送ページへ移動し、配送申請の宛先や状態と照合します。",
          "交換済みの景品はポイント履歴またはポイント調整履歴と合わせて確認します。",
        ],
        checks: [
          "storedは保管中、shipping_requestedは配送申請済みです。",
          "保管期限切れが近い景品は期限管理の確認対象です。",
          "convertedはポイント交換済みのため配送対象ではありません。",
        ],
      },
    },
    {
      title: "配送",
      tag: "申請・追跡",
      description: "景品ごとの配送申請、状態、追跡番号を確認します。",
      items: [
        "配送一覧はまとめ配送ではなく、景品ごとに表示します。",
        "依頼、梱包、発送済み、配達済みなどの状態で絞り込みます。",
        "編集ページで配送状態、追跡番号、メモを更新します。",
      ],
      manual: {
        purpose: "ユーザーから申請された配送対象景品を、景品ごとに管理します。",
        steps: [
          "左メニューの配送を選択します。",
          "配送メニューを選択した場合は必ず配送一覧が表示されます。",
          "特定ユーザーの配送対象を確認する場合はユーザーIDを入力します。",
          "依頼、梱包、発送済み、配達済み、返送、取消などの状態を選択して検索します。",
          "一覧でユーザー、景品、配送先、現在の状態、追跡番号を確認します。",
          "対象行の編集ボタンを選択し、配送編集ページへ移動します。",
          "梱包開始時はpacking、発送後はshipped、配達確認後はdeliveredへ更新します。",
          "発送時は管理側で追跡番号を入力します。",
          "返送や取消が発生した場合は、対象景品箱の状態と配送状態が矛盾しないよう確認します。",
        ],
        checks: [
          "配送状態は景品ごとに更新します。",
          "追跡番号は管理側が入力し、発送済み以降で確認対象です。",
          "配送対象の景品箱ステータスと配送ステータスを照合します。",
          "返送や取消は理由を残せる運用にしておく必要があります。",
        ],
      },
    },
    {
      title: "決済",
      tag: "購入・返金",
      description: "ポイント購入、決済成功、返金、チャージバックを確認します。",
      items: [
        "決済ステータスやプロバイダで対象履歴を絞り込みます。",
        "金額、有償ポイント、無償ポイント、支払日時を確認します。",
        "返金やチャージバックは対象決済とユーザー残高の整合を確認します。",
      ],
      manual: {
        purpose: "ポイント購入と返金、チャージバックの履歴を確認します。",
        steps: [
          "左メニューの決済を選択します。",
          "特定ユーザーの決済を確認する場合はユーザーIDを入力します。",
          "pending、succeeded、failed、canceled、refunded、chargebackなどの状態を選択して検索します。",
          "決済プロバイダを確認したい場合はプロバイダ名を入力して検索します。",
          "一覧の決済列でプロバイダ決済IDを確認します。",
          "金額列で決済金額、有償P列で購入付与ポイント、無償P列でキャンペーン付与ポイントを確認します。",
          "状態列で決済の現在状態を確認します。",
          "日時列で決済作成日時を確認し、問い合わせ日時と照合します。",
          "返金対象は元の決済がsucceededであることを確認してから処理します。",
          "チャージバック対象はユーザー残高、ポイント台帳、監査ログと合わせて確認します。",
        ],
        checks: [
          "succeededのみ購入完了として扱います。",
          "refundedやchargebackはポイント残高との整合が重要です。",
          "本番決済キーには接続せず、環境ごとの設定を確認します。",
        ],
      },
    },
    {
      title: "ポイント",
      tag: "付与・減算",
      description: "管理者によるポイント付与、減算、履歴確認を扱います。",
      items: [
        "ユーザーID、区分、ポイント種別で調整履歴を絞り込みます。",
        "有償ポイントは期限なし、無償ポイントはロットごとに期限を持ちます。",
        "調整登録時は理由を明確に残します。",
      ],
      manual: {
        purpose: "管理者によるポイント付与、補填、減算を記録します。",
        steps: [
          "左メニューのポイントを選択します。",
          "左側のポイント調整フォームに対象ユーザーIDを入力します。",
          "区分で付与または減算を選択します。",
          "付与を選択した場合はポイント種別で有償または無償を選択します。",
          "無償ポイントを付与する場合は期限欄に有効期限を入力します。",
          "数量欄に調整するポイント数を入力します。",
          "理由欄に問い合わせ番号、キャンペーン名、補填理由など後から追跡できる内容を入力します。",
          "登録ボタンを選択し、成功メッセージが表示されることを確認します。",
          "右側のポイント調整履歴に登録内容が追加されたことを確認します。",
          "必要に応じてユーザーID、区分、種別で履歴を検索し、過去の調整履歴と重複していないか確認します。",
        ],
        checks: [
          "有償ポイントは期限なし、無償ポイントのみ期限ありです。",
          "無償ポイントは獲得ロットごとに期限を持ち、期限が切れたロットから失効します。",
          "ユーザー側のポイント履歴では、有効期限が1か月を切った無償ポイントが赤文字で表示されます。",
          "減算時は理由を明確に残します。",
          "大量調整はユーザー残高、台帳、監査ログの整合確認が必要です。",
        ],
      },
    },
    {
      title: "購入プラン",
      tag: "ポイント購入",
      description: "ユーザー側のポイント購入画面に表示する購入プランを管理します。",
      items: [
        "購入プラン一覧から登録、編集へ移動します。",
        "金額、有償ポイント、無償ポイント、表示状態、並び順を管理します。",
        "ユーザー側の購入画面は管理画面で有効なプランを表示します。",
      ],
      manual: {
        purpose: "ポイント購入プランの表示内容を管理します。",
        steps: [
          "左メニューの購入プランを選択します。",
          "一覧で現在登録されているプラン、金額、付与ポイント、表示状態を確認します。",
          "登録ボタンから購入プラン登録ページへ移動します。",
          "プラン名、金額、有償ポイント、無償ポイント、並び順、表示状態を入力します。",
          "既存プランを変更する場合は、一覧の編集ボタンから編集ページへ移動します。",
          "ユーザー側のポイント購入画面で表示内容を確認します。",
        ],
        checks: [
          "有償ポイントは購入対価として付与され、期限なしです。",
          "無償ポイントは特典分として付与され、期限管理対象です。",
          "非表示にしたプランはユーザー側の購入画面に表示されません。",
        ],
      },
    },
    {
      title: "設定",
      tag: "規約・演出素材",
      description: "規約ページ、プライバシーポリシー、特商法表記、ランク演出素材を管理します。",
      items: [
        "利用規約、プライバシーポリシー、特商法表記などの固定ページを編集します。",
        "ランク演出設定で、ランク画像と抽選演出動画をマスタ素材として登録します。",
        "登録した演出素材はランク登録、編集画面で複数選択できます。",
      ],
      manual: {
        purpose: "サイト共通の表示内容とランク演出素材を管理します。",
        steps: [
          "左メニューの設定を選択します。",
          "固定ページを編集する場合は、対象ページの編集ボタンを選択します。",
          "タイトルと本文内容を編集し、更新ボタンで保存します。",
          "ランク演出素材を追加する場合は、ランク演出設定の演出素材登録ボタンを選択します。",
          "タイトル、種別、画像または動画URL、有効状態を入力して作成します。",
          "登録済み素材を変更する場合は、ランク演出設定一覧の編集ボタンを選択します。",
          "ガチャ管理のランク登録または編集画面で、登録した画像と動画を選択して紐づけます。",
        ],
        checks: [
          "ランク画像は抽選結果画面のメイン画像として使われます。",
          "抽選演出動画は抽選ボタン押下後に全画面で再生されます。",
          "ランクに複数素材がある場合、抽選結果ごとに1つずつ選ばれ、過去結果の表示は後から変わりません。",
        ],
      },
    },
  ];
  const selectedManual = sections.find((section) => section.title === activeManual) ?? null;

  if (selectedManual) {
    return (
      <div className="operation-guide">
        <div className="guide-manual-page">
          <div className="guide-manual-head">
            <div>
              <span>{selectedManual.tag}</span>
              <h3>{selectedManual.title}マニュアル</h3>
              <p>{selectedManual.manual.purpose}</p>
            </div>
            <button className="secondary-button" type="button" onClick={() => setActiveManual(null)}>操作ガイドへ戻る</button>
          </div>

          <div className="manual-layout">
            <section className="manual-block">
              <h4>操作手順</h4>
              <ol>
                {selectedManual.manual.steps.map((step) => (
                  <li key={step}>{step}</li>
                ))}
              </ol>
            </section>
            <section className="manual-block">
              <h4>確認ポイント</h4>
              <ul>
                {selectedManual.manual.checks.map((check) => (
                  <li key={check}>{check}</li>
                ))}
              </ul>
            </section>
          </div>
          {"fields" in selectedManual && selectedManual.fields && (
            <section className="manual-block manual-field-block">
              <h4>ガチャ管理項目の説明</h4>
              <div className="manual-field-list">
                {selectedManual.fields.map((field) => (
                  <div className="manual-field-item" key={field.name}>
                    <strong>{field.name}</strong>
                    <p>{field.detail}</p>
                  </div>
                ))}
              </div>
            </section>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="operation-guide">
      <div className="guide-intro">
        <div>
          <h3>管理画面 操作ガイド</h3>
          <p>左メニューの各ページで確認する内容と、主な運用作業をまとめています。</p>
        </div>
        <span>Guide</span>
      </div>

      <div className="guide-section-grid">
        {sections.map((section) => (
          <section className="guide-section" key={section.title}>
            <div className="guide-section-head">
              <div>
                <h4>{section.title}</h4>
                <p>{section.description}</p>
              </div>
              <span>{section.tag}</span>
            </div>
            <ol>
              {section.items.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ol>
            <div className="guide-section-actions">
              <button className="secondary-button" type="button" onClick={() => setActiveManual(section.title)}>マニュアル</button>
            </div>
          </section>
        ))}
      </div>
    </div>
  );
}

function ProfitSimulationPanel({ simulation }: { simulation: ProfitSimulation }) {
  return (
    <div className="simulation-panel">
      <div className="form-title form-title-row">
        <span>
          <strong>利益シミュレーション</strong>
          <span>完売時売上と最大原価から粗利を計算</span>
        </span>
        <StatusBadge value={simulation.profit.meets_target === false ? "failed" : "completed"} />
      </div>
      <div className="simulation-grid">
        <SimulationItem label="完売時売上" value={moneyLabel(simulation.sales.total_sales)} />
        <SimulationItem label="景品原価総額" value={moneyLabel(simulation.costs.prize_inventory_cost)} />
        <SimulationItem label="最低保証最大原価" value={moneyLabel(simulation.costs.minimum_guarantee_max_cost)} />
        <SimulationItem label="最大原価合計" value={moneyLabel(simulation.costs.max_cost)} />
        <SimulationItem label="想定利益" value={moneyLabel(simulation.profit.projected_profit)} emphasis={simulation.profit.projected_profit >= 0 ? "positive" : "negative"} />
        <SimulationItem label="想定粗利率" value={simulation.profit.projected_margin_rate !== null ? `${simulation.profit.projected_margin_rate.toLocaleString("ja-JP")}%` : "-"} />
        <SimulationItem label="目標利益" value={simulation.profit.target_profit !== null ? moneyLabel(simulation.profit.target_profit) : "-"} />
        <SimulationItem label="目標差分" value={simulation.profit.gap_to_target_profit !== null ? moneyLabel(simulation.profit.gap_to_target_profit) : "-"} emphasis={(simulation.profit.gap_to_target_profit ?? 0) >= 0 ? "positive" : "negative"} />
      </div>
      <div className="form-title">
        <strong>確率ベース期待値</strong>
        <span>公開済み確率バージョンから1回あたり期待原価と完売時期待利益を計算</span>
      </div>
      {simulation.expected.available ? (
        <>
          <div className="simulation-grid compact">
            <SimulationItem label="1回あたり期待原価" value={simulation.expected.expected_cost_per_draw !== null ? moneyLabel(simulation.expected.expected_cost_per_draw) : "-"} />
            <SimulationItem label="期待原価合計" value={simulation.expected.expected_total_cost !== null ? moneyLabel(simulation.expected.expected_total_cost) : "-"} />
            <SimulationItem label="期待利益" value={simulation.expected.expected_profit !== null ? moneyLabel(simulation.expected.expected_profit) : "-"} emphasis={(simulation.expected.expected_profit ?? 0) >= 0 ? "positive" : "negative"} />
            <SimulationItem label="期待粗利率" value={simulation.expected.expected_margin_rate !== null ? `${simulation.expected.expected_margin_rate.toLocaleString("ja-JP")}%` : "-"} />
          </div>
          {simulation.expected.stages.length > 0 && (
            <div className="stage-simulation-list">
              {simulation.expected.stages.map((stage) => (
                <div className="stage-simulation-row" key={stage.stage_key}>
                  <span>{stage.name}</span>
                  <small>{stage.draw_count.toLocaleString("ja-JP")}口</small>
                  <strong>{moneyLabel(stage.expected_cost_per_draw)} / 回</strong>
                  <strong>{moneyLabel(stage.expected_total_cost)}</strong>
                </div>
              ))}
            </div>
          )}
        </>
      ) : (
        <div className="empty-detail compact">公開済み確率がないため、期待原価は未計算です。</div>
      )}
      {simulation.warnings.length > 0 && (
        <div className="simulation-warnings">
          {simulation.warnings.map((warning) => (
            <span key={warning}>{warning}</span>
          ))}
        </div>
      )}
    </div>
  );
}

function PricingPlannerPanel({ selectedGacha, gachaForm }: { selectedGacha: Gacha | null; gachaForm: { price: string; targetMargin: string } }) {
  const initialRanks = useMemo(() => {
    const ranks = selectedGacha?.ranks ?? [];

    // ガチャ未選択やランク未登録でも試算できるよう、標準的なS/A/Bの仮値を用意する。
    if (ranks.length === 0) {
      return [
        { key: "S", label: "S", count: 3, cost: 150000 },
        { key: "A", label: "A", count: 10, cost: 100000 },
        { key: "B", label: "B", count: 50, cost: 50000 },
      ];
    }

    return ranks.map((rank) => {
      const prizes = rank.prizes ?? [];

      // 登録済み景品がある場合は、当選上限と原価からランク別仕入を初期値にする。
      return {
        key: String(rank.id),
        label: rank.display_name || rank.rank_key,
        count: prizes.reduce((sum, prize) => sum + Number(prize.max_win_count || 0), 0),
        cost: prizes.reduce((sum, prize) => sum + Number(prize.cost_price || 0) * Number(prize.max_win_count || 0), 0),
      };
    });
  }, [selectedGacha?.id, selectedGacha?.ranks]);

  const [rankRows, setRankRows] = useState(initialRanks);
  const [marginPct, setMarginPct] = useState(Number(gachaForm.targetMargin || 20));
  const [pointPrice, setPointPrice] = useState(Number(gachaForm.price || selectedGacha?.price || 1000));
  const [yenPerPoint, setYenPerPoint] = useState(1);

  useEffect(() => {
    setRankRows(initialRanks);
  }, [initialRanks]);

  useEffect(() => {
    setPointPrice(Number(gachaForm.price || selectedGacha?.price || 1000));
  }, [gachaForm.price, selectedGacha?.price]);

  useEffect(() => {
    setMarginPct(Number(gachaForm.targetMargin || 20));
  }, [gachaForm.targetMargin, selectedGacha?.id]);

  const setRankValue = (key: string, field: "count" | "cost", value: string) => {
    const nextValue = Math.max(0, Number(value || 0));
    setRankRows((rows) => rows.map((row) => row.key === key ? { ...row, [field]: nextValue } : row));
  };

  const calc = useMemo(() => {
    const totalCost = rankRows.reduce((sum, row) => sum + row.cost, 0);
    const totalPrizes = rankRows.reduce((sum, row) => sum + row.count, 0);
    const margin = marginPct / 100;
    const targetRevenue = totalCost * (1 + margin);
    const revenuePerDraw = pointPrice * yenPerPoint;
    const drawCountRaw = revenuePerDraw > 0 ? targetRevenue / revenuePerDraw : Number.NaN;
    const drawCount = Number.isFinite(drawCountRaw) ? Math.ceil(drawCountRaw) : Number.NaN;
    const fullRevenue = Number.isFinite(drawCount) ? drawCount * revenuePerDraw : Number.NaN;
    const fullProfit = fullRevenue - totalCost;
    const breakEvenWorst = Number.isFinite(fullRevenue) && fullRevenue > 0 ? totalCost / fullRevenue : Number.NaN;
    const rows = [0.1, 0.25, 0.5, 0.75, 0.9, 1].map((rate) => {
      const soldDraws = Number.isFinite(drawCount) ? Math.round(drawCount * rate) : Number.NaN;
      const revenue = Number.isFinite(fullRevenue) ? fullRevenue * rate : Number.NaN;

      return {
        rate,
        soldDraws,
        revenue,
        // 単純按分は景品原価も消化率どおり発生する楽観寄りの見方。
        profitProrated: revenue - totalCost * rate,
        // 最悪利益は景品が先に全部出た前提で、保守的な資金繰りを見る。
        profitWorst: revenue - totalCost,
      };
    });

    return { totalCost, totalPrizes, targetRevenue, revenuePerDraw, drawCount, fullRevenue, fullProfit, breakEvenWorst, rows };
  }, [rankRows, marginPct, pointPrice, yenPerPoint]);

  return (
    <div className="simulation-panel pricing-planner-panel">
      <div className="form-title form-title-row">
        <span>
          <strong>商品設計プランナー</strong>
          <span>ランク別仕入から必要口数と消化率別利益を試算</span>
        </span>
      </div>

      <div className="pricing-planner-grid">
        <div className="planner-input-card">
          <div className="planner-table-head">
            <span>ランク</span>
            <span>商品数</span>
            <span>仕入合計</span>
          </div>
          {rankRows.map((row) => (
            <div className="planner-rank-row" key={row.key}>
              <strong>{row.label}</strong>
              <input value={String(row.count)} onChange={(event) => setRankValue(row.key, "count", event.target.value)} inputMode="numeric" />
              <input value={String(row.cost)} onChange={(event) => setRankValue(row.key, "cost", event.target.value)} inputMode="numeric" />
            </div>
          ))}
          <div className="planner-subtotal">
            <span>合計</span>
            <strong>{calc.totalPrizes.toLocaleString("ja-JP")}点</strong>
            <strong>{moneyLabel(Math.round(calc.totalCost))}</strong>
          </div>
          <div className="inline-fields three planner-controls">
            <label>
              <span>粗利率</span>
              <input value={String(marginPct)} onChange={(event) => setMarginPct(Math.max(0, Number(event.target.value || 0)))} inputMode="decimal" />
            </label>
            <label>
              <span>1回ポイント</span>
              <input value={String(pointPrice)} onChange={(event) => setPointPrice(Math.max(0, Number(event.target.value || 0)))} inputMode="numeric" />
            </label>
            <label>
              <span>円 / pt</span>
              <input value={String(yenPerPoint)} onChange={(event) => setYenPerPoint(Math.max(0, Number(event.target.value || 0)))} inputMode="decimal" />
            </label>
          </div>
        </div>

        <div className="planner-result-card">
          <div className="planner-big-stat">
            <span>必要な総口数</span>
            <strong>{formatFiniteNumber(calc.drawCount)}<small>口</small></strong>
            <small>1回 {formatFiniteNumber(pointPrice)}pt（{formatFiniteMoney(calc.revenuePerDraw)}）で販売</small>
          </div>
          <div className="simulation-grid compact">
            <SimulationItem label="目標総売上" value={formatFiniteMoney(calc.targetRevenue)} />
            <SimulationItem label="完売時売上" value={formatFiniteMoney(calc.fullRevenue)} />
            <SimulationItem label="完売時利益" value={formatFiniteMoney(calc.fullProfit)} emphasis={calc.fullProfit >= 0 ? "positive" : "negative"} />
            <SimulationItem label="損益分岐消化率" value={formatFinitePercent(calc.breakEvenWorst)} />
          </div>
        </div>
      </div>

      <div className="planner-profit-table">
        <div className="stage-simulation-row planner-profit-head">
          <span>消化率</span>
          <small>販売口数</small>
          <strong>売上</strong>
          <strong>単純按分利益</strong>
          <strong>最悪利益</strong>
        </div>
        {calc.rows.map((row) => (
          <div className="stage-simulation-row" key={row.rate}>
            <span>{formatFinitePercent(row.rate)}</span>
            <small>{formatFiniteNumber(row.soldDraws)}口</small>
            <strong>{formatFiniteMoney(row.revenue)}</strong>
            <strong className={row.profitProrated >= 0 ? "text-positive" : "text-negative"}>{formatFiniteMoney(row.profitProrated)}</strong>
            <strong className={row.profitWorst >= 0 ? "text-positive" : "text-negative"}>{formatFiniteMoney(row.profitWorst)}</strong>
          </div>
        ))}
      </div>
      <p className="inline-note">概算です。決済手数料・送料・景品交換による還元などは含みません。</p>
    </div>
  );
}

function SimulationItem({ label, value, emphasis }: { label: string; value: string; emphasis?: "positive" | "negative" }) {
  return (
    <div className={`simulation-item ${emphasis ? `simulation-${emphasis}` : ""}`}>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function SectionHeader({ title, description, loading }: { title: string; description: string; loading: boolean }) {
  return (
    <div className="section-header">
      <div>
        <h2>{title}</h2>
        <p>{description}</p>
      </div>
      <span className={`sync-state ${loading ? "loading" : ""}`}>{loading ? "同期中" : "最新"}</span>
    </div>
  );
}

function Notice({ tone, message }: { tone: NoticeTone; message: string }) {
  if (!message) {
    return null;
  }

  return (
    <div className={`flash-message flash-${tone}`} role={tone === "error" ? "alert" : "status"} aria-live="polite">
      <span>{tone === "success" ? "成功" : tone === "error" ? "失敗" : "通知"}</span>
      <p>{message}</p>
    </div>
  );
}

function ListSurface({
  title,
  actionLabel,
  onAction,
  children,
}: {
  title: string;
  actionLabel: string;
  onAction: () => void;
  children: ReactNode;
}) {
  return (
    <div className="subpage-surface">
      <div className="subpage-title">
        <h3>{title}</h3>
        <button className="primary-button" type="button" onClick={onAction}>{actionLabel}</button>
      </div>
      {children}
    </div>
  );
}

function FormSurface({
  title,
  backLabel,
  onBack,
  children,
  wide = false,
}: {
  title: string;
  backLabel: string;
  onBack: () => void;
  children: ReactNode;
  wide?: boolean;
}) {
  return (
    <div className={`subpage-surface ${wide ? "wide" : "narrow"}`}>
      <div className="subpage-title">
        <h3>{title}</h3>
        <button className="secondary-button" type="button" onClick={onBack}>{backLabel}</button>
      </div>
      {children}
    </div>
  );
}

function FilterPanel({
  tab,
  filters,
  onChange,
  onSubmit,
  onClear,
}: {
  tab: TabKey;
  filters: FilterState;
  onChange: (name: string, value: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onClear: () => void;
}) {
  return (
    <form className="filter-panel" onSubmit={onSubmit}>
      {["draws", "prizes", "shipping", "payments", "points"].includes(tab) && (
        <label>
          <span>ユーザーID</span>
          <input value={filters.user_id ?? ""} onChange={(event) => onChange("user_id", event.target.value)} inputMode="numeric" />
        </label>
      )}

      {tab === "gachas" && (
        <SelectField
          label="状態"
          value={filters.status ?? ""}
          onChange={(value) => onChange("status", value)}
          options={[
            ["", "すべて"],
            ["draft", "下書き"],
            ["scheduled", "予定"],
            ["active", "稼働中"],
            ["paused", "停止"],
            ["sold_out", "完売"],
            ["ended", "終了"],
            ["hidden", "非表示"],
          ]}
        />
      )}

      {tab === "users" && (
        <>
          <label>
            <span>名前・メール</span>
            <input value={filters.q ?? ""} onChange={(event) => onChange("q", event.target.value)} />
          </label>
          <SelectField
            label="状態"
            value={filters.status ?? ""}
            onChange={(value) => onChange("status", value)}
            options={[
              ["", "すべて"],
              ["active", "有効"],
              ["suspended", "停止"],
              ["withdrawn", "退会"],
            ]}
          />
        </>
      )}

      {tab === "announcements" && (
        <SelectField
          label="状態"
          value={filters.status ?? ""}
          onChange={(value) => onChange("status", value)}
          options={[
            ["", "すべて"],
            ["draft", "下書き"],
            ["published", "公開"],
            ["hidden", "非表示"],
          ]}
        />
      )}

      {tab === "contacts" && (
        <>
          <SelectField
            label="状態"
            value={filters.status ?? ""}
            onChange={(value) => onChange("status", value)}
            options={[
              ["", "すべて"],
              ["new", "未対応"],
              ["replied", "返信済み"],
              ["closed", "完了"],
            ]}
          />
          <label>
            <span>メール</span>
            <input value={filters.email ?? ""} onChange={(event) => onChange("email", event.target.value)} />
          </label>
        </>
      )}

      {(tab === "draws" || tab === "prizes") && (
        <label>
          <span>ガチャID</span>
          <input value={filters.gacha_id ?? ""} onChange={(event) => onChange("gacha_id", event.target.value)} inputMode="numeric" />
        </label>
      )}

      {tab === "draws" && (
        <SelectField
          label="状態"
          value={filters.status ?? ""}
          onChange={(value) => onChange("status", value)}
          options={[
            ["", "すべて"],
            ["processing", "処理中"],
            ["completed", "完了"],
            ["failed", "失敗"],
          ]}
        />
      )}

      {tab === "prizes" && (
        <SelectField
          label="状態"
          value={filters.status ?? ""}
          onChange={(value) => onChange("status", value)}
          options={[
            ["", "すべて"],
            ["stored", "保管中"],
            ["shipping_requested", "配送依頼"],
            ["shipped", "発送済み"],
            ["converted", "交換済み"],
            ["expired", "期限切れ"],
          ]}
        />
      )}

      {tab === "shipping" && (
        <SelectField
          label="状態"
          value={filters.status ?? ""}
          onChange={(value) => onChange("status", value)}
          options={[
            ["", "すべて"],
            ["requested", "依頼"],
            ["packing", "梱包"],
            ["shipped", "発送済み"],
            ["delivered", "配達済み"],
            ["returned", "返送"],
            ["canceled", "取消"],
          ]}
        />
      )}

      {tab === "payments" && (
        <>
          <SelectField
            label="状態"
            value={filters.status ?? ""}
            onChange={(value) => onChange("status", value)}
            options={[
              ["", "すべて"],
              ["pending", "保留"],
              ["succeeded", "成功"],
              ["failed", "失敗"],
              ["canceled", "取消"],
              ["refunded", "返金"],
              ["chargeback", "CB"],
            ]}
          />
          <label>
            <span>プロバイダ</span>
            <input value={filters.provider ?? ""} onChange={(event) => onChange("provider", event.target.value)} />
          </label>
        </>
      )}

      {tab === "purchasePlans" && (
        <SelectField
          label="状態"
          value={filters.is_active ?? ""}
          onChange={(value) => onChange("is_active", value)}
          options={[
            ["", "すべて"],
            ["true", "有効"],
            ["false", "無効"],
          ]}
        />
      )}

      {tab === "points" && (
        <>
          <SelectField
            label="区分"
            value={filters.adjustment_type ?? ""}
            onChange={(value) => onChange("adjustment_type", value)}
            options={[
              ["", "すべて"],
              ["grant", "付与"],
              ["deduct", "減算"],
            ]}
          />
          <SelectField
            label="種別"
            value={filters.point_type ?? ""}
            onChange={(value) => onChange("point_type", value)}
            options={[
              ["", "すべて"],
              ["paid", "有償"],
              ["free", "無償"],
            ]}
          />
        </>
      )}

      <div className="filter-actions">
        <button className="secondary-button" type="submit">検索</button>
        <button className="ghost-button" type="button" onClick={onClear}>クリア</button>
      </div>
    </form>
  );
}

function SelectField({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: [string, string][];
  onChange: (value: string) => void;
}) {
  return (
    <label>
      <span>{label}</span>
      <select value={value} onChange={(event) => onChange(event.target.value)}>
        {options.map(([optionValue, optionLabel]) => (
          <option key={optionValue} value={optionValue}>{optionLabel}</option>
        ))}
      </select>
    </label>
  );
}

function ImageUploadField({
  label,
  value,
  context,
  required = false,
  onChange,
  onUploadImage,
}: {
  label: string;
  value: string;
  context: AssetContext;
  required?: boolean;
  onChange: (value: string) => void;
  onUploadImage: (context: AssetContext, file: File) => Promise<string>;
}) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState("");

  async function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    if (!file) {
      return;
    }

    setUploading(true);
    setError("");

    try {
      const url = await onUploadImage(context, file);
      onChange(url);
    } catch (uploadError) {
      setError(uploadError instanceof Error ? uploadError.message : "画像アップロードに失敗しました");
    } finally {
      setUploading(false);
      event.target.value = "";
    }
  }

  return (
    <div className="image-upload-field">
      <label>
        <span className="upload-field-label">
          {label}
          <small>画像は5MBまで</small>
        </span>
        <input value={value} onChange={(event) => onChange(event.target.value)} required={required} />
      </label>
      <div className="image-upload-actions">
        <label className="file-button">
          <input type="file" accept="image/*" onChange={handleFileChange} disabled={uploading} />
          <span>{uploading ? "アップロード中" : "画像を選択"}</span>
        </label>
        {value && (
          <a className="ghost-button link-button" href={value} target="_blank" rel="noreferrer">表示</a>
        )}
      </div>
      {value && (
        <div className="image-preview" aria-hidden="true">
          <span className="image-preview-asset" style={{ backgroundImage: `url("${value}")` }} />
        </div>
      )}
      {error && <p className="field-error">{error}</p>}
    </div>
  );
}

function VideoUploadField({
  label,
  value,
  context,
  onChange,
  onUploadVideo,
}: {
  label: string;
  value: string;
  context: AssetContext;
  onChange: (value: string) => void;
  onUploadVideo: (context: AssetContext, file: File) => Promise<string>;
}) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState("");

  async function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    if (!file) {
      return;
    }

    setUploading(true);
    setError("");

    try {
      const url = await onUploadVideo(context, file);
      onChange(url);
    } catch (uploadError) {
      setError(uploadError instanceof Error ? uploadError.message : "動画アップロードに失敗しました");
    } finally {
      setUploading(false);
      event.target.value = "";
    }
  }

  return (
    <div className="image-upload-field">
      <label>
        <span className="upload-field-label">
          {label}
          <small>動画は50MBまで</small>
        </span>
        <input value={value} onChange={(event) => onChange(event.target.value)} />
      </label>
      <div className="image-upload-actions">
        <label className="file-button">
          <input type="file" accept="video/mp4,video/webm,video/quicktime" onChange={handleFileChange} disabled={uploading} />
          <span>{uploading ? "アップロード中" : "動画を選択"}</span>
        </label>
        {value && (
          <a className="ghost-button link-button" href={value} target="_blank" rel="noreferrer">表示</a>
        )}
      </div>
      {value && (
        <video className="video-preview" src={value} controls muted playsInline preload="metadata" />
      )}
      {error && <p className="field-error">{error}</p>}
    </div>
  );
}

function Pagination({ meta, onPage }: { meta: PaginationMeta | null; onPage: (page: number) => void }) {
  if (!meta) {
    return null;
  }

  const prevPage = Math.max(1, meta.current_page - 1);
  const nextPage = Math.min(meta.last_page, meta.current_page + 1);

  return (
    <div className="pagination-bar">
      <span>
        {meta.total.toLocaleString("ja-JP")}件中 {meta.from ?? 0}-{meta.to ?? 0}件
      </span>
      <div>
        <button className="secondary-button" type="button" disabled={meta.current_page <= 1} onClick={() => onPage(prevPage)}>前へ</button>
        <span className="page-count">{meta.current_page} / {meta.last_page}</span>
        <button className="secondary-button" type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(nextPage)}>次へ</button>
      </div>
    </div>
  );
}

function GachaManagement({
  activeView,
  rows,
  categories,
  tags,
  topBanners,
  gachaRanks,
  gachaPrizes,
  rankAssets,
  categoryForm,
  tagForm,
  topBannerForm,
  selectedGacha,
  readiness,
  gachaForm,
  rankForm,
  prizeForm,
  probabilityStages,
  probabilityPreview,
  profitSimulation,
  loading,
  onSelect,
  onLoadGacha,
  onChangeView,
  onCategoryFormChange,
  onEditCategory,
  onResetCategoryForm,
  onSubmitCategory,
  onTagFormChange,
  onEditTag,
  onResetTagForm,
  onSubmitTag,
  onTopBannerFormChange,
  onEditTopBanner,
  onResetTopBannerForm,
  onSubmitTopBanner,
  onBulkUpdateTopBanners,
  onResetGachaForm,
  onGachaFormChange,
  onSubmitGacha,
  onActivateGacha,
  onRankFormChange,
  onEditRank,
  onResetRankForm,
  onPrizeFormChange,
  onEditPrize,
  onResetPrizeForm,
  onAddProbabilityStage,
  onRemoveProbabilityStage,
  onProbabilityStageChange,
  onProbabilityRowChange,
  onSubmitRank,
  onSubmitPrize,
  onPreviewProbability,
  onPublishProbability,
  onUploadImage,
  onUploadVideo,
}: {
  activeView: GachaAdminView;
  rows: Gacha[];
  categories: GachaCategory[];
  tags: GachaTag[];
  topBanners: TopBanner[];
  gachaRanks: GachaRank[];
  gachaPrizes: GachaPrize[];
  rankAssets: RankAsset[];
  categoryForm: {
    id: string;
    name: string;
    slug: string;
    sortOrder: string;
    isVisible: boolean;
  };
  tagForm: {
    id: string;
    name: string;
    slug: string;
    sortOrder: string;
    isActive: boolean;
  };
  topBannerForm: {
    id: string;
    imageUrl: string;
    linkUrl: string;
    sortOrder: string;
    isActive: boolean;
  };
  selectedGacha: Gacha | null;
  readiness: GachaReadiness | null;
  gachaForm: {
    id: string;
    title: string;
    slug: string;
    categoryId: string;
    price: string;
    totalCount: string;
    dailyDrawLimit: string;
    probabilityMode: string;
    minimumGuaranteeType: string;
    minimumGuaranteeValue: string;
    minimumGuaranteeCost: string;
    status: string;
    startAt: string;
    endAt: string;
    description: string;
    caution: string;
    mainImageUrl: string;
    showOnTopSlider: boolean;
    targetMargin: string;
    tagIds: string[];
  };
  rankForm: {
    id: string;
    rankKey: string;
    displayName: string;
    description: string;
    imageUrl: string;
    rankImageAssetId: string;
    rankImageAssetIds: string[];
    drawVideoUrl: string;
    drawVideoAssetId: string;
    drawVideoAssetIds: string[];
    resultImageUrl: string;
    sortOrder: string;
    isVisible: boolean;
  };
  prizeForm: {
    id: string;
    rankId: string;
    name: string;
    imageUrl: string;
    maxWinCount: string;
    costPrice: string;
    displayPrice: string;
    exchangePoint: string;
    condition: string;
    isActive: boolean;
    isVisible: boolean;
    sortOrder: string;
  };
  probabilityStages: ProbabilityStageForm[];
  probabilityPreview: ProbabilityPreview["data"] | null;
  profitSimulation: ProfitSimulation | null;
  loading: boolean;
  onSelect: (gachaId: number) => void | Promise<void>;
  onLoadGacha: (gachaId: number) => void | Promise<void>;
  onChangeView: (view: GachaAdminView) => void;
  onCategoryFormChange: (next: Partial<typeof categoryForm>) => void;
  onEditCategory: (category: GachaCategory) => void;
  onResetCategoryForm: () => void;
  onSubmitCategory: (event: FormEvent<HTMLFormElement>) => void;
  onTagFormChange: (next: Partial<typeof tagForm>) => void;
  onEditTag: (tag: GachaTag) => void;
  onResetTagForm: () => void;
  onSubmitTag: (event: FormEvent<HTMLFormElement>) => void;
  onTopBannerFormChange: (next: Partial<typeof topBannerForm>) => void;
  onEditTopBanner: (banner: TopBanner) => void;
  onResetTopBannerForm: () => void;
  onSubmitTopBanner: (event: FormEvent<HTMLFormElement>) => void;
  onBulkUpdateTopBanners: (ids: number[], isActive: boolean) => Promise<void>;
  onResetGachaForm: () => void;
  onGachaFormChange: (next: Partial<typeof gachaForm>) => void;
  onSubmitGacha: (event: FormEvent<HTMLFormElement>) => void;
  onActivateGacha: () => void;
  onRankFormChange: (next: Partial<typeof rankForm>) => void;
  onEditRank: (rank: GachaRank) => void;
  onResetRankForm: () => void;
  onPrizeFormChange: (next: Partial<typeof prizeForm>) => void;
  onEditPrize: (prize: GachaPrize) => void;
  onResetPrizeForm: () => void;
  onAddProbabilityStage: () => void;
  onRemoveProbabilityStage: (uid: string) => void;
  onProbabilityStageChange: (uid: string, next: Partial<ProbabilityStageForm>) => void;
  onProbabilityRowChange: (uid: string, prizeId: number, value: string) => void;
  onSubmitRank: (event: FormEvent<HTMLFormElement>) => void;
  onSubmitPrize: (event: FormEvent<HTMLFormElement>) => void;
  onPreviewProbability: () => void;
  onPublishProbability: () => void;
  onUploadImage: (context: AssetContext, file: File) => Promise<string>;
  onUploadVideo: (context: AssetContext, file: File) => Promise<string>;
}) {
  const prizes = selectedGacha?.ranks?.flatMap((rank) => rank.prizes ?? []) ?? [];
  const rankImageAssets = rankAssets.filter((asset) => asset.asset_type === "image" && asset.is_active);
  const rankVideoAssets = rankAssets.filter((asset) => asset.asset_type === "video" && asset.is_active);
  const stageTotals = probabilityStages.map((stage) => ({
    uid: stage.uid,
    total: probabilityStageTotal(stage, prizes),
  }));
  const canPublishProbability = prizes.length > 0 && probabilityStages.length > 0 && stageTotals.every((stage) => stage.total === 1000000);
  const [rankSearchGachaId, setRankSearchGachaId] = useState(selectedGacha?.id ? String(selectedGacha.id) : "");
  const [rankListMode, setRankListMode] = useState(false);
  const [prizeSearchGachaId, setPrizeSearchGachaId] = useState(selectedGacha?.id ? String(selectedGacha.id) : "");
  const [prizeListMode, setPrizeListMode] = useState(false);
  const [selectedTopBannerIds, setSelectedTopBannerIds] = useState<number[]>([]);
  const selectedTopBannerSet = useMemo(() => new Set(selectedTopBannerIds), [selectedTopBannerIds]);

  useEffect(() => {
    if (selectedGacha?.id) {
      setRankSearchGachaId(String(selectedGacha.id));
      setPrizeSearchGachaId(String(selectedGacha.id));
    }
  }, [selectedGacha?.id]);

  useEffect(() => {
    if (activeView !== "rank-list") {
      setRankListMode(false);
    }

    if (activeView !== "prize-list") {
      setPrizeListMode(false);
    }

    if (activeView !== "top-banner-list") {
      setSelectedTopBannerIds([]);
    }
  }, [activeView]);

  if (activeView === "category-list") {
    return (
      <ListSurface
        title="カテゴリ一覧"
        actionLabel="カテゴリ登録"
        onAction={() => {
          onResetCategoryForm();
          onChangeView("category-new");
        }}
      >
        <DataTable
          headers={["ID", "カテゴリ名", "slug", "並び順", "表示", "操作"]}
          rows={categories.map((category) => [
            `#${category.id}`,
            category.name,
            category.slug,
            category.sort_order.toLocaleString("ja-JP"),
            <StatusBadge key="status" value={category.is_visible ? "visible" : "hidden"} />,
            <button className="secondary-button small-button" type="button" key="edit" onClick={() => onEditCategory(category)}>編集</button>,
          ])}
        />
      </ListSurface>
    );
  }

  if (activeView === "category-new" || activeView === "category-edit") {
    return (
      <FormSurface
        title={activeView === "category-edit" ? "カテゴリ編集" : "カテゴリ登録"}
        backLabel="カテゴリ一覧"
        onBack={() => onChangeView("category-list")}
      >
        <form className="stack-form compact-form category-form" onSubmit={onSubmitCategory} noValidate>
          <div className="inline-fields">
            <label>
              <span>カテゴリ名</span>
              <input value={categoryForm.name} onChange={(event) => onCategoryFormChange({ name: event.target.value })} required />
            </label>
            <label>
              <span>slug（未入力なら自動）</span>
              <input value={categoryForm.slug} onChange={(event) => onCategoryFormChange({ slug: event.target.value })} />
            </label>
          </div>
          <div className="inline-fields">
            <label>
              <span>並び順</span>
              <input value={categoryForm.sortOrder} onChange={(event) => onCategoryFormChange({ sortOrder: event.target.value })} inputMode="numeric" required />
            </label>
            <label className="check-row">
              <input type="checkbox" checked={categoryForm.isVisible} onChange={(event) => onCategoryFormChange({ isVisible: event.target.checked })} />
              <span>表示する</span>
            </label>
          </div>
          <button className="primary-button" type="submit">{activeView === "category-edit" ? "カテゴリ更新" : "カテゴリ作成"}</button>
        </form>
      </FormSurface>
    );
  }

  if (activeView === "tag-list") {
    return (
      <ListSurface
        title="タグ一覧"
        actionLabel="タグ登録"
        onAction={() => {
          onResetTagForm();
          onChangeView("tag-new");
        }}
      >
        <DataTable
          headers={["ID", "タグ名", "slug", "並び順", "状態", "操作"]}
          rows={tags.map((tag) => [
            `#${tag.id}`,
            tag.name,
            tag.slug,
            tag.sort_order.toLocaleString("ja-JP"),
            <StatusBadge key="status" value={tag.is_active ? "active" : "hidden"} />,
            <button className="secondary-button small-button" type="button" key="edit" onClick={() => onEditTag(tag)}>編集</button>,
          ])}
        />
      </ListSurface>
    );
  }

  if (activeView === "tag-new" || activeView === "tag-edit") {
    return (
      <FormSurface
        title={activeView === "tag-edit" ? "タグ編集" : "タグ登録"}
        backLabel="タグ一覧"
        onBack={() => onChangeView("tag-list")}
      >
        <form className="stack-form compact-form category-form" onSubmit={onSubmitTag} noValidate>
          <div className="inline-fields">
            <label>
              <span>タグ名</span>
              <input value={tagForm.name} onChange={(event) => onTagFormChange({ name: event.target.value })} required />
            </label>
            <label>
              <span>slug（未入力なら自動）</span>
              <input value={tagForm.slug} onChange={(event) => onTagFormChange({ slug: event.target.value })} />
            </label>
          </div>
          <div className="inline-fields">
            <label>
              <span>並び順</span>
              <input value={tagForm.sortOrder} onChange={(event) => onTagFormChange({ sortOrder: event.target.value })} inputMode="numeric" required />
            </label>
            <label className="check-row">
              <input type="checkbox" checked={tagForm.isActive} onChange={(event) => onTagFormChange({ isActive: event.target.checked })} />
              <span>有効</span>
            </label>
          </div>
          <button className="primary-button" type="submit">{activeView === "tag-edit" ? "タグ更新" : "タグ作成"}</button>
        </form>
      </FormSurface>
    );
  }

  if (activeView === "top-banner-list") {
    const allTopBannerIds = topBanners.map((banner) => banner.id);
    const allSelected = allTopBannerIds.length > 0 && allTopBannerIds.every((id) => selectedTopBannerSet.has(id));

    return (
      <ListSurface
        title="トップバナー一覧"
        actionLabel="バナー登録"
        onAction={() => {
          onResetTopBannerForm();
          onChangeView("top-banner-new");
        }}
      >
        <div className="table-toolbar" style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px', flexWrap: 'wrap' }}>
          <label className="check-row">
            <input
              type="checkbox"
              checked={allSelected}
              onChange={(event) => setSelectedTopBannerIds(event.target.checked ? allTopBannerIds : [])}
            />
            <span>全選択</span>
          </label>
          <button
            className="secondary-button small-button"
            type="button"
            disabled={loading || selectedTopBannerIds.length === 0}
            onClick={async () => {
              await onBulkUpdateTopBanners(selectedTopBannerIds, true);
              setSelectedTopBannerIds([]);
            }}
          >
            選択を有効
          </button>
          <button
            className="secondary-button small-button"
            type="button"
            disabled={loading || selectedTopBannerIds.length === 0}
            onClick={async () => {
              await onBulkUpdateTopBanners(selectedTopBannerIds, false);
              setSelectedTopBannerIds([]);
            }}
          >
            選択を無効
          </button>
        </div>
        <DataTable
          headers={["ID", "バナー", "リンク先", "並び順", "状態", "編集"]}
          rows={topBanners.map((banner) => [
            <label className="table-check" key="select" style={{ display: 'inline-flex', alignItems: 'center', gap: '8px', whiteSpace: 'nowrap', fontWeight: 700 }}>
              <input
                type="checkbox"
                checked={selectedTopBannerSet.has(banner.id)}
                onChange={(event) => {
                  setSelectedTopBannerIds((current) => event.target.checked
                    ? [...current, banner.id]
                    : current.filter((id) => id !== banner.id));
                }}
		/>
              <span>#{banner.id}</span>
            </label>,
            <span className="table-thumb wide" style={{ width: '120px', backgroundImage: `url("${banner.image_url}")` }} key="image" />,
            banner.link_url || "-",
            banner.sort_order.toLocaleString("ja-JP"),
            <StatusBadge key="status" value={banner.is_active ? "active" : "hidden"} />,
            <button className="secondary-button small-button" type="button" key="edit" onClick={() => onEditTopBanner(banner)}>編集</button>,
          ])}
        />
      </ListSurface>
    );
  }

  if (activeView === "top-banner-new" || activeView === "top-banner-edit") {
    return (
      <FormSurface
        title={activeView === "top-banner-edit" ? "トップバナー編集" : "トップバナー登録"}
        backLabel="トップバナー一覧"
        onBack={() => onChangeView("top-banner-list")}
      >
        <form className="stack-form compact-form category-form" onSubmit={onSubmitTopBanner} noValidate>
          <ImageUploadField
            label="アップロード画像"
            value={topBannerForm.imageUrl}
            context="top-banner"
            required
            onChange={(value) => onTopBannerFormChange({ imageUrl: value })}
            onUploadImage={onUploadImage}
          />
          <div className="inline-fields">
            <label>
              <span>画像選択のリンク先</span>
              <input value={topBannerForm.linkUrl} onChange={(event) => onTopBannerFormChange({ linkUrl: event.target.value })} placeholder="/gachas/1" />
            </label>
            <label>
              <span>並び順</span>
              <input value={topBannerForm.sortOrder} onChange={(event) => onTopBannerFormChange({ sortOrder: event.target.value })} inputMode="numeric" required />
            </label>
          </div>
          <label className="check-row">
            <input type="checkbox" checked={topBannerForm.isActive} onChange={(event) => onTopBannerFormChange({ isActive: event.target.checked })} />
            <span>有効</span>
          </label>
          <button className="primary-button" type="submit">{activeView === "top-banner-edit" ? "バナー更新" : "バナー作成"}</button>
        </form>
      </FormSurface>
    );
  }

  if (activeView === "rank-list") {
    const selectedRanks = selectedGacha?.ranks ?? [];
    const displayedRankGacha = rankListMode ? selectedGacha : null;

    return (
      <ListSurface
        title="ランク一覧"
        actionLabel="ランク登録"
        onAction={() => {
          onResetRankForm();
          onChangeView("rank-new");
        }}
      >
        {displayedRankGacha ? (
          <div className="nested-list-section">
            <div className="subsection-title">
              <strong>{displayedRankGacha.title} のランク</strong>
              <button className="secondary-button small-button" type="button" onClick={() => setRankListMode(false)}>ガチャ一覧へ戻る</button>
            </div>
            <span className="subsection-count">{selectedRanks.length.toLocaleString("ja-JP")}件</span>
            {selectedRanks.length === 0 ? (
              <div className="empty-detail compact">このガチャにはランクが登録されていません。</div>
            ) : (
              <DataTable
                headers={["ID", "ランク", "キー", "景品", "演出", "表示", "操作"]}
                rows={selectedRanks.map((rank) => [
                  `#${rank.id}`,
                  rank.display_name,
                  rank.rank_key,
                  `${rank.prizes_count ?? rank.prizes?.length ?? 0}点`,
                  rank.draw_video_asset_ids?.length ? `${rank.draw_video_asset_ids.length}件設定` : rank.draw_video_url ? "設定済み" : "未設定",
                  <StatusBadge key="status" value={rank.is_visible ? "visible" : "hidden"} />,
                  <button
                    className="secondary-button small-button"
                    type="button"
                    key="edit"
                    onClick={() => {
                      onEditRank(rank);
                    }}
                  >
                    編集
                  </button>,
                ])}
              />
            )}
          </div>
        ) : (
          <>
            <form
              className="rank-search-panel"
              onSubmit={(event) => {
                event.preventDefault();
                if (rankSearchGachaId) {
                  setRankListMode(true);
                  void onLoadGacha(Number(rankSearchGachaId));
                }
              }}
            >
              <label>
                <span>ガチャ名で検索</span>
                <select value={rankSearchGachaId} onChange={(event) => setRankSearchGachaId(event.target.value)}>
                  <option value="">ガチャを選択</option>
                  {rows.map((row) => (
                    <option key={row.id} value={row.id}>{row.title}</option>
                  ))}
                </select>
              </label>
              <button className="secondary-button" type="submit" disabled={!rankSearchGachaId || loading}>検索</button>
            </form>

            <div className="nested-list-section">
              <div className="subsection-title">
                <strong>登録済みガチャ</strong>
                <span>{rows.length.toLocaleString("ja-JP")}件</span>
              </div>
              <DataTable
                headers={["ID", "ガチャ名", "カテゴリ", "価格", "状態", "ランク", "操作"]}
                rows={rows.map((row) => [
                  `#${row.id}`,
                  <span className="user-cell" key="title"><span>{row.title}</span><small>{row.slug}</small></span>,
                  row.category?.name ?? "-",
                  pointLabel(row.price),
                  <StatusBadge key="status" value={row.status} />,
                  `${row.ranks_count ?? 0}件`,
                  <button
                    className="secondary-button small-button"
                    type="button"
                    key="show"
                    onClick={() => {
                      setRankSearchGachaId(String(row.id));
                      setRankListMode(true);
                      void onLoadGacha(row.id);
                    }}
                  >
                    ランク表示
                  </button>,
                ])}
              />
            </div>
          </>
        )}
      </ListSurface>
    );
  }

  if ((activeView === "rank-new" || activeView === "rank-edit") && !selectedGacha) {
    return (
      <FormSurface title={activeView === "rank-edit" ? "ランク編集" : "ランク登録"} backLabel="ランク一覧" onBack={() => onChangeView("rank-list")}>
        <label className="stack-form compact-form">
          <span>対象ガチャ</span>
          <select defaultValue="" onChange={(event) => event.target.value && void onSelect(Number(event.target.value))}>
            <option value="">選択</option>
            {rows.map((row) => (
              <option key={row.id} value={row.id}>{row.title}</option>
            ))}
          </select>
        </label>
      </FormSurface>
    );
  }

  if ((activeView === "rank-new" || activeView === "rank-edit") && selectedGacha) {
    return (
      <FormSurface title={activeView === "rank-edit" ? "ランク編集" : "ランク登録"} backLabel="ランク一覧" onBack={() => onChangeView("rank-list")}>
        <div className="detail-heading">
          <div>
            <h3>{selectedGacha.title}</h3>
            <p>#{selectedGacha.id} / {selectedGacha.slug}</p>
          </div>
          <StatusBadge value={selectedGacha.status} />
        </div>
        <form className="stack-form compact-form" onSubmit={onSubmitRank}>
          <label>
            <span>対象ガチャ</span>
            <select value={selectedGacha.id} onChange={(event) => event.target.value && void onSelect(Number(event.target.value))}>
              {rows.map((row) => (
                <option key={row.id} value={row.id}>{row.title}</option>
              ))}
            </select>
          </label>
          <label>
            <span>ランクキー</span>
            <input value={rankForm.rankKey} onChange={(event) => onRankFormChange({ rankKey: event.target.value })} placeholder="S" required />
          </label>
          <label>
            <span>表示名</span>
            <input value={rankForm.displayName} onChange={(event) => onRankFormChange({ displayName: event.target.value })} placeholder="S賞" required />
          </label>
          <label>
            <span>説明</span>
            <textarea value={rankForm.description} onChange={(event) => onRankFormChange({ description: event.target.value })} />
          </label>
          <label>
            <span>ランク画像</span>
            <select
              multiple
              value={rankForm.rankImageAssetIds}
              size={Math.min(6, Math.max(3, rankImageAssets.length))}
              onChange={(event) => onRankFormChange({ rankImageAssetIds: selectedOptionValues(event.currentTarget), rankImageAssetId: "" })}
            >
              {rankImageAssets.map((asset) => (
                <option key={asset.id} value={asset.id}>{asset.title}</option>
              ))}
            </select>
          </label>
          {rankForm.imageUrl && rankForm.rankImageAssetIds.length === 0 && (
            <p className="inline-note">旧URL: {rankForm.imageUrl}</p>
          )}
          <label>
            <span>抽選演出動画</span>
            <select
              multiple
              value={rankForm.drawVideoAssetIds}
              size={Math.min(6, Math.max(3, rankVideoAssets.length))}
              onChange={(event) => onRankFormChange({ drawVideoAssetIds: selectedOptionValues(event.currentTarget), drawVideoAssetId: "" })}
            >
              {rankVideoAssets.map((asset) => (
                <option key={asset.id} value={asset.id}>{asset.title}</option>
              ))}
            </select>
          </label>
          {rankForm.drawVideoUrl && rankForm.drawVideoAssetIds.length === 0 && (
            <p className="inline-note">旧URL: {rankForm.drawVideoUrl}</p>
          )}
          <label>
            <span>並び順</span>
            <input value={rankForm.sortOrder} onChange={(event) => onRankFormChange({ sortOrder: event.target.value })} inputMode="numeric" required />
          </label>
          <label className="check-row">
            <input type="checkbox" checked={rankForm.isVisible} onChange={(event) => onRankFormChange({ isVisible: event.target.checked })} />
            <span>表示する</span>
          </label>
          <button className="primary-button" type="submit" disabled={loading}>{rankForm.id ? "ランク更新" : "ランク作成"}</button>
        </form>
      </FormSurface>
    );
  }

  if (activeView === "gacha-list") {
    return (
      <ListSurface
        title="ガチャ一覧"
        actionLabel="ガチャ登録"
        onAction={() => {
          onResetGachaForm();
          onChangeView("gacha-new");
        }}
      >
        <DataTable
          headers={["ID", "タイトル", "カテゴリ", "タグ", "価格", "販売", "景品", "状態", "操作"]}
          rows={rows.map((row) => [
            `#${row.id}`,
            <span className="user-cell" key="title"><span>{row.title}</span><small>{row.slug}</small></span>,
            row.category?.name ?? "-",
            row.tags?.length ? row.tags.map((tag) => tag.name).join(" / ") : "-",
            pointLabel(row.price),
            `${row.sold_count.toLocaleString("ja-JP")} / ${row.total_count.toLocaleString("ja-JP")}`,
            `${row.prizes_count ?? 0}点`,
            <StatusBadge key="status" value={row.status} />,
            <button className="secondary-button small-button" type="button" key="edit" onClick={() => void onSelect(row.id)}>編集</button>,
          ])}
        />
      </ListSurface>
    );
  }

  if (activeView === "prize-list") {
    const selectedPrizes = selectedGacha?.ranks?.flatMap((rank) => rank.prizes ?? []) ?? [];
    const displayedPrizeGacha = prizeListMode ? selectedGacha : null;

    return (
      <ListSurface
        title="景品一覧"
        actionLabel="景品登録"
        onAction={() => {
          onResetPrizeForm();
          onChangeView("prize-new");
        }}
      >
        {displayedPrizeGacha ? (
          <div className="nested-list-section">
            <div className="subsection-title">
              <strong>{displayedPrizeGacha.title} の景品</strong>
              <button className="secondary-button small-button" type="button" onClick={() => setPrizeListMode(false)}>ガチャ一覧へ戻る</button>
            </div>
            <span className="subsection-count">{selectedPrizes.length.toLocaleString("ja-JP")}件</span>
            {selectedPrizes.length === 0 ? (
              <div className="empty-detail compact">このガチャには景品が登録されていません。</div>
            ) : (
              <DataTable
                headers={["ID", "景品名", "ランク", "当選", "交換P", "状態", "操作"]}
                rows={selectedPrizes.map((prize) => [
                  `#${prize.id}`,
                  prize.name,
                  prize.rank?.display_name ?? `#${prize.rank_id}`,
                  `${prize.won_count.toLocaleString("ja-JP")} / ${prize.max_win_count.toLocaleString("ja-JP")}`,
                  prize.exchange_point !== null ? pointLabel(prize.exchange_point) : "-",
                  <StatusBadge key="status" value={prize.is_active ? "active" : "hidden"} />,
                  <button
                    className="secondary-button small-button"
                    type="button"
                    key="edit"
                    onClick={() => {
                      onEditPrize(prize);
                    }}
                  >
                    編集
                  </button>,
                ])}
              />
            )}
          </div>
        ) : (
          <>
            <form
              className="rank-search-panel"
              onSubmit={(event) => {
                event.preventDefault();
                if (prizeSearchGachaId) {
                  setPrizeListMode(true);
                  void onLoadGacha(Number(prizeSearchGachaId));
                }
              }}
            >
              <label>
                <span>ガチャ名で検索</span>
                <select value={prizeSearchGachaId} onChange={(event) => setPrizeSearchGachaId(event.target.value)}>
                  <option value="">ガチャを選択</option>
                  {rows.map((row) => (
                    <option key={row.id} value={row.id}>{row.title}</option>
                  ))}
                </select>
              </label>
              <button className="secondary-button" type="submit" disabled={!prizeSearchGachaId || loading}>検索</button>
            </form>

            <div className="nested-list-section">
              <div className="subsection-title">
                <strong>登録済みガチャ</strong>
                <span>{rows.length.toLocaleString("ja-JP")}件</span>
              </div>
              <DataTable
                headers={["ID", "ガチャ名", "カテゴリ", "価格", "状態", "景品", "操作"]}
                rows={rows.map((row) => [
                  `#${row.id}`,
                  <span className="user-cell" key="title"><span>{row.title}</span><small>{row.slug}</small></span>,
                  row.category?.name ?? "-",
                  pointLabel(row.price),
                  <StatusBadge key="status" value={row.status} />,
                  `${row.prizes_count ?? 0}点`,
                  <button
                    className="secondary-button small-button"
                    type="button"
                    key="show"
                    onClick={() => {
                      setPrizeSearchGachaId(String(row.id));
                      setPrizeListMode(true);
                      void onLoadGacha(row.id);
                    }}
                  >
                    景品表示
                  </button>,
                ])}
              />
            </div>
          </>
        )}
      </ListSurface>
    );
  }

  if ((activeView === "prize-new" || activeView === "prize-edit") && !selectedGacha) {
    return (
      <FormSurface title={activeView === "prize-edit" ? "景品編集" : "景品登録"} backLabel="景品一覧" onBack={() => onChangeView("prize-list")}>
        <label className="stack-form compact-form">
          <span>対象ガチャ</span>
          <select defaultValue="" onChange={(event) => event.target.value && void onSelect(Number(event.target.value))}>
            <option value="">選択</option>
            {rows.map((row) => (
              <option key={row.id} value={row.id}>{row.title}</option>
            ))}
          </select>
        </label>
      </FormSurface>
    );
  }

  if ((activeView === "prize-new" || activeView === "prize-edit") && selectedGacha) {
    return (
      <FormSurface title={activeView === "prize-edit" ? "景品編集" : "景品登録"} backLabel="景品一覧" onBack={() => onChangeView("prize-list")}>
        <div className="detail-heading">
          <div>
            <h3>{selectedGacha.title}</h3>
            <p>#{selectedGacha.id} / {selectedGacha.slug}</p>
          </div>
          <StatusBadge value={selectedGacha.status} />
        </div>
        <form className="stack-form compact-form" onSubmit={onSubmitPrize}>
          {selectedGacha.status === "active" && prizeForm.id && (
            <p className="inline-note">稼働中は当選上限・原価・交換ポイント・ランク移動は変更できません。</p>
          )}
          <label>
            <span>対象ガチャ</span>
            <select value={selectedGacha.id} onChange={(event) => event.target.value && void onSelect(Number(event.target.value))}>
              {rows.map((row) => (
                <option key={row.id} value={row.id}>{row.title}</option>
              ))}
            </select>
          </label>
          <label>
            <span>ランク</span>
            <select value={prizeForm.rankId} onChange={(event) => onPrizeFormChange({ rankId: event.target.value })} disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} required>
              <option value="">選択</option>
              {selectedGacha.ranks?.map((rank) => (
                <option key={rank.id} value={rank.id}>{rank.display_name}</option>
              ))}
            </select>
          </label>
          <label>
            <span>景品名</span>
            <input value={prizeForm.name} onChange={(event) => onPrizeFormChange({ name: event.target.value })} required />
          </label>
          <ImageUploadField
            context="prize"
            label="画像URL"
            value={prizeForm.imageUrl}
            onChange={(value) => onPrizeFormChange({ imageUrl: value })}
            onUploadImage={onUploadImage}
            required
          />
          <div className="inline-fields">
            <label>
              <span>当選上限</span>
              <input value={prizeForm.maxWinCount} onChange={(event) => onPrizeFormChange({ maxWinCount: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} required />
            </label>
            <label>
              <span>原価</span>
              <input value={prizeForm.costPrice} onChange={(event) => onPrizeFormChange({ costPrice: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} required />
            </label>
          </div>
          <div className="inline-fields">
            <label>
              <span>表示価格</span>
              <input value={prizeForm.displayPrice} onChange={(event) => onPrizeFormChange({ displayPrice: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} />
            </label>
            <label>
              <span>交換P</span>
              <input value={prizeForm.exchangePoint} onChange={(event) => onPrizeFormChange({ exchangePoint: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} />
            </label>
          </div>
          <label>
            <span>状態</span>
            <input value={prizeForm.condition} onChange={(event) => onPrizeFormChange({ condition: event.target.value })} required />
          </label>
          <label>
            <span>並び順</span>
            <input value={prizeForm.sortOrder} onChange={(event) => onPrizeFormChange({ sortOrder: event.target.value })} inputMode="numeric" required />
          </label>
          <div className="check-group">
            <label className="check-row">
              <input type="checkbox" checked={prizeForm.isActive} onChange={(event) => onPrizeFormChange({ isActive: event.target.checked })} />
              <span>有効</span>
            </label>
            <label className="check-row">
              <input type="checkbox" checked={prizeForm.isVisible} onChange={(event) => onPrizeFormChange({ isVisible: event.target.checked })} />
              <span>表示</span>
            </label>
          </div>
          <button className="primary-button" type="submit" disabled={loading || (selectedGacha.status === "active" && !prizeForm.id)}>{prizeForm.id ? "景品更新" : "景品作成"}</button>
        </form>
      </FormSurface>
    );
  }

  return (
    <div className="gacha-manager form-only">
      <div className="gacha-list">
        <DataTable
          headers={["ID", "タイトル", "価格", "販売", "景品", "状態", "確率"]}
          rows={rows.map((row) => [
            <button className="table-link" type="button" key="id" onClick={() => onSelect(row.id)}>#{row.id}</button>,
            <span className="user-cell" key="title"><span>{row.title}</span><small>{row.slug}</small></span>,
            pointLabel(row.price),
            `${row.sold_count.toLocaleString("ja-JP")} / ${row.total_count.toLocaleString("ja-JP")}`,
            `${row.prizes_count ?? 0}点`,
            <StatusBadge key="status" value={row.status} />,
            row.current_probability_version
              ? `v${row.current_probability_version.version_number}`
              : "未公開",
          ])}
        />
      </div>

      <div className="detail-pane">
        <div className="category-admin-panel">
          <form className="stack-form compact-form category-form" onSubmit={onSubmitCategory} noValidate>
            <div className="form-title form-title-row">
              <span>
                <strong>{categoryForm.id ? "カテゴリ編集" : "カテゴリ作成"}</strong>
                <span>ガチャ分類と表示順</span>
              </span>
              <button className="secondary-button" type="button" onClick={onResetCategoryForm}>新規</button>
            </div>
            <div className="inline-fields">
              <label>
                <span>カテゴリ名</span>
                <input value={categoryForm.name} onChange={(event) => onCategoryFormChange({ name: event.target.value })} required />
              </label>
              <label>
                <span>slug（未入力なら自動）</span>
                <input value={categoryForm.slug} onChange={(event) => onCategoryFormChange({ slug: event.target.value })} />
              </label>
            </div>
            <div className="inline-fields">
              <label>
                <span>並び順</span>
                <input value={categoryForm.sortOrder} onChange={(event) => onCategoryFormChange({ sortOrder: event.target.value })} inputMode="numeric" required />
              </label>
              <label className="check-row">
                <input type="checkbox" checked={categoryForm.isVisible} onChange={(event) => onCategoryFormChange({ isVisible: event.target.checked })} />
                <span>表示する</span>
              </label>
            </div>
            <button className="primary-button" type="submit">{categoryForm.id ? "カテゴリ更新" : "カテゴリ作成"}</button>
          </form>

          <div className="category-list">
            {categories.length === 0 ? (
              <span>カテゴリなし</span>
            ) : categories.map((category) => (
              <button className="category-chip" type="button" key={category.id} onClick={() => onEditCategory(category)}>
                <strong>{category.name}</strong>
                <small>{category.slug}</small>
                <StatusBadge value={category.is_visible ? "visible" : "hidden"} />
              </button>
            ))}
          </div>
        </div>

        {selectedGacha && readiness && (
          <div className={`readiness-panel ${readiness.ready ? "ready" : ""}`}>
            <div className="form-title form-title-row">
              <span>
                <strong>{readiness.ready ? "公開可能" : "公開前チェック"}</strong>
                <span>{readiness.ready ? "稼働状態へ変更できます" : "不足項目を解消してください"}</span>
              </span>
              {readiness.ready && selectedGacha.status !== "active" && (
                <button className="primary-button" type="button" onClick={onActivateGacha} disabled={loading}>稼働化</button>
              )}
            </div>
            <div className="check-list">
              {readiness.checks.map((check) => {
                const isWarning = !check.passed && check.severity === "warning";

                return (
                  <div className={`check-item ${isWarning ? "warning" : ""}`} key={check.key}>
                    <span className={check.passed ? "check-dot passed" : isWarning ? "check-dot warning" : "check-dot"}>{check.passed ? "✓" : isWarning ? "i" : "!"}</span>
                    <span>
                      <strong>{check.label}</strong>
                      {check.message && <small>{check.message}</small>}
                    </span>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        <form className="stack-form compact-form gacha-form" onSubmit={onSubmitGacha} noValidate>
          <div className="form-title form-title-row">
            <span>
              <strong>{gachaForm.id ? "ガチャ編集" : "ガチャ新規作成"}</strong>
              <span>基本情報と公開状態</span>
            </span>
            <button className="secondary-button" type="button" onClick={onResetGachaForm}>新規</button>
          </div>
          <div className="inline-fields">
            <label>
              <span>タイトル</span>
              <input value={gachaForm.title} onChange={(event) => onGachaFormChange({ title: event.target.value })} required />
            </label>
            <label>
              <span>slug（未入力なら自動）</span>
              <input value={gachaForm.slug} onChange={(event) => onGachaFormChange({ slug: event.target.value })} />
            </label>
          </div>
          <div className="inline-fields three">
            <label>
              <span>カテゴリ</span>
              <select value={gachaForm.categoryId} onChange={(event) => onGachaFormChange({ categoryId: event.target.value })} required>
                <option value="">選択</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>{category.name}</option>
                ))}
              </select>
            </label>
            <label>
              <span>タグ</span>
              <select
                multiple
                value={gachaForm.tagIds}
                size={Math.min(6, Math.max(3, tags.length))}
                onChange={(event) => onGachaFormChange({ tagIds: selectedOptionValues(event.currentTarget) })}
              >
                {tags
                  .filter((tag) => tag.is_active || gachaForm.tagIds.includes(String(tag.id)))
                  .map((tag) => (
                    <option key={tag.id} value={tag.id}>{tag.name}{tag.is_active ? "" : "（無効）"}</option>
                  ))}
              </select>
            </label>
            <label>
              <span>価格</span>
              <input value={gachaForm.price} onChange={(event) => onGachaFormChange({ price: event.target.value })} inputMode="numeric" required />
            </label>
            <label>
              <span>総口数</span>
              <input value={gachaForm.totalCount} onChange={(event) => onGachaFormChange({ totalCount: event.target.value })} inputMode="numeric" required />
            </label>
            <label>
              <span>1日の規定回数</span>
              <input value={gachaForm.dailyDrawLimit} onChange={(event) => onGachaFormChange({ dailyDrawLimit: event.target.value })} inputMode="numeric" placeholder="空白なら無制限" />
            </label>
          </div>
          <div className="inline-fields three">
            <SelectField
              label="確率方式"
              value={gachaForm.probabilityMode}
              onChange={(value) => onGachaFormChange({ probabilityMode: value })}
              options={[
                ["single", "単一"],
                ["sold_count_stage", "販売数ステージ"],
              ]}
            />
            <SelectField
              label="最低保証"
              value={gachaForm.minimumGuaranteeType}
              onChange={(value) => onGachaFormChange({ minimumGuaranteeType: value })}
              options={[
                ["point", "ポイント"],
                ["prize", "景品"],
              ]}
            />
            <SelectField
              label="状態"
              value={gachaForm.status}
              onChange={(value) => onGachaFormChange({ status: value })}
              options={[
                ["draft", "下書き"],
                ["scheduled", "予定"],
                ["active", "稼働中"],
                ["paused", "停止"],
                ["sold_out", "完売"],
                ["ended", "終了"],
                ["hidden", "非表示"],
              ]}
            />
          </div>
          <div className="inline-fields three">
            <label>
              <span>保証値</span>
              <input value={gachaForm.minimumGuaranteeValue} onChange={(event) => onGachaFormChange({ minimumGuaranteeValue: event.target.value })} inputMode="numeric" required />
            </label>
            <label>
              <span>保証原価</span>
              <input value={gachaForm.minimumGuaranteeCost} onChange={(event) => onGachaFormChange({ minimumGuaranteeCost: event.target.value })} inputMode="numeric" required />
            </label>
            <label>
              <span>目標粗利</span>
              <input value={gachaForm.targetMargin} onChange={(event) => onGachaFormChange({ targetMargin: event.target.value })} inputMode="decimal" />
            </label>
          </div>
          <div className="inline-fields">
            <label>
              <span>開始日時</span>
              <input type="datetime-local" value={gachaForm.startAt} onChange={(event) => onGachaFormChange({ startAt: event.target.value })} />
            </label>
            <label>
              <span>終了日時</span>
              <input type="datetime-local" value={gachaForm.endAt} onChange={(event) => onGachaFormChange({ endAt: event.target.value })} />
            </label>
          </div>
          <ImageUploadField
            context="gacha"
            label="メイン画像URL"
            value={gachaForm.mainImageUrl}
            onChange={(value) => onGachaFormChange({ mainImageUrl: value })}
            onUploadImage={onUploadImage}
          />
          <label className="check-row">
            <input type="checkbox" checked={gachaForm.showOnTopSlider} onChange={(event) => onGachaFormChange({ showOnTopSlider: event.target.checked })} />
            <span>トップのスライドに表示</span>
          </label>
          <div className="inline-fields">
            <label>
              <span>説明</span>
              <textarea value={gachaForm.description} onChange={(event) => onGachaFormChange({ description: event.target.value })} />
            </label>
            <label>
              <span>注意事項</span>
              <textarea value={gachaForm.caution} onChange={(event) => onGachaFormChange({ caution: event.target.value })} />
            </label>
          </div>
          <button className="primary-button" type="submit">{gachaForm.id ? "更新" : "作成"}</button>
        </form>

        {selectedGacha ? (
          <>
            <div className="detail-heading">
              <div>
                <h3>{selectedGacha.title}</h3>
                <p>#{selectedGacha.id} / {selectedGacha.slug}</p>
              </div>
              <StatusBadge value={selectedGacha.status} />
            </div>

            <div className="mini-metrics">
              <Metric label="価格" value={selectedGacha.price} tone="teal" caption="pt" />
              <Metric label="総口数" value={selectedGacha.total_count} tone="blue" caption={`${selectedGacha.remaining_count.toLocaleString("ja-JP")} 残`} />
              <Metric label="景品" value={prizes.length} tone="green" caption={`${selectedGacha.ranks?.length ?? 0} ランク`} />
            </div>

            {profitSimulation && (
              <ProfitSimulationPanel simulation={profitSimulation} />
            )}

            <PricingPlannerPanel selectedGacha={selectedGacha} gachaForm={gachaForm} />

            <div className="form-grid">
              <form className="stack-form compact-form" onSubmit={onSubmitRank}>
                <div className="form-title form-title-row">
                  <span>
                    <strong>{rankForm.id ? "ランク編集" : "ランク登録"}</strong>
                    <span>S/A/Bなどの景品グループ</span>
                  </span>
                  <button className="secondary-button" type="button" onClick={onResetRankForm}>新規</button>
                </div>
                <label>
                  <span>ランクキー</span>
                  <input value={rankForm.rankKey} onChange={(event) => onRankFormChange({ rankKey: event.target.value })} placeholder="S" required />
                </label>
                <label>
                  <span>表示名</span>
                  <input value={rankForm.displayName} onChange={(event) => onRankFormChange({ displayName: event.target.value })} placeholder="S賞" required />
                </label>
                <label>
                  <span>説明</span>
                  <textarea value={rankForm.description} onChange={(event) => onRankFormChange({ description: event.target.value })} />
                </label>
                <label>
                  <span>ランク画像</span>
                  <select
                    multiple
                    value={rankForm.rankImageAssetIds}
                    size={Math.min(6, Math.max(3, rankImageAssets.length))}
                    onChange={(event) => onRankFormChange({ rankImageAssetIds: selectedOptionValues(event.currentTarget), rankImageAssetId: "" })}
                  >
                    {rankImageAssets.map((asset) => (
                      <option key={asset.id} value={asset.id}>{asset.title}</option>
                    ))}
                  </select>
                </label>
                {rankForm.imageUrl && rankForm.rankImageAssetIds.length === 0 && (
                  <p className="inline-note">旧URL: {rankForm.imageUrl}</p>
                )}
                <label>
                  <span>抽選演出動画</span>
                  <select
                    multiple
                    value={rankForm.drawVideoAssetIds}
                    size={Math.min(6, Math.max(3, rankVideoAssets.length))}
                    onChange={(event) => onRankFormChange({ drawVideoAssetIds: selectedOptionValues(event.currentTarget), drawVideoAssetId: "" })}
                  >
                    {rankVideoAssets.map((asset) => (
                      <option key={asset.id} value={asset.id}>{asset.title}</option>
                    ))}
                  </select>
                </label>
                {rankForm.drawVideoUrl && rankForm.drawVideoAssetIds.length === 0 && (
                  <p className="inline-note">旧URL: {rankForm.drawVideoUrl}</p>
                )}
                <label>
                  <span>並び順</span>
                  <input value={rankForm.sortOrder} onChange={(event) => onRankFormChange({ sortOrder: event.target.value })} inputMode="numeric" required />
                </label>
                <label className="check-row">
                  <input type="checkbox" checked={rankForm.isVisible} onChange={(event) => onRankFormChange({ isVisible: event.target.checked })} />
                  <span>表示する</span>
                </label>
                <button className="primary-button" type="submit" disabled={loading}>{rankForm.id ? "ランク更新" : "ランク作成"}</button>
              </form>

              <form className="stack-form compact-form" onSubmit={onSubmitPrize}>
                <div className="form-title form-title-row">
                  <span>
                    <strong>{prizeForm.id ? "景品編集" : "景品登録"}</strong>
                    <span>確率は公開時に別設定</span>
                  </span>
                  <button className="secondary-button" type="button" onClick={onResetPrizeForm}>新規</button>
                </div>
                {selectedGacha.status === "active" && prizeForm.id && (
                  <p className="inline-note">稼働中は当選上限・原価・交換ポイント・ランク移動は変更できません。</p>
                )}
                <label>
                  <span>ランク</span>
                  <select value={prizeForm.rankId} onChange={(event) => onPrizeFormChange({ rankId: event.target.value })} disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} required>
                    <option value="">選択</option>
                    {selectedGacha.ranks?.map((rank) => (
                      <option key={rank.id} value={rank.id}>{rank.display_name}</option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>景品名</span>
                  <input value={prizeForm.name} onChange={(event) => onPrizeFormChange({ name: event.target.value })} required />
                </label>
                <ImageUploadField
                  context="prize"
                  label="画像URL"
                  value={prizeForm.imageUrl}
                  onChange={(value) => onPrizeFormChange({ imageUrl: value })}
                  onUploadImage={onUploadImage}
                  required
                />
                <div className="inline-fields">
                  <label>
                    <span>当選上限</span>
                    <input value={prizeForm.maxWinCount} onChange={(event) => onPrizeFormChange({ maxWinCount: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} required />
                  </label>
                  <label>
                    <span>原価</span>
                    <input value={prizeForm.costPrice} onChange={(event) => onPrizeFormChange({ costPrice: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} required />
                  </label>
                </div>
                <div className="inline-fields">
                  <label>
                    <span>表示価格</span>
                    <input value={prizeForm.displayPrice} onChange={(event) => onPrizeFormChange({ displayPrice: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} />
                  </label>
                  <label>
                    <span>交換P</span>
                    <input value={prizeForm.exchangePoint} onChange={(event) => onPrizeFormChange({ exchangePoint: event.target.value })} inputMode="numeric" disabled={selectedGacha.status === "active" && Boolean(prizeForm.id)} />
                  </label>
                </div>
                <label>
                  <span>状態</span>
                  <input value={prizeForm.condition} onChange={(event) => onPrizeFormChange({ condition: event.target.value })} required />
                </label>
                <div className="check-group">
                  <label className="check-row">
                    <input type="checkbox" checked={prizeForm.isActive} onChange={(event) => onPrizeFormChange({ isActive: event.target.checked })} />
                    <span>有効</span>
                  </label>
                  <label className="check-row">
                    <input type="checkbox" checked={prizeForm.isVisible} onChange={(event) => onPrizeFormChange({ isVisible: event.target.checked })} />
                    <span>表示</span>
                  </label>
                </div>
                <button className="primary-button" type="submit" disabled={loading || (selectedGacha.status === "active" && !prizeForm.id)}>{prizeForm.id ? "景品更新" : "景品作成"}</button>
              </form>
            </div>

            <div className="rank-prize-editor">
              <div className="form-title">
                <strong>ランク・景品一覧</strong>
                <span>選択すると上のフォームで編集できます</span>
              </div>
              {(selectedGacha.ranks ?? []).length === 0 ? (
                <div className="empty-detail compact">ランクなし</div>
              ) : selectedGacha.ranks?.map((rank) => (
                <div className="rank-block" key={rank.id}>
                  <div className="rank-block-header">
                    <button className="table-link" type="button" onClick={() => onEditRank(rank)}>{rank.display_name}</button>
                    <span className="mono-id">{rank.rank_key} / #{rank.id}</span>
                    <span className={rank.draw_video_url ? "ok-text" : "muted-text"}>{rank.draw_video_url ? "演出あり" : "演出なし"}</span>
                    <StatusBadge value={rank.is_visible ? "visible" : "hidden"} />
                  </div>
                  <div className="prize-chip-list">
                    {(rank.prizes ?? []).length === 0 ? (
                      <span className="empty-inline">景品なし</span>
                    ) : rank.prizes?.map((prize) => (
                      <button className="prize-chip" type="button" key={prize.id} onClick={() => onEditPrize(prize)}>
                        <strong>{prize.name}</strong>
                        <small>#{prize.id} / 残 {prize.remaining_win_count.toLocaleString("ja-JP")}</small>
                        <StatusBadge value={prize.is_active ? "active" : "hidden"} />
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </div>

            <div className="probability-panel">
              <div className="form-title form-title-row">
                <span>
                  <strong>確率設定</strong>
                  <span>各ステージ合計 100%。最低保証行を含めて公開します。</span>
                </span>
                <button className="secondary-button" type="button" onClick={onAddProbabilityStage}>ステージ追加</button>
              </div>

              {probabilityStages.length === 0 ? (
                <div className="empty-detail compact">ステージなし</div>
              ) : probabilityStages.map((stage, index) => {
                const total = stageTotals.find((item) => item.uid === stage.uid)?.total ?? 0;

                return (
                  <div className="probability-stage" key={stage.uid}>
                    <div className="probability-total">
                      <span>Stage {index + 1}</span>
                      <strong className={total === 1000000 ? "ok-text" : "danger-text"}>{ppmToPercentString(total)}%</strong>
                      <button className="ghost-button" type="button" onClick={() => onRemoveProbabilityStage(stage.uid)} disabled={probabilityStages.length <= 1}>削除</button>
                    </div>
                    <div className="inline-fields four">
                      <label>
                        <span>キー</span>
                        <input value={stage.stageKey} onChange={(event) => onProbabilityStageChange(stage.uid, { stageKey: event.target.value })} required />
                      </label>
                      <label>
                        <span>名称</span>
                        <input value={stage.name} onChange={(event) => onProbabilityStageChange(stage.uid, { name: event.target.value })} required />
                      </label>
                      <label>
                        <span>開始口数</span>
                        <input value={stage.minDrawNumber} onChange={(event) => onProbabilityStageChange(stage.uid, { minDrawNumber: event.target.value })} inputMode="numeric" required />
                      </label>
                      <label>
                        <span>終了口数</span>
                        <input value={stage.maxDrawNumber} onChange={(event) => onProbabilityStageChange(stage.uid, { maxDrawNumber: event.target.value })} inputMode="numeric" placeholder="最終は空" />
                      </label>
                    </div>
                    <div className="probability-grid">
                      {prizes.map((prize) => (
                        <label key={prize.id}>
                          <span>{prize.name}（%）</span>
                          <input value={stage.rows[String(prize.id)] ?? "0"} onChange={(event) => onProbabilityRowChange(stage.uid, prize.id, event.target.value)} inputMode="decimal" />
                        </label>
                      ))}
                      <label>
                        <span>最低保証（%）</span>
                        <input value={stage.minimumGuaranteePpm} onChange={(event) => onProbabilityStageChange(stage.uid, { minimumGuaranteePpm: event.target.value })} inputMode="decimal" />
                      </label>
                    </div>
                  </div>
                );
              })}

              <div className="toolbar-row">
                <button className="secondary-button" type="button" onClick={onPreviewProbability} disabled={loading || prizes.length === 0}>検証</button>
                <button className="primary-button" type="button" onClick={onPublishProbability} disabled={loading || !canPublishProbability}>公開</button>
              </div>
              {probabilityPreview && (
                <div className="preview-box">
                  <strong>検証OK</strong>
                  <span>{probabilityPreview.stages[0]?.prize_count ?? 0}景品 / 最低保証 {ppmToPercentString(probabilityPreview.stages[0]?.minimum_guarantee_ppm ?? 0)}%</span>
                </div>
              )}
            </div>
          </>
        ) : (
          <div className="empty-detail">左の一覧からガチャを選択</div>
        )}
      </div>
    </div>
  );
}

function DrawTable({ rows, onEdit }: { rows: DrawRequest[]; onEdit?: (row: DrawRequest) => void }) {
  return (
    <DataTable
      headers={onEdit ? ["ID", "ユーザー", "ガチャ", "回数", "消費", "状態", "日時", "操作"] : ["ID", "ユーザー", "ガチャ", "回数", "消費", "状態", "日時"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        <UserCell key="user" user={row.user} fallbackId={row.user_id} />,
        row.gacha?.title ?? `#${row.gacha_id}`,
        `${row.draw_count}回`,
        pointLabel(row.consumed_point_total),
        <StatusBadge key="status" value={row.status} />,
        formatDate(row.created_at),
        ...(onEdit ? [<button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>] : []),
      ])}
    />
  );
}

function UserTable({ rows, onDetail }: { rows: User[]; onDetail: (user: User) => void }) {
  return (
    <DataTable
      headers={["ID", "ユーザー", "状態", "合計残高", "有償P", "無償P", "登録日", "操作"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        <span className="user-cell" key="user"><span>{row.email}</span><small>{row.name}</small></span>,
        <StatusBadge key="status" value={row.status} />,
        pointLabel(row.wallet?.total_balance ?? 0),
        pointLabel(row.wallet?.paid_balance ?? 0),
        pointLabel(row.wallet?.free_balance ?? 0),
        formatDate(row.created_at ?? null),
        <button className="secondary-button small-button" type="button" key="detail" onClick={() => onDetail(row)}>詳細</button>,
      ])}
    />
  );
}

function PrizeTable({ rows, onEdit }: { rows: UserPrize[]; onEdit?: (row: UserPrize) => void }) {
  return (
    <DataTable
      headers={onEdit ? ["ID", "ユーザー", "景品", "ランク", "状態", "交換P", "期限", "操作"] : ["ID", "ユーザー", "景品", "ランク", "状態", "交換P", "期限"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        <UserCell key="user" user={row.user} fallbackId={row.user_id} />,
        row.prize?.name ?? "-",
        <span className="rank-pill" key="rank">{row.prize?.rank?.display_name ?? "-"}</span>,
        <StatusBadge key="status" value={row.status} />,
        pointLabel(row.converted_point ?? row.prize?.exchange_point ?? 0),
        formatDate(row.storage_expire_at),
        ...(onEdit ? [<button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>] : []),
      ])}
    />
  );
}

function ShippingTable({ rows, onEdit }: { rows: ShippingRequest[]; onEdit: (row: ShippingRequest, item?: ShippingItem) => void }) {
  const itemRows = shippingRowsByItem(rows);

  return (
    <DataTable
      headers={["配送ID", "ユーザー", "景品", "ガチャ", "宛名", "住所", "状態", "追跡番号"]}
      rows={itemRows.map(({ request, item }) => [
        <span className="mono-id" key="id">#{request.id}</span>,
        <UserCell key="user" user={request.user} fallbackId={request.user_id} />,
        item?.user_prize?.prize?.name ?? "-",
        item?.user_prize?.gacha?.title ?? "-",
        request.recipient_name,
        shippingAddress(request),
        <StatusBadge key="status" value={item?.status ?? request.status} />,
        <span className="inline-action-cell" key="tracking">
          <span>{item?.tracking_number ?? "未入力"}</span>
          <button className="secondary-button small-button" type="button" onClick={() => onEdit(request, item)}>編集</button>
        </span>,
      ])}
    />
  );
}

function ShippingEditPanel({
  request,
  item,
  form,
  loading,
  onBack,
  onSubmit,
  onChange,
}: {
  request: ShippingRequest | null;
  item: ShippingItem | null;
  form: { status: string; trackingNumber: string; shippedAt: string; note: string };
  loading: boolean;
  onBack: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onChange: (form: { status: string; trackingNumber: string; shippedAt: string; note: string }) => void;
}) {
  return (
    <FormSurface title="配送編集" backLabel="配送一覧" onBack={onBack} wide>
      {request && item ? (
        <div className="stack-sections">
          <div className="profile-detail-grid">
            <DetailItem label="配送ID" value={`#${request.id}`} />
            <DetailItem label="景品ID" value={`#${item.user_prize_id}`} />
            <DetailItem label="ユーザー" value={request.user ? `${request.user.name} / ${request.user.email}` : `ID:${request.user_id}`} />
            <DetailItem label="景品" value={item.user_prize?.prize?.name ?? "-"} />
            <DetailItem label="ガチャ" value={item.user_prize?.gacha?.title ?? "-"} />
            <DetailItem label="宛名" value={request.recipient_name} />
            <DetailItem label="電話番号" value={request.phone_number ?? "-"} />
            <DetailItem label="住所" value={shippingAddress(request)} />
            <DetailItem label="景品配送状態" value={statusLabel(item.status)} />
            <DetailItem label="追跡番号" value={item.tracking_number ?? "未入力"} />
            <DetailItem label="依頼日時" value={formatDate(request.requested_at)} />
            <DetailItem label="発送日時" value={formatDate(item.shipped_at)} />
            <DetailItem label="更新日時" value={formatDate(request.updated_at ?? null)} />
          </div>

          <div className="nested-list-section">
            <div className="subsection-title">
              <strong>同じ配送依頼の景品</strong>
              <span>{request.items?.length ?? 0}件</span>
            </div>
            <ShippingItemTable items={request.items ?? []} selectedItemId={item.id} />
          </div>

          <form className="stack-form compact-form" onSubmit={onSubmit}>
            <div className="inline-fields">
              <label>
                <span>配送状態</span>
                <select value={form.status} onChange={(event) => onChange({ ...form, status: event.target.value })}>
                  <option value="requested">依頼受付</option>
                  <option value="packing">梱包中</option>
                  <option value="shipped">発送済み</option>
                  <option value="delivered">配達完了</option>
                  <option value="returned">返送</option>
                  <option value="canceled">キャンセル</option>
                </select>
              </label>
              <label>
                <span>追跡番号</span>
                <input value={form.trackingNumber} onChange={(event) => onChange({ ...form, trackingNumber: event.target.value })} placeholder="管理側で入力" />
              </label>
              <label>
                <span>発送日時</span>
                <input type="datetime-local" value={form.shippedAt} onChange={(event) => onChange({ ...form, shippedAt: event.target.value })} />
              </label>
            </div>
            <label>
              <span>管理メモ</span>
              <textarea value={form.note} onChange={(event) => onChange({ ...form, note: event.target.value })} placeholder="ステータス変更理由や社内メモ" />
            </label>
            <button className="primary-button" type="submit" disabled={loading}>更新</button>
          </form>

          <div className="nested-list-section">
            <div className="subsection-title">
              <strong>更新履歴</strong>
              <span>{request.histories?.length ?? 0}件</span>
            </div>
            <DataTable
              headers={["日時", "変更", "追跡番号", "担当", "メモ"]}
              rows={(request.histories ?? []).map((history) => [
                formatDate(history.created_at),
                `${history.from_status ? statusLabel(history.from_status) : "-"} → ${statusLabel(history.to_status)}`,
                history.tracking_number ?? "-",
                history.admin_user?.name ?? history.admin_user?.email ?? "-",
                history.note ?? "-",
              ])}
            />
          </div>
        </div>
      ) : (
        <div className="empty-detail compact">配送景品を選択してください。</div>
      )}
    </FormSurface>
  );
}

function ShippingItemTable({ items, selectedItemId }: { items: ShippingItem[]; selectedItemId?: number }) {
  return (
    <DataTable
      headers={["景品ID", "景品", "ガチャ", "ランク", "配送状態", "追跡番号", "獲得日時"]}
      rows={items.map((item) => [
        <span className="mono-id" key="id">{selectedItemId === item.id ? "編集中 " : ""}#{item.user_prize_id}</span>,
        item.user_prize?.prize?.name ?? "-",
        item.user_prize?.gacha?.title ?? "-",
        <span className="rank-pill" key="rank">{item.user_prize?.prize?.rank?.display_name ?? "-"}</span>,
        <StatusBadge key="status" value={item.status} />,
        item.tracking_number ?? "未入力",
        formatDate(item.user_prize?.acquired_at ?? null),
      ])}
    />
  );
}

function shippingRowsByItem(rows: ShippingRequest[]): ShippingItemRow[] {
  return rows.flatMap<ShippingItemRow>((request) => {
    if (request.items?.length) {
      return request.items.map((item) => ({ request, item }));
    }

    return [{ request }];
  });
}

function shippingAddress(request: ShippingRequest) {
  return [
    request.postal_code ? `〒${request.postal_code}` : "",
    request.prefecture,
    request.city,
    request.address_line1 ?? "",
    request.address_line2 ?? "",
  ].filter(Boolean).join(" ");
}

function PaymentTable({ rows, onEdit }: { rows: Payment[]; onEdit?: (row: Payment) => void }) {
  return (
    <DataTable
      headers={onEdit ? ["ID", "ユーザー", "決済", "金額", "有償P", "無償P", "状態", "日時", "操作"] : ["ID", "ユーザー", "決済", "金額", "有償P", "無償P", "状態", "日時"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        <UserCell key="user" user={row.user} />,
        <span className="mono-id" key="payment">{row.provider_payment_id}</span>,
        moneyLabel(row.amount),
        pointLabel(row.paid_point_amount),
        pointLabel(row.free_point_amount),
        <StatusBadge key="status" value={row.status} />,
        formatDate(row.created_at),
        ...(onEdit ? [<button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>] : []),
      ])}
    />
  );
}

function PointPurchasePlanTable({ rows, onEdit }: { rows: PointPurchasePlan[]; onEdit: (plan: PointPurchasePlan) => void }) {
  return (
    <DataTable
      headers={["ID", "プラン名", "支払金額", "有償P", "無償P", "販売期間", "並び順", "状態", "操作"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        row.name,
        moneyLabel(row.amount),
        pointLabel(row.paid_point_amount),
        pointLabel(row.free_point_amount),
        purchasePlanPeriodLabel(row),
        row.sort_order.toLocaleString("ja-JP"),
        <StatusBadge key="status" value={row.is_active ? "active" : "hidden"} />,
        <button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>,
      ])}
    />
  );
}

function AnnouncementTable({ rows, onEdit }: { rows: Announcement[]; onEdit: (announcement: Announcement) => void }) {
  return (
    <DataTable
      headers={["ID", "サムネイル", "タイトル", "状態", "公開日時", "更新日時", "操作"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        row.thumbnail_url ? <span className="table-thumb" key="thumb" style={{ backgroundImage: `url("${row.thumbnail_url}")` }} /> : <span className="muted-text" key="thumb">未設定</span>,
        <span className="user-cell" key="title"><span>{row.title}</span><small>{row.body.slice(0, 48)}</small></span>,
        <StatusBadge key="status" value={row.status} />,
        formatDate(row.published_at),
        formatDate(row.updated_at),
        <button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>,
      ])}
    />
  );
}

function RankAssetTable({ rows, onEdit }: { rows: RankAsset[]; onEdit: (asset: RankAsset) => void }) {
  return (
    <DataTable
      headers={["ID", "種別", "タイトル", "プレビュー", "状態", "更新日時", "操作"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        <StatusBadge key="type" value={row.asset_type} />,
        row.title,
        row.asset_type === "image"
          ? <span className="table-thumb" key="preview" style={{ backgroundImage: `url("${row.url}")` }} />
          : <a className="table-link" href={row.url} key="preview" target="_blank" rel="noreferrer">動画を表示</a>,
        <StatusBadge key="status" value={row.is_active ? "active" : "hidden"} />,
        formatDate(row.updated_at),
        <button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>,
      ])}
    />
  );
}

function ContactRequestTable({ rows, onEdit }: { rows: ContactRequest[]; onEdit: (contact: ContactRequest) => void }) {
  return (
    <DataTable
      headers={["ID", "氏名", "メール", "電話番号", "状態", "受付日時", "操作"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        <span className="user-cell" key="name"><span>{row.name}</span><small>{row.body.slice(0, 48)}</small></span>,
        row.email,
        row.phone,
        <StatusBadge key="status" value={row.status} />,
        formatDate(row.created_at),
        <button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>,
      ])}
    />
  );
}

function PointAdjustmentTable({ rows, onEdit }: { rows: PointAdjustment[]; onEdit?: (row: PointAdjustment) => void }) {
  return (
    <DataTable
      headers={onEdit ? ["ID", "ユーザー", "区分", "種別", "数量", "期限", "理由", "操作"] : ["ID", "ユーザー", "区分", "種別", "数量", "期限", "理由"]}
      rows={rows.map((row) => [
        <span className="mono-id" key="id">#{row.id}</span>,
        <UserCell key="user" user={row.user} fallbackId={row.user_id} />,
        <StatusBadge key="type" value={row.adjustment_type} />,
        row.point_type ? <StatusBadge key="point" value={row.point_type} /> : "-",
        pointLabel(row.amount),
        formatDate(row.expire_at),
        row.reason,
        ...(onEdit ? [<button className="secondary-button small-button" type="button" key="edit" onClick={() => onEdit(row)}>編集</button>] : []),
      ])}
    />
  );
}

function DataTable({ headers, rows }: { headers: string[]; rows: ReactNode[][] }) {
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            {headers.map((header) => <th key={header}>{header}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={headers.length}>データなし</td>
            </tr>
          ) : rows.map((row, index) => (
            <tr key={`${row[0]}-${index}`}>
              {row.map((cell, cellIndex) => <td key={cellIndex}>{cell}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ReferralTable({ rows }: { rows: UserReferral[] }) {
  return (
    <DataTable
      headers={["ID", "紹介元", "紹介先", "紹介コード", "状態", "付与予定", "付与日時", "登録日時"]}
      rows={rows.map((referral) => [
        `#${referral.id}`,
        <UserCell key="referrer" user={referral.referrer} fallbackId={referral.referrer_user_id} />,
        <UserCell key="referred" user={referral.referred} fallbackId={referral.referred_user_id} />,
        referral.referral_code,
        statusLabel(referral.status),
        `${referral.reward_point_amount.toLocaleString("ja-JP")}pt`,
        formatDate(referral.rewarded_at),
        formatDate(referral.created_at),
      ])}
    />
  );
}

function UserReferralMadeTable({ rows }: { rows: UserReferral[] }) {
  return (
    <DataTable
      headers={["ID", "紹介先", "状態", "付与予定", "付与日時", "登録日時"]}
      rows={rows.map((referral) => [
        `#${referral.id}`,
        userDisplayName(referral.referred),
        statusLabel(referral.status),
        `${referral.reward_point_amount.toLocaleString("ja-JP")}pt`,
        formatDate(referral.rewarded_at),
        formatDate(referral.created_at),
      ])}
    />
  );
}

function userDisplayName(user?: User | null) {
  if (!user) {
    return "";
  }

  return `${user.profile?.last_name ?? ""} ${user.profile?.first_name ?? ""}`.trim() || user.name || user.email;
}

function UserCell({ user, fallbackId }: { user?: User; fallbackId?: number }) {
  const label = user?.email ?? (fallbackId ? `User #${fallbackId}` : "-");
  const subLabel = user ? `ID ${user.id} / ${user.status}` : "未読込";

  return (
    <span className="user-cell">
      <span>{label}</span>
      <small>{subLabel}</small>
    </span>
  );
}

function DetailItem({ label, value }: { label: string; value: string | number | null | undefined }) {
  return (
    <div className="detail-item">
      <span>{label}</span>
      <strong>{value !== null && value !== undefined && String(value).trim() !== "" ? value : "-"}</strong>
    </div>
  );
}

function StatusBadge({ value }: { value: string }) {
  return <span className={`status-badge status-${value.replaceAll("_", "-")}`}>{statusLabel(value)}</span>;
}

const adminDataCacheKey = "oripa_admin_dashboard_data_v1";
const adminDataCacheTtlMs = 5 * 60 * 1000;

function readAdminDataCache(): AdminDataCache | null {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    const raw = window.sessionStorage.getItem(adminDataCacheKey);

    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as Partial<AdminDataCache>;

    if (!parsed.savedAt || Date.now() - parsed.savedAt > adminDataCacheTtlMs) {
      window.sessionStorage.removeItem(adminDataCacheKey);
      return null;
    }

    return {
      savedAt: parsed.savedAt,
      announcements: parsed.announcements ?? [],
      contacts: parsed.contacts ?? [],
      staticPages: parsed.staticPages ?? [],
      rankAssets: parsed.rankAssets ?? [],
      categories: parsed.categories ?? [],
      tags: parsed.tags ?? [],
      topBanners: parsed.topBanners ?? [],
      gachas: parsed.gachas ?? [],
      users: parsed.users ?? [],
      gachaRanks: parsed.gachaRanks ?? [],
      gachaPrizes: parsed.gachaPrizes ?? [],
      draws: parsed.draws ?? [],
      prizes: parsed.prizes ?? [],
      shipping: parsed.shipping ?? [],
      payments: parsed.payments ?? [],
      purchasePlans: parsed.purchasePlans ?? [],
      pointAdjustments: parsed.pointAdjustments ?? [],
      referralSetting: parsed.referralSetting ?? null,
      referrals: parsed.referrals ?? [],
    };
  } catch {
    window.sessionStorage.removeItem(adminDataCacheKey);
    return null;
  }
}

function writeAdminDataCache(data: Omit<AdminDataCache, "savedAt">) {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.sessionStorage.setItem(adminDataCacheKey, JSON.stringify({
      ...data,
      savedAt: Date.now(),
    }));
  } catch {
    window.sessionStorage.removeItem(adminDataCacheKey);
  }
}

async function apiRequest<T>(path: string, init: RequestInit = {}, token?: string): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("accept", "application/json");

  if (!(init.body instanceof FormData)) {
    headers.set("content-type", "application/json");
  }

  if (token) {
    headers.set("authorization", `Bearer ${token}`);
  }

  const response = await fetch(`${adminApiBase}${path}`, {
    ...init,
    headers,
  });

  const data = await response.json().catch(() => null) as {
    message?: string;
    errors?: Record<string, string[]>;
  } | null;

  if (!response.ok) {
    if (response.status === 401) {
      if (token && path !== "/login" && typeof window !== "undefined") {
        window.localStorage.removeItem("oripa_admin_session");
        document.cookie = "oripa_admin_session=; path=/; max-age=0; SameSite=Lax; secure";
        window.location.href = "/admin-logout?expired=1";
        return await new Promise<never>(() => {});
      }

      throw new Error(data?.message || "ログインに失敗しました");
    }

    const details = data?.errors ? Object.values(data.errors).flat() : [];
    const message = [data?.message, ...details].filter(Boolean).join(" / ");

    throw new Error(message || `API error: ${response.status}`);
  }

  return data as T;
}

function endpointFor(tab: TabKey, page: number, filters: FilterState) {
  const paths: Record<TabKey, string> = {
    guide: "/me",
    announcements: "/announcements",
    contacts: "/contact-requests",
    gachas: "/gachas",
    users: "/users",
    draws: "/draw-requests",
    prizes: "/user-prizes",
    shipping: "/shipping-requests",
    payments: "/payments",
    purchasePlans: "/point-purchase-plans",
    points: "/point-adjustments",
    settings: "/static-pages",
  };
  const params = new URLSearchParams({
    per_page: String(perPage),
    page: String(page),
  });

  Object.entries(filters).forEach(([key, value]) => {
    if (value !== "") {
      params.set(key, value);
    }
  });

  return `${paths[tab]}?${params.toString()}`;
}

function userLabel(user?: User, fallbackId?: number) {
  if (user) {
    return `${user.email} (#${user.id})`;
  }

  return fallbackId ? `#${fallbackId}` : "-";
}

function statusLabel(value: string) {
  const labels: Record<string, string> = {
    completed: "完了",
    failed: "失敗",
    processing: "処理中",
    stored: "保管中",
    converted: "交換済み",
    shipping_requested: "配送依頼",
    requested: "依頼",
    packing: "梱包",
    shipped: "発送済み",
    delivered: "配達済み",
    returned: "返送",
    canceled: "取消",
    pending: "保留",
    succeeded: "成功",
    refunded: "返金",
    chargeback: "CB",
    grant: "付与",
    deduct: "減算",
    paid: "有償",
    free: "無償",
    draft: "下書き",
    scheduled: "予定",
    active: "稼働中",
    paused: "停止",
    suspended: "停止",
    withdrawn: "退会",
    sold_out: "完売",
    ended: "終了",
    hidden: "非表示",
    visible: "表示",
    published: "公開",
    new: "未対応",
    replied: "返信済み",
    closed: "完了",
  };

  return labels[value] ?? value;
}

function resolveTab(value?: string): TabKey {
  return tabKeys.includes(value as TabKey) ? value as TabKey : "gachas";
}

function resolveGachaView(value?: string): GachaAdminView {
  const views: GachaAdminView[] = [
    "gacha-list",
    "gacha-new",
    "gacha-edit",
    "rank-list",
    "rank-new",
    "rank-edit",
    "category-list",
    "category-new",
    "category-edit",
    "tag-list",
    "tag-new",
    "tag-edit",
    "top-banner-list",
    "top-banner-new",
    "top-banner-edit",
    "prize-list",
    "prize-new",
    "prize-edit",
  ];

  return views.includes(value as GachaAdminView) ? value as GachaAdminView : "gacha-list";
}

function resolveAnnouncementView(value?: string): AnnouncementView {
  return value === "new" || value === "edit" ? value : "list";
}

function resolvePointView(value?: string): PointView {
  return value === "new" ? "new" : "list";
}

function resolveContactView(value?: string): ContactView {
  return value === "edit" ? "edit" : "list";
}

function resolveSettingView(value?: string): SettingView {
  const views: SettingView[] = ["list", "pages", "edit", "rank-assets", "rank-asset-new", "rank-asset-edit", "referral"];
  return views.includes(value as SettingView) ? value as SettingView : "list";
}

function resolvePurchasePlanView(value?: string): PurchasePlanView {
  return value === "new" || value === "edit" ? value : "list";
}

function resolveUserView(value?: string): UserManagementView {
  return value === "detail" ? "detail" : "list";
}

function resolveShippingView(value?: string): ShippingView {
  return value === "edit" ? "edit" : "list";
}

function parsePositiveInt(value?: string) {
  if (!value) {
    return null;
  }

  const parsed = Number(value);

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

function adminPathForTab(tab: TabKey) {
  const paths: Record<TabKey, string> = {
    guide: "/admin/guide",
    announcements: "/admin/announcements",
    contacts: "/admin/contacts",
    gachas: "/admin/gachas",
    users: "/admin/users",
    draws: "/admin/draws",
    prizes: "/admin/prizes",
    shipping: "/admin/shipping",
    payments: "/admin/payments",
    purchasePlans: "/admin/purchase-plans",
    points: "/admin/points",
    settings: "/admin/settings",
  };

  return paths[tab];
}

function adminPathForGachaView(view: GachaAdminView, id?: number | string) {
  const paths: Record<GachaAdminView, string> = {
    "gacha-list": "/admin/gachas",
    "gacha-new": "/admin/gachas/new",
    "gacha-edit": `/admin/gachas/${id ?? ""}/edit`,
    "rank-list": "/admin/gachas/ranks",
    "rank-new": "/admin/gachas/ranks/new",
    "rank-edit": `/admin/gachas/ranks/${id ?? ""}/edit`,
    "category-list": "/admin/gachas/categories",
    "category-new": "/admin/gachas/categories/new",
    "category-edit": `/admin/gachas/categories/${id ?? ""}/edit`,
    "tag-list": "/admin/gachas/tags",
    "tag-new": "/admin/gachas/tags/new",
    "tag-edit": `/admin/gachas/tags/${id ?? ""}/edit`,
    "top-banner-list": "/admin/gachas/top-banners",
    "top-banner-new": "/admin/gachas/top-banners/new",
    "top-banner-edit": `/admin/gachas/top-banners/${id ?? ""}/edit`,
    "prize-list": "/admin/gachas/prizes",
    "prize-new": "/admin/gachas/prizes/new",
    "prize-edit": `/admin/gachas/prizes/${id ?? ""}/edit`,
  };

  return paths[view].replace("//", "/");
}

function adminPathForAnnouncementView(view: AnnouncementView, id?: number | string) {
  if (view === "new") {
    return "/admin/announcements/new";
  }

  if (view === "edit") {
    return `/admin/announcements/${id ?? ""}/edit`;
  }

  return adminPathForTab("announcements");
}

function adminPathForContactView(view: ContactView, id?: number | string) {
  return view === "edit" ? `/admin/contacts/${id ?? ""}/edit` : adminPathForTab("contacts");
}

function adminPathForPurchasePlanView(view: PurchasePlanView, id?: number | string) {
  if (view === "new") {
    return "/admin/purchase-plans/new";
  }

  if (view === "edit") {
    return `/admin/purchase-plans/${id ?? ""}/edit`;
  }

  return adminPathForTab("purchasePlans");
}

function adminPathForPointView(view: PointView) {
  return view === "new" ? "/admin/points/new" : adminPathForTab("points");
}

function adminPathForUserDetail(id: number | string) {
  return `/admin/users/${id}`;
}

function adminPathForShippingEdit(requestId: number | string, itemId: number | string) {
  return `/admin/shipping/${requestId}/items/${itemId}/edit`;
}

function adminPathForSettingView(view: SettingView, id?: number | string) {
  if (view === "pages") {
    return "/admin/settings/pages";
  }

  if (view === "rank-assets") {
    return "/admin/settings/rank-assets";
  }

  if (view === "referral") {
    return "/admin/settings/referral";
  }

  if (view === "rank-asset-new") {
    return "/admin/settings/rank-assets/new";
  }

  if (view === "rank-asset-edit") {
    return `/admin/settings/rank-assets/${id ?? ""}/edit`;
  }

  if (view === "edit") {
    return `/admin/settings/static-pages/${id ?? ""}/edit`;
  }

  return adminPathForTab("settings");
}

function buildProbabilityPayload(
  gacha: Gacha,
  stages: ProbabilityStageForm[],
) {
  const prizes = gacha.ranks?.flatMap((rank) => rank.prizes ?? []) ?? [];

  return {
    stages: stages.map((stage, index) => ({
        stage_key: stage.stageKey,
        name: stage.name,
        condition_type: "sold_count",
        min_draw_number: Number(stage.minDrawNumber),
        max_draw_number: stage.maxDrawNumber ? Number(stage.maxDrawNumber) : null,
        sort_order: Number(stage.sortOrder || index + 1),
        probabilities: [
          ...prizes.map((prize) => ({
            prize_id: prize.id,
            probability_ppm: percentToPpm(stage.rows[String(prize.id)] || "0"),
          })),
          {
            is_minimum_guarantee: true,
            probability_ppm: percentToPpm(stage.minimumGuaranteePpm || "0"),
          },
        ],
      })),
  };
}

function probabilityFormsFromMatrix(gacha: Gacha, stages: ProbabilityStagePayload[]): ProbabilityStageForm[] {
  if (stages.length === 0) {
    return [createEmptyProbabilityStage(gacha, 1)];
  }

  const prizes = gacha.ranks?.flatMap((rank) => rank.prizes ?? []) ?? [];

  return stages.map((stage, index) => {
    const rows: Record<string, string> = {};

    prizes.forEach((prize) => {
      const row = stage.probabilities.find((probability) => probability.prize_id === prize.id);
      rows[String(prize.id)] = ppmToPercentString(row?.probability_ppm ?? 0);
    });

    const guarantee = stage.probabilities.find((probability) => probability.is_minimum_guarantee);

    return {
      uid: stage.stage_key || `stage_${index + 1}`,
      stageKey: stage.stage_key || `stage_${index + 1}`,
      name: stage.name || `Stage ${index + 1}`,
      minDrawNumber: String(stage.min_draw_number),
      maxDrawNumber: stage.max_draw_number !== null ? String(stage.max_draw_number) : "",
      sortOrder: String(stage.sort_order || index + 1),
      minimumGuaranteePpm: ppmToPercentString(guarantee?.probability_ppm ?? 0),
      rows,
    };
  });
}

function createEmptyProbabilityStage(gacha: Gacha | null, index: number): ProbabilityStageForm {
  const rows: Record<string, string> = {};

  gacha?.ranks?.forEach((rank) => {
    rank.prizes?.forEach((prize) => {
      rows[String(prize.id)] = "0";
    });
  });

  return {
    uid: `stage_${index}`,
    stageKey: `stage_${index}`,
    name: index === 1 ? "Default" : `Stage ${index}`,
    minDrawNumber: index === 1 ? "1" : "",
    maxDrawNumber: "",
    sortOrder: String(index),
    minimumGuaranteePpm: "100",
    rows,
  };
}

function nextProbabilityStageIndex(stages: ProbabilityStageForm[]) {
  const usedIndexes = stages
    .map((stage) => Number(stage.stageKey.replace(/^stage_/, "")))
    .filter((value) => Number.isInteger(value) && value > 0);

  return usedIndexes.length > 0 ? Math.max(...usedIndexes) + 1 : stages.length + 1;
}

function probabilityStageTotal(stage: ProbabilityStageForm, prizes: GachaPrize[]) {
  return prizes.reduce(
    (sum, prize) => sum + percentToPpm(stage.rows[String(prize.id)] || "0"),
    percentToPpm(stage.minimumGuaranteePpm || "0"),
  );
}

function formFromGacha(gacha: Gacha) {
  return {
    id: String(gacha.id),
    title: gacha.title,
    slug: gacha.slug,
    categoryId: String(gacha.category_id),
    price: String(gacha.price),
    totalCount: String(gacha.total_count),
    dailyDrawLimit: gacha.daily_draw_limit !== null ? String(gacha.daily_draw_limit) : "",
    probabilityMode: gacha.probability_mode,
    minimumGuaranteeType: gacha.minimum_guarantee.type,
    minimumGuaranteeValue: String(gacha.minimum_guarantee.value),
    minimumGuaranteeCost: String(gacha.minimum_guarantee.cost),
    status: gacha.status,
    startAt: toDateTimeLocal(gacha.start_at),
    endAt: toDateTimeLocal(gacha.end_at),
    description: gacha.description ?? "",
    caution: gacha.caution ?? "",
    mainImageUrl: gacha.main_image_url ?? "",
    showOnTopSlider: gacha.show_on_top_slider,
    targetMargin: gacha.target_margin !== null ? String(gacha.target_margin) : "",
    tagIds: (gacha.tag_ids ?? gacha.tags?.map((tag) => tag.id) ?? []).map(String),
  };
}

function formFromTopBanner(banner: TopBanner) {
  return {
    id: String(banner.id),
    imageUrl: banner.image_url,
    linkUrl: banner.link_url ?? "",
    sortOrder: String(banner.sort_order),
    isActive: banner.is_active,
  };
}

function formFromPurchasePlan(plan: PointPurchasePlan) {
  return {
    id: String(plan.id),
    name: plan.name,
    amount: String(plan.amount),
    paidPointAmount: String(plan.paid_point_amount),
    freePointAmount: String(plan.free_point_amount),
    sortOrder: String(plan.sort_order),
    isActive: plan.is_active,
    startsAt: toDateTimeLocal(plan.starts_at),
    endsAt: toDateTimeLocal(plan.ends_at),
  };
}

function purchasePlanPeriodLabel(plan: PointPurchasePlan) {
  if (!plan.starts_at && !plan.ends_at) {
    return "無期限";
  }

  return `${formatDate(plan.starts_at)} - ${formatDate(plan.ends_at)}`;
}

function pointAdjustmentPayload(form: {
  adjustmentType: string;
  pointType: string;
  amount: string;
  expireAt: string;
  reason: string;
}): Record<string, string | number> {
  const payload: Record<string, string | number> = {
    adjustment_type: form.adjustmentType,
    amount: Number(form.amount),
    reason: form.reason,
  };

  if (form.adjustmentType === "grant") {
    payload.point_type = form.pointType;

    if (form.pointType === "free") {
      payload.expire_at = form.expireAt;
    }
  }

  return payload;
}

function gachaPayload(form: ReturnType<typeof formFromGacha>) {
  return {
    title: form.title,
    slug: form.slug,
    category_id: Number(form.categoryId),
    price: Number(form.price),
    total_count: Number(form.totalCount),
    daily_draw_limit: form.dailyDrawLimit ? Number(form.dailyDrawLimit) : null,
    probability_mode: form.probabilityMode,
    minimum_guarantee_type: form.minimumGuaranteeType,
    minimum_guarantee_value: Number(form.minimumGuaranteeValue),
    minimum_guarantee_cost: Number(form.minimumGuaranteeCost),
    status: form.status,
    start_at: form.startAt || null,
    end_at: form.endAt || null,
    description: form.description || null,
    caution: form.caution || null,
    main_image_url: form.mainImageUrl || null,
    show_on_top_slider: form.showOnTopSlider,
    target_margin: form.targetMargin ? Number(form.targetMargin) : null,
    tag_ids: form.tagIds.map(Number),
  };
}

function toDateTimeLocal(value: string | null) {
  if (!value) {
    return "";
  }

  const date = new Date(value);
  const offset = date.getTimezoneOffset() * 60000;

  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function pointLabel(value: number) {
  return `${value.toLocaleString("ja-JP")} pt`;
}

function moneyLabel(value: number) {
  return `¥${value.toLocaleString("ja-JP")}`;
}

function formatFiniteNumber(value: number) {
  return Number.isFinite(value) ? Math.round(value).toLocaleString("ja-JP") : "-";
}

function formatFiniteMoney(value: number) {
  return Number.isFinite(value) ? moneyLabel(Math.round(value)) : "-";
}

function formatFinitePercent(value: number) {
  return Number.isFinite(value) ? `${(value * 100).toFixed(1)}%` : "-";
}

function percentToPpm(value: string) {
  const percentage = Number(value);

  if (!Number.isFinite(percentage)) {
    return 0;
  }

  return Math.round(percentage * 10000);
}

function ppmToPercentString(value: number) {
  const percentage = value / 10000;

  return percentage
    .toFixed(4)
    .replace(/\.?0+$/, "");
}

function selectedOptionValues(select: HTMLSelectElement): string[] {
  return Array.from(select.selectedOptions).map((option) => option.value);
}

function makeSlugFallback(prefix: string) {
  return `${prefix}-${Date.now()}`;
}

function formatDate(value: string | null) {
  if (!value) {
    return "-";
  }

  return new Intl.DateTimeFormat("ja-JP", {
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}
