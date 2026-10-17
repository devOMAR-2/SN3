<?php

namespace App\Http\Pages;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The `can` maps that browser pages receive (CORE-006, ADR-026 §3). Every flag is a policy decision
 * (TeamPolicy, ProjectPolicy) made on the server, combined with the resource state that makes every
 * write answer 423 (an archived team or project, ARCHITECTURE.md §5.2 step 6). The UI only reads
 * these flags to hide controls; the /api/v1 endpoints still authorise every request themselves.
 */
final class Abilities
{
    /**
     * @return array{view_settings: bool, update: bool, create_project: bool, invite: bool, invite_admin: bool, change_member_roles: bool, remove_other_members: bool, transfer_ownership: bool, leave: bool, archive: bool, unarchive: bool, delete: bool, export: bool}
     */
    public static function team(User $user, Team $team): array
    {
        $gate = Gate::forUser($user);
        $writable = ! $team->isArchived();
        $isOwner = $team->roleOf($user)?->isOwner() ?? false;

        return [
            'view_settings' => self::viewsTeamSettings($user, $team),
            'update' => $writable && $gate->allows('update', $team),
            'create_project' => $writable && $gate->allows('createProject', $team),
            // CORE-002 (invitations) reads this flag for its panel on the members page.
            'invite' => $writable && $gate->allows('invite', $team),
            // DECIDED-007: only the Owner may send (email-specific) Admin invitations; for CORE-002's panel.
            'invite_admin' => $writable && $isOwner && $gate->allows('invite', $team),
            // TeamPolicy::changeMemberRole: the Owner, for every member except the Owner's own row.
            'change_member_roles' => $writable && $gate->allows('changeRoles', $team),
            // TeamPolicy::removeMember for someone else: the Owner only.
            'remove_other_members' => $writable && $isOwner,
            'transfer_ownership' => $writable && $gate->allows('transferOwnership', $team),
            // Anyone but the Owner may leave; the Owner transfers ownership first (TEAM-01, 409).
            'leave' => $writable && ! $isOwner && $gate->allows('removeMember', [$team, $user]),
            'archive' => $writable && $gate->allows('archive', $team),
            'unarchive' => ! $writable && $gate->allows('archive', $team),
            'delete' => $writable && $gate->allows('delete', $team),
            // An archived team can still be exported (ADR-024).
            'export' => $gate->allows('export', $team),
        ];
    }

    /**
     * @return array{view_settings: bool, update: bool, manage_members: bool, manage_workflow: bool, manage_labels: bool, contribute: bool, restore_tasks: bool, archive: bool, unarchive: bool, delete: bool}
     */
    public static function project(User $user, Project $project): array
    {
        $gate = Gate::forUser($user);
        $teamWritable = ! $project->team->isArchived();
        $writable = $teamWritable && ! $project->isArchived();

        return [
            'view_settings' => self::viewsProjectSettings($user, $project),
            'update' => $writable && $gate->allows('update', $project),
            'manage_members' => $writable && $gate->allows('manageMembers', $project),
            'manage_workflow' => $writable && $gate->allows('manageWorkflow', $project),
            'manage_labels' => $writable && $gate->allows('manageLabels', $project),
            'contribute' => $writable && $gate->allows('contribute', $project),
            'restore_tasks' => $writable && $gate->allows('restoreTasks', $project),
            // An archived team freezes its projects' lifecycle too (ADR-022 §2).
            'archive' => $writable && $gate->allows('archive', $project),
            'unarchive' => $teamWritable && $project->isArchived() && $gate->allows('archive', $project),
            'delete' => $writable && $gate->allows('delete', $project),
        ];
    }

    /** The team settings page is for someone who may change at least one thing on it (Owner, Admin). */
    public static function viewsTeamSettings(User $user, Team $team): bool
    {
        return Gate::forUser($user)->any(['update', 'archive', 'delete', 'export'], $team);
    }

    /** The project settings page: general, workflow, members or lifecycle rights. */
    public static function viewsProjectSettings(User $user, Project $project): bool
    {
        return Gate::forUser($user)->any(['update', 'manageMembers', 'manageWorkflow', 'archive', 'delete'], $project);
    }

    /**
     * Why writes are refused, for the read-only banner: the team's archive wins over the project's.
     */
    public static function lock(Team $team, ?Project $project = null): ?string
    {
        if ($team->isArchived()) {
            return 'team_archived';
        }

        return $project?->isArchived() === true ? 'project_archived' : null;
    }
}
