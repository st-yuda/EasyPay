# EasyPay — FOSSBilling payment gateway for Burundi

EasyPay is a single-file FOSSBilling payment adapter that lets a Burundian
hosting company collect money through the channels customers actually use,
behind one clean checkout:

| Method            | How it settles | Customer does | Admin does |
| ----------------- | -------------- | ------------- | ---------- |
| **Leapa** (VISA / Mastercard) | Automatic | Pays in the embedded Leapa widget | Nothing |
| **EcoCash**       | Manual | Pays a merchant code, then confirms | Verifies on the portal, clicks **Process** |
| **Lumicash**      | Manual | Pays a merchant code, then confirms | Verifies on the portal, clicks **Process** |
| **Bancobu eNoti** | Manual | Pays a merchant code, then confirms | Verifies on the portal, clicks **Process** |
| **Other Banks**   | Manual | Wires to the bank account, then confirms | Verifies on the portal, clicks **Process** |

## Installation

Copy the adapter into your FOSSBilling installation:

```
library/Payment/Adapter/EasyPay/EasyPay.php
```

Then in the admin panel go to **System → Payments → Payment gateways**, install
**EasyPay**, and configure it (see below). The class is `Payment_Adapter_EasyPay`
and requires no Composer dependencies — it only uses FOSSBilling core services.

## Configuration

Every field is optional; a method only appears at checkout once it has been
enabled **and** given the details it needs (a Leapa key, a merchant code, or a
bank account number). This means a half-configured gateway never shows a broken
option to customers.

| Setting | Purpose |
| ------- | ------- |
| Leapa API key — test / live | Card key used in test / live mode. Either may be left empty. The gateway's global **Test mode** toggle decides which one is used. |
| Currency code | Optional override for the checkout currency. Defaults to the invoice currency. |
| Payment method order | Optional. Comma-separated keys controlling which method shows first → last, e.g. `lumicash, ecocash, bancobu, leapa`. Keys: `leapa`, `ecocash`, `lumicash`, `bancobu`, `otherbanks` (aliases like `card`/`bank` also work). Anything left out keeps the default order. |
| Enable EcoCash / Lumicash / Bancobu eNoti | Show the method at checkout. |
| Merchant code / name (per method) | Shown to the customer so they know who to pay. |
| Enable Other Banks | Show the bank-transfer option. |
| Bank accounts (one per line) | **Multiple banks.** One bank per line as `Bank name \| Account holder \| Account number \| SWIFT/BIC`, e.g. `BANCOBU \| HOGI SARL \| 20012345678 \| BCBUBIBI`. When filled, this replaces the single-bank fields below. |
| Bank name, account holder, account number, SWIFT / BIC (single bank) | Legacy single-bank fields, used only when *Bank accounts* is empty. |

### Currencies

EasyPay works with **any FOSSBilling currency** (BIF, USD, EUR, …). Amounts are
sent to the Leapa widget in the currency's smallest unit (e.g. `1000` = USD
10.00; zero-decimal currencies such as BIF are sent as whole integers). Because
Leapa's card API only supports **BIF and USD**, the card option is hidden
automatically for any other currency — the manual methods (EcoCash, Lumicash,
Bancobu eNoti, bank transfer) remain available and work with every currency.

### Ordering & multiple banks

The **Payment method order** setting lets you arrange the tabs however you like
(e.g. start with Lumicash and finish with Leapa). The **Bank accounts** setting
accepts as many banks as you need — one per line — and the checkout lists them
all so the customer can wire to whichever they prefer.

## How it works

### Automatic — Leapa (card, BIF/USD)

The checkout embeds the official [`<leapa-customer>`](https://www.leapa.co/docs/widgets/integration)
widget (an ES module, with its stylesheet) in `charge` mode with the invoice
amount. On a successful charge the widget fires an `onSuccess` event; EasyPay
posts the charge id to FOSSBilling's IPN endpoint (via `getCallbackUrl`), the
invoice is paid from a matching credit, the order is activated, and the customer
is returned to the invoice — no admin action required.

### Manual — mobile money & bank transfer (any currency)

The customer pays externally (mobile app or bank wire), enters their phone
number and an optional reference, and clicks **I have paid — confirm**. EasyPay
records a **pending** transaction whose *Note* contains the method, the
normalised phone number, and the reference, for example:

> `EcoCash payment | Phone: +257 79123456 | Reference: TXN999 | Awaiting verification — check the payment portal, then click Process to confirm.`

The admin checks the payment portal, opens the transaction in FOSSBilling, and
clicks **Process**. EasyPay then credits the client, pays the invoice, and
activates the order (status turns **Processed**, invoice **Paid**). The invoice
is never marked paid until the admin confirms.

## Validation

- **Burundian phone number** — accepted as `+257…`, `00257…`, `0257…`, `0…` or
  bare; normalised to the 8 national digits. Validated both in the browser and
  on the server.
- **Bank account number** — a soft check hints when a Burundian (BIF) account
  isn't 11 digits; international accounts (IBAN, etc.) are accepted as-is.

Transaction ids generated by EasyPay are prefixed with `EP-`.

## Security note

As described by the Leapa widget integration, card payments are settled when the
browser posts the charge id back to the IPN endpoint. For hardened production
use you should additionally verify each charge against Leapa's API (using the
secret key) before trusting it — `Payment_Adapter_EasyPay::verifyLeapaCharge()`
is the single method to implement for that.

The Leapa `amount` is sent in major currency units (e.g. `15000` for
`15000 BIF`). Burundian Franc has no subunit, so this is exact; verify the unit
against your Leapa account before charging non-BIF currencies.

## License

Apache-2.0.
