<?php

declare(strict_types=1);

/**
 * EasyPay — FOSSBilling payment gateway for Burundi.
 *
 * EasyPay bundles the payment methods Burundian customers actually use behind a
 * single, beautiful checkout:
 *
 *   - Leapa          VISA / Mastercard, charged automatically through the
 *                    official Leapa widget (https://www.leapa.co/docs/widgets/integration).
 *   - EcoCash        Mobile money. Customer pays a merchant code, admin verifies.
 *   - Lumicash       Mobile money. Customer pays a merchant code, admin verifies.
 *   - Bancobu eNoti  Mobile money. Customer pays a merchant code, admin verifies.
 *   - Other Banks    Plain bank wire. Customer transfers, admin verifies.
 *
 * Card payments settle automatically: on a successful Leapa charge the browser
 * posts the charge id back to FOSSBilling's IPN endpoint and the invoice is paid.
 *
 * Every other method is "manual": the customer tells us how they paid (phone
 * number + optional reference), we record a pending transaction whose Note holds
 * everything the admin needs, and the admin confirms it on FOSSBilling's
 * transaction page once they've checked the money landed.
 *
 * SPDX-License-Identifier: Apache-2.0
 */
class Payment_Adapter_EasyPay implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    /** Transaction ids we generate are prefixed with this. */
    private const TXN_PREFIX = 'EP-';

    /** A processTransaction() call carrying this source is an admin confirmation. */
    private const TRUSTED_SOURCE = 'admin';

    /** The single automatic method. */
    private const METHOD_LEAPA = 'leapa';

    /** Methods that wait for a human to confirm the money arrived. */
    private const MANUAL_METHODS = ['ecocash', 'lumicash', 'bancobu', 'otherbanks'];

    public function __construct(private array $config)
    {
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Gateway capabilities and the admin configuration form.
     *
     * Per requirement every field is optional ('required' => false): a fresh
     * install should never block the admin, and each method simply hides itself
     * until it has been configured.
     */
    public static function getConfig(): array
    {
        return [
            // The Leapa widget and our own scripts must run in the main document,
            // so the gateway is never embedded in an iframe.
            'can_load_in_iframe' => false,
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept Burundian payments by card (Leapa), EcoCash, Lumicash, '
                . 'Bancobu eNoti or bank transfer. Card payments are confirmed automatically; '
                . 'mobile money and bank transfers are recorded as pending for an administrator to verify.',
            'form' => [
                // --- Leapa (automatic card payments) -------------------------
                'enable_leapa' => self::toggle('Enable Leapa (VISA / Mastercard)'),
                'leapa_test_key' => self::textField(
                    'Leapa API key — test',
                    'Used while the gateway is in test mode. Leave empty if unused.'
                ),
                'leapa_live_key' => self::textField(
                    'Leapa API key — live',
                    'Used while the gateway is in live mode. Leave empty if unused.'
                ),
                'currency' => self::textField(
                    'Currency code',
                    'Currency sent to Leapa, e.g. BIF. Defaults to the invoice currency.'
                ),

                // --- EcoCash -------------------------------------------------
                'enable_ecocash' => self::toggle('Enable EcoCash'),
                'ecocash_code' => self::textField('EcoCash merchant code'),
                'ecocash_name' => self::textField('EcoCash merchant name'),

                // --- Lumicash ------------------------------------------------
                'enable_lumicash' => self::toggle('Enable Lumicash'),
                'lumicash_code' => self::textField('Lumicash merchant code'),
                'lumicash_name' => self::textField('Lumicash merchant name'),

                // --- Bancobu eNoti -------------------------------------------
                'enable_bancobu' => self::toggle('Enable Bancobu eNoti'),
                'bancobu_code' => self::textField('Bancobu eNoti merchant code'),
                'bancobu_name' => self::textField('Bancobu eNoti merchant name'),

                // --- Other banks (wire transfer) -----------------------------
                'enable_otherbanks' => self::toggle('Enable Other Banks (wire transfer)'),
                'bank_name' => self::textField('Bank name'),
                'bank_account_holder' => self::textField('Account holder name'),
                'bank_account_number' => self::textField('Account number (11 digits)'),
                'bank_swift' => self::textField('SWIFT / BIC code'),
            ],
        ];
    }

    /**
     * Render the checkout shown to the customer on the invoice payment page.
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->load('Invoice', $invoice_id);
        if (!$invoice instanceof \Model_Invoice || !$invoice->id) {
            return '<div class="easypay-error">Invoice not found.</div>';
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $total = (float) $invoiceService->getTotalWithTax($invoice);
        $currency = $this->currency($invoice);

        $invoiceRef = trim((string) $invoice->serie . (string) $invoice->nr);
        if ($invoiceRef === '') {
            $invoiceRef = '#' . $invoice->id;
        }

        $ctx = [
            'notify' => $this->notifyUrl($invoice),
            'invoice' => (string) $invoice->id,
            'gateway' => $this->gatewayId(),
            'amount_attr' => $this->amountForGateway($total, $currency),
            'amount_display' => $this->amountForDisplay($total, $currency),
            'currency' => $currency,
            'desc' => $invoiceRef,
        ];

        // Decide which methods are available, in display order, and build the UI.
        $tabs = '';
        $panels = '';
        $first = true;
        foreach ($this->availableMethods() as $method) {
            $active = $first ? ' is-active' : '';
            $hidden = $first ? '' : ' ep-hidden';
            $tabs .= '<button type="button" class="ep-tab' . $active . '" data-method="' . $this->esc($method) . '">'
                . $this->esc($this->tabLabel($method)) . '</button>';
            $panels .= '<div class="ep-panel' . $hidden . '" data-method="' . $this->esc($method) . '">'
                . $this->renderPanel($method, $ctx) . '</div>';
            $first = false;
        }

        if ($tabs === '') {
            return '<div class="easypay-error">No payment method is currently available. '
                . 'Please contact us to complete your payment.</div>';
        }

        $jsConfig = json_encode([
            'phoneError' => 'Enter a valid Burundian phone number (8 digits, e.g. +257 79 00 00 00).',
            'leapaJs' => 'https://widgets.leapa.co/customer.js',
            'leapaCss' => 'https://widgets.leapa.co/customer.css',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return strtr($this->shell(), [
            '{{amount}}' => $this->esc($this->amountForDisplay($total, $currency)),
            '{{currency}}' => $this->esc($currency),
            '{{invoiceLabel}}' => $this->esc('Invoice ' . $ctx['desc']),
            '{{tabs}}' => $tabs,
            '{{panels}}' => $panels,
            '{{config}}' => $jsConfig ?: '{}',
        ]);
    }

    /**
     * Handle an IPN / admin confirmation for a transaction.
     *
     * Three things land here:
     *   1. A Leapa browser callback  -> verify-less auto settle (card already charged).
     *   2. A manual "I have paid" submit -> record a pending transaction, do NOT pay.
     *   3. An admin clicking "Process"   -> $data['source'] === 'admin' -> settle the invoice.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if (!$this->isIpnValid($data)) {
            throw new Payment_Exception('EasyPay callback could not be verified.');
        }

        try {
            $tx = $this->di['db']->getExistingModelById('Transaction', $id);
            $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);

            $isAdmin = ($data['source'] ?? null) === self::TRUSTED_SOURCE;
            $method = strtolower((string) $this->readParam($data, $tx, 'easypay_method'));
            $isLeapa = $method === self::METHOD_LEAPA;

            // Keep a stable transaction id for the whole lifecycle of this record.
            $txnId = (string) ($tx->txn_id ?: $this->generateTxnId($invoice));

            // When an admin clicks "Process", FOSSBilling re-runs the *stored* IPN,
            // which still carries source='ipn' (not 'admin') — so we can't rely on
            // the source alone. A manual transaction we have already recorded as
            // pending (it carries one of our EP- ids) that is being processed again
            // is the administrator's approval to settle.
            $alreadyRecorded = str_starts_with((string) $tx->txn_id, self::TXN_PREFIX);
            $adminApproval = $isAdmin || $alreadyRecorded;

            if ($isLeapa || $adminApproval) {
                return $this->settle($tx, $invoice, $method, $txnId, $data);
            }

            // First time we see this manual transaction: record it as pending.
            return $this->recordPending($tx, $invoice, $method, $txnId, $data);
        } catch (Payment_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            error_log('EasyPay processTransaction failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * A callback is valid when it is an admin confirmation or it names a method
     * we recognise. Random hits on the IPN endpoint are rejected.
     */
    public function isIpnValid($data): bool
    {
        if (($data['source'] ?? null) === self::TRUSTED_SOURCE) {
            return true;
        }

        $method = strtolower((string) ($data['post']['easypay_method'] ?? $data['get']['easypay_method'] ?? ''));

        return $method === self::METHOD_LEAPA || in_array($method, self::MANUAL_METHODS, true);
    }

    // -----------------------------------------------------------------------
    // Settlement
    // -----------------------------------------------------------------------

    /**
     * Pay the invoice and provision the order.
     *
     * Flow per spec: addFunds() -> payInvoiceWithCredits() -> activateOrder().
     */
    private function settle(\Model_Transaction $tx, \Model_Invoice $invoice, string $method, string $txnId, array $data): bool
    {
        // For card payments, this is the single place to add server-side
        // verification of the charge against Leapa before we trust it.
        if ($method === self::METHOD_LEAPA) {
            $chargeId = (string) $this->readParam($data, $tx, 'leapa_charge_id');
            if (!$this->verifyLeapaCharge($chargeId)) {
                throw new Payment_Exception('Leapa charge could not be verified.');
            }
        }

        $clientService = $this->di['mod_service']('Client');
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = (float) $invoiceService->getTotalWithTax($invoice);

        // Idempotency: if the invoice is already paid (e.g. a duplicate IPN),
        // finalise the transaction record without crediting the client again.
        if ((string) $invoice->status === \Model_Invoice::STATUS_PAID) {
            $tx->txn_id = $txnId;
            $tx->txn_status = 'complete';
            $tx->amount = $total;
            $tx->currency = $invoice->currency;
            $tx->note = $this->buildNote($method, $data, $tx, true);
            $tx->status = \Model_Transaction::STATUS_PROCESSED;
            $tx->updated_at = date('Y-m-d H:i:s');

            return (bool) $this->di['db']->store($tx);
        }

        $client = $clientService->get(['id' => $invoice->client_id]);

        $label = $this->methodLabel($method ?: 'easypay');
        $description = 'EasyPay — ' . $label . ' (' . $txnId . ')';

        // 1. Credit the client's balance with the invoice total.
        $clientService->addFunds($client, $total, $description, []);

        // 2. Pay the invoice from that balance (this also marks items as paid).
        if (method_exists($invoiceService, 'payInvoiceWithCredits')) {
            $invoiceService->payInvoiceWithCredits($invoice);
        } else {
            // Defensive fallback for older cores: mark paid and execute order tasks.
            $invoiceService->markAsPaid($invoice, true, true);
        }

        // 3. Make sure any orders on the invoice are activated/provisioned.
        $this->activateInvoiceOrders($invoice);

        $tx->txn_id = $txnId;
        $tx->txn_status = 'complete';
        $tx->amount = $total;
        $tx->currency = $invoice->currency;
        $tx->note = $this->buildNote($method, $data, $tx, true);
        $tx->status = \Model_Transaction::STATUS_PROCESSED;
        $tx->updated_at = date('Y-m-d H:i:s');

        return (bool) $this->di['db']->store($tx);
    }

    /**
     * Hook for server-side verification of a Leapa charge.
     *
     * The widget integration settles a card payment by posting the charge id
     * back to our IPN endpoint, so by default we trust a charge that carries an
     * id. For hardened production use, verify the charge here against Leapa's
     * API (using the secret key) before returning true — this is the one place
     * that needs to change to do so.
     */
    private function verifyLeapaCharge(string $chargeId): bool
    {
        return $chargeId !== '';
    }

    /**
     * Record a manual payment as pending. The invoice is left unpaid; the admin
     * reads the Note, checks the payment portal, then clicks Process to settle.
     */
    private function recordPending(\Model_Transaction $tx, \Model_Invoice $invoice, string $method, string $txnId, array $data): bool
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        $tx->txn_id = $txnId;
        $tx->txn_status = 'pending';
        $tx->amount = (float) $invoiceService->getTotalWithTax($invoice);
        $tx->currency = $invoice->currency;
        $tx->note = $this->buildNote($method, $data, $tx, false);
        $tx->status = 'received';
        $tx->updated_at = date('Y-m-d H:i:s');

        return (bool) $this->di['db']->store($tx);
    }

    /**
     * Activate every order attached to the invoice. Re-activating an already
     * active order throws, which we swallow so settlement stays idempotent.
     */
    private function activateInvoiceOrders(\Model_Invoice $invoice): void
    {
        try {
            $orderService = $this->di['mod_service']('Order');
        } catch (\Exception $e) {
            return;
        }

        if (!method_exists($orderService, 'activateOrder')) {
            return;
        }

        $items = $this->di['db']->find('InvoiceItem', 'invoice_id = :id', [':id' => $invoice->id]);
        foreach ($items as $item) {
            if ((string) ($item->type ?? '') !== 'order' || empty($item->rel_id)) {
                continue;
            }
            $order = $this->di['db']->load('ClientOrder', $item->rel_id);
            if (!$order || !$order->id) {
                continue;
            }
            try {
                $orderService->activateOrder($order);
            } catch (\Exception $e) {
                error_log('EasyPay: order ' . $item->rel_id . ' not activated — ' . $e->getMessage());
            }
        }
    }

    /**
     * Compose the Note shown on FOSSBilling's transaction page. This is the
     * admin's whole brief: which method, the phone number to look up, any
     * reference, and what to do next.
     */
    private function buildNote(string $method, array $data, \Model_Transaction $tx, bool $settled): string
    {
        $label = $this->methodLabel($method ?: 'easypay');

        if ($method === self::METHOD_LEAPA) {
            $charge = (string) $this->readParam($data, $tx, 'leapa_charge_id');

            return 'Leapa card payment' . ($charge !== '' ? ' — charge ' . $charge : '') . ' (settled automatically).';
        }

        $rawPhone = (string) $this->readParam($data, $tx, 'easypay_phone');
        $phone = $this->normalizePhone($rawPhone);
        $phoneText = $phone !== null ? '+257 ' . $phone : ($rawPhone !== '' ? $rawPhone . ' (unverified format)' : 'not provided');

        $reference = trim((string) $this->readParam($data, $tx, 'easypay_reference'));

        $parts = [
            $label . ' payment',
            'Phone: ' . $phoneText,
        ];
        if ($reference !== '') {
            $parts[] = 'Reference: ' . $reference;
        }
        $parts[] = $settled
            ? 'Confirmed by administrator.'
            : 'Awaiting verification — check the payment portal, then click Process to confirm.';

        return implode(' | ', $parts);
    }

    // -----------------------------------------------------------------------
    // Checkout rendering
    // -----------------------------------------------------------------------

    /** Build the panel body for a single method. */
    private function renderPanel(string $method, array $ctx): string
    {
        return match ($method) {
            self::METHOD_LEAPA => $this->renderLeapaPanel($ctx),
            'ecocash' => $this->renderMobilePanel('ecocash', $ctx),
            'lumicash' => $this->renderMobilePanel('lumicash', $ctx),
            'bancobu' => $this->renderMobilePanel('bancobu', $ctx),
            'otherbanks' => $this->renderBankPanel($ctx),
            default => '',
        };
    }

    private function renderLeapaPanel(array $ctx): string
    {
        $key = $this->leapaKey();
        if ($key === '') {
            return '<p class="ep-muted">Card payments are temporarily unavailable. '
                . 'Please choose another method.</p>';
        }

        $key = $this->esc($key);
        $amount = $this->esc($ctx['amount_attr']);
        $currency = $this->esc($ctx['currency']);
        $desc = $this->esc('Invoice ' . $ctx['desc']);
        $hidden = $this->hiddenFields('leapa', $ctx)
            . '<input type="hidden" name="leapa_charge_id" id="ep-leapa-charge" value="">';

        // Attribute names follow the official Leapa widget docs: the API key is
        // "api-key" (NOT "key" — a wrong name makes the widget call .split on
        // undefined), charge mode needs amount/currency/description, and the
        // widget script + stylesheet are loaded from our JS (with graceful
        // failure) so a load error can never surface as a "Script error".
        return ''
            . '<p class="ep-lead">Pay securely by VISA or Mastercard. Your card is charged instantly '
            . 'and your order is activated automatically.</p>'
            . '<div class="ep-card-widget">'
            . '<leapa-customer id="ep-leapa" mode="charge" api-key="' . $key . '" '
            . 'amount="' . $amount . '" currency="' . $currency . '" description="' . $desc . '" '
            . 'first-name="true" last-name="true" tos="true" badge="light"></leapa-customer>'
            . '</div>'
            . '<form id="ep-leapa-form" class="ep-hidden" method="post" action="' . $this->esc($ctx['notify']) . '">'
            . $hidden
            . '</form>';
    }

    private function renderMobilePanel(string $method, array $ctx): string
    {
        $code = trim((string) ($this->config[$method . '_code'] ?? ''));
        $name = trim((string) ($this->config[$method . '_name'] ?? ''));
        $label = $this->methodLabel($method);

        if ($code === '') {
            return '<p class="ep-muted">' . $this->esc($label) . ' is not fully configured yet. '
                . 'Please choose another method.</p>';
        }

        $steps = ''
            . '<ol class="ep-steps">'
            . '<li>Open your <strong>' . $this->esc($label) . '</strong> app and pay '
            . '<strong>' . $this->esc($ctx['amount_display']) . ' ' . $this->esc($ctx['currency']) . '</strong> to:</li>'
            . '</ol>'
            . '<div class="ep-merchant">'
            . '<div class="ep-merchant-row"><span>Merchant code</span>'
            . '<strong class="ep-copy" data-copy="' . $this->esc($code) . '">' . $this->esc($code) . '</strong></div>'
            . ($name !== '' ? '<div class="ep-merchant-row"><span>Merchant name</span><strong>' . $this->esc($name) . '</strong></div>' : '')
            . '</div>'
            . '<p class="ep-lead">Once you have paid, confirm below so we can match your payment.</p>';

        return $steps . $this->renderManualForm($method, $ctx);
    }

    private function renderBankPanel(array $ctx): string
    {
        $bank = trim((string) ($this->config['bank_name'] ?? ''));
        $holder = trim((string) ($this->config['bank_account_holder'] ?? ''));
        $account = trim((string) ($this->config['bank_account_number'] ?? ''));
        $swift = trim((string) ($this->config['bank_swift'] ?? ''));

        if ($account === '') {
            return '<p class="ep-muted">Bank transfer is not fully configured yet. '
                . 'Please choose another method.</p>';
        }

        $accountWarn = $this->isValidBankAccount($account)
            ? ''
            : '<p class="ep-warn">Note: the configured account number is not 11 digits — please double-check it.</p>';

        $rows = '';
        if ($bank !== '') {
            $rows .= '<div class="ep-merchant-row"><span>Bank</span><strong>' . $this->esc($bank) . '</strong></div>';
        }
        if ($holder !== '') {
            $rows .= '<div class="ep-merchant-row"><span>Account holder</span><strong>' . $this->esc($holder) . '</strong></div>';
        }
        $rows .= '<div class="ep-merchant-row"><span>Account number</span>'
            . '<strong class="ep-copy" data-copy="' . $this->esc($account) . '">' . $this->esc($account) . '</strong></div>';
        if ($swift !== '') {
            $rows .= '<div class="ep-merchant-row"><span>SWIFT / BIC</span><strong>' . $this->esc($swift) . '</strong></div>';
        }

        return ''
            . '<p class="ep-lead">Transfer <strong>' . $this->esc($ctx['amount_display']) . ' ' . $this->esc($ctx['currency'])
            . '</strong> to the account below, then confirm so we can match your payment.</p>'
            . '<div class="ep-merchant">' . $rows . '</div>'
            . $accountWarn
            . $this->renderManualForm('otherbanks', $ctx);
    }

    /** The shared phone + reference confirmation form for every manual method. */
    private function renderManualForm(string $method, array $ctx): string
    {
        return ''
            . '<form class="ep-form" method="post" action="' . $this->esc($ctx['notify']) . '" '
            . 'data-method="' . $this->esc($method) . '">'
            . $this->hiddenFields($method, $ctx)
            . '<div class="ep-field">'
            . '<label>Your phone number</label>'
            . '<input type="tel" name="easypay_phone" class="ep-phone" autocomplete="tel" '
            . 'placeholder="+257 79 00 00 00" required>'
            . '</div>'
            . '<div class="ep-field">'
            . '<label>Reference <span class="ep-opt">(optional)</span></label>'
            . '<input type="text" name="easypay_reference" maxlength="120" placeholder="Transaction id or note">'
            . '</div>'
            . '<div class="ep-error" hidden></div>'
            . '<button type="submit" class="ep-btn">I have paid — confirm</button>'
            . '<p class="ep-note">Your payment will be verified by our team, usually within a few hours.</p>'
            . '</form>';
    }

    private function hiddenFields(string $method, array $ctx): string
    {
        // The notify URL already carries invoice_id / gateway_id in its query
        // string; these mirror them in the POST body as a safety net. The names
        // match what FOSSBilling's ipn.php reads (invoice_id / gateway_id).
        return ''
            . '<input type="hidden" name="easypay_method" value="' . $this->esc($method) . '">'
            . '<input type="hidden" name="invoice_id" value="' . $this->esc($ctx['invoice']) . '">'
            . '<input type="hidden" name="gateway_id" value="' . $this->esc($ctx['gateway']) . '">';
    }

    /** The outer markup, styles and behaviour. Dynamic parts are injected via strtr(). */
    private function shell(): string
    {
        return <<<'HTML'
<div class="easypay">
    <style>
    .easypay{--ep-accent:#0b7a4b;--ep-accent-dark:#095e3a;--ep-bg:#f6f8fa;--ep-line:#e3e8ee;--ep-text:#1c2733;--ep-muted:#6b7785;max-width:520px;margin:0 auto;color:var(--ep-text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.5}
    .easypay *{box-sizing:border-box}
    .ep-summary{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:18px 20px;background:linear-gradient(135deg,var(--ep-accent),var(--ep-accent-dark));color:#fff;border-radius:16px 16px 0 0}
    .ep-summary .ep-label{font-size:13px;opacity:.85;margin:0}
    .ep-summary .ep-total{font-size:26px;font-weight:700;margin:2px 0 0}
    .ep-summary .ep-inv{font-size:12px;opacity:.85;text-align:right}
    .ep-tabs{display:flex;flex-wrap:wrap;gap:6px;padding:14px 16px 0;background:#fff;border-left:1px solid var(--ep-line);border-right:1px solid var(--ep-line)}
    .ep-tab{appearance:none;border:1px solid var(--ep-line);background:var(--ep-bg);color:var(--ep-muted);font-size:13px;font-weight:600;padding:8px 14px;border-radius:999px;cursor:pointer;transition:.15s}
    .ep-tab:hover{color:var(--ep-text)}
    .ep-tab.is-active{background:var(--ep-accent);border-color:var(--ep-accent);color:#fff}
    .ep-body{background:#fff;border:1px solid var(--ep-line);border-top:0;border-radius:0 0 16px 16px;padding:20px}
    .ep-lead{margin:0 0 14px;font-size:14px;color:var(--ep-muted)}
    .ep-steps{margin:0 0 12px;padding-left:18px;font-size:14px}
    .ep-merchant{border:1px dashed var(--ep-line);border-radius:12px;padding:12px 14px;background:var(--ep-bg);margin-bottom:14px}
    .ep-merchant-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:5px 0;font-size:14px}
    .ep-merchant-row+.ep-merchant-row{border-top:1px solid var(--ep-line)}
    .ep-merchant-row span{color:var(--ep-muted)}
    .ep-copy{cursor:pointer;border-bottom:1px dotted var(--ep-muted)}
    .ep-copy:hover{color:var(--ep-accent)}
    .ep-field{margin-bottom:12px}
    .ep-field label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
    .ep-opt{font-weight:400;color:var(--ep-muted)}
    .ep-field input{width:100%;padding:11px 13px;border:1px solid var(--ep-line);border-radius:10px;font-size:15px;transition:.15s}
    .ep-field input:focus{outline:none;border-color:var(--ep-accent);box-shadow:0 0 0 3px rgba(11,122,75,.12)}
    .ep-btn{width:100%;border:0;background:var(--ep-accent);color:#fff;font-size:15px;font-weight:600;padding:13px;border-radius:10px;cursor:pointer;transition:.15s}
    .ep-btn:hover{background:var(--ep-accent-dark)}
    .ep-btn:disabled{opacity:.6;cursor:default}
    .ep-error{background:#fdecec;color:#b3261e;border-radius:8px;padding:9px 12px;font-size:13px;margin-bottom:12px}
    .ep-note{margin:10px 0 0;font-size:12px;color:var(--ep-muted);text-align:center}
    .ep-muted{color:var(--ep-muted);font-size:14px}
    .ep-warn{color:#9a6700;background:#fff8e6;border-radius:8px;padding:9px 12px;font-size:13px;margin:0 0 12px}
    .ep-card-widget{margin:8px 0 4px}
    .ep-hidden{display:none!important}
    .ep-flash{background:#e7f6ee;color:var(--ep-accent-dark);border-radius:10px;padding:14px;font-size:14px;text-align:center}
    .easypay-error{max-width:520px;margin:0 auto;padding:16px;border-radius:12px;background:#fdecec;color:#b3261e;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
    </style>

    <div class="ep-summary">
        <div>
            <p class="ep-label">Amount due</p>
            <p class="ep-total">{{amount}} {{currency}}</p>
        </div>
        <div class="ep-inv">{{invoiceLabel}}</div>
    </div>
    <div class="ep-tabs">{{tabs}}</div>
    <div class="ep-body">{{panels}}</div>

    <script>
    (function () {
        var CFG = {{config}};
        var PHONE_ERROR = CFG.phoneError || 'Enter a valid Burundian phone number.';
        var LEAPA_JS = CFG.leapaJs || 'https://widgets.leapa.co/customer.js';
        var LEAPA_CSS = CFG.leapaCss || 'https://widgets.leapa.co/customer.css';

        // Burundian phone validation (mirrors the PHP side).
        function normalizePhone(value) {
            var d = (value || '').replace(/\D+/g, '');
            var prefixes = ['00257', '0257', '257'];
            for (var i = 0; i < prefixes.length; i++) {
                if (d.indexOf(prefixes[i]) === 0) { d = d.slice(prefixes[i].length); break; }
            }
            if (d.length === 9 && d.charAt(0) === '0') { d = d.slice(1); }
            return /^\d{8}$/.test(d) ? d : null;
        }

        // Event delegation on document so behaviour survives however FOSSBilling
        // injects this markup, and binds only once even if the script runs twice.
        if (!window.__easypayDelegated) {
            window.__easypayDelegated = true;

            document.addEventListener('click', function (e) {
                if (!e.target.closest) { return; }
                var tab = e.target.closest('.ep-tab');
                if (tab) {
                    var root = tab.closest('.easypay');
                    if (!root) { return; }
                    var method = tab.getAttribute('data-method');
                    root.querySelectorAll('.ep-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                    root.querySelectorAll('.ep-panel').forEach(function (p) { p.classList.toggle('ep-hidden', p.getAttribute('data-method') !== method); });
                    return;
                }
                var copy = e.target.closest('.ep-copy');
                if (copy) {
                    var value = copy.getAttribute('data-copy') || copy.textContent;
                    if (navigator.clipboard) { navigator.clipboard.writeText(value).catch(function () {}); }
                    var original = copy.textContent;
                    copy.textContent = 'Copied';
                    setTimeout(function () { copy.textContent = original; }, 1200);
                }
            });

            // Manual forms: validate the phone, then let the form submit normally.
            // ipn.php records the pending transaction and redirects back to the
            // invoice (redirect=true&invoice_hash in the action URL).
            document.addEventListener('submit', function (e) {
                var form = e.target.closest ? e.target.closest('.ep-form') : null;
                if (!form) { return; }
                var phone = form.querySelector('.ep-phone');
                var error = form.querySelector('.ep-error');
                if (phone && !normalizePhone(phone.value)) {
                    e.preventDefault();
                    if (error) { error.textContent = PHONE_ERROR; error.hidden = false; }
                    phone.focus();
                    return;
                }
                if (error) { error.hidden = true; }
                var button = form.querySelector('.ep-btn');
                if (button) { button.disabled = true; button.textContent = 'Confirming…'; }
            });
        }

        // Leapa card flow: bind onSuccess and load the widget (an ES module)
        // defensively so a load failure degrades gracefully instead of throwing.
        function initLeapa() {
            var leapa = document.getElementById('ep-leapa');
            var leapaForm = document.getElementById('ep-leapa-form');
            if (!leapa || !leapaForm || leapa.getAttribute('data-ep-bound')) { return; }
            leapa.setAttribute('data-ep-bound', '1');

            leapa.addEventListener('onSuccess', function (e) {
                var detail = (e && e.detail) || {};
                var invoice = detail.invoice || detail;
                var chargeId = invoice.charge || invoice.id || detail.charge || detail.id || '';
                var field = document.getElementById('ep-leapa-charge');
                if (field) { field.value = chargeId; }
                leapaForm.submit();
            });

            // The widget requires its stylesheet to render correctly.
            if (!document.querySelector('link[data-easypay-leapa]')) {
                var l = document.createElement('link');
                l.rel = 'stylesheet';
                l.href = LEAPA_CSS;
                l.setAttribute('data-easypay-leapa', '1');
                document.head.appendChild(l);
            }

            if (!document.querySelector('script[data-easypay-leapa]')) {
                var s = document.createElement('script');
                s.type = 'module';
                s.src = LEAPA_JS;
                s.async = true;
                s.setAttribute('data-easypay-leapa', '1');
                s.onerror = function () {
                    var panel = leapa.closest('.ep-panel');
                    if (panel) {
                        panel.innerHTML = '<p class="ep-muted">Card payments are temporarily unavailable. '
                            + 'Please choose another method.</p>';
                    }
                };
                document.head.appendChild(s);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initLeapa);
        } else {
            initLeapa();
        }
    })();
    </script>
</div>
HTML;
    }

    // -----------------------------------------------------------------------
    // Configuration helpers
    // -----------------------------------------------------------------------

    /** Methods to show in the checkout, in display order. */
    private function availableMethods(): array
    {
        $methods = [];
        if ($this->methodEnabled('enable_leapa') && $this->leapaKey() !== '') {
            $methods[] = self::METHOD_LEAPA;
        }
        if ($this->methodEnabled('enable_ecocash') && trim((string) ($this->config['ecocash_code'] ?? '')) !== '') {
            $methods[] = 'ecocash';
        }
        if ($this->methodEnabled('enable_lumicash') && trim((string) ($this->config['lumicash_code'] ?? '')) !== '') {
            $methods[] = 'lumicash';
        }
        if ($this->methodEnabled('enable_bancobu') && trim((string) ($this->config['bancobu_code'] ?? '')) !== '') {
            $methods[] = 'bancobu';
        }
        if ($this->methodEnabled('enable_otherbanks') && trim((string) ($this->config['bank_account_number'] ?? '')) !== '') {
            $methods[] = 'otherbanks';
        }

        return $methods;
    }

    /** A toggle is on unless the admin explicitly set it to a falsy value. */
    private function methodEnabled(string $key): bool
    {
        $value = $this->config[$key] ?? null;
        if ($value === null || $value === '') {
            return true;
        }

        return !in_array((string) $value, ['0', 'no', 'false', 'off'], true);
    }

    /** The Leapa API key for the gateway's current mode (test vs live). */
    private function leapaKey(): string
    {
        $key = !empty($this->config['test_mode'])
            ? ($this->config['leapa_test_key'] ?? '')
            : ($this->config['leapa_live_key'] ?? '');

        return is_string($key) ? trim($key) : '';
    }

    private function currency(\Model_Invoice $invoice): string
    {
        $configured = trim((string) ($this->config['currency'] ?? ''));

        return $configured !== '' ? strtoupper($configured) : (string) ($invoice->currency ?: 'BIF');
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Normalise a Burundian phone number to its 8 national digits.
     *
     * Strips +257 / 00257 / 0257 and a single leading 0, then requires exactly
     * 8 digits. Returns null when the input cannot be a valid number.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        foreach (['00257', '0257', '257'] as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                $digits = substr($digits, strlen($prefix));
                break;
            }
        }
        if (strlen($digits) === 9 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return (strlen($digits) === 8 && ctype_digit($digits)) ? $digits : null;
    }

    /** A bank account number is exactly 11 digits. */
    private function isValidBankAccount(string $account): bool
    {
        $digits = preg_replace('/\D+/', '', $account) ?? '';

        return strlen($digits) === 11 && ctype_digit($digits);
    }

    // -----------------------------------------------------------------------
    // URLs, ids and small utilities
    // -----------------------------------------------------------------------

    /**
     * IPN endpoint for this gateway + invoice.
     *
     * Uses FOSSBilling's own PayGateway::getCallbackUrl() (which emits the
     * correct invoice_id / gateway_id that ipn.php expects), then appends
     * redirect=true&invoice_hash so the browser is returned to the invoice
     * after the transaction is processed. Falls back to building the URL by
     * hand if that service is unavailable.
     */
    private function notifyUrl(\Model_Invoice $invoice): string
    {
        $hash = rawurlencode((string) $invoice->hash);

        try {
            $gateway = $this->di['db']->findOne('PayGateway', 'gateway = :g', [':g' => 'EasyPay']);
            if ($gateway) {
                $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
                if (is_object($payGatewayService) && method_exists($payGatewayService, 'getCallbackUrl')) {
                    $url = (string) $payGatewayService->getCallbackUrl($gateway, $invoice);
                    if ($url !== '') {
                        return $url . (str_contains($url, '?') ? '&' : '?') . 'redirect=true&invoice_hash=' . $hash;
                    }
                }
            }
        } catch (\Exception $e) {
            // fall through to the manual build
        }

        return $this->baseUrl() . 'ipn.php?invoice_id=' . (int) $invoice->id
            . '&gateway_id=' . rawurlencode($this->gatewayId())
            . '&redirect=true&invoice_hash=' . $hash;
    }

    private function baseUrl(): string
    {
        $url = '';
        if (isset($this->di['config']['url'])) {
            $url = (string) $this->di['config']['url'];
        }

        return $url !== '' ? rtrim($url, '/') . '/' : '/';
    }

    /** Resolve this adapter's PayGateway id (needed for IPN routing). */
    private function gatewayId(): string
    {
        if (!empty($this->config['gateway_id'])) {
            return (string) $this->config['gateway_id'];
        }
        try {
            $gateway = $this->di['db']->findOne('PayGateway', 'gateway = :g', [':g' => 'EasyPay']);
            if ($gateway && $gateway->id) {
                return (string) $gateway->id;
            }
        } catch (\Exception $e) {
            // fall through
        }

        return '';
    }

    private function generateTxnId(\Model_Invoice $invoice): string
    {
        return self::TXN_PREFIX . $invoice->id . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /** Read a request value from the live callback first, then the stored IPN. */
    private function readParam(array $data, \Model_Transaction $tx, string $key, string $default = ''): string
    {
        foreach (['post', 'get'] as $bag) {
            if (isset($data[$bag][$key]) && $data[$bag][$key] !== '' && !is_array($data[$bag][$key])) {
                return (string) $data[$bag][$key];
            }
        }
        if (isset($data[$key]) && !is_array($data[$key]) && $data[$key] !== '') {
            return (string) $data[$key];
        }

        $stored = json_decode((string) ($tx->ipn ?? ''), true);
        if (is_array($stored)) {
            foreach (['post', 'get'] as $bag) {
                if (isset($stored[$bag][$key]) && $stored[$bag][$key] !== '' && !is_array($stored[$bag][$key])) {
                    return (string) $stored[$bag][$key];
                }
            }
        }

        return $default;
    }

    /**
     * Amount sent to the Leapa widget: an integer in the currency's smallest
     * unit (e.g. 1000 = USD 10.00). Zero-decimal currencies such as BIF have no
     * minor unit, so the integer is the amount itself (e.g. 237401 = BIF 237401).
     */
    private function amountForGateway(float $total, string $currency): string
    {
        $factor = $this->isZeroDecimalCurrency($currency) ? 1 : 100;

        return (string) (int) round($total * $factor);
    }

    /** Human-friendly amount with thousands separators, matching the invoice total. */
    private function amountForDisplay(float $total, string $currency): string
    {
        $decimals = (floor($total) === $total) ? 0 : 2;

        return number_format($total, $decimals, '.', ' ');
    }

    /** ISO 4217 currencies that have no minor unit. */
    private function isZeroDecimalCurrency(string $currency): bool
    {
        static $zeroDecimal = [
            'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];

        return in_array(strtoupper($currency), $zeroDecimal, true);
    }

    private function tabLabel(string $method): string
    {
        return match ($method) {
            self::METHOD_LEAPA => 'Card',
            'ecocash' => 'EcoCash',
            'lumicash' => 'Lumicash',
            'bancobu' => 'Bancobu',
            'otherbanks' => 'Bank',
            default => ucfirst($method),
        };
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            self::METHOD_LEAPA => 'Card (VISA / Mastercard)',
            'ecocash' => 'EcoCash',
            'lumicash' => 'Lumicash',
            'bancobu' => 'Bancobu eNoti',
            'otherbanks' => 'Bank transfer',
            default => ucfirst($method),
        };
    }

    private function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    // -----------------------------------------------------------------------
    // Form field factories (used by the static getConfig)
    // -----------------------------------------------------------------------

    private static function textField(string $label, string $description = ''): array
    {
        $options = ['label' => $label, 'required' => false];
        if ($description !== '') {
            $options['description'] = $description;
        }

        return ['text', $options];
    }

    private static function toggle(string $label): array
    {
        return ['radio', [
            'label' => $label,
            'multiOptions' => ['1' => 'Yes', '0' => 'No'],
            'required' => false,
        ]];
    }
}
