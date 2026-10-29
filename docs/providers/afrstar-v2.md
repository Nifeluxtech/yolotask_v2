<!-- Page 1 -->

# Afrstar Nigeria Payment API v2

### Integration reference for collections, payouts, callbacks, balance, and Nigeria extensions

#### Author: Manus AI Source: [https://ngn.afrstar.com/doc/v2api.htm](https://ngn.afrstar.com/doc/v2api.htm)

#### Prepared: 24 October 2026

<!-- Page 2 -->

# Contents

1 Introduction ................................................................................................ 1

1.1 Request signing ....................................................................................... 1
1.2 Common response envelope ......................................................................... 2
2 Collection payments ........................................................................................ 2

2.1 Create a collection order ............................................................................. 2
2.2 Collection webhook .................................................................................. 2
3 Payouts ...................................................................................................... 3

3.1 Create a payout order ................................................................................ 3
3.2 Payout webhook ...................................................................................... 4
4 Balance ...................................................................................................... 5

4.1 Balance query ......................................................................................... 5
5 Nigeria business extensions ................................................................................ 5

5.1 Nigeria virtual account ............................................................................... 5
5.2 Fixed-account search and manual settlement ....................................................... 6
5.2.1 Search incoming transactions .................................................................. 6
5.2.2 Manual settlement .............................................................................. 6
6 Nigeria payout bank codes ................................................................................. 7
7 Integration checklist ...................................................................................... 11
8 References ................................................................................................. 11

<!-- Page 3 -->

<u>Afrstar Nigeria Payment API v2 Manus AI</u>

# 1 Introduction

This reference documents the published Afrstar Nigeria payment API for creating collection and payout orders, receiving asynchronous status notifications, retrieving merchant balance, and using Nigeria- specific payment extensions. All requests use HTTPS POST with Content-Type: application/json. The gateway base URL is

```
[https://ngn.afrstar.com](https://ngn.afrstar.com). A response with code: 0 indicates success. Keep the platform order number
```
(platform_osn) returned by the API for reconciliation and support.

## 1.1 Request signing

Each endpoint defines its signing fields in an exact order. Concatenate the parameter values directly, without separators, spaces, or delimiters, and calculate an MD5 hash:

signature = md5(string_to_sign) string_to_sign = value_of_parameter_A + value_of_parameter_B + ...

The resulting signature is a 32-character lowercase hexadecimal string. Preserve amount values in their two-decimal form, such as 500.00.

import java.nio.charset.StandardCharsets; import java.security.MessageDigest;

