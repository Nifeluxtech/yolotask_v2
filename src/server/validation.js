export function requiredString(value, label, maxLength = 500) {
  if (typeof value !== 'string' || !value.trim()) throw new Error(`${label} is required.`);
  const trimmed = value.trim();
  if (trimmed.length > maxLength) throw new Error(`${label} must be ${maxLength} characters or fewer.`);
  return trimmed;
}
export function email(value) {
  const trimmed = requiredString(value, 'Email', 254);
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) throw new Error('Enter a valid email address.');
  return trimmed.toLowerCase();
}
export function positiveInt(value, label) {
  const n = Number(value);
  if (!Number.isFinite(n) || n <= 0) throw new Error(`${label} must be a positive number.`);
  return Math.floor(n);
}
export function positiveNumber(value, label) {
  const n = Number(value);
  if (!Number.isFinite(n) || n <= 0) throw new Error(`${label} must be a positive number.`);
  return n;
}
export function oneOf(value, label, allowed) {
  if (!allowed.includes(value)) throw new Error(`${label} must be one of: ${allowed.join(', ')}.`);
  return value;
}
export function uuid(value, label) {
  const trimmed = requiredString(value, label, 80);
  if (!/^[0-9a-f-]{32,36}$/i.test(trimmed)) throw new Error(`${label} is not a valid identifier.`);
  return trimmed;
}
export function interestArray(value, label = 'Interests') {
  if (!Array.isArray(value)) return [];
  return value.filter(v => typeof v === 'string' && v.trim()).slice(0, 40);
}
