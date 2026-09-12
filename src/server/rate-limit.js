import { adminClient } from './supabase.js';

const WINDOW_SECONDS = Number(process.env.RATE_LIMIT_WINDOW_SECONDS || 60);
const MAX_REQUESTS = Number(process.env.RATE_LIMIT_MAX_REQUESTS || 60);

export function clientIp(req) {
  const forwarded = req.headers['x-forwarded-for'];
  if (typeof forwarded === 'string' && forwarded.length) return forwarded.split(',')[0].trim();
  return req.socket?.remoteAddress || 'unknown';
}

/** Fails open on any infra error — a limiter bug should never lock out real users. */
export async function enforceRateLimit(req, scope, identifier) {
  if (!adminClient) return;
  const key = identifier || clientIp(req);
  try {
    const { data, error } = await adminClient.rpc('check_rate_limit', {
      p_scope: scope, p_identifier: key, p_window_seconds: WINDOW_SECONDS, p_max_requests: MAX_REQUESTS
    });
    if (error) { console.error(`Rate limit check failed for ${scope}:`, error.message); return; }
    if (data === false) throw Object.assign(new Error('Too many requests. Please slow down and try again shortly.'), { status: 429 });
  } catch (error) {
    if (error.status === 429) throw error;
    console.error(`Rate limit check threw for ${scope}:`, error.message);
  }
}
