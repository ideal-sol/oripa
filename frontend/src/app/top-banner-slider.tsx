"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useState } from "react";

export type TopBannerItem = {
  key: string;
  href: string;
  imageUrl: string;
  title: string;
};

export default function TopBannerSlider({ items }: { items: TopBannerItem[] }) {
  const [activeIndex, setActiveIndex] = useState(0);

  useEffect(() => {
    if (items.length <= 1) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      setActiveIndex((current) => (current + 1) % items.length);
    }, 2800);

    return () => window.clearInterval(timer);
  }, [items.length]);

  if (items.length === 0) {
    return null;
  }

  return (
    <section className="top-banner" aria-label="キャンペーンバナー">
      <div className="top-banner-viewport">
        <div className="top-banner-track" style={{ transform: `translateX(-${activeIndex * 100}%)` }}>
          {items.map((item, index) => (
            <Link className="top-banner-item" href={item.href} key={item.key}>
              <Image className="optimized-image" src={item.imageUrl} alt={item.title} fill priority={index === 0} sizes="(max-width: 760px) 92vw, 1180px" />
            </Link>
          ))}
        </div>
      </div>
      {items.length > 1 && (
        <div className="top-banner-dots" aria-label="バナー選択">
          {items.map((item, index) => (
            <button
              aria-label={`${item.title}を表示`}
              aria-current={activeIndex === index}
              className={activeIndex === index ? "active" : ""}
              key={`${item.key}-dot`}
              type="button"
              onClick={() => setActiveIndex(index)}
            />
          ))}
        </div>
      )}
    </section>
  );
}
