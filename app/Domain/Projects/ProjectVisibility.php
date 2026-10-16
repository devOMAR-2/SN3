<?php

namespace App\Domain\Projects;

/**
 * Project visibility (FR-PROJ-002, DATABASE.md §4 `projects.visibility`). New projects are
 * team-visible (DEC-017).
 *
 * - `team`: every member of the team can see the project; writes follow team and project roles.
 * - `private`: only explicit project members and the Team Owner can see that it exists (DECIDED-003).
 */
enum ProjectVisibility: string
{
    case Team = 'team';
    case Private = 'private';
}
