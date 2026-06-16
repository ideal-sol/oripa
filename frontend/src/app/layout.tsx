import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Oripa",
  description: "Oripa local development frontend",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="ja">
      <body>{children}</body>
    </html>
  );
}
