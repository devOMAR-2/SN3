<?php

namespace App\Domain\Projects;

/**
 * Explicit project roles (DECIDED-003, DATABASE.md §19): `manager` manages project settings and
 * membership; `contributor` works on tasks. There is no project viewer role.
 *
 * A project role never lifts a Team Viewer above read-only, and the Team Owner has implicit manager
 * authority without any membership row (App\Policies\ProjectPolicy).
 */
enum ProjectRole: string
{
    case Manager = 'manager';
    case Contributor = 'contributor';
}
