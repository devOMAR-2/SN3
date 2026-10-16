// English dictionary: one file per namespace. To add a namespace, create `<name>.ts` here and in `../ar/`,
// then add one line to each index. The Arabic index must satisfy `Messages`, so a missing key fails typecheck.
import common from './common';
import layout from './layout';
import welcome from './welcome';

const en = {
  common,
  layout,
  welcome,
};

export type Messages = typeof en;

export default en;
