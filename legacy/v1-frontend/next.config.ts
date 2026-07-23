import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  allowedDevOrigins: ["luxe-pack.biz", "admin.luxe-pack.biz"],
  images: {
    formats: ["image/avif", "image/webp"],
    remotePatterns: [
      { protocol: "https", hostname: "luxe-pack.biz" },
      { protocol: "https", hostname: "placehold.co" },
      { protocol: "https", hostname: "**.amazonaws.com" },
      { protocol: "https", hostname: "**.r2.cloudflarestorage.com" },
      { protocol: "https", hostname: "**.digitaloceanspaces.com" },
    ],
  },
  async headers() {
    const noStoreHeaders = [
      {
        key: "Cache-Control",
        value: "no-store, no-cache, max-age=0, must-revalidate",
      },
    ];

    return [
      {
        source: "/_next/static/chunks/:path*",
        headers: noStoreHeaders,
      },
      {
        source: "/:path*.css",
        headers: noStoreHeaders,
      },
    ];
  },
};

export default nextConfig;
