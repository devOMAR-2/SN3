// English dictionary: one file per namespace. To add a namespace, create `<name>.ts` here and in `../ar/`,
// then add one line to each index. The Arabic index must satisfy `Messages`, so a missing key fails typecheck.
import account from './account';
import auth from './auth';
import common from './common';
import invitations from './invitations';
import layout from './layout';
import validation from './validation';
import welcome from './welcome';

const en = {
  account,
  auth,
  common,
  invitations,
  layout,
  validation,
  welcome,
};

export type Messages = typeof en;

export default en;
