import { AxiosError, AxiosHeaders, type AxiosInstance, type AxiosResponse } from 'axios';
import { describe, expect, it, vi } from 'vitest';
import { createTaskEditor } from './taskEditor';
import type { Task } from '@/types/domain';

const base: Task = {
  id: 't1',
  project_id: 'p1',
  key: 'WEB-1',
  number: 1,
  title: 'First',
  description: null,
  status_id: 's1',
  priority: 'none',
  assignee_user_id: null,
  due_date: null,
  label_ids: [],
  parent_task_id: null,
  created_by: 'u1',
  version: 3,
  created_at: null,
  updated_at: null,
};

function failure(status: number, code: string): AxiosError {
  const config = { headers: new AxiosHeaders() };
  const response = {
    status,
    data: { error: { code, message: code, request_id: 'req' } },
    statusText: '',
    headers: {},
    config,
  } as AxiosResponse;

  return new AxiosError('failed', 'ERR_BAD_REQUEST', config, null, response);
}

describe('createTaskEditor', () => {
  it('sends If-Match with the latest version and runs edits one after another', async () => {
    const seen: string[] = [];
    let version = 3;
    const patch = vi.fn(async (_url: string, body: Partial<Task>, config: { headers: Record<string, string> }) => {
      seen.push(config.headers['If-Match']!);
      await new Promise((resolve) => setTimeout(resolve, 5));
      version += 1;

      return { data: { data: { ...base, ...body, version } } };
    });
    const onChange = vi.fn();
    const editor = createTaskEditor({ patch } as unknown as AxiosInstance, base, onChange);

    const [first, second] = await Promise.all([editor.save({ priority: 'high' }), editor.save({ title: 'Renamed' })]);

    expect(seen).toEqual(['"3"', '"4"']);
    expect(first.ok && second.ok).toBe(true);
    expect(editor.current().version).toBe(5);
    expect(onChange).toHaveBeenCalledTimes(2);
  });

  it('reports a 412 as a conflict, keeps the known task and does not retry', async () => {
    const patch = vi.fn().mockRejectedValue(failure(412, 'version_mismatch'));
    const onChange = vi.fn();
    const editor = createTaskEditor({ patch } as unknown as AxiosInstance, base, onChange);

    const result = await editor.save({ title: 'Mine' });

    expect(result).toMatchObject({ ok: false, outcome: 'conflict' });
    expect(patch).toHaveBeenCalledTimes(1);
    expect(editor.current()).toBe(base);
    expect(onChange).not.toHaveBeenCalled();
  });

  it('classifies 423 as locked and keeps later edits working', async () => {
    const patch = vi
      .fn()
      .mockRejectedValueOnce(failure(423, 'resource_locked'))
      .mockResolvedValueOnce({ data: { data: { ...base, version: 4 } } });
    const editor = createTaskEditor({ patch } as unknown as AxiosInstance, base, () => undefined);

    expect(await editor.save({ priority: 'low' })).toMatchObject({ ok: false, outcome: 'locked' });
    expect(await editor.save({ priority: 'low' })).toMatchObject({ ok: true });
  });

  it('starts from a reloaded task after reset', async () => {
    const patch = vi.fn(async (_url: string, _body: unknown, config: { headers: Record<string, string> }) => ({
      data: { data: { ...base, version: Number(config.headers['If-Match']!.replaceAll('"', '')) + 1 } },
    }));
    const editor = createTaskEditor({ patch } as unknown as AxiosInstance, base, () => undefined);

    editor.reset({ ...base, version: 9 });
    await editor.save({ priority: 'urgent' });

    expect(patch.mock.calls[0]![2]).toEqual({ headers: { 'If-Match': '"9"' } });
  });
});
