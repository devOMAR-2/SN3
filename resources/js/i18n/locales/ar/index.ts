// Arabic dictionary. `satisfies Messages` makes a missing namespace or key a `vue-tsc` error.
import type { Messages } from '../en';
import common from './common';
import layout from './layout';
import welcome from './welcome';

const ar = {
  common,
  layout,
  welcome,
} satisfies Messages;

export default ar;
