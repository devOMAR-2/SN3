// Read-only banners (archived teams and projects answer 423, ADR-022 §2).
export default {
  team_archived: {
    title: 'This team is archived',
    body: 'Everything in it is read-only until the Owner unarchives it.',
  },
  project_archived: {
    title: 'This project is archived',
    body: 'It is read-only until the Owner or a Project Manager unarchives it.',
  },
  read_only: {
    title: 'This is read-only now',
    body: 'The project or its team was archived. Your change was not saved.',
  },
};
