export const contractSha256 = "9fc4776d7dca09ca4cc31e8253940817990b4571fc3fbbb9948c41818705aea1";
export type AgencyAggregatePeriod = { "start_date": string; "end_date": string; "timezone": "Asia/Tokyo" };
export type AgencyUserAggregateRow = { "advertising_code": string; "temporary_users": number; "full_users": number };
export type AgencyUserAggregate = { "items": (AgencyUserAggregateRow)[]; "period": AgencyAggregatePeriod; "next_cursor": string | null };
export type AgencySalesAggregateRow = { "advertising_code": string; "temporary_paying_users": number; "temporary_amount": number; "full_paying_users": number; "full_amount": number };
export type AgencySalesAggregate = { "items": (AgencySalesAggregateRow)[]; "period": AgencyAggregatePeriod; "next_cursor": string | null };
export type OpaqueId = string;
export type SemanticVersion = string;
export type UtcDateTime = string;
export type BusinessDate = string;
export type ProblemDetails = { "type": string; "title": string; "status": number; "detail"?: string; "instance"?: string; "code": string; "request_id": string; "retryable": boolean; "errors"?: ValidationErrors; "retry_after_seconds"?: number };
export type AgencyProfile = { "id": OpaqueId; "company_name": string; "contact_name": string; "phone": string; "email": string; "address": string; "login_id": string; "advertising_code": string; "status": "active" | "suspended" };
export type AgencyLogin = { "login_id": string; "password": string };
export type AgencyContact = { "contact_name": string; "phone": string };
export type AgencyEmailChange = { "current_password": string; "email": string };
export type AgencyPasswordChange = { "current_password": string; "password": string; "password_confirmation": string };
export type AgencySession = { "authenticated": boolean; "agency": AgencyProfile | null };
export type AgencyProfileResponse = { "data": AgencyProfile };
export type AgencyLogout = { "status": "logged_out" };
export type ValidationErrors = {  };
export const operations = {
  "getAgencyUserAggregate": {
    "path": "/agency/api/v2/aggregates/users",
    "method": "GET"
  },
  "getAgencySalesAggregate": {
    "path": "/agency/api/v2/aggregates/sales",
    "method": "GET"
  },
  "agencySession": {
    "path": "/agency/api/v2/auth/session",
    "method": "GET"
  },
  "agencyLogin": {
    "path": "/agency/api/v2/auth/login",
    "method": "POST"
  },
  "agencyLogout": {
    "path": "/agency/api/v2/auth/logout",
    "method": "POST"
  },
  "agencyProfile": {
    "path": "/agency/api/v2/me",
    "method": "GET"
  },
  "agencyContact": {
    "path": "/agency/api/v2/me/contact",
    "method": "PATCH"
  },
  "agencyEmailChange": {
    "path": "/agency/api/v2/me/email",
    "method": "POST"
  },
  "agencyPasswordChange": {
    "path": "/agency/api/v2/me/password",
    "method": "POST"
  }
} as const;
