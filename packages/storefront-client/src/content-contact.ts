import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface ContentListQuery {
  limit?: number;
  cursor?: string;
}

export interface ContactSubmissionOptions {
  csrf_token: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface BrowserContactSubmissionOptions {
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface StorefrontContentContactClient {
  listBanners(): Promise<StorefrontResponse<Schemas["ContentBannerCollection"]>>;
  listNotices(
    query?: ContentListQuery,
  ): Promise<StorefrontResponse<Schemas["ContentNoticeCollection"]>>;
  getNotice(id: string): Promise<StorefrontResponse<Schemas["ContentNotice"]>>;
  getStaticPage(
    slug: string,
  ): Promise<StorefrontResponse<Schemas["ContentStaticPage"]>>;
  listFooterPages(): Promise<
    StorefrontResponse<Schemas["ContentFooterPageCollection"]>
  >;
  submitContact(
    input: Schemas["CreateContactInquiryRequest"],
    options: ContactSubmissionOptions,
  ): Promise<StorefrontResponse<Schemas["ContactInquiryReceipt"]>>;
}

export interface BrowserStorefrontContentContactClient {
  listBanners(): Promise<StorefrontResponse<Schemas["ContentBannerCollection"]>>;
  listNotices(
    query?: ContentListQuery,
  ): Promise<StorefrontResponse<Schemas["ContentNoticeCollection"]>>;
  getNotice(id: string): Promise<StorefrontResponse<Schemas["ContentNotice"]>>;
  getStaticPage(
    slug: string,
  ): Promise<StorefrontResponse<Schemas["ContentStaticPage"]>>;
  listFooterPages(): Promise<
    StorefrontResponse<Schemas["ContentFooterPageCollection"]>
  >;
  submitContact(
    input: Schemas["CreateContactInquiryRequest"],
    options?: BrowserContactSubmissionOptions,
  ): Promise<StorefrontResponse<Schemas["ContactInquiryReceipt"]>>;
}

function segment(value: string, name: string): string {
  if (!value || value.includes("/") || value.includes("\0")) {
    throw new TypeError(`${name} is invalid`);
  }
  return encodeURIComponent(value);
}

function queryString(query: ContentListQuery): string {
  const parameters = new URLSearchParams();
  if (query.limit !== undefined) {
    if (!Number.isSafeInteger(query.limit) || query.limit < 1 || query.limit > 100) {
      throw new TypeError("limit must be an integer from 1 through 100");
    }
    parameters.set("limit", String(query.limit));
  }
  if (query.cursor !== undefined) {
    parameters.set("cursor", query.cursor);
  }
  const encoded = parameters.toString();
  return encoded === "" ? "" : `?${encoded}`;
}

function csrf(value: string): Record<string, string> {
  if (!/^[0-9a-f]{64}$/.test(value)) {
    throw new TypeError("csrf_token is invalid");
  }
  return { "X-XSRF-TOKEN": value };
}

export function createStorefrontContentContactClient(
  transport: StorefrontTransport,
): StorefrontContentContactClient {
  return {
    listBanners: () => transport.request({ path: "/content/banners" }),
    listNotices: (query = {}) =>
      transport.request({ path: `/content/notices${queryString(query)}` }),
    getNotice: (id) =>
      transport.request({
        path: `/content/notices/${segment(id, "notice_id")}`,
      }),
    getStaticPage: (slug) =>
      transport.request({
        path: `/content/pages/${segment(slug, "slug")}`,
      }),
    listFooterPages: () =>
      transport.request({ path: "/content/footer-pages" }),
    submitContact: (input, options) =>
      transport.request({
        path: "/contact-inquiries",
        method: "POST",
        body: input,
        headers: csrf(options.csrf_token),
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
        retry: false,
      }),
  };
}

export function createCsrfManagedStorefrontContentContactClient(
  transport: StorefrontTransport,
): BrowserStorefrontContentContactClient {
  return {
    listBanners: () => transport.request({ path: "/content/banners" }),
    listNotices: (query = {}) =>
      transport.request({ path: `/content/notices${queryString(query)}` }),
    getNotice: (id) =>
      transport.request({
        path: `/content/notices/${segment(id, "notice_id")}`,
      }),
    getStaticPage: (slug) =>
      transport.request({
        path: `/content/pages/${segment(slug, "slug")}`,
      }),
    listFooterPages: () =>
      transport.request({ path: "/content/footer-pages" }),
    submitContact: (input, options = {}) =>
      transport.request({
        path: "/contact-inquiries",
        method: "POST",
        body: input,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
        retry: false,
      }),
  };
}
