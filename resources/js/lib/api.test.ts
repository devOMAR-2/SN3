import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { describe, expect, it } from 'vitest';
import { api, ApiError, toApiError } from './api';

function axiosErrorWith(status: number, data: unknown): AxiosError {
  const config = { headers: new AxiosHeaders() };
  const response = { status, data, statusText: '', headers: {}, config } as AxiosResponse;

  return new AxiosError('Request failed', 'ERR_BAD_RESPONSE', config, null, response);
}

describe('toApiError', () => {
  it('reads the API_SPEC error envelope', () => {
    const error = toApiError(
      axiosErrorWith(422, {
        error: {
          code: 'validation_failed',
          message: 'البيانات المُدخلة غير صالحة.',
          fields: { email: ['credentials'] },
          request_id: 'req_01TEST',
        },
      }),
    );

    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(422);
    expect(error.code).toBe('validation_failed');
    expect(error.fields).toEqual({ email: ['credentials'] });
    expect(error.requestId).toBe('req_01TEST');
    expect(error.message).toBe('البيانات المُدخلة غير صالحة.');
  });

  it('falls back for non-envelope responses and network failures', () => {
    expect(toApiError(axiosErrorWith(502, '<html>Bad gateway</html>')).code).toBe('server_error');
    expect(toApiError(new AxiosError('Network Error')).code).toBe('network_error');
    expect(toApiError(new Error('boom')).status).toBe(0);
  });
});

describe('api client', () => {
  it('targets /api/v1 with session cookies and the XSRF header', () => {
    expect(api.defaults.baseURL).toBe('/api/v1');
    expect(api.defaults.withCredentials).toBe(true);
    expect(api.defaults.withXSRFToken).toBe(true);
    expect(api.defaults.headers.Accept).toBe('application/json');
  });
});
