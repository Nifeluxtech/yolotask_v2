import { adminClient, requireUser, rpc } from '../src/server/supabase.js';
import { ok, fail, body, action } from '../src/server/http.js';
import { positiveNumber, requiredString, oneOf } from '../src/server/validation.js';
import { enforceRateLimit } from '../src/server/rate-limit.js';

export default async function handler(req, res) {
  try {
    const act = action(req) || 'summary';
    const { profile } = await requireUser(req, ['earner', 'advertiser', 'admin']);

    if (act === 'summary' && req.method === 'GET') {
      if (profile.role === 'admin') throw Object.assign(new Error('Admins do not have a personal wallet.'), { status: 403 });
      await rpc('ensure_wallet', { p_profile_id: profile.id });
      const { data: wallet, error: walletError } = await adminClient.from('wallets').select('*').eq('profile_id', profile.id).single();
      if (walletError) throw walletError;
      const { data: transactions, error: txError } = await adminClient
        .from('ledger_entries').select('*').eq('profile_id', profile.id).order('created_at', { ascending: false }).limit(50);
      if (txError) throw txError;
      return ok(res, { wallet, transactions: transactions || [] });
    }

    if (act === 'withdraw' && req.method === 'POST') {
      if (profile.role !== 'earner') throw Object.assign(new Error('Only earners can request withdrawals.'), { status: 403 });
      await enforceRateLimit(req, 'wallet:withdraw', profile.id);
      const input = await body(req);
      const result = await rpc('request_withdrawal', {
        p_profile_id: profile.id,
        p_amount: positiveNumber(input.amount, 'Amount'),
        p_bank: requiredString(input.bank, 'Bank', 120),
        p_account_number: requiredString(input.account_number, 'Account number', 20),
        p_account_name: requiredString(input.account_name, 'Account name', 160)
      });
      return ok(res, { withdrawal: result });
    }

    if (act === 'withdrawals' && req.method === 'GET') {
      if (profile.role !== 'admin') throw Object.assign(new Error('Admin authorization required.'), { status: 403 });
      const { data, error } = await adminClient.from('withdrawals').select('*, profiles(full_name,email)')
        .in('status', ['pending', 'processing']).order('created_at', { ascending: true });
      if (error) throw error;
      return ok(res, { withdrawals: data || [] });
    }

    if (act === 'review' && req.method === 'PATCH') {
      if (profile.role !== 'admin') throw Object.assign(new Error('Admin authorization required.'), { status: 403 });
      const input = await body(req);
      const result = await rpc('review_withdrawal', {
        p_admin_id: profile.id,
        p_withdrawal_id: requiredString(input.withdrawal_id, 'Withdrawal ID', 80),
        p_status: oneOf(input.status, 'Status', ['processing', 'paid', 'rejected']),
        p_reason: input.reason || null
      });
      return ok(res, { withdrawal: result });
    }

    return fail(res, 405, 'Wallet action not supported.');
  } catch (error) {
    return fail(res, error.status || 400, error.message || 'Wallet request failed.');
  }
}
