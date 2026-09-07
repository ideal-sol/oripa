export const contractSha256 = "0451b9b320a27df893634f0b1e47c59c7b7bd521b6e70fc9d8f094d2d1dfac60";
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
