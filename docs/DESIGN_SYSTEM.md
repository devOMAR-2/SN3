# Design System & UX Specification — FINAL

> Document ID: DS-PM-001  
> Version: 1.0.1  
> Status: FINAL — implementation baseline  
> Date: 2026-10-12  
> Product: SN3 (سنع) — free, open-source project management for small teams (Cloud + Self-Hosted)  
> Audience: AI design agents, Vue frontend engineers, QA engineers, accessibility reviewers  
> Language: English technical specification. User-facing copy is localized to Arabic and English.

## 0. AI agent implementation contract

1. Implement the rules in this document; do not replace design choices with personal preferences or ask for new visual direction.
2. Product requirements in `BRD.md` and security/data rules in `ARCHITECTURE.md` and `DATABASE.md` take precedence over visual choices. For UI matters, this document is normative.
3. Do not generate marketing-site artwork, illustration systems, logos, or brand names as prerequisites. The app must work with a configurable plain-text product name and a neutral geometric placeholder mark.
4. Do not introduce paid components, external CDN runtime dependencies, proprietary fonts, or required hosted UI services. All assets must be locally served.
5. Build accessible semantic Vue components. Support Arabic RTL and English LTR in every state, including dialogs, forms, emails, notifications, and drag/drop alternatives.
6. Design components and views with realistic loading, empty, error, forbidden, offline, and success states; never show fake operational data as real.
7. Cloud and Self-Hosted use the same design system, with no tier-based UI differences.
8. No outstanding design decisions exist in this document. Implementation details not visible to users may be selected by engineers if consistent with these requirements.

## 1. Product design principles

- **Simple by default:** create a task within two deliberate actions from an open board (press Add task, enter title, press Enter). Advanced settings are progressive disclosure.
- **Calm and efficient:** compact enough for real work, never cramped or decorative.
- **One consistent task model:** task type is not mandatory; labels and custom fields handle classification.
- **Project-owned customization:** statuses, views, fields, templates, and optional sprints are project-level controls.
- **Privacy first:** a private project is not surfaced in search, navigation, activity, mentions, notifications, or previews to unauthorized users.
- **Keyboard and touch parity:** drag/drop is optional convenience, not the only method.
- **Localization first:** design components for text expansion and mirrored layouts rather than patching Arabic later.

## 2. Visual identity — binding choices

**Style:** Modern Minimal SaaS; neutral surfaces, restrained Saudi-inspired green accent, high-contrast typography, small radii, subtle borders, almost no decorative shadows. Do not use glassmorphism, gradients as main surfaces, neumorphism, neo-brutalism, oversized illustrations, or animation-heavy layouts.

**App name:** display `APP_NAME` configuration value, fallback `SN3` (Arabic brand: `سنع`). The brand is a Saudi colloquial word evoking doing work properly and with care. The spelling is approved; trademark and domain availability are not yet verified and must be checked before public launch.

**Logo:** simple square icon with two offset check-shaped bars; single-color SVG, 24×24 viewBox, no text baked into the SVG. Temporary accessible lettermark `س` is acceptable until final SVG; do not block implementation.

**Typography:** use `Space Grotesk` for English and `IBM Plex Sans Arabic` for Arabic, both self-hosted and licensed appropriately; fall back to system UI sans-serif. Use CSS `:lang(ar)` / `:lang(en)`; do not download fonts at runtime. Numbers in data remain LTR where required.

### 2.1 Color tokens

All colors below are implementation defaults, not approximate inspiration. Use semantic tokens in components; never hard-code palette hex values in views.

| Semantic token | Light | Dark | Purpose |
|---|---|---|---|
| `--bg` | `#F6FAF7` | `#0C1712` | App canvas |
| `--surface` | `#FFFFFF` | `#15241C` | Cards/panels |
| `--surface-muted` | `#F1F5F9` | `#20352A` | Hover/subtle panels |
| `--surface-hover` | `#E8F3EB` | `#253D32` | Interactive hover |
| `--text` | `#0F172A` | `#F1F5F9` | Primary text |
| `--text-muted` | `#475569` | `#A7B6C9` | Secondary text |
| `--text-subtle` | `#64748B` | `#94A3B8` | Metadata |
| `--border` | `#DCE3EC` | `#334155` | Dividers/controls |
| `--primary` | `#145A38` | `#6BD6A0` | Primary interactive color |
| `--primary-hover` | `#0B422A` | `#9AE9BC` | Primary hover |
| `--primary-on` | `#FFFFFF` | `#08291B` | On primary |
| `--focus` | `#29845A` | `#9AE9BC` | Focus ring |
| `--success` | `#166534` | `#86EFAC` | Success text/icons |
| `--warning` | `#92400E` | `#FCD34D` | Warning text/icons |
| `--danger` | `#B91C1C` | `#FCA5A5` | Destructive text/icons |
| `--info` | `#236B53` | `#9AE9BC` | Informational text/icons |
| `--overlay` | `rgba(15,23,42,.56)` | `rgba(0,0,0,.70)` | Modal backdrop |

