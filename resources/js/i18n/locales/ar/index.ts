// Arabic dictionary. `satisfies Messages` makes a missing namespace or key a `vue-tsc` error.
import type { Messages } from '../en';
import account from './account';
import auth from './auth';
import common from './common';
import invitations from './invitations';
import layout from './layout';
import validation from './validation';
import welcome from './welcome';
// CORE-006 (navigation, projects, task detail).
import checklist from './checklist';
import conflict from './conflict';
import empty from './empty';
import error from './error';
import label from './label';
import lock from './lock';
import nav from './nav';
import project from './project';
import task from './task';
import team from './team';
import trash from './trash';
import ui from './ui';
import workflow from './workflow';

const ar = {
  account,
  auth,
  common,
  invitations,
  layout,
  validation,
  welcome,
  checklist,
  conflict,
  empty,
  error,
  label,
  lock,
  nav,
  project,
  task,
  team,
  trash,
  ui,
  workflow,
} satisfies Messages;

export default ar;
