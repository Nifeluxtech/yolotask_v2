import { adminClient, requireUser, rpc } from '../src/server/supabase.js';
import { ok, fail, body, action } from '../src/server/http.js';
import { requiredString } from '../src/server/validation.js';

export default async function handler(req, res) {
  try {
    const act = action(req) || 'list';

    // Earner-facing: browse, claim, submit proof.
    if (act === 'list' && req.method === 'GET') {
      const { profile } = await requireUser(req, ['earner']);
      if (!profile.is_activated) throw Object.assign(new Error('Activate your account to access tasks.'), { status: 403 });
      const { data, error } = await adminClient.from('eligible_campaigns').select('*').order('priority_tier', { ascending: false });
      if (error) throw error;
      return ok(res, { tasks: data || [] });
    }

    if (act === 'claim' && req.method === 'POST') {
      const { profile } = await requireUser(req, ['earner']);
      const input = await body(req);
      const result = await rpc('claim_task', { p_profile_id: profile.id, p_campaign_id: requiredString(input.campaign_id, 'Campaign ID', 80) });
      return ok(res, { claim: result });
    }

    if (act === 'submit' && req.method === 'POST') {
      const { profile } = await requireUser(req, ['earner']);
      const input = await body(req);
      const result = await rpc('submit_task_proof', {
        p_profile_id: profile.id,
        p_campaign_id: requiredString(input.campaign_id, 'Campaign ID', 80),
        p_proof: requiredString(input.proof, 'Proof', 5000)
      });
      return ok(res, { submission: result });
    }

    // Advertiser-facing (or admin override): the review queue this whole
    // rebuild was built around — nothing pays out until this happens.
    if (act === 'pending-reviews' && req.method === 'GET') {
      const { profile } = await requireUser(req, ['advertiser', 'admin']);
      let query = adminClient
        .from('task_submissions')
        .select('*, campaigns!inner(id,title,advertiser_id,price_per_worker), profiles!task_submissions_earner_id_fkey(full_name)')
        .eq('status', 'submitted')
        .order('submitted_at', { ascending: true });
      if (profile.role === 'advertiser') query = query.eq('campaigns.advertiser_id', profile.id);
      const { data, error } = await query;
      if (error) throw error;
      return ok(res, { submissions: data || [] });
    }

    if (act === 'review' && req.method === 'PATCH') {
      const { profile } = await requireUser(req, ['advertiser', 'admin']);
      const input = await body(req);
      const result = await rpc('review_task_submission', {
        p_submission_id: requiredString(input.submission_id, 'Submission ID', 80),
        p_reviewer_id: profile.id,
        p_decision: input.decision === 'approved' ? 'approved' : 'rejected',
        p_reason: input.reason || null
      });
      return ok(res, { result });
    }

    // Gamification: achievements progress and daily check-in.
    if (act === 'achievements' && req.method === 'GET') {
      const { profile } = await requireUser(req, ['earner']);
      const [{ data: defs, error: defErr }, { data: earned, error: earnErr }, { data: streakRow }] = await Promise.all([
        adminClient.from('achievements').select('*').eq('is_active', true).order('xp_reward', { ascending: true }),
        adminClient.from('user_achievements').select('achievement_id').eq('profile_id', profile.id),
        adminClient.from('daily_checkins').select('streak').eq('profile_id', profile.id).order('checkin_date', { ascending: false }).limit(1).maybeSingle()
      ]);
      if (defErr) throw defErr;
      if (earnErr) throw earnErr;
      const { count: approvedCount, error: countErr } = await adminClient
        .from('task_submissions').select('id', { count: 'exact', head: true }).eq('earner_id', profile.id).eq('status', 'approved');
      if (countErr) throw countErr;
      const earnedIds = new Set((earned || []).map(e => e.achievement_id));
      const streak = streakRow?.streak || 0;
      const progress = (defs || []).map(a => {
        const criteria = a.criteria || {};
        let current = 0, target = 1;
        if ('approved_tasks' in criteria) { current = approvedCount || 0; target = criteria.approved_tasks; }
        else if ('checkin_streak' in criteria) { current = streak; target = criteria.checkin_streak; }
        return { id: a.id, key: a.key, name: a.name, description: a.description, xp_reward: a.xp_reward, current: Math.min(current, target), target, earned: earnedIds.has(a.id) };
      });
      return ok(res, { achievements: progress });
    }

    if (act === 'checkin' && req.method === 'POST') {
      const { profile } = await requireUser(req, ['earner']);
      if (!profile.is_activated) throw Object.assign(new Error('Activate your account to check in.'), { status: 403 });
      const result = await rpc('record_daily_checkin', { p_profile_id: profile.id });
      return ok(res, { checkin: result });
    }

    return fail(res, 405, 'Task action not supported.');
  } catch (error) {
    return fail(res, error.status || 400, error.message || 'Task request failed.');
  }
}
