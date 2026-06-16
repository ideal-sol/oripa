export type ApiHealth = {
  app: string;
  db: string;
  redis: string;
  storage: string;
  timestamp: string;
};

export type ApiCollection<T> = {
  data: T[];
};

export type Announcement = {
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

export type StaticPage = {
  id: number;
  slug: string;
  title: string;
  body: string;
  status: string;
  published_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type PublicGachaListItem = {
  id: number;
  title: string;
  slug: string;
  category: {
    id: number | null;
    name: string | null;
    slug: string | null;
  };
  price: number;
  total_count: number;
  sold_count: number;
  remaining_count: number;
  probability_mode: string;
  minimum_guarantee: {
    type: string;
    value: number;
  };
  status: string;
  main_image_url: string | null;
  show_on_top_slider: boolean;
  start_at: string | null;
  end_at: string | null;
};

export type PublicGachaDetail = PublicGachaListItem & {
  description: string | null;
  caution: string | null;
  current_probability_version: {
    id: number;
    version_number: number;
    snapshot_hash: string;
    published_at: string | null;
  } | null;
  current_stage: PublicGachaStage | null;
  next_stage: PublicGachaStage | null;
  stages: (PublicGachaStage & {
    minimum_guarantee_ppm: number | null;
  })[];
  ranks: {
    id: number;
    rank_key: string;
    display_name: string;
    description: string | null;
    image_url: string | null;
    draw_video_url: string | null;
    sort_order: number;
    stage_total_ppm: Record<string, number>;
    prizes: {
      id: number;
      name: string;
      image_url: string | null;
      max_win_count: number;
      won_count: number;
      remaining_win_count: number;
      display_price: number | null;
      exchange_point: number | null;
      condition: string;
      is_active: boolean;
      sort_order: number;
      ppm: Record<string, number>;
    }[];
  }[];
};

export type PublicGachaStage = {
  id: number;
  stage_key: string;
  name: string;
  condition_type: string;
  min_draw_number: number;
  max_draw_number: number | null;
};

export async function fetchApiHealth(baseUrl = getApiBaseUrl()): Promise<ApiHealth> {
  const response = await fetch(`${baseUrl}/health`, {
    cache: "no-store",
    headers: {
      accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error(`Laravel API health check failed: ${response.status}`);
  }

  return response.json() as Promise<ApiHealth>;
}

export async function fetchPublicGachas(baseUrl = getApiBaseUrl()): Promise<ApiCollection<PublicGachaListItem>> {
  const response = await fetch(`${baseUrl}/gachas`, {
    cache: "no-store",
    headers: {
      accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error(`Laravel API gacha list failed: ${response.status}`);
  }

  return response.json() as Promise<ApiCollection<PublicGachaListItem>>;
}

export async function fetchPublicAnnouncements(baseUrl = getApiBaseUrl()): Promise<ApiCollection<Announcement>> {
  const response = await fetch(`${baseUrl}/announcements`, {
    cache: "no-store",
    headers: {
      accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error(`Laravel API announcement list failed: ${response.status}`);
  }

  return response.json() as Promise<ApiCollection<Announcement>>;
}

export async function fetchPublicAnnouncement(id: number, baseUrl = getApiBaseUrl()): Promise<{ data: Announcement }> {
  const response = await fetch(`${baseUrl}/announcements/${id}`, {
    cache: "no-store",
    headers: {
      accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error(`Laravel API announcement detail failed: ${response.status}`);
  }

  return response.json() as Promise<{ data: Announcement }>;
}

export async function fetchPublicStaticPage(slug: string, baseUrl = getApiBaseUrl()): Promise<{ data: StaticPage }> {
  const response = await fetch(`${baseUrl}/static-pages/${slug}`, {
    cache: "no-store",
    headers: {
      accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error(`Laravel API static page failed: ${response.status}`);
  }

  return response.json() as Promise<{ data: StaticPage }>;
}

export async function fetchPublicGacha(id: number, baseUrl = getApiBaseUrl()): Promise<{ data: PublicGachaDetail }> {
  const response = await fetch(`${baseUrl}/gachas/${id}`, {
    cache: "no-store",
    headers: {
      accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error(`Laravel API gacha detail failed: ${response.status}`);
  }

  return response.json() as Promise<{ data: PublicGachaDetail }>;
}

export function getApiBaseUrl(): string {
  return process.env.INTERNAL_API_BASE_URL
    ?? process.env.NEXT_PUBLIC_API_BASE_URL
    ?? "http://localhost:8000/api";
}
