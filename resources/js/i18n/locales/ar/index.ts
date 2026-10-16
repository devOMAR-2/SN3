// Arabic dictionary. `satisfies Messages` makes a missing namespace or key a `vue-tsc` error.
import type { Messages } from '../en';
import account from './account';
import auth from './auth';
import common from './common';
import layout from './layout';
import validation from './validation';
import welcome from './welcome';

const ar = {
  account,
  auth,
  common,
  layout,
  validation,
  welcome,
} satisfies Messages;

export default ar;
