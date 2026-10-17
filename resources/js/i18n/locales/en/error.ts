// Error states (DESIGN_SYSTEM.md §7.1 `error.generic`, §9.3, §9.4).
export default {
  generic: 'Something went wrong. Try again.',
  load_failed: 'Could not load this',
  request_id: 'Support reference:',
  back_home: 'Go to home',
  sign_in: 'Sign in',
  page: {
    403: {
      title: 'You can’t open this page',
      body: 'Your role doesn’t allow this. Ask the team’s Owner or an Admin if you need access.',
    },
    404: {
      title: 'This page isn’t available',
      body: 'It may not exist, it may have been deleted, or you may not have access to it.',
    },
    419: {
      title: 'Your session expired',
      body: 'Reload the page and try again.',
    },
    429: {
      title: 'Too many requests',
      body: 'Wait a moment, then try again.',
    },
    500: {
      title: 'Something went wrong',
      body: 'Try again in a moment.',
    },
    503: {
      title: 'Temporarily unavailable',
      body: 'Try again in a few minutes.',
    },
  },
};
