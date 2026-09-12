import { createHmac, timingSafeEqual } from 'node:crypto';
import { adminClient, requireUser, rpc } from '../src/server/supabase.js';
import { ok, fail, body, action } from '../src/server/http.js';
import { positiveInt } from '../src/server/validation.js';
import { enforceRateLimit } from '../src/server/rate-limit.js';

async function rawBody(req) {
  let raw = '';
  for await (const chunk of req) raw += chunk;
  return raw;
}

function verifyPaystackSignature(rawText, signatureHeader) {
  // Paystack has no separate webhook secret — the X-Paystack-Signature header
  // is HMAC-SHA512 of the raw body, signed with the same secret key used for
  // API calls.
  if (!process.env.PAYSTACK_SECRET_KEY) throw Object.assign(new Error('Paystack is not configured.'), { status: 500 });
  const expected = createHmac('sha512', process.env.PAYSTACK_SECRET_KEY).update(rawText).digest('hex');
  const sigBuffer = Buffer.from(typeof signatureHeader === 'string' ? signatureHeader : '', 'utf8');
  const expBuffer = Buffer.from(expected, 'utf8');
  if (sigBuffer.length !== expBuffer.length || !timingSafeEqual(sigBuffer, expBuffer)) {
    throw Object.assign(new Error('Invalid webhook signature.'), { status: 401 });
  }
}

async function settleIfMatching(reference, amountKobo, paystackId) {
  const { data: intent } = await adminClient.from('payment_intents').select('*').eq('reference', reference).maybeSingle();
  if (!intent || intent.status === 'verified') return;
  if (Number(amountKobo) !== Number(intent.amount_kobo)) throw new Error('Webhook amount does not match the payment intent.');
  await rpc('settle_verified_payment', { p_reference: reference, p_paystack_id: String(paystackId) });
}

export default async function handler(req, res) {
  try {
    const act = action(req);

    // Paystack calls this directly — authenticity comes from the HMAC
    // signature, not a bearer token, so this runs before anything else.
    if (act === 'webhook' && req.method === 'POST') {
      const raw = await rawBody(req);
      verifyPaystackSignature(raw, req.headers['x-paystack-signature']);
      const event = JSON.parse(raw);
      if (event.event === 'charge.success' && event.data?.reference) {
        await settleIfMatching(event.data.reference, event.data.amount, event.data.id);
      }
      return ok(res, { received: true });
    }

    if (act === 'initialize' && req.method === 'POST') {
      const { profile } = await requireUser(req, ['earner', 'advertiser']);
      await enforceRateLimit(req, 'payments:initialize', profile.id);
      const input = await body(req);
      let amount;
      if (profile.role === 'earner') {
        const { data: feeRow } = await adminClient.from('platform_settings').select('value').eq('key', 'earner_activation_fee').maybeSingle();
        amount = Number(feeRow?.value ?? 1000);
      } else {
        amount = positiveInt(input.amount, 'Amount');
      }
      const reference = `YOTO-${profile.id.slice(0, 8)}-${Date.now()}`;
      const returnPath = profile.role === 'earner' ? '/earner/index.html' : '/advertiser/wallet.html';
      const response = await fetch('https://api.paystack.co/transaction/initialize', {
        method: 'POST',
        headers: { Authorization: `Bearer ${process.env.PAYSTACK_SECRET_KEY}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          email: profile.email, amount: amount * 100, reference,
          callback_url: `${process.env.APP_URL}${returnPath}?payment=verify&reference=${reference}`,
          metadata: { profile_id: profile.id, purpose: profile.role === 'earner' ? 'activation' : 'wallet_funding', amount_naira: amount }
        })
      });
      const payload = await response.json();
      if (!response.ok || !payload.status) throw new Error(payload?.message || 'Payment initialization failed.');
      await adminClient.from('payment_intents').insert({
        profile_id: profile.id, reference,
        purpose: profile.role === 'earner' ? 'activation' : 'wallet_funding',
        amount_kobo: amount * 100, status: 'initialized'
      });
      return ok(res, { authorization_url: payload.data.authorization_url, reference });
    }

    if (act === 'verify' && req.method === 'POST') {
      const { profile } = await requireUser(req, ['earner', 'advertiser']);
      await enforceRateLimit(req, 'payments:verify', profile.id);
      const input = await body(req);
      if (typeof input.reference !== 'string') throw new Error('Payment reference is required.');
      const response = await fetch(`https://api.paystack.co/transaction/verify/${encodeURIComponent(input.reference)}`, {
        headers: { Authorization: `Bearer ${process.env.PAYSTACK_SECRET_KEY}` }
      });
      const payload = await response.json();
      if (!response.ok || payload.data?.status !== 'success') throw new Error(payload?.message || 'Payment could not be verified.');
      const { data: intent } = await adminClient.from('payment_intents').select('*').eq('reference', input.reference).eq('profile_id', profile.id).single();
      if (!intent || intent.status === 'verified') return ok(res, { verified: true });
      if (Number(payload.data.amount) !== Number(intent.amount_kobo)) throw new Error('Verified amount does not match the payment intent.');
      const result = await rpc('settle_verified_payment', { p_reference: input.reference, p_paystack_id: String(payload.data.id) });
      return ok(res, { verified: true, result });
    }

    return fail(res, 405, 'Payment action not supported.');
  } catch (error) {
    return fail(res, error.status || 400, error.message || 'Payment request failed.');
  }
}
