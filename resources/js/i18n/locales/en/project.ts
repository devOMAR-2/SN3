// Projects: list, creation and settings (CORE-006; API_SPEC.md §4, §5; DESIGN_SYSTEM.md §5.1, §7.1).
export default {
  create: 'Create project',
  first_title: 'Create your first project',
  create_intro: 'Projects hold tasks. You can change these details later.',
  create_submit: 'Create project',
  created: 'Project created.',
  create_members_failed: 'The project was created, but some members could not be added. Add them here.',
  restored: 'Project restored from the trash.',
  archived: 'Archived',
  key_hint:
    '2–10 uppercase letters or digits, starting with a letter. Task keys use it, e.g. WEB-12. It can change until the first task exists.',
  key_frozen: 'The key is fixed now that the project has tasks, so task keys never change.',
  members_help:
    'Optional. On a private project, only these people and the Team Owner can see it. You become a Project Manager.',
  no_other_members: 'There is nobody else in the team yet.',
  not_a_member: 'Not a member',
  member_role_for: 'Project role of {name}',
  becomes_private:
    'Only explicit project members and the Team Owner will see this project. Others, including Admins who are not members, lose access at once, and their tasks become unassigned.',
  visibility: {
    team: 'Team-visible',
    private: 'Private project',
  },
  visibility_help: {
    team: 'Everyone in the team can see it.',
    private: 'Only the members you add and the Team Owner can see it.',
  },
  roles: {
    manager: 'Project Manager',
    contributor: 'Contributor',
  },
  fields: {
    name: 'Project name',
    key: 'Project key',
    description: 'Description',
    visibility: 'Visibility',
    members: 'Members',
    role: 'Role',
  },
  errors: {
    key: {
      regex: 'Use 2–10 uppercase letters or digits, starting with a letter.',
    },
  },
  members: {
    add: 'Add member',
    add_person: 'Person',
    added: '{name} was added.',
    removed: '{name} was removed from the project.',
    none: 'No explicit members.',
    team_help: 'Everyone in the team can see this project. Project Managers manage its settings and members.',
    private_help: 'Only these people and the Team Owner can see this private project.',
    remove_body: 'They keep seeing this team-visible project, but no longer as an explicit member.',
    remove_private_body: 'They lose access to this private project at once, and their tasks in it become unassigned.',
  },
  settings: {
    title: 'Project settings',
    general: 'General',
    sprints: 'Sprints',
    sprints_help: 'Sprints are optional. Turning them off hides sprint planning but keeps all data.',
    sprints_switch: 'Use sprints in this project',
  },
  danger: {
    archive: 'Archive project',
    archive_title: 'Archive {project}?',
    archive_body: 'An archived project is read-only. Its tasks and history stay as they are, and you can unarchive it.',
    archived: 'Project archived. It is now read-only.',
    unarchive: 'Unarchive project',
    unarchive_body: 'The project can be changed again.',
    unarchived: 'Project unarchived.',
    delete: 'Delete project',
    delete_title: 'Delete {project}?',
    delete_body: 'Moves the project to the trash. The Owner or a Project Manager can restore it within 30 days.',
    delete_dialog_body:
      'The project and its tasks go to the trash for 30 days, then they are deleted permanently. Nothing on GitHub is deleted.',
    deleted: 'Project moved to the trash. It can be restored within 30 days.',
  },
};
