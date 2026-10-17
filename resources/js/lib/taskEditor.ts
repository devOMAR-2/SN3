/**
 * Serialised, version-checked task edits (API_SPEC.md §1.4, §6.1, §14.3; CORE-006, ADR-026 §5).
 *
 * Every change is a `PATCH /tasks/{id}` with `If-Match` set to the version the client last saw. Edits
 * run one after another, so each one carries the version the previous one returned and two quick
 * edits on the same page never conflict with each other. A 412 means someone else changed the task:
 * the edit is not retried or merged; the caller shows a reload prompt and keeps the unsaved text.
 */
import type { AxiosInstance } from 'axios';
import { toApiError, type ApiEnvelope, type ApiError } from '@/lib/api';
import { classifyWriteError, ifMatch, type WriteOutcome } from '@/lib/http';
import type { Task } from '@/types/domain';

export type TaskChanges = Partial<
  Pick<Task, 'title' | 'description' | 'status_id' | 'priority' | 'assignee_user_id' | 'due_date' | 'label_ids'>
>;

export type EditResult = { ok: true; task: Task } | { ok: false; outcome: WriteOutcome; error: ApiError };

export interface TaskEditor {
  /** Applies `changes` to the latest known version; resolves once the server answered. */
  save(changes: TaskChanges): Promise<EditResult>;
  /** The task as the server last returned it. */
  current(): Task;
  /** Replaces the known task (after a reload). */
  reset(task: Task): void;
}

export function createTaskEditor(client: AxiosInstance, initial: Task, onChange: (task: Task) => void): TaskEditor {
  let task = initial;
  let queue: Promise<unknown> = Promise.resolve();

  async function send(changes: TaskChanges): Promise<EditResult> {
    try {
      const response = await client.patch<ApiEnvelope<Task>>(`/tasks/${task.id}`, changes, {
        headers: ifMatch(task.version),
      });
      task = response.data.data;
      onChange(task);

      return { ok: true, task };
    } catch (thrown) {
      const error = toApiError(thrown);

      return { ok: false, outcome: classifyWriteError(error), error };
    }
  }

  return {
    save(changes) {
      const run = queue.then(() => send(changes));
      queue = run.catch(() => undefined);

      return run;
    },
    current: () => task,
    reset(next) {
      task = next;
      onChange(task);
    },
  };
}
