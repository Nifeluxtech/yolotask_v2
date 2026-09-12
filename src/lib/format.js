export const money = (value = 0) =>
  `\u20a6${Number(value || 0).toLocaleString('en-NG', { maximumFractionDigits: 2 })}`;

export const esc = (value = '') =>
  String(value).replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c]));

export const cap = (value = '') =>
  String(value).replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase());

export function formatDate(value, opts = { dateStyle: 'medium' }) {
  return new Date(value).toLocaleString('en-NG', opts);
}
