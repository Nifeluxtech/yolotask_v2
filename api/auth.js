import { adminClient, authClient, requireUser } from '../src/server/supabase.js';
import { ok, fail, body, action } from '../src/server/http.js';
import { email as validateEmail, requiredString, oneOf, interestArray } from '../src/server/validation.js';
import { enforceRateLimit } from '../src/server/rate-limit.js';

function dashboardPathFor(role) {
  return role === 'admin' ? '/admin/index.html' : role === 'advertiser' ? '/advertiser/index.html' : '/earner/index.html';
}

export default async function handler(req, res) {
  try {
    const act = action(req) || 'session';

    if (act === 'interests' && req.method === 'GET') {
      const { data, error } = await adminClient
        .from('interests')
        .select('name,is_active,interest_categories(name,sort_order,is_active)')
        .eq('is_active', true)
        .order('name', { ascending: true });
      if (error) throw error;
      const rows = (data || []).filter(r => r.interest_categories?.is_active !== false);
      rows.sort((a, b) => (a.interest_categories?.sort_order ?? 999) - (b.interest_categories?.sort_order ?? 999) || a.name.localeCompare(b.name));
      return ok(res, { interests: rows.map(r => r.name) });
    }

    if (act === 'register' && req.method === 'POST') {
      if (!adminClient || !authClient) throw Object.assign(new Error('Server configuration is missing.'), { status: 500 });
      await enforceRateLimit(req, 'auth:register');
      const input = await body(req);
      const role = oneOf(input.role || 'earner', 'Role', ['earner', 'advertiser']);
      const fullName = requiredString(input.full_name, 'Full name', 160);
      const emailValue = validateEmail(input.email);
      const password = requiredString(input.password, 'Password', 72);
      if (password.length < 8) throw new Error('Password must be at least 8 characters.');
      const gender = role === 'earner' ? oneOf(input.gender || 'prefer_not_to_say', 'Gender', ['male', 'female', 'prefer_not_to_say']) : 'prefer_not_to_say';
      const interests = role === 'earner' ? interestArray(input.interests) : [];
      if (role === 'earner' && interests.length < 3) throw new Error('Select at least 3 interests.');

      const { data: signUpData, error: signUpError } = await adminClient.auth.admin.createUser({
        email: emailValue, password, email_confirm: true,
        user_metadata: { full_name: fullName, role, gender }
      });
      if (signUpError) throw new Error(signUpError.message);
      const userId = signUpData.user.id;

      if (role === 'earner' && interests.length) {
        const { data: interestRows } = await adminClient.from('interests').select('id,name').in('name', interests);
        const rows = (interestRows || []).map(r => ({ profile_id: userId, interest_id: r.id }));
        if (rows.length) await adminClient.from('profile_interests').insert(rows);
      }

      const { data: signInData, error: signInError } = await authClient.auth.signInWithPassword({ email: emailValue, password });
      if (signInError) throw new Error('Account created. Please log in.');

      const { data: profile } = await adminClient.from('profiles').select('*').eq('id', userId).single();
      return ok(res, { user: { ...profile, interests }, session: signInData.session, dashboard_path: dashboardPathFor(role) });
    }

    if (act === 'login' && req.method === 'POST') {
      if (!authClient) throw Object.assign(new Error('Server configuration is missing.'), { status: 500 });
      await enforceRateLimit(req, 'auth:login');
      const input = await body(req);
      const emailValue = validateEmail(input.email);
      const password = requiredString(input.password, 'Password', 72);
      const { data, error } = await authClient.auth.signInWithPassword({ email: emailValue, password });
      if (error) throw new Error('Incorrect email or password.');
      const { data: profile } = await adminClient.from('profiles').select('*').eq('id', data.user.id).single();
      if (profile?.is_suspended) throw Object.assign(new Error('This account has been suspended.'), { status: 403 });
      return ok(res, { user: profile, session: data.session, dashboard_path: dashboardPathFor(profile.role) });
    }

    if (act === 'session' && req.method === 'GET') {
      const { authUser, profile } = await requireUser(req);
      let interests = [];
      if (profile.role === 'earner') {
        const { data: rows } = await adminClient.from('profile_interests').select('interests(name)').eq('profile_id', profile.id);
        interests = (rows || []).map(r => r.interests?.name).filter(Boolean);
      }
      return ok(res, { user: { ...profile, interests }, auth_user_id: authUser.id, dashboard_path: dashboardPathFor(profile.role) });
    }

    if (act === 'profile' && req.method === 'PATCH') {
      const { profile } = await requireUser(req);
      const input = await body(req);
      const updates = {};
      if (input.full_name !== undefined) updates.full_name = requiredString(input.full_name, 'Full name', 160);
      if (input.gender !== undefined) updates.gender = oneOf(input.gender, 'Gender', ['male', 'female', 'prefer_not_to_say']);
      if (Object.keys(updates).length) {
        await adminClient.from('profiles').update(updates).eq('id', profile.id);
      }
      if (profile.role === 'earner' && Array.isArray(input.interests)) {
        const names = interestArray(input.interests);
        if (names.length < 3) throw new Error('Select at least 3 interests.');
        const { data: interestRows } = await adminClient.from('interests').select('id').in('name', names);
        await adminClient.from('profile_interests').delete().eq('profile_id', profile.id);
        const rows = (interestRows || []).map(r => ({ profile_id: profile.id, interest_id: r.id }));
        if (rows.length) await adminClient.from('profile_interests').insert(rows);
      }
      const { data: updated } = await adminClient.from('profiles').select('*').eq('id', profile.id).single();
      return ok(res, { user: updated });
    }

    return fail(res, 405, 'Auth action not supported.');
  } catch (error) {
    return fail(res, error.status || 400, error.message || 'Request failed.');
  }
}
