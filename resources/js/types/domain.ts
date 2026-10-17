/**
 * Domain shapes of the /api/v1 representations (API_SPEC.md §3–§6) and of the page props that the
 * CORE-006 web controllers send (App\Http\Controllers\Web, App\Http\Pages\Abilities).
 */

export type TeamRole = 'owner' | 'admin' | 'member' | 'viewer';
export type ProjectRole = 'manager' | 'contributor';
export type Visibility = 'team' | 'private';
export type StatusCategory = 'todo' | 'in_progress' | 'done';
export type Priority = 'none' | 'low' | 'medium' | 'high' | 'urgent';

/** Why writes are refused (423): the team's archive wins over the project's. */
export type Lock = 'team_archived' | 'project_archived' | null;

export const priorities: readonly Priority[] = ['none', 'low', 'medium', 'high', 'urgent'];
export const statusCategories: readonly StatusCategory[] = ['todo', 'in_progress', 'done'];

export interface Team {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  owner_user_id: string;
  role: TeamRole | null;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface Project {
  id: string;
  team_id: string;
  name: string;
  key: string;
  description: string | null;
  visibility: Visibility;
  role: ProjectRole | null;
  created_by: string;
  version: number;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ProjectSettings {
  project_id: string;
  sprints_enabled: boolean;
  key_frozen: boolean;
  version: number;
}

export interface ProjectStatus {
  id: string;
  project_id: string;
  name: string;
  category: StatusCategory;
  color: string | null;
  position: number;
  is_default: boolean;
  version: number;
}

export interface Label {
  id: string;
  project_id: string;
  name: string;
  color: string | null;
}

export interface Task {
  id: string;
  project_id: string;
  key: string;
  number: number;
  title: string;
  description: string | null;
  status_id: string;
  priority: Priority;
  assignee_user_id: string | null;
  due_date: string | null;
  label_ids: string[];
  parent_task_id: string | null;
  created_by: string;
  version: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface TaskSummary {
  id: string;
  key: string;
  title: string;
  status_id: string;
}

export interface ChecklistItem {
  id: string;
  task_id: string;
  text: string;
  checked: boolean;
  position: number;
}

export interface TeamMember {
  user_id: string;
  name: string;
  email?: string;
  role: TeamRole;
  joined_at: string;
}

export interface ProjectMember {
  user_id: string;
  name: string;
  email?: string;
  role: ProjectRole;
  added_at: string | null;
}

export interface Assignee {
  user_id: string;
  name: string;
  /** `false` for a current assignee who may no longer be assigned: shown, not offered. */
  eligible?: boolean;
}

export interface TeamExport {
  id: string;
  status: 'pending' | 'processing' | 'ready' | 'failed' | 'expired';
  download_url: string | null;
  expires_at: string | null;
}

export interface TrashedTeam {
  id: string;
  name: string;
  slug: string;
  deleted_at: string | null;
  recoverable_until: string | null;
}

export interface TrashedProject {
  id: string;
  name: string;
  key: string;
  visibility: Visibility;
  deleted_at: string | null;
  recoverable_until: string | null;
}

/** App\Http\Pages\Abilities::team() */
export interface TeamAbilities {
  view_settings: boolean;
  update: boolean;
  create_project: boolean;
  invite: boolean;
  invite_admin: boolean;
  change_member_roles: boolean;
  remove_other_members: boolean;
  transfer_ownership: boolean;
  leave: boolean;
  archive: boolean;
  unarchive: boolean;
  delete: boolean;
  export: boolean;
}

/** App\Http\Pages\Abilities::project() */
export interface ProjectAbilities {
  view_settings: boolean;
  update: boolean;
  manage_members: boolean;
  manage_workflow: boolean;
  manage_labels: boolean;
  contribute: boolean;
  restore_tasks: boolean;
  archive: boolean;
  unarchive: boolean;
  delete: boolean;
}

export interface TaskAbilities {
  edit: boolean;
  delete: boolean;
  manage_labels: boolean;
}

/** The team switcher entries (shared `workspace` prop, App\Http\Pages\Workspace::switcher()). */
export interface WorkspaceTeam {
  id: string;
  name: string;
  slug: string;
  role: TeamRole | null;
  archived: boolean;
}

export interface WorkspaceProps {
  teams: WorkspaceTeam[];
  has_more: boolean;
}

/** A /api/v1 collection page (API_SPEC.md §1.2). */
export interface CursorPage<T> {
  data: T[];
  meta: { next_cursor: string | null; has_more: boolean; limit: number; request_id: string };
}

/** Notification types (App\Domain\Notifications\NotificationType; CORE-009, ADR-029 §2). */
export const notificationTypes = [
  'task.assigned',
  'task.updated',
  'task.commented',
  'comment.mentioned',
  'team.invitation',
] as const;
export type NotificationType = (typeof notificationTypes)[number];

/** The task fields a `task.updated` notification may name (InboxPresenter::TASK_FIELDS). */
export const notificationFields = [
  'assignee_user_id',
  'description',
  'due_date',
  'label_ids',
  'parent_task_id',
  'priority',
  'status_id',
  'title',
] as const;

/** One inbox notification (GET /api/v1/notifications; InboxPresenter, ADR-029 §4). */
export interface InboxNotification {
  id: string;
  type: NotificationType;
  team: { id: string; name: string };
  project: { id: string; key: string; name: string } | null;
  task: { id: string; key: string; title: string } | null;
  comment_id: string | null;
  actor: { id: string; name: string } | null;
  fields: string[];
  event_count: number;
  invitation: { role: TeamRole; expires_at: string } | null;
  read_at: string | null;
  created_at: string;
  updated_at: string;
}
