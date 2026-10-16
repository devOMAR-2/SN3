/**
 * Client for the /api/v1 JSON interface (API_SPEC.md §1). Same-origin session cookies; Laravel's
 * encrypted XSRF-TOKEN cookie is echoed as X-XSRF-TOKEN. No bearer tokens are ever stored.
 */
import axios, { isAxiosError, type AxiosInstance } from 'axios';

export interface ApiErrorEnvelope {
  error: {
    code: string;
    message: string;
    fields?: Record<string, string[]>;
    request_id: string;
  };
}

export interface ApiEnvelope<T> {
  data: T;
  meta: { request_id: string };
}

export const api: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  withXSRFToken: true,
});

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string,
    readonly fields: Record<string, string[]> = {},
    readonly requestId: string | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

function isErrorEnvelope(value: unknown): value is ApiErrorEnvelope {
  if (value === null || typeof value !== 'object' || !('error' in value)) {
    return false;
  }

  const error = (value as { error: unknown }).error;

  return error !== null && typeof error === 'object' && typeof (error as { code?: unknown }).code === 'string';
}

/** Normalises any thrown value into an ApiError; network failures get status 0 and code `network_error`. */
export function toApiError(thrown: unknown): ApiError {
  if (isAxiosError(thrown)) {
    const status = thrown.response?.status ?? 0;
    const body: unknown = thrown.response?.data;

    if (isErrorEnvelope(body)) {
      return new ApiError(body.error.message, status, body.error.code, body.error.fields ?? {}, body.error.request_id);
    }

    return new ApiError(thrown.message, status, status === 0 ? 'network_error' : 'server_error');
  }

  return new ApiError(thrown instanceof Error ? thrown.message : String(thrown), 0, 'network_error');
}
