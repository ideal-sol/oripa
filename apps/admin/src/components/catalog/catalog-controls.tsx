"use client";

import { Search } from "lucide-react";
import type { FormEvent } from "react";

import type { CatalogSection } from "@/lib/catalog/catalog-registry";

export interface CatalogFilters {
  direction: "asc" | "desc";
  mediaType: "all" | "image" | "video";
  query: string;
  sort: string;
  visibility: "all" | "visible" | "hidden";
}

export function SearchForm({
  initialValue,
  onSubmit,
}: {
  initialValue: string;
  onSubmit: (value: string) => void;
}) {
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    onSubmit(String(data.get("q") ?? "").trim());
  }

  return (
    <form className="catalog-search" onSubmit={submit} role="search">
      <label>
        <span className="sr-only">Catalogを検索</span>
        <span className="input-shell">
          <Search size={17} aria-hidden="true" />
          <input
            defaultValue={initialValue}
            maxLength={100}
            name="q"
            placeholder="名称・Codeで検索"
            type="search"
          />
        </span>
      </label>
      <button className="secondary-button" type="submit">
        検索
      </button>
    </form>
  );
}

export function FilterControl({
  filters,
  onChange,
  section,
}: {
  filters: CatalogFilters;
  onChange: (filters: CatalogFilters) => void;
  section: CatalogSection;
}) {
  return (
    <div className="catalog-filters">
      <label>
        公開状態
        <select
          onChange={(event) =>
            onChange({
              ...filters,
              visibility: event.target.value as CatalogFilters["visibility"],
            })
          }
          value={filters.visibility}
        >
          <option value="all">すべて</option>
          <option value="visible">公開可</option>
          <option value="hidden">非公開</option>
        </select>
      </label>
      {section.supportsMediaType ? (
        <label>
          Media
          <select
            onChange={(event) =>
              onChange({
                ...filters,
                mediaType: event.target.value as CatalogFilters["mediaType"],
              })
            }
            value={filters.mediaType}
          >
            <option value="all">すべて</option>
            <option value="image">Image</option>
            <option value="video">Video</option>
          </select>
        </label>
      ) : null}
      <label>
        並び順
        <select
          onChange={(event) => onChange({ ...filters, sort: event.target.value })}
          value={filters.sort}
        >
          {section.sortOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <label>
        方向
        <select
          onChange={(event) =>
            onChange({
              ...filters,
              direction: event.target.value as CatalogFilters["direction"],
            })
          }
          value={filters.direction}
        >
          <option value="asc">昇順</option>
          <option value="desc">降順</option>
        </select>
      </label>
    </div>
  );
}
