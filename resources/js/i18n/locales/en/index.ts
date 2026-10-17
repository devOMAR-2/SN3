// English dictionary: one file per namespace. To add a namespace, create `<name>.ts` here and in `../ar/`,
// then add one line to each index. The Arabic index must satisfy `Messages`, so a missing key fails typecheck.
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
// CORE-009 (inbox, notification preferences).
import inbox from './inbox';

const en = {
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
  inbox,
};

export type Messages = typeof en;

export default en;