Status badges use pale semantic backgrounds and dark semantic text in light mode, with dark translucent backgrounds and light semantic text in dark mode. Do not communicate status by color alone. Check WCAG contrast on actual pairings before release; adjust semantic foreground tokens if necessary without changing the visual direction.

### 2.2 Spacing, sizing and elevation

- Spacing scale: `4, 8, 12, 16, 20, 24, 32, 40, 48, 64px`; base rhythm 4px.
- Border radii: `4px` tiny, `8px` controls, `12px` cards, `16px` dialogs; pill `999px` only for chips and avatars.
- Borders: 1px. Shadows: panels `0 2px 8px rgba(15,23,42,.05)` light / `0 4px 16px rgba(0,0,0,.18)` dark; no stacked shadows.
- Control height: 36px desktop, 44px touch; icon-only target at least 44×44px on touch.
- Sidebar width: 248px expanded; 64px collapsed desktop; mobile is an overlay drawer.
- Topbar: 56px desktop and mobile.
- Main reading width: 1120px for forms/settings; boards may use full available viewport width.
- Card padding: 16px; dialogs 24px desktop / 16px mobile.
- Type scale: caption 12/18, small 13/20, body 14/22, heading 18/26, page title 24/32, display 30/38 (px size/line-height). Arabic line-height may increase up to 1.65 as needed without clipping.
- Weights: 400 body, 500 controls, 600 headings; 700 only for rare key metrics.
- Motion: 120ms hover, 180ms panels, 220ms dialogs; ease-out; honor `prefers-reduced-motion` with effectively no movement.

## 3. Layout and navigation

### 3.1 Desktop (`>= 1024px`)

- Persistent sidebar: workspace switcher; My Work; Inbox; Teams/Projects tree; bottom Settings, Help, profile.
- Topbar: breadcrumb (`Team / Project / View`), global search trigger, Create button, notifications, user menu.
- Project header: icon/name, visibility indicator, project member avatars, GitHub integration indicator, settings action (only if authorized).
- Project tabs: Board, List; Backlog and Sprints only if enabled; Calendar after implemented. Hidden tabs do not leave gaps.
- Sidebar navigation retains scroll position and collapses manually; keyboard focus remains predictable.
- No permanent second sidebar unless user opens task detail panel.

### 3.2 Tablet (`768–1023px`)

- Sidebar collapses to 64px icon rail by default; labels available via tooltip or expand action.
- Board scrolls horizontally by column; no tiny compressed columns.
- Task detail opens as a wide drawer or dialog with independent scroll.

### 3.3 Mobile (`< 768px`)

- Sidebar becomes a modal drawer; topbar shows menu, page title, search, and contextual Create.
- Project views use a compact tab strip. Board shows one column at a time with swipe or explicit column selector; list is the default mobile view for a project unless the user explicitly chose Board.
- Task detail uses full-screen sheet with sticky header and Save/Close actions.
- Forms are single-column; popovers convert to bottom sheets when necessary.
- No hover-only actions; all commands are reachable by visible tap targets or menus.
- Mobile task movement must support a Move to status menu; dragging is not required on touch.

### 3.4 Page map

| Route intent | Required UI |
|---|---|
| Sign in/up/verify/reset | Minimal public auth shell, no workspace navigation |
| Team creation | Short wizard: name → optional invitations → first project |
| My Work | Assigned tasks grouped by due date and project, accessible filters |
| Inbox | Notifications with unread controls, target links subject to authorization |
| Team projects | Project grid/list, search, visibility indicators, create action |
| Project Board | Columns, counts, task cards, quick add, status movement |
| Project List | Sortable/paginated task table, saved filters, bulk select where authorized |
| Backlog | Unscheduled tasks and sprint planning when enabled |
| Sprints | Current/future/completed sprints, planning and completion actions |
| Task details | Title, status, priority, assignee, dates, labels, custom fields, subtasks, comments, attachments, GitHub activity |
| Team settings | General, Members/Invites, Templates, Integrations, Danger Zone |
| Project settings | General/Visibility, Members, Workflow, Custom Fields, Views, Sprints, GitHub, Danger Zone |
| Profile | Locale, theme, personal notifications, security, memberships |

