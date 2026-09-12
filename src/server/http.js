export function json(res, status, body) {
  res.status(status).setHeader('Content-Type', 'application/json');
  return res.end(JSON.stringify(body));
}
export function ok(res, data = {}) { return json(res, 200, { ok: true, ...data }); }
export function fail(res, status, error) { return json(res, status, { ok: false, error }); }

export async function body(req) {
  let raw = '';
  for await (const chunk of req) raw += chunk;
  if (!raw) return {};
  try { return JSON.parse(raw); } catch { throw new Error('Request body must be valid JSON.'); }
}

export function action(req) {
  return new URL(req.url, `http://${req.headers.host || 'localhost'}`).searchParams.get('action');
}
export function query(req, key) {
  return new URL(req.url, `http://${req.headers.host || 'localhost'}`).searchParams.get(key);
}
