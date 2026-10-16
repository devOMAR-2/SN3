// Sign in/up, verification and password reset (FND-004).
export default {
  fields: {
    name: 'Name',
    email: 'Email',
    password: 'Password',
    remember: 'Keep me signed in on this device',
  },
  errors: {
    email: {
      credentials: 'These details do not match an active account.',
    },
    password: {
      min: 'Use at least 12 characters.',
      max: 'Use at most 128 characters.',
      password: 'This password has appeared in a data breach. Choose a different one.',
    },
    name: {
      max: 'Use at most 100 characters.',
    },
    token: {
      invalid_token: 'This reset link is invalid or has expired. Request a new one.',
    },
  },
  hints: {
    password: 'At least 12 characters. A short phrase is easier to remember.',
  },
  login: {
    title: 'Sign in',
    submit: 'Sign in',
    forgot: 'Forgot password?',
    no_account: 'New to SN3?',
    register_link: 'Create an account',
  },
  register: {
    title: 'Create your account',
    submit: 'Create account',
    have_account: 'Already have an account?',
    login_link: 'Sign in',
    sent_title: 'Check your email',
    sent_body:
      'If this address can be used, we have sent a link to {email}. Open it to verify your email, then sign in.',
  },
  forgot: {
    title: 'Reset your password',
    intro: 'Enter your account email and we will send you a link to choose a new password.',
    submit: 'Send reset link',
    sent: 'If an account exists for {email}, a reset link is on its way. The link expires in 60 minutes.',
    back: 'Back to sign in',
  },
  reset: {
    title: 'Choose a new password',
    submit: 'Save new password',
    done: 'Your password has been changed. Sign in with your new password.',
  },
  verify: {
    title: 'Verify your email',
    body: 'We sent a verification link to {email}. Verify your email to create or join a team.',
    resend: 'Send a new link',
    sent: 'A new verification link has been sent.',
    already: 'Your email is already verified.',
  },
  status: {
    email_verified: 'Your email has been verified.',
    verification_link_invalid: 'This verification link is invalid or has expired. Sign in to request a new one.',
  },
  logout: 'Sign out',
};
