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
 * An `Idempotency-Key` that survives retries (API_SPEC.md §1.4): the same request (same fingerprint)
 * reuses its key until it succeeds, so a retry after a timeout or a network error replays instead of
 * creating a second resource. A changed request, or the next one after a success, gets a new key.
 */
export interface RetryKey {
  for(fingerprint: string): string;
  /** Call after the server accepted the request. */
  settle(): void;
}

export function createRetryKey(generate: () => string = newIdempotencyKey): RetryKey {
  let key: string | null = null;
  let keyFor: string | null = null;

  return {
    for(fingerprint) {
      if (key === null || keyFor !== fingerprint) {
        key = generate();
        keyFor = fingerprint;
      }

      return key;
    },
    settle() {
      key = null;
      keyFor = null;
    },
  };
}

/**
 * How a page should react to a failed write:
 * - `conflict`: 412, someone changed the resource; reload and reapply (the local draft is kept);
 * - `locked`: 423, the team or project is archived and read-only;
 * - `gone`: 404, the resource is no longer visible (deleted, made private, access removed);
 * - `forbidden`: 403;
 * - `invalid`: 422 with field errors;
 * - `error`: anything else, shown with the server's translated message: 409 (a conflict such as a
 *   taken key, or a right lost while the request waited), 429, network and server errors.
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
