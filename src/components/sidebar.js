import { esc } from '../lib/format.js';
import { clearSession } from '../lib/api.js';

const NAV_BY_ROLE = {
  earner: [
    { id: 'overview', label: 'Overview', href: '/earner/index.html', icon: '\u2302' },
    { id: 'tasks', label: 'Tasks', href: '/earner/tasks.html', icon: '\u2713' },
    { id: 'wallet', label: 'Wallet', href: '/earner/wallet.html', icon: '\u20a6' },
    { id: 'referrals', label: 'Referrals', href: '/earner/referrals.html', icon: '\u2197' },
    { id: 'leaderboard', label: 'Leaderboard', href: '/earner/leaderboard.html', icon: '\u2605' },
    { id: 'reputation', label: 'Reputation', href: '/earner/reputation.html', icon: '\u25c6' },
    { id: 'achievements', label: 'Achievements', href: '/earner/achievements.html', icon: '\u2691' },
    { id: 'notifications', label: 'Notifications', href: '/earner/notifications.html', icon: '\u25cf' },
    { id: 'announcements', label: 'Announcements', href: '/earner/announcements.html', icon: '\u2690' },
    { id: 'profile', label: 'Profile', href: '/earner/profile.html', icon: '\u25c9' },
    { id: 'support', label: 'Support', href: '/earner/support.html', icon: '?' }
  ],
  advertiser: [
    { id: 'overview', label: 'Overview', href: '/advertiser/index.html', icon: '\u2302' },
    { id: 'campaigns', label: 'Campaigns', href: '/advertiser/campaigns.html', icon: '\u25a4' },
    { id: 'reviews', label: 'Review queue', href: '/advertiser/reviews.html', icon: '\u2713' },
    { id: 'wallet', label: 'Wallet', href: '/advertiser/wallet.html', icon: '\u20a6' },
    { id: 'analytics', label: 'Analytics', href: '/advertiser/analytics.html', icon: '\u25c8' },
    { id: 'support', label: 'Support', href: '/advertiser/support.html', icon: '?' }
  ],
  admin: [
    { id: 'overview', label: 'Overview', href: '/admin/index.html', icon: '\u2302' },
    { id: 'campaigns', label: 'Campaign review', href: '/admin/campaigns.html', icon: '\u25a4' },
    { id: 'users', label: 'Users', href: '/admin/users.html', icon: '\u25ce' },
    { id: 'withdrawals', label: 'Withdrawals', href: '/admin/withdrawals.html', icon: '\u20a6' },
    { id: 'support', label: 'Support', href: '/admin/support.html', icon: '?' },
    { id: 'settings', label: 'Settings', href: '/admin/settings.html', icon: '\u2699' }
  ]
};

/**
 * @param {HTMLElement} rootEl
 * @param {{role: string, activeId: string, user: {full_name: string, email: string}}} opts
 */
export function renderSidebar(rootEl, { role, activeId, user }) {
  const items = NAV_BY_ROLE[role] || [];
  rootEl.className = 'sidebar';
  rootEl.innerHTML = `
    <a class="brand" href="${items[0]?.href || '/index.html'}"><span class="brand-mark">Y</span> YOLOTASK</a>
    <nav>
      ${items.map(item => `<a href="${item.href}"${item.id === activeId ? ' class="active"' : ''}><span class="nav-icon">${item.icon}</span>${esc(item.label)}</a>`).join('')}
    </nav>
    <div class="sidebar-foot">
      <div class="sidebar-user"><strong>${esc(user.full_name)}</strong><span class="muted">${esc(user.email)}</span></div>
      <button type="button" class="btn btn-secondary btn-small btn-block" id="logout-btn">Log out</button>
    </div>`;
  rootEl.querySelector('#logout-btn').addEventListener('click', () => {
    clearSession();
    window.location.href = '/auth/login.html';
  });
}

/** Wires the mobile hamburger button (present in each page's topbar) to toggle the sidebar. */
export function wireMobileMenu(shellEl) {
  const btn = shellEl.querySelector('.mobile-menu');
  if (!btn) return;
  btn.addEventListener('click', () => shellEl.classList.toggle('sidebar-open'));
}
