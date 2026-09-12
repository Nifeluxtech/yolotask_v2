import { adminClient, requireUser } from '../src/server/supabase.js';
import { ok, fail, body, action } from '../src/server/http.js';
import { requiredString } from '../src/server/validation.js';

export default async function handler(req, res) {
  try {
    const act = action(req) || 'dashboard';
    const { profile } = await requireUser(req, ['admin']);

    if (act === 'dashboard' && req.method === 'GET') {
      const { data, error } = await adminClient.from('admin_dashboard').select('*').single();
      if (error) throw error;
      return ok(res, { dashboard: data });
    }

    if (act === 'users' && req.method === 'GET') {
      const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
      const roleFilter = url.searchParams.get('role');
      const q = url.searchParams.get('q');
      let query = adminClient.from('profiles').select('id,full_name,email,role,is_activated,is_suspended,reputation_rank,xp,created_at').order('created_at', { ascending: false }).limit(100);
      if (roleFilter && ['earner', 'advertiser', 'admin'].includes(roleFilter)) query = query.eq('role', roleFilter);
      if (q) query = query.ilike('full_name', `%${q}%`);
      const { data, error } = await query;
      if (error) throw error;
      return ok(res, { users: data || [] });
    }

    if (act === 'user-status' && req.method === 'PATCH') {
      const input = await body(req);
      const targetId = requiredString(input.profile_id, 'Profile ID', 80);
      const suspended = Boolean(input.is_suspended);
      const { data, error } = await adminClient.from('profiles').update({ is_suspended: suspended }).eq('id', targetId).select('id,is_suspended').single();
      if (error) throw error;
      await adminClient.from('audit_logs').insert({ actor_id: profile.id, action: suspended ? 'user_suspended' : 'user_unsuspended', entity_type: 'profile', entity_id: targetId });
      return ok(res, { user: data });
    }

    if (act === 'settings' && req.method === 'GET') {
      const { data, error } = await adminClient.from('platform_settings').select('*').order('key', { ascending: true });
      if (error) throw error;
      return ok(res, { settings: data || [] });
    }

    if (act === 'settings' && req.method === 'PATCH') {
      const input = await body(req);
      const key = requiredString(input.key, 'Setting key', 120);
      const value = requiredString(String(input.value), 'Setting value', 1000);
      const { data, error } = await adminClient.from('platform_settings').upsert({ key, value, updated_by: profile.id }).select().single();
      if (error) throw error;
      return ok(res, { setting: data });
    }

    if (act === 'interests' && req.method === 'POST') {
      const input = await body(req);
      const { data, error } = await adminClient.from('interests').insert({ name: requiredString(input.name, 'Interest name', 120), category_id: input.category_id || null }).select().single();
      if (error) throw error;
      return ok(res, { interest: data });
    }

    if (act === 'announcement' && req.method === 'POST') {
      const input = await body(req);
      const { data, error } = await adminClient.from('announcements').insert({
        title: requiredString(input.title, 'Title', 160),
        message: requiredString(input.message, 'Message', 5000),
        audience: ['all', 'earners', 'advertisers'].includes(input.audience) ? input.audience : 'all',
        created_by: profile.id
      }).select().single();
      if (error) throw error;
      return ok(res, { announcement: data });
    }

    if (act === 'support' && req.method === 'GET') {
      const { data, error } = await adminClient.from('support_tickets').select('*, profiles(full_name,email)').order('updated_at', { ascending: false });
      if (error) throw error;
      return ok(res, { tickets: data || [] });
    }

    return fail(res, 405, 'Admin action not supported.');
  } catch (error) {
    return fail(res, error.status || 400, error.message || 'Admin request failed.');
  }
}
