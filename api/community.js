import { adminClient, requireUser, rpc } from '../src/server/supabase.js';
import { ok, fail, body, action } from '../src/server/http.js';
import { requiredString, oneOf } from '../src/server/validation.js';

export default async function handler(req, res) {
  try {
    const act = action(req);
    const { profile } = await requireUser(req, ['earner', 'advertiser', 'admin']);

    if (act === 'referrals' && req.method === 'GET') {
      if (profile.role !== 'earner') throw Object.assign(new Error('Referrals are available to earners only.'), { status: 403 });
      const { data, error } = await adminClient.from('referrals').select('*').eq('referrer_id', profile.id).order('created_at', { ascending: false });
      if (error) throw error;
      return ok(res, { referrals: data || [], link: `${process.env.APP_URL}/auth/register.html?ref=${profile.referral_code}` });
    }

    if (act === 'attach-referral' && req.method === 'POST') {
      const input = await body(req);
      await rpc('attach_referral', { p_referred_id: profile.id, p_referral_code: requiredString(input.code, 'Referral code', 20) });
      return ok(res, {});
    }

    if (act === 'notifications' && req.method === 'GET') {
      const { data, error } = await adminClient.from('notifications').select('*').eq('profile_id', profile.id).order('created_at', { ascending: false }).limit(50);
      if (error) throw error;
      return ok(res, { notifications: data || [] });
    }

    if (act === 'notifications-read' && req.method === 'PATCH') {
      const input = await body(req);
      const { data, error } = await adminClient.from('notifications').update({ read_at: new Date().toISOString() })
        .eq('id', requiredString(input.id, 'Notification ID', 80)).eq('profile_id', profile.id).select().single();
      if (error) throw error;
      return ok(res, { notification: data });
    }

    if (act === 'announcements' && req.method === 'GET') {
      const audienceKey = profile.role === 'earner' ? 'earners' : profile.role === 'advertiser' ? 'advertisers' : 'all';
      const nowIso = new Date().toISOString();
      const { data, error } = await adminClient.from('announcements').select('*').eq('status', 'published')
        .or(`audience.eq.all,audience.eq.${audienceKey}`).lte('start_date', nowIso).or(`expiry_date.is.null,expiry_date.gte.${nowIso}`)
        .order('created_at', { ascending: false }).limit(20);
      if (error) throw error;
      return ok(res, { announcements: data || [] });
    }

    if (act === 'leaderboard' && req.method === 'GET') {
      const { data, error } = await adminClient.from('earner_leaderboard').select('*').limit(50);
      if (error) throw error;
      return ok(res, { leaderboard: data || [] });
    }

    if (act === 'support' && req.method === 'GET') {
      const { data, error } = await adminClient.from('support_tickets').select('*').eq('profile_id', profile.id).order('created_at', { ascending: false });
      if (error) throw error;
      return ok(res, { tickets: data || [] });
    }

    if (act === 'support' && req.method === 'POST') {
      const input = await body(req);
      const { data: ticket, error } = await adminClient.from('support_tickets').insert({
        profile_id: profile.id,
        category: oneOf(input.category || 'general', 'Category', ['general', 'payment', 'campaign', 'task_appeal', 'account', 'report']),
        subject: requiredString(input.subject, 'Subject', 200)
      }).select().single();
      if (error) throw error;
      await adminClient.from('support_messages').insert({ ticket_id: ticket.id, sender_id: profile.id, message: requiredString(input.message, 'Message', 5000) });
      return ok(res, { ticket });
    }

    if (act === 'support-thread' && req.method === 'GET') {
      const ticketId = requiredString(req.headers['x-ticket-id'] || new URL(req.url, 'http://x').searchParams.get('ticket_id'), 'Ticket ID', 80);
      const { data: ticket } = await adminClient.from('support_tickets').select('*').eq('id', ticketId).maybeSingle();
      if (!ticket || (ticket.profile_id !== profile.id && profile.role !== 'admin')) throw Object.assign(new Error('Ticket not found.'), { status: 404 });
      const { data, error } = await adminClient.from('support_messages').select('*').eq('ticket_id', ticketId).order('created_at', { ascending: true });
      if (error) throw error;
      return ok(res, { ticket, messages: data || [] });
    }

    if (act === 'support-reply' && req.method === 'POST') {
      const input = await body(req);
      const ticketId = requiredString(input.ticket_id, 'Ticket ID', 80);
      const { data: ticket } = await adminClient.from('support_tickets').select('*').eq('id', ticketId).maybeSingle();
      if (!ticket || (ticket.profile_id !== profile.id && profile.role !== 'admin')) throw Object.assign(new Error('Ticket not found.'), { status: 404 });
      await adminClient.from('support_messages').insert({ ticket_id: ticketId, sender_id: profile.id, message: requiredString(input.message, 'Message', 5000) });
      await adminClient.from('support_tickets').update({ updated_at: new Date().toISOString(), status: profile.role === 'admin' ? 'in_progress' : ticket.status }).eq('id', ticketId);
      return ok(res, {});
    }

    return fail(res, 405, 'Community action not supported.');
  } catch (error) {
    return fail(res, error.status || 400, error.message || 'Request failed.');
  }
}
