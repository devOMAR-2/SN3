/**
 * Request helpers for the /api/v1 conventions (API_SPEC.md §1.4) that CORE-006 pages use.
 */
import { ApiError } from '@/lib/api';

/** `If-Match: "<version>"` for versioned resources (projects, tasks, statuses, the workflow). */
export function ifMatch(version: number): Record<string, string> {
  return { 'If-Match': `"${version}"` };
}

/** A fresh `Idempotency-Key` for retry-safe creates (tasks, exports). */
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  // RFC 4122 v4 from getRandomValues (older browsers without randomUUID).
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6]! & 0x0f) | 0x40;
  bytes[8] = (bytes[8]! & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * How a page should react to a failed write:
 * - `conflict`: 412, someone changed the resource; reload and reapply (the local draft is kept);
 * - `locked`: 423, the team or project is archived and read-only;
 * - `gone`: 404, the resource is no longer visible (deleted, made private, access removed);
 * - `forbidden`: 403, or 409 after losing a right while the request waited;
 * - `invalid`: 422 with field errors;
 * - `error`: anything else (network, rate limit, server).
 */
export type WriteOutcome = 'conflict' | 'locked' | 'gone' | 'forbidden' | 'invalid' | 'error';

export function classifyWriteError(error: ApiError): WriteOutcome {
  switch (error.status) {
    case 412:
      return 'conflict';
    case 423:
      return 'locked';
    case 404:
      return 'gone';
    case 403:
      return 'forbidden';
    case 422:
      return 'invalid';
    default:
      return 'error';
  }
}
