"use client";

import { useMemo } from "react";
import { AdminApiClient } from "../../lib/admin-api/client";
import { AdminShell } from "../shell/admin-shell";
import { ProtectedAdminRoute } from "../permissions/protected-admin-route";
import { AgencyAggregateTable } from "./agency-aggregate-table";

export function AgencyAggregateWorkspace({ kind }: { kind: "users" | "sales" }) {
  const load = useMemo(() => {
    const client = new AdminApiClient();
    return (query: Record<string, string>, signal?: AbortSignal) => kind === "users"
      ? client.agencyUserAggregate(query, signal) : client.agencySalesAggregate(query, signal);
  }, [kind]);

  return <AdminShell><ProtectedAdminRoute permission="agency.read"><AgencyAggregateTable key={kind} kind={kind} admin load={load} /></ProtectedAdminRoute></AdminShell>;
}
