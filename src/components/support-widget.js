// Shared by every role's Support page — the ticket list, create-ticket
// modal, and thread/reply modal are identical regardless of who's using
// them, so this is written once and initialized per page.

import { apiRequest } from '../lib/api.js';
import { openModal, closeModal } from '../lib/modal.js';
import { toast } from '../lib/toast.js';
import { esc, cap, formatDate } from '../lib/format.js';

const CATEGORIES = ['general', 'payment', 'campaign', 'task_appeal', 'account', 'report'];

function statusBadgeClass(status) {
  if (status === 'resolved' || status === 'closed') return 'badge';
  if (status === 'in_progress') return 'badge pending';
  return 'badge live';
}

function ticketRow(t) {
  return `<div class="list-item" data-open-ticket="${t.id}" style="cursor:pointer">
    <span><strong>${esc(t.subject)}</strong><span class="muted" style="display:block;font-size:.8rem">${esc(cap(t.category))} &middot; ${formatDate(t.updated_at, { dateStyle: 'medium' })}</span></span>
    <span class="${statusBadgeClass(t.status)}">${esc(cap(t.status))}</span>
  </div>`;
}

async function openThread(ticketId) {
  const modal = openModal('Ticket', '<p class="muted">Loading…</p>');
  try {
    const result = await apiRequest(`community?action=support-thread&ticket_id=${encodeURIComponent(ticketId)}`);
    const { ticket, messages } = result;
    modal.querySelector('.modal').innerHTML = `
      <div class="modal-head"><h3>${esc(ticket.subject)}</h3><button type="button" class="close" aria-label="Close">&times;</button></div>
      <div class="list" style="max-height:320px;overflow:auto;margin-bottom:16px">
        ${messages.map(m => `<div class="list-item" style="flex-direction:column;align-items:stretch"><span class="muted" style="font-size:.76rem">${formatDate(m.created_at, { dateStyle: 'medium', timeStyle: 'short' })}</span><span>${esc(m.message)}</span></div>`).join('')}
      </div>
      <form id="reply-form">
        <label>Reply<textarea name="message" rows="3" required></textarea></label>
        <div class="form-actions" style="margin-top:10px"><button class="btn btn-primary" type="submit">Send</button></div>
      </form>`;
    modal.querySelector('.close').addEventListener('click', () => closeModal());
    modal.querySelector('#reply-form').addEventListener('submit', async event => {
      event.preventDefault();
      const message = new FormData(event.currentTarget).get('message');
      try {
        await apiRequest('community?action=support-reply', { method: 'POST', body: { ticket_id: ticketId, message } });
        closeModal();
        toast('Reply sent.');
        openThread(ticketId);
      } catch (error) { toast(error.message, 'error'); }
    });
  } catch (error) {
    modal.querySelector('.modal').innerHTML = `<p class="muted">Unable to load this ticket: ${esc(error.message)}</p>`;
  }
}

export function initSupportPage() {
  const list = document.querySelector('#ticket-list');

  async function load() {
    list.innerHTML = '<p class="muted">Loading…</p>';
    try {
      const result = await apiRequest('community?action=support');
      const tickets = result.tickets || [];
      list.innerHTML = tickets.length ? tickets.map(ticketRow).join('') : '<p class="muted">No tickets yet.</p>';
    } catch (error) {
      list.innerHTML = `<p class="muted">Unable to load tickets: ${esc(error.message)}</p>`;
    }
  }

  list.addEventListener('click', event => {
    const row = event.target.closest('[data-open-ticket]');
    if (row) openThread(row.dataset.openTicket);
  });

  document.querySelector('#new-ticket-btn').addEventListener('click', () => {
    const modal = openModal('New support ticket', `
      <form id="new-ticket-form" class="form-grid">
        <label>Category<select name="category">${CATEGORIES.map(c => `<option value="${c}">${esc(cap(c))}</option>`).join('')}</select></label>
        <label>Subject<input name="subject" required></label>
        <label>Message<textarea name="message" rows="4" required></textarea></label>
        <div class="form-actions"><button class="btn btn-primary btn-block" type="submit">Submit ticket</button></div>
      </form>`);
    modal.querySelector('#new-ticket-form').addEventListener('submit', async event => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      try {
        await apiRequest('community?action=support', { method: 'POST', body: { category: data.get('category'), subject: data.get('subject'), message: data.get('message') } });
        closeModal();
        toast('Ticket submitted.');
        load();
      } catch (error) { toast(error.message, 'error'); }
    });
  });

  load();
}
