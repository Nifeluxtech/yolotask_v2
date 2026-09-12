import { apiRequest, setSession } from '/src/lib/api.js';
import { toast } from '/src/lib/toast.js';
import { esc } from '/src/lib/format.js';

const form = document.querySelector('#register-form');
const roleInput = form.querySelector('[name=role]');
const roleButtons = document.querySelectorAll('.role-toggle button');
const earnerFields = document.querySelector('#earner-fields');
const interestGrid = document.querySelector('#interest-grid');

// Pre-fill a referral code from the URL, if this link came from a referrer.
// Attached to the account right after signup succeeds (see below) — not at
// signup time itself, since the reward isn't credited until activation.
const referralCode = new URLSearchParams(window.location.search).get('ref');

roleButtons.forEach(btn => {
  btn.addEventListener('click', () => {
    roleButtons.forEach(b => { b.classList.toggle('active', b === btn); b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
    roleInput.value = btn.dataset.role;
    earnerFields.style.display = btn.dataset.role === 'earner' ? 'block' : 'none';
  });
});

async function loadInterests() {
  interestGrid.innerHTML = '<p class="muted">Loading interests…</p>';
  try {
    const result = await apiRequest('auth?action=interests');
    const names = result.interests || [];
    interestGrid.innerHTML = names.length
      ? names.map(name => `<label class="interest"><input type="checkbox" name="interests" value="${esc(name)}"><span>${esc(name)}</span></label>`).join('')
      : '<p class="muted">No interests are configured yet. Contact support to continue.</p>';
  } catch {
    interestGrid.innerHTML = '<p class="muted">Unable to load interests. Refresh the page to try again.</p>';
  }
}
loadInterests();

form.addEventListener('submit', async event => {
  event.preventDefault();
  const submitBtn = form.querySelector('button[type=submit]');
  const data = new FormData(form);
  const role = data.get('role');
  const interests = data.getAll('interests');

  if (role === 'earner' && interests.length < 3) {
    toast('Choose at least 3 interests.', 'error');
    interestGrid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  submitBtn.disabled = true;
  submitBtn.textContent = 'Creating account…';
  try {
    const result = await apiRequest('auth?action=register', {
      method: 'POST',
      body: {
        role,
        full_name: data.get('full_name'),
        email: data.get('email'),
        password: data.get('password'),
        gender: data.get('gender'),
        interests
      }
    });
    setSession({ ...result.session, profile: result.user });

    if (role === 'earner' && referralCode) {
      try { await apiRequest('community?action=attach-referral', { method: 'POST', body: { code: referralCode } }); }
      catch (err) { console.warn('Referral attribution failed:', err.message); }
    }

    window.location.href = result.dashboard_path;
  } catch (error) {
    toast(error.message, 'error');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Create account';
  }
});