## 4. Component library

Use Vue 3 + TypeScript + Tailwind with accessible headless primitives from Reka UI and locally owned styled wrappers. Components live under `resources/js/components/ui` and domain components under `resources/js/components/domain`. Prefer composition over bespoke one-off controls. Use Lucide icons consistently; ship icons with the bundle.

| Component | Required variants / behavior |
|---|---|
| Button | primary, secondary, ghost, danger; loading, disabled; icon-only with accessible label |
| TextInput / Textarea | labels, helper text, errors, counter if max length; IME-safe |
| Select / Combobox | searchable when large, keyboard navigation, clearable where nullable |
| Checkbox / Radio / Switch | visible checked/focus/disabled states, large labels |
| Dialog / ConfirmDialog | focus trap, Escape, restore focus, explicit destructive confirmation |
| Drawer / Sheet | side for desktop, bottom/fullscreen for mobile |
| Menu / Popover / Tooltip | keyboard and pointer accessible; never critical content in tooltip only |
| Badge / StatusChip | text + semantic treatment; truncation with full-name access |
| Avatar / AvatarGroup | initials fallback, safe image handling, overflow count |
| Tabs | URL-backed selected view; arrow-key navigation |
| Toast | success/info/error; `aria-live`; no auto-dismiss of critical errors |
| EmptyState | short description and single relevant CTA if authorized |
| Skeleton / Spinner | preserve approximate layout; no indefinite silent loading |
| DataTable | sort, pagination, selection, responsive overflow, proper table semantics |
| DatePicker | locale-aware labels, unambiguous date format, keyboard input |
| RichTextEditor | safe Markdown-like formatting, sanitization, accessible toolbar |
| FileUpload | drag/drop plus file input, progress, size/type errors, removal |
| Search / CommandPalette | accessible search, grouped results, no unauthorized results |
| Breadcrumb | logical ancestor links, collapse intermediate items on narrow screens |
| KanbanColumn / TaskCard | stable keyboard and touch alternatives for all operations |
| ActivityFeed | chronological actor/action/time with timezone-aware display |

**No giant design-system dependency** that requires paid licenses or a proprietary hosted service. Third-party packages must be checked for compatibility with AGPL-3.0.

## 5. Interaction specifications

### 5.1 Creating a project

1. User chooses `Create project` from a team they can administer.
2. Modal/page requests name (required, 2–100 characters), optional description, icon/color, template (Simple default, Agile, Blank), and visibility (Team-visible default or Private).
3. Private project creator is a Project Manager. Team Owner retains access by policy.
4. `Create` is disabled during submission, shows field errors inline, and navigates to the new project on success.
5. Simple template starts with `To do`, `In progress`, `Done`; Agile adds optional sprint module; Blank starts with a single `To do` status and supports further configuration.
6. Project keys are generated from a safe uppercase ASCII slug with collision-safe suffix; users may edit before first task only. Never expose a hidden implementation identifier as the display name.

### 5.2 Task creation and editing

- Quick add from a board column: title input, Enter creates, Escape cancels; show inline pending state and error recovery.
- Full task form: title required (1–200 chars), description optional, status required, one nullable primary assignee, priority (None/Low/Medium/High/Urgent), due date optional, labels, sprint if enabled, custom fields.
- Default task priority is `None`; default status is the project's first active status.
- New task uses an immutable human-readable key like `WEB-104`; links use stable internal UUID so renaming the project never breaks links.
- Editing uses explicit Save in complex forms; simple field edits may auto-save with visible saving/error feedback and rollback on failure.
- Comments support `@mentions` only for people authorized to view the task; do not leak hidden team members or private projects through suggestions.
- Destructive actions are in a separate menu and require confirmation; archive and trash follow backend retention rules.
- Activity displays who changed which field and when, without exposing secrets or sensitive GitHub payloads.

### 5.3 Board behavior

