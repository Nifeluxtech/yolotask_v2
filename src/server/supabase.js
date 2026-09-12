import { createClient } from '@supabase/supabase-js';

const url = process.env.SUPABASE_URL;
const serviceKey = process.env.SUPABASE_SERVICE_ROLE_KEY;
const anonKey = process.env.SUPABASE_ANON_KEY;

// Service-role client: bypasses RLS entirely. Every API file uses this one
// for all reads/writes — RLS policies in the schema are a defense-in-depth
// backstop, not the primary access control. Authorization happens here, in
// requireUser(), and inside the SECURITY DEFINER functions themselves.
export const adminClient = (url && serviceKey) ? createClient(url, serviceKey, { auth: { persistSession: false } }) : null;

// Used only to verify a bearer token belongs to a real, current Supabase
// Auth session — never used for data access.
export const authClient = (url && anonKey) ? createClient(url, anonKey, { auth: { persistSession: false } }) : null;

export function bearer(req) {
  const value = req.headers.authorization || '';
  return value.startsWith('Bearer ') ? value.slice(7) : '';
}

/**
 * Verifies the request's bearer token and loads the corresponding profile.
 * Throws a 401 if there's no valid session, or a 403 if allowedRoles is
 * given and the profile's role isn't in it.
 */
export async function requireUser(req, allowedRoles = null) {
  if (!adminClient || !authClient) {
    throw Object.assign(new Error('Supabase server configuration is missing.'), { status: 500 });
  }
  const token = bearer(req);
  if (!token) throw Object.assign(new Error('Sign in required.'), { status: 401 });

  const { data: authData, error: authError } = await authClient.auth.getUser(token);
  if (authError || !authData?.user) throw Object.assign(new Error('Your session has expired. Please sign in again.'), { status: 401 });

  const { data: profile, error: profileError } = await adminClient
    .from('profiles').select('*').eq('id', authData.user.id).single();
  if (profileError || !profile) throw Object.assign(new Error('Profile not found.'), { status: 404 });
  if (profile.is_suspended) throw Object.assign(new Error('This account has been suspended.'), { status: 403 });
  if (allowedRoles && !allowedRoles.includes(profile.role)) {
    throw Object.assign(new Error('You are not authorized for this action.'), { status: 403 });
  }
  return { authUser: authData.user, profile };
}

/** Thin wrapper around a Postgres RPC call that turns a Postgres error into a normal thrown JS Error. */
export async function rpc(name, args) {
  const { data, error } = await adminClient.rpc(name, args);
  if (error) throw Object.assign(new Error(error.message), { status: 400 });
  return data;
}
