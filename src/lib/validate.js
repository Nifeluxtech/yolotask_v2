// Client-side checks are for instant feedback only — the server re-validates
// everything and is the actual source of truth.

export function required(value, label) {
  return (!value || !String(value).trim()) ? `${label} is required.` : null;
}
export function minLength(value, len, label) {
  return String(value || '').length < len ? `${label} must be at least ${len} characters.` : null;
}
export function isEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || ''));
}
export function showFieldError(input, message) {
  clearFieldError(input);
  if (!message) return;
  const note = document.createElement('span');
  note.className = 'field-error';
  note.textContent = message;
  input.insertAdjacentElement('afterend', note);
  input.setAttribute('aria-invalid', 'true');
}
export function clearFieldError(input) {
  input.removeAttribute('aria-invalid');
  const next = input.nextElementSibling;
  if (next?.classList.contains('field-error')) next.remove();
}