- Each column maps to exactly one active project status; display task count and Add task action.
- Column width 288px desktop (min 264, max 340); board horizontally scrollable. Column headings remain visible while vertically scrolling long columns when technically feasible.
- Cards show key, title (max three lines), primary assignee, priority, due date, and up to two labels plus overflow. Do not show every custom field by default.
- Drag/drop across columns updates task status optimistically but reverts and explains server rejection. Keyboard alternative: focus card → action menu → Move to status → choose destination.
- Column reorder and status rename require project-management permissions; normal task movement follows Member permissions.
- Large boards use paginated/virtualized fetching by column and do not load the entire team's tasks.
- Filters include assignee, status, priority, label, sprint, due date and text; filtering state is URL-serializable and shareable only within access boundaries.
- Empty columns show a concise placeholder, not large illustrations.

### 5.4 Sprints and backlog

- Sprints are disabled by default for Simple and Blank; Agile template enables them.
- Enabling sprints adds Backlog and Sprints tabs without changing existing task status or ownership.
- Backlog is tasks not assigned to an active/planned sprint, subject to filter settings.
- Sprint card shows name, date range, goal, task count, progress; `Start`, `Complete`, and `Move unfinished tasks` actions require confirmation when consequential.
- Completed sprint view is read-only for sprint metadata; task content continues to obey regular task permissions.
- Story points are a configurable project field in sprint-enabled projects, not mandatory for all tasks.

### 5.5 GitHub integration

- Project settings > GitHub shows connection status, linked repositories, permissions granted, sync direction, and last successful sync.
- GitHub App install/authorization opens a clearly labeled external flow; never ask users to paste a Personal Access Token for normal use.
- Task detail GitHub section shows linked branches, commits, PRs, checks and mapped Issue, with external links and status timestamps.
- Optional bidirectional Issues sync is OFF until a project manager explicitly enables it; show a clear summary of fields synced and conflict rules.
- Automation rules use a readable builder: `When [PR opened/merged, Issue closed/reopened] → [change task status]`, with per-rule enabled toggle and preview.
- Sync conflict shows nonblocking warning with local value retained and a link to activity; never silently overwrite a user's local edit.
- Connection failure must not block creating/editing tasks. Show last known data labeled with timestamp and a Retry action when authorized.
- Never display private repository names or issue content to unauthorized project viewers.

## 6. Roles and privacy in the UI

- Team roles are fixed: Owner, Admin, Member, Viewer. Project roles: Manager and Contributor.
- Server authorization is authoritative; hiding a button is usability, not security.
- Team-visible projects appear to team members; private projects appear only to explicitly assigned members and Team Owner. Team Admin does not gain private-project content access automatically.
- For unauthorized resources, show a generic 404-style unavailable page; do not confirm private project names or existence in links, search, notifications, or error copy.
- Owners can access and manage all projects; administrative surfaces may expose minimal metadata as permitted by backend policy, but never task contents without authorization.
- Viewer sees read-only content and no actionable editing affordances. Destructive controls are hidden from users without authorization.
- Invite UI supports email-specific invitation and revocable share link; communicate expiry, redemption limits and assigned role.

## 7. Localization and RTL/LTR

- Arabic (`ar`) is the initial default for a new user when browser language starts with Arabic; otherwise English (`en`). User choice is persisted to their profile and overrides browser preference. Team language never overrides individual preference.
- Set `html.lang` and `html.dir` on each authenticated page and public auth route; switch without losing current task or form data.
- Use CSS logical properties (`margin-inline-start`, `padding-inline-end`, `inset-inline-start`, `border-inline-start`) and direction-aware icons. Do not mirror logos, GitHub icon, check marks, numerals, media, or code.
- Arabic labels and error messages are professionally localized, not transliterated; English labels are concise sentence case. All strings come from localization catalogs, never concatenated fragments.
- Use Intl for date/time/number formatting with user locale and timezone; store UTC server-side. Dates should have accessible unambiguous full forms in tooltips or detail text.
- Task keys, repository paths, URLs, code, SHA hashes, branch names and email addresses render in isolated LTR spans with `dir="ltr"` and `unicode-bidi: isolate`.
- Icons representing directional navigation flip appropriately; universal icons do not.
- Arabic and English search both supported; trim and normalize user input server-side without changing stored originals.

### 7.1 Required translation examples