|String parameterA =|"AAA";||
|---|---|---|
|String parameterB =|"BBB";||
|String parameterC = String stringToSign = parameterA + parameterB + parameterC;|"CCC";||
|MessageDigest md5 = byte[] digest = md5.digest(stringToSign.getBytes(StandardCharsets.UTF_8));|MessageDigest.getInstance("MD5");||
|StringBuilder signature =||new StringBuilder();|
|for (byte value : digest) signature.append(String.format("%02x", value)); } System.out.println(signature.toString());||{|

$parameterA = 'AAA'; $parameterB = 'BBB'; $parameterC = 'CCC'; $stringToSign = $parameterA. $parameterB. $parameterC; $signature = md5($stringToSign); echo $signature;

|stringToSign|:= parameterA|+ parameterB|+ parameterC|
|---|---|---|---|
|signature fmt.Println(signature)|:= fmt.Sprintf("%x",|md5.Sum([]byte(stringToSign)))||

<!-- Page 4 -->

## 1.2 Common response envelope

{ "code": 0, "message": "success", "data": {} }

# 2 Collection payments

## 2.1 Create a collection order

Creates a payable order and returns a hosted checkout URL.

```
Endpoint: POST [https://ngn.afrstar.com/gateway/payin/](https://ngn.afrstar.com/gateway/payin/)
Signature: md5(merchant_ak + order_sn + amount + merchant_sk)
```
{ "merchant_sn": "YOUR_MERCHANT_CODE", "order_sn": "ORDER-20260920-0001", "amount": "500.00", "name": "John Doe", "email": "john@example.com", "phone": "08000000000", "remark": "Order payment", "redirect_url": "[https://merchant.example/payment/result"](https://merchant.example/payment/result"), "sign": "MD5_SIGNATURE" }

Required fields are merchant_sn, order_sn, amount, customer name, email, and phone, remark,

```
redirect_url, and sign. The merchant order number may be up to 64 characters. Payment confirmation
```
must be based on the callback, not only on the customer return URL.

{ "code": 0, "message": "success", "data": { "platform_osn": "abcde", "pay_url": "[https://gateway.example/pay/abcde"](https://gateway.example/pay/abcde") } }

## 2.2 Collection webhook

Configure the Collection callback URL in the merchant portal. It is separate from redirect_url. The platform sends JSON using POST and Content-Type: application/json.

<!-- Page 5 -->

{ "merchant_sn": "YOUR_MERCHANT_CODE", "order_sn": "ORDER-20260920-0001", "platform_osn": "abcde", "status": 1, "message": "Paid Success", "amount": "500.00", "utr": "PAYMENT_REFERENCE", "session_id": "PAYMENT_REFERENCE", "amount_need": "500.00", "amount_paid": "500.00", "time_paid": "2026-09-20 10:30:00", "sign": "MD5_SIGNATURE" }

A collection is successful only when status is 1; do not credit an order for any other status. amount and

```
amount_paid are actual payment values and may differ from the original order amount. amount_need is the
```
amount instructed to the customer. utr and session_id are payment references when available. time_paid is UTC in YYYY-MM-DD HH:mm:ss format, or an empty string when unavailable. Nigeria channel data may also include to_account, from_name, and from_account.

```
Callback signature: md5(merchant_ak + platform_osn + status + original_order_amount +
merchant_sk)
```
Use the original two-decimal order amount stored against order_sn in the signing string. Do not substitute the callback’s amount, amount_paid, or amount_need. After validating the signature and matching the merchant and order numbers, persist the result and respond with HTTP 200 and the exact plain-text body success or SUCCESS. Do not return JSON, HTML, spaces, or a trailing newline. The callback timeout is five seconds. Notifications can be retried or manually re-pushed, so process them idempotently by merchant order number or platform order number.

# 3 Payouts

## 3.1 Create a payout order

Creates a bank, UPI, wallet, or USSD payout subject to the services enabled for the merchant. For Nigeria bank payouts, use type: "bank" and provide the bank code in ifsc.

```
Endpoint: POST [https://ngn.afrstar.com/gateway/payout/](https://ngn.afrstar.com/gateway/payout/)
Signature: md5(merchant_ak + order_sn + account + upi_handle + amount + merchant_sk)
```
{ "merchant_sn": "YOUR_MERCHANT_CODE", "order_sn": "PAYOUT-20260920-0001", "amount": "500.00", "type": "bank",

<!-- Page 6 -->

"name": "John Doe", "account": "0123456789", "ifsc": "80000030", "upi_handle": "", "remark": "Vendor payment", "sign": "MD5_SIGNATURE" }

Required fields are merchant_sn, order_sn, amount, type, name, remark, and sign. Bank payouts require

```
account and ifsc; UPI payouts require upi_handle. When a field is not applicable, send an empty string
```
if it remains part of the signature input. Supported types are bank, upi, wallet, and ussd.

{ "code": 0, "message": "success", "data": { "platform_osn": "axrjk", "status": "init" } }

## 3.2 Payout webhook

Configure the Payout callback URL in the merchant portal. Final results are sent as JSON using POST and

```
Content-Type: application/json.
```
{ "merchant_sn": "YOUR_MERCHANT_CODE", "order_sn": "PAYOUT-20260920-0001", "platform_osn": "axrjk", "status": 1, "utr": "TRANSFER_REFERENCE", "name": "John Doe", "message": "Transfered Success", "amount": "500.00", "time_transfered": "2026-09-20 10:35:00", "sign": "MD5_SIGNATURE" }

```
status 1 means payout succeeded and 9 means payout failed. Pending or under-review orders (5) do not
```
trigger this callback. amount is the original payout amount excluding merchant fees. time_transfered is UTC in YYYY-MM-DD HH:mm:ss format, or an empty string when unavailable; the field name is spelled exactly as shown.

```
Callback signature: md5(merchant_ak + platform_osn + status + amount + merchant_sk)
```

<!-- Page 7 -->

Preserve the two-decimal amount and match the amount and order numbers against the stored payout request before applying the result. Acknowledge verified callbacks with HTTP 200 and the exact plain-text body success or SUCCESS. Make processing idempotent so repeated notifications do not repeat balance changes.

# 4 Balance

## 4.1 Balance query

Retrieves the available balance for the authenticated merchant account.

```
Endpoint: POST [https://ngn.afrstar.com/gateway/balance/](https://ngn.afrstar.com/gateway/balance/)
Signature: md5(merchant_ak + merchant_sk)
```
{ "merchant_sn": "YOUR_MERCHANT_CODE", "sign": "MD5_SIGNATURE" }

{ "code": 0, "message": "success", "data": { "balance": "105.00"} }

# 5 Nigeria business extensions

These endpoints are available only when the relevant Nigeria services are enabled. Source IP validation and service authorization may apply.

## 5.1 Nigeria virtual account

After creating a collection order, obtain a dynamic virtual account. For hosted checkout, redirect to pay_url instead. For a custom cashier, wait at least one second after order creation before calling this endpoint.

```
Endpoint: POST [https://ngn.afrstar.com/gateway/ngnva/](https://ngn.afrstar.com/gateway/ngnva/)
Signature: md5(merchant_ak + merchant_sn + platform_osn + merchant_sk)
```
{ "merchant_sn": "YOUR_MERCHANT_CODE", "platform_osn": "abcde", "sign": "MD5_SIGNATURE" }

{ "code": 0,

<!-- Page 8 -->

"message": "success", "data": { "platform_osn": "abcde", "amount": "1000.00", "bank_name": "ASSETS MICROFINANCE BANK", "account_number": "8177261609", "account_name": "BUD DIGITAL", "expires_in": 600 } }

## 5.2 Fixed-account search and manual settlement

Use these endpoints only for authorized fixed receiving accounts. A transaction can be settled once, against a pending order belonging to the current merchant, with an exact amount and matching receiving account.

### 5.2.1 Search incoming transactions

```
Endpoint: POST [https://ngn.afrstar.com/gateway/ngsearch/](https://ngn.afrstar.com/gateway/ngsearch/)
Signature: md5(merchant_ak + search_key + search_val + merchant_sk)
```
{ "merchant_sn": "YOUR_MERCHANT_CODE", "search_key": "session_id", "search_val": "090405260712143212470902915269", "sign": "MD5_SIGNATURE" }

```
search_key accepts sender_name, sender_account, or session_id. search_val is the corresponding
```
value. The response returns up to 10 recent transactions. An unclaimed transaction contains a merchant- bound transaction_token valid for 10 minutes.

### 5.2.2 Manual settlement

```
Endpoint: POST [https://ngn.afrstar.com/gateway/ngbind/](https://ngn.afrstar.com/gateway/ngbind/)
Signature: md5(merchant_ak + platform_osn + transaction_token + merchant_sk)
```
{ "merchant_sn": "YOUR_MERCHANT_CODE", "platform_osn": "habcd", "transaction_token": "TOKEN_RETURNED_BY_NGSEARCH", "sign": "MD5_SIGNATURE" }

<!-- Page 9 -->

# 6 Nigeria payout bank codes

For a Nigeria bank payout, send the applicable code in the ifsc field. The directory below is reproduced from the source reference.

|Bank name|Bank code|
|---|---|
|Bank nameBank codeAccess Bank|80000001|
|Citibank Nigeria|80000002|
|Ecobank Nigeria|80000004|
|Enterprise Bank|80000005|
|Fidelity Bank|80000006|
|First Bank of Nigeria|80000007|
|First City Monument Bank|80000008|
|Guaranty Trust Bank|80000009|
|Heritage Bank|80000010|
|Jaiz Bank|80000011|
|Keystone Bank|80000012|
|MainStreet Bank|80000013|
|Parallex Bank|80000014|
|Providus Bank|80000015|
|Polaris Bank|80000016|
|Stanbic IBTC Bank|80000017|
|Standard Chartered Bank|80000018|
|Sterling Bank|80000019|
|Suntrust Bank|80000020|
|Union Bank of Nigeria|80000021|
|United Bank For Africa (UBA)|80000022|
|Unity Bank|80000023|
|Wema Bank|80000024|
|Zenith Bank|80000025|
|eTranzact PocketMoni|80000026|
|TAJ Bank|80000027|
|Kuda Bank|80000028|
|Moniepoint MFB|80000029|
|OPay|80000030|
|FINCA Nigeria|80000032|
|PalmPay|80000033|
|Rubies MFB|80000034|

|Titan Trust Bank|80000035|
|---|---|
|Coronation Merchant Bank|80000036|
|Rand Merchant Bank|80000037|
|PAGA|80000038|
|Jubilee Life Mortgage Bank|80000039|
|GLOBUS BANK|80000040|
|NIRSAL NATIONAL MICROFINANCE BANK|80000041|
|HOPE PSB|80000042|
|ACCION MICROFINANCE BANK|80000043|
|VFD microfinance bank|80000044|
|Lotus bank|80000045|
|FFS MICROFINANCE BANK|80000046|
|SMARTCASH|80000047|
|EYOWO|80000048|
|LAPO MICROFINANCE BANK|80000049|
|PremiumTrust Bank|80000050|
|TANADI MFB (CRUST)|80000051|
|Abbey Mortgage Bank|80000052|
|FairMoney|80000053|
|Good News Microfinance Bank|80000054|
|Dot Microfinance Bank|80000055|
|Ibom Fadama Microfinance Bank|80000056|
|AKU Microfinance Bank|80000057|
|PRISTINE DIVITIS MICROFINANCE BANK LTD|80000059|
|Moneymaster PSB|80000061|
|Uhuru Microfinance Bank|80000062|
|Pillar Microfinance Bank|80000063|
|Aspire Microfinance Bank|80000064|
|Haggai Mortgage Bank|80000065|
|Manny Microfinance Bank|80000067|
|FSDH|80000068|
|Titan-Paystack Microfinance Bank|80000069|
|Rigo Microfinance Bank|80000070|
|Peace Microfinance Bank|80000072|
|Infinity Trust Mortgage Bank|80000073|
|Kredi Money Microfinance Bank|80000074|

|CORESTEP MICROFINANCE BANK|80000075|
|---|---|
|MOMO PSB|80000076|
|CARBON|80000077|
|Zikora Microfinance Bank|80000078|
|CROSS RIVER MICROFINANCE BANK LTD|80000079|
|Chukwunenye MFB|80000080|
|SPECTRUM MICROFINANCE BANK|80000081|
|Rehoboth MFB|80000082|
|Infinity Microfinance Bank|80000083|
|AB MICROFINANCE BANK|80000085|
|BRIGHTWAY MICROFINANCE BANK|80000086|
|Iyamoye Microfinance Bank|80000087|
|Parkway Microfinance Bank|80000089|
|Baines Credit MFB|80000090|
|Yes Microfinance Bank|80000091|
|Nice Microfinance Bank LTD|80000093|
|Delta Trust Mortgage Bank|80000094|
|UNICAL MICROFINANCE BANK|80000096|
|9 PAYMENT SERVICE BANK|80000097|
|AG MORTGAGE BANK|80000098|
|Nomba|80000099|
|Branch International Financial Services|80000100|
|GoMoney|80000101|
|Baobab MFB|80000103|
|AKWA SAVINGS & LOANS|80000104|
|NowNow|80000106|
|Aniocha MFB|80000107|
|SUPPORT MICROFINANCE BANK|80000109|
|Firmus Microfiance Bank|80000113|
|Safe Haven Microfinance Bank|80000115|
|NPF MFB|80000116|
|NEPTUNE MICROFINANCE BANK|80000117|
|KAYVEE MFB|80000119|
|Regent MFB|80000120|
|Rigo Microfinance Bank Limited|80000121|
|Otech Microfinance Bank Ltd|80000122|

|PREEMINENT MFB|80000124|
|---|---|
|Mainland Microfinance Bank|80000125|
|FIDFUND MICROFINANCE BANK LIMITED|80000127|
|Aztec Microfinance Bank Limited|80000128|
|Taj_Pinspay|80000129|
|CAPSTONE MICROFINANCE BANK|80000135|
|ZENITH EAZY WALLET|80000137|
|AFEMAI MICROFINANCE BANK|80000139|
|PAYSTACK PAYMENTS LIMITED|80000141|
|FEDETH Microfinance Bank|80000143|
|Oche Microfinance Bank Ltd|80000144|
|UNN Staff Microfinance Bank Ltd|80000145|
|Consistent Trust Microfinance Bank|80000146|
|Uzondu MFBank|80000147|
|MONARCH MICROFINANCE BANK|80000148|
|Chibueze microfinance bank|80000150|
|Platinum Mortgage Bank Limited|80000152|
|Links Microfinance Bank|80000154|
|OSCOTECH MICROFINANCE BANK|80000158|
|ISUOFIA MICROFINANCE BANK|80000159|
|NWANNEGADI MICROFINANCE BANK|80000161|
|UNIUYO MICROFINANCE BANK|80000162|
|OTUO MICROFINANCE BANK|80000163|
|Balera Microfinance Bank Limited|80000165|
|Letshego MFB|80000166|
|OCTOPUS MICROFINANCE BANK|80000169|
|Citizen Trust Microfinance Bank Limited|80000171|
|Ampersand Microfinance Bank|80000172|
|ASO Savings and Loans|80000173|
|Covenant Microfinance Bank|80000174|
|Ekondo Microfinance Bank|80000175|
|Enrich Microfinance Bank|80000176|
|Hackman Microfinance Bank|80000177|
|Hasal Microfinance Bank|80000178|
|IBILE Microfinance Bank|80000179|
|Imperial Homes Mortgage Bank|80000180|

<!-- Page 13 -->

Mint-Finex MICROFINANCE BANK 80000181 Sparkle Microfinance Bank 80000183

# 7 Integration checklist

1. Store merchant credentials securely and never expose the secret key to client-side code.
2. Build each signature from the endpoint’s exact field order, with no separators.
3. Preserve two decimal places for all signed amount values.
4. Treat callbacks as the source of truth for payment and payout results.
5. Verify signatures and match merchant, order, platform order, and amount values before changing balances.
6. Persist callback results before acknowledging them.
7. Make callback handling idempotent because notifications may be retried or re-pushed.
8. Return only success or SUCCESS as the callback body, with HTTP 200, within five seconds.
9. Use the current bank-code directory and confirm that the merchant account has the required Nigeria service enabled.
# 8 References

[1] Source documentation: [https://ngn.afrstar.com/doc/v2api.htm](https://ngn.afrstar.com/doc/v2api.htm) This PDF is a formatted reproduction of the published reference accessed on 24 October 2026. Endpoint behavior, field names, status values, signature formulas, and bank codes remain subject to the provider’s current documentation and merchant-portal configuration.
