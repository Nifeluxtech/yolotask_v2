import { apiRequest } from '../lib/api.js';
import { esc, cap } from '../lib/format.js';

function row(a) {
  return `<article class="list-item">
    <span><strong>${esc(a.title)}</strong><span class="muted" style="display:block;font-size:.85rem">${esc(a.message)}</span></span>
    <span class="badge${a.priority === 'high' ? ' live' : ''}">${esc(cap(a.audience))}</span>
  </article>`;
}

export async function initAnnouncementsPage() {
  const list = document.querySelector('#announcement-list');
  try {
    const result = await apiRequest('community?action=announcements');
    const announcements = result.announcements || [];
    list.innerHTML = announcements.length ? announcements.map(row).join('') : '<p class="muted">No announcements right now.</p>';
  } catch (error) {
    list.innerHTML = `<p class="muted">Unable to load announcements: ${esc(error.message)}</p>`;
  }
}