| Key | Arabic | English |
|---|---|---|
| `nav.my_work` | مهامي | My work |
| `nav.inbox` | الوارد | Inbox |
| `nav.projects` | المشاريع | Projects |
| `project.create` | إنشاء مشروع | Create project |
| `project.visibility.team` | متاح للفريق | Team-visible |
| `project.visibility.private` | مشروع خاص | Private project |
| `task.create` | إضافة مهمة | Add task |
| `task.assignee` | المسؤول | Assignee |
| `task.due_date` | تاريخ الاستحقاق | Due date |
| `task.status.todo` | للإنجاز | To do |
| `task.status.in_progress` | قيد التنفيذ | In progress |
| `task.status.done` | مكتمل | Done |
| `sprint.backlog` | المهام غير المجدولة | Backlog |
| `github.sync_conflict` | تعذرت مزامنة بعض التغييرات. احتفظنا بتعديلاتك المحلية. | Some changes could not be synced. Your local edits were preserved. |
| `error.generic` | حدث خطأ. حاول مرة أخرى. | Something went wrong. Try again. |
| `empty.tasks` | لا توجد مهام حتى الآن | No tasks yet |

## 8. Accessibility and input methods

- Target WCAG 2.2 AA; every release must pass automated accessibility scans plus manual keyboard and screen-reader smoke tests.
- Visible focus indicator at least 2px, with sufficient contrast; focus order follows DOM reading order in both directions.
- All inputs have persistent labels; placeholder text is never the sole label.
- Dialogs announce title and description; opening traps focus and closing restores it.
- Task card movement is possible without pointer dragging. Reordering must announce the resulting position/status via live region.
- Notifications use appropriate `aria-live` politeness and never steal focus except blocking security events.
- Do not rely solely on color, icon, animation or hover for meaning.
- Text zoom to 200% and reflow at 320 CSS px without horizontal scrolling except intentional board/table regions.
- User can disable nonessential motion via OS preference. Respect reduced contrast/transparency preferences when supported.
- Error summary on failed complex forms links to fields and preserves entered values.

## 9. States and feedback

For every data-backed view implement:

1. **Loading:** stable skeleton, no flashing layout.
2. **Empty:** concise localized explanation, one useful CTA if permitted.
3. **Error:** retry where safe, actionable explanation, non-sensitive correlation ID in support detail.
4. **Forbidden/hidden:** generic unavailable treatment for private content; no existence leak.
5. **Success:** subtle toast, visible updated state, no unnecessary modal.
6. **Network interruption:** persistent but unobtrusive offline indicator; block writes that cannot safely queue; do not claim saved until server confirms.
7. **Concurrent modification:** refresh notice or conflict resolution; preserve unsaved local text.
8. **Integration stale:** last-sync timestamp and status, not an invented live status.

Do not show success toasts for background tasks until the relevant server action succeeds. For destructive actions, state whether an item is archived, trashed, or permanently deleted.

## 10. Performance and frontend engineering rules

- Route-level code splitting and lazy loading of heavy rich text, date picker and board interactions.
- No required third-party font/icon CDN; bundle static assets and cache with fingerprinted names.
- Paginate large lists and per-column board data; use server filtering and indexing, not giant client arrays.
- Avoid global reactive stores for entire tenant datasets; keep view-local state and explicit invalidation boundaries.
- Avoid animation on large collections and expensive per-card observers.
- Optimistic updates only where rollback and conflict reporting are implemented.
- No real-time dependency for core CRUD; poll/refetch on focus or explicit refresh when live updates unavailable.
- Use semantic design tokens with theme classes; do not duplicate arbitrary Tailwind color strings throughout the app.

## 11. Design tokens implementation shape

The tokens above must be implemented as CSS custom properties on `:root` and `[data-theme="dark"]`, consumed via Tailwind semantic aliases. Theme modes: `system` (default), `light`, `dark`; system follows `prefers-color-scheme` unless user overrides. Persist choice in user profile and local pre-auth preference to prevent theme flash. Theme switch must not require reload.

Typography locale selectors:

```css
:root { font-family: "Space Grotesk", system-ui, sans-serif; }
:lang(ar) { font-family: "IBM Plex Sans Arabic", system-ui, sans-serif; }
.code, code, pre, [data-technical-id] { direction: ltr; unicode-bidi: isolate; }
```

**Note:** The CSS above is illustrative of behavior; actual implementation must define local `@font-face` entries and fallback stacks, not rely on fonts being installed on the visitor's device.

## 12. Design QA acceptance criteria

