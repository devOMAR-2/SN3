// Generic messages for the API's validation codes (API_SPEC.md §1.2). `{attribute}` is the field label.
// Field-specific wording lives in each feature namespace under `errors.<field>.<code>`.
export default {
  required: '{attribute} is required.',
  string: '{attribute} must be text.',
  email: 'Enter a valid email address.',
  min: '{attribute} is too short.',
  max: '{attribute} is too long.',
  in: 'Choose a valid {attribute}.',
  timezone: 'Choose a valid time zone.',
  boolean: '{attribute} must be yes or no.',
  unknown_field: '{attribute} cannot be changed here.',
  regex: '{attribute} has an invalid format.',
  exists: 'Choose a valid {attribute}.',
  uuid: 'Choose a valid {attribute}.',
  date_format: 'Enter a valid date.',
  after_or_equal: '{attribute} is too early.',
  invalid: '{attribute} is invalid.',
  network_error: 'Could not reach the server. Check your connection and try again.',
};
