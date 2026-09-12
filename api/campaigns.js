import { adminClient, requireUser, rpc } from '../src/server/supabase.js';
import { ok, fail, body, action } from '../src/server/http.js';
import { requiredString, oneOf, positiveInt, positiveNumber, uuid } from '../src/server/validation.js';

export default async function handler(req, res) {
  try {
    const act = action(req) || 'list';
    const { profile } = await requireUser(req, ['advertiser', 'admin']);

    if (act === 'form-options' && req.method === 'GET') {
      const [{ data: interestRows, error: interestErr }, { data: taskTypeRows, error: typeErr }, { data: feeRow }] = await Promise.all([
        adminClient.from('interests').select('id,name').eq('is_active', true).order('name', { ascending: true }),
        adminClient.from('task_types').select('id,name').eq('is_active', true).order('name', { ascending: true }),
        adminClient.from('platform_settings').select('value').eq('key', 'platform_fee_rate').maybeSingle()
      ]);
      if (interestErr) throw interestErr;
      if (typeErr) throw typeErr;
      return ok(res, { interests: interestRows || [], task_types: taskTypeRows || [], platform_fee_rate: Number(feeRow?.value ?? 0.10) });
    }

    if (act === 'create' && req.method === 'POST') {
      if (profile.role !== 'advertiser') throw Object.assign(new Error('Only advertisers can create campaigns.'), { status: 403 });
      const input = await body(req);
      const audience = oneOf(input.audience_type, 'Audience', ['general', 'targeted']);
      const result = await rpc('create_campaign_with_reservation', {
        p_advertiser_id: profile.id,
        p_task_type_id: uuid(input.task_type_id, 'Task type'),
        p_title: requiredString(input.title, 'Campaign name', 160),
        p_description: requiredString(input.description, 'Brief', 5000),
        p_target_url: input.target_url || null,
        p_audience_type: audience,
        // No gender restriction is a valid, common choice for a targeted
        // (interest-only) campaign — 'prefer_not_to_say' is a profile's own
        // gender option, not a meaningful targeting choice, so it's not
        // offered here.
        p_gender_target: (audience === 'targeted' && input.gender_target) ? oneOf(input.gender_target, 'Gender target', ['male', 'female']) : null,
        p_price_per_worker: positiveNumber(input.price_per_worker, 'Price per worker'),
        p_worker_limit: positiveInt(input.worker_limit, 'Number of workers'),
        p_interest_ids: audience === 'targeted' ? (Array.isArray(input.interest_ids) ? input.interest_ids : []) : []
      });
      return ok(res, { campaign: result });
    }

    if (act === 'analytics' && req.method === 'GET') {
      if (profile.role !== 'advertiser') throw Object.assign(new Error('Advertiser authorization required.'), { status: 403 });
      const { data: myCampaigns, error: campErr } = await adminClient.from('campaigns').select('id,workers_completed,worker_limit,price_per_worker').eq('advertiser_id', profile.id);
      if (campErr) throw campErr;
      const campaignIds = (myCampaigns || []).map(c => c.id);
      const workersReached = (myCampaigns || []).reduce((sum, c) => sum + (c.workers_completed || 0), 0);
      const workerCapacity = (myCampaigns || []).reduce((sum, c) => sum + (c.worker_limit || 0), 0);

      let totalSubmissions = 0, approvedSubmissions = 0;
      if (campaignIds.length) {
        const [{ count: total }, { count: approved }] = await Promise.all([
          adminClient.from('task_submissions').select('id', { count: 'exact', head: true }).in('campaign_id', campaignIds),
          adminClient.from('task_submissions').select('id', { count: 'exact', head: true }).in('campaign_id', campaignIds).eq('status', 'approved')
        ]);
        totalSubmissions = total || 0;
        approvedSubmissions = approved || 0;
      }

      return ok(res, {
        campaign_count: campaignIds.length,
        workers_reached: workersReached,
        worker_capacity: workerCapacity,
        total_submissions: totalSubmissions,
        approved_submissions: approvedSubmissions,
        approval_rate: totalSubmissions ? approvedSubmissions / totalSubmissions : null
      });
    }

    if (act === 'list' && req.method === 'GET') {
      let query = adminClient.from('campaigns').select('*, task_types(name)').order('created_at', { ascending: false });
      if (profile.role === 'advertiser') query = query.eq('advertiser_id', profile.id);
      const { data, error } = await query;
      if (error) throw error;
      return ok(res, { campaigns: data || [] });
    }

    if (act === 'status' && req.method === 'PATCH') {
      const input = await body(req);
      const newStatus = oneOf(input.status, 'Status', ['submitted', 'approved', 'live', 'paused', 'completed', 'archived', 'rejected']);
      if (profile.role !== 'admin' && ['approved', 'live', 'rejected', 'archived'].includes(newStatus)) {
        throw Object.assign(new Error('Only an admin can approve, publish, reject, or archive a campaign.'), { status: 403 });
      }
      const result = await rpc('change_campaign_status', {
        p_campaign_id: requiredString(input.campaign_id, 'Campaign ID', 80),
        p_actor_id: profile.id,
        p_new_status: newStatus,
        p_reason: input.reason || null
      });
      return ok(res, { campaign_id: result });
    }

    return fail(res, 405, 'Campaign action not supported.');
  } catch (error) {
    return fail(res, error.status || 400, error.message || 'Campaign request failed.');
  }
}