| ID | Testable requirement |
|---|---|
| DS-ACC-001 | All primary workflows operate by keyboard only, including moving a task between statuses. |
| DS-ACC-002 | RTL screens mirror structural layout while preserving LTR technical identifiers and GitHub labels. |
| DS-ACC-003 | 320px, 375px, 768px, 1024px, and 1440px layouts remain usable without clipping critical controls. |
| DS-ACC-004 | Light/dark/system modes persist and do not flash wrong theme on navigation. |
| DS-ACC-005 | All interactive controls expose visible focus, accessible names and non-color-only states. |
| DS-ACC-006 | Team Admin cannot see private-project content without explicit membership; UI and server both enforce access. |
| DS-ACC-007 | Board handles at least 500 tasks in a project without loading all cards into the browser at once. |
| DS-ACC-008 | Task creation, status change, comment, and file upload display honest pending/error/success states. |
| DS-ACC-009 | Arabic and English localization covers routes, validation, dialogs, emails and empty/error states. |
| DS-ACC-010 | GitHub sync disabled/failing does not prevent normal task work. |
| DS-ACC-011 | No core action requires hover or drag/drop; equivalent mobile and keyboard actions exist. |
| DS-ACC-012 | No required runtime network request to third-party font, icon or UI CDNs. |
| DS-ACC-013 | Screens are usable with 200% text zoom and reduced-motion preference. |
| DS-ACC-014 | Private project metadata does not leak through global search, inbox, mentions, or direct-link errors. |
| DS-ACC-015 | Status/priority labels remain understandable without their colors. |

## 13. Recommended component delivery order

1. Tokens, themes, fonts, locale direction and app shell.
2. Accessible primitives: buttons, forms, menus, dialogs, toasts, tables.
3. Auth, team creation and workspace switcher.
4. Project list, project creation and visibility settings.
5. Task detail, comments and board with accessible movement.
6. List/filter/saved views and project customization.
7. Optional backlog/sprints.
8. GitHub connection, activity and conflict states.
9. Responsive regression, screen-reader testing, theme/localization snapshots.

This order is implementation guidance, not a change to the agreed feature scope or release commitments.

## 14. Final decisions and conflict resolution

- Style is **Modern Minimal SaaS**; default accent **green**; neutral backgrounds; restrained borders and shadows.
- Both **light and dark themes** are mandatory; default follows system preference.
- **Space Grotesk** (English) and **IBM Plex Sans Arabic** (Arabic) are mandatory locally bundled font families subject to their redistribution licenses.
- **Arabic and English** are fully supported; locale is a per-user preference, and layout direction is per locale.
- **Desktop sidebar**, **tablet icon rail**, **mobile drawer** are mandatory responsive navigation patterns.
- **List is mobile project default**; **Board is desktop default**, while a user's explicit view choice persists per project.
- **Sprints are optional**, hidden when disabled; task model remains unified.
- **GitHub sync is opt-in**, and errors never block local task management.
- **No premium UI, no feature gates, no advertisements** in Cloud or Self-Hosted.
- If this document conflicts with security, privacy or business invariants in final architecture/database/BRD, enforce the more restrictive security/privacy behavior and record a code-level traceability note. Do not silently weaken access controls.

**Status: FINAL. Ready for implementation and AI agent consumption.**


## 15. Brand update — binding revision

- Visual direction: Saudi-inspired deep green with neutral surfaces; green must not be used as the sole indicator of task completion or success.
- Primary brand: `#145A38`, hover `#0B422A`, accent `#29845A`, subtle background `#E8F3EB`, app canvas `#F6FAF7`.
- English font: **Space Grotesk**, Arabic font: **IBM Plex Sans Arabic**. Bundle font assets locally under their respective licenses.
- Approved product name: **سنع / SN3** (no diacritics). Name spelling is final; trademark and domain availability still require verification before public launch. UI must use configurable APP_NAME.
- The name **نسق / NASAQ** is explicitly rejected due to established Saudi technology/productivity uses.
- This revision supersedes any previous blue/Inter/name instructions in other design documents.

## 16. Reconciled project privacy and release UX

- Do not render private project names, counts, navigation, notifications, search suggestions, attachment names, or GitHub metadata for nonmembers, including Team Admin. Team Owner has override.
- Team Viewer is read-only even when listed as a project manager; project role choices are only manager/contributor.
- Optional sprints, custom fields, templates, Board/List/Calendar views, and GitHub PR activity are public v1.0 screens; two-way Issues conflict screens are v1.1.
- Every mandatory screen must implement loading, empty, error, forbidden/404, narrow-mobile, RTL and LTR states, with keyboard equivalents for drag/drop.
