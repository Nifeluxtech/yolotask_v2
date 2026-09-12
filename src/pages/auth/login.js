import { apiRequest, setSession, getSession } from '/src/lib/api.js';
import { toast } from '/src/lib/toast.js';

// Already signed in? Skip straight to the dashboard rather than showing the
// form again.
if (getSession()?.access_token) {
  apiRequest('auth?action=session')
    .then(result => { window.location.href = result.dashboard_path; })
    .catch(() => { /* stale/expired session — let them log in fresh */ });
}

const form = document.querySelector('#login-form');
form.addEventListener('submit', async event => {
  event.preventDefault();
  const submitBtn = form.querySelector('button[type=submit]');
  const data = new FormData(form);
  submitBtn.disabled = true;
  submitBtn.textContent = 'Logging in…';
  try {
    const result = await apiRequest('auth?action=login', {
      method: 'POST',
      body: { email: data.get('email'), password: data.get('password') }
    });
    setSession({ ...result.session, profile: result.user });
    window.location.href = result.dashboard_path;
  } catch (error) {
    toast(error.message, 'error');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Log in';
  }
});
