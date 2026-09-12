// Every protected page's own script imports requireSession() and awaits it
// before doing anything else. It verifies the session against the server
// (not just localStorage presence), enforces the page's expected role, and
// returns the authoritative, fresh profile — no cross-file handoff via
// window globals, no dynamic import gymnastics.
//
//   import { requireSession } from '/src/lib/auth-guard.js';
//   const user = await requireSession('earner');
//   // only reachable for a verified, correctly-roled, non-suspended user

import { apiRequest, getSession, clearSession } from './api.js';

function renderBlocked(title, message, actionHref, actionLabel) {
  document.body.innerHTML = `
    <main class="auth-form-wrap" style="min-height:100vh">
      <section class="card panel" style="width:min(480px,100%);text-align:center">
        <div class="brand" style="justify-content:center;margin-bottom:24px">
          <span class="brand-mark">Y</span> YOLOTASK
        </div>
        <div class="section-kicker">Restricted area</div>
        <h1 style="margin-top:10px">${title}</h1>
        <p class="muted">${message}</p>
        <a class="btn btn-primary" href="${actionHref}">${actionLabel}</a>
      </section>
    </main>`;
}

export async function requireSession(expectedRole) {
  const session = getSession();
  if (!session?.access_token) {
    renderBlocked('Sign in required', 'Log in to reach your workspace.', '/auth/login.html', 'Log in');
    throw new Error('AUTH_GUARD_HALT');
  }

  let result;
  try {
    result = await apiRequest('auth?action=session');
  } catch {
    clearSession();
    renderBlocked('Session expired', 'Please sign in again to continue.', '/auth/login.html', 'Return to login');
    throw new Error('AUTH_GUARD_HALT');
  }

  if (expectedRole && result.user.role !== expectedRole) {
    renderBlocked(
      'Wrong workspace',
      `This area is for ${expectedRole}s. Head to your own dashboard instead.`,
      result.dashboard_path || '/auth/login.html',
      'Go to my dashboard'
    );
    throw new Error('AUTH_GUARD_HALT');
  }

  return result.user;
}
