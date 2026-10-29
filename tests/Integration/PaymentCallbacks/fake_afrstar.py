#!/usr/bin/env python3
"""Local fake of the AfrStar v2 gateway for integration tests.

Implements /gateway/payin/, /gateway/payout/ and /gateway/balance/ with the
real signing rules (md5 of ordered values + merchant_sk). Every request is
recorded; GET /_requests returns them as JSON. A payout to account
0000000000 is refused (code 1) to exercise the "definitive failure" path.

Usage: fake_afrstar.py PORT MERCHANT_SN MERCHANT_AK MERCHANT_SK
"""
import hashlib, json, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT, SN, AK, SK = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
REQUESTS = []
LOCK = threading.Lock()
COUNTER = {'n': 0}


def md5(*values):
    return hashlib.md5((''.join(str(v) for v in values) + SK).encode()).hexdigest()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def reply(self, obj, status=200):
        body = json.dumps(obj).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == '/_requests':
            with LOCK:
                return self.reply(REQUESTS)
        self.reply({'code': 404, 'message': 'not found'}, 404)

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get('Content-Length', '0') or 0))
        try:
            body = json.loads(raw)
        except Exception:
            return self.reply({'code': 1, 'message': 'bad json'})
        with LOCK:
            COUNTER['n'] += 1
            n = COUNTER['n']
        sign_ok = False
        resp = {'code': 1, 'message': 'unknown endpoint'}
        if body.get('merchant_sn') != SN:
            resp = {'code': 1, 'message': 'merchant error'}
        elif self.path == '/gateway/payin/':
            sign_ok = body.get('sign') == md5(AK, body.get('order_sn', ''), body.get('amount', ''))
            resp = {'code': 0, 'message': 'success', 'data': {'platform_osn': f'PI{n:05d}', 'pay_url': f'https://pay.fake.test/p/PI{n:05d}'}} if sign_ok else {'code': 1, 'message': 'sign error'}
        elif self.path == '/gateway/payout/':
            sign_ok = body.get('sign') == md5(AK, body.get('order_sn', ''), body.get('account', ''), body.get('upi_handle', ''), body.get('amount', ''))
            if not sign_ok:
                resp = {'code': 1, 'message': 'sign error'}
            elif body.get('account') == '0000000000':
                resp = {'code': 1, 'message': 'Invalid beneficiary account'}
            else:
                resp = {'code': 0, 'message': 'success', 'data': {'platform_osn': f'PO{n:05d}', 'status': 'init'}}
        elif self.path == '/gateway/balance/':
            sign_ok = body.get('sign') == md5(AK)
            resp = {'code': 0, 'message': 'success', 'data': {'balance': '105.00'}} if sign_ok else {'code': 1, 'message': 'sign error'}
        with LOCK:
            REQUESTS.append({'path': self.path, 'body': body, 'sign_ok': sign_ok, 'response': resp})
        self.reply(resp)


ThreadingHTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
