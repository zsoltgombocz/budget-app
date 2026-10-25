<?php

return [
    /*
     * Whether anyone can sign up. Production is invite only: admins invite people from
     * the admin panel. The dev stack keeps it open for testing.
     */
    'registration_open' => (bool) env('REGISTRATION_OPEN', false),

    /*
     * Shared password in front of the whole app (dev stack). Empty turns the gate off.
     */
    'dev_gate_password' => env('DEV_GATE_PASSWORD'),

    /*
     * Host of the admin panel and the monitoring dashboard, e.g. admin.moneysight.app.
     */
    'admin_domain' => env('ADMIN_DOMAIN'),

    /*
     * Address shown on the Contact page.
     */
    'contact_email' => env('CONTACT_EMAIL') ?: 'hello@moneysight.app',

    /*
     * Sentry issues page of production, linked from the admin menu.
     */
    'sentry_url' => env('SENTRY_DASHBOARD_URL') ?: 'https://moneysight.sentry.io/issues/?project=4512205235552336',

    /*
     * Plusz prices in forints, as shown on the subscription page (Alap is free). They must match
     * the Stripe prices below, which Checkout charges.
     */
    'prices' => [
        'plus' => ['month' => 3_990, 'year' => 31_990],
    ],

    /*
     * The operator shown in the legal pages, the imprint and the e-mail footer. Placeholders until
     * the operator is registered; the legal pages say so when a value is missing.
     */
    'operator' => [
        'name' => env('OPERATOR_NAME'),
        'address' => env('OPERATOR_ADDRESS'),
        'registration' => env('OPERATOR_REGISTRATION'),
        'tax_number' => env('OPERATOR_TAX_NUMBER'),
        'email' => env('OPERATOR_EMAIL', 'hello@moneysight.app'),
        'phone' => env('OPERATOR_PHONE'),
        'conciliation_body' => env('OPERATOR_CONCILIATION_BODY'),
        'vat_note' => env('OPERATOR_VAT_NOTE', 'Az árak bruttó árak.'),
    ],

    'legal' => [
        'version' => env('LEGAL_VERSION', '1.0'),
        'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026. november 1.'),
    ],

    /*
     * Days after the start of a subscription in which it can be withdrawn with a full refund.
     */
    'withdrawal_days' => 14,

    'stripe_prices' => [
        'month' => env('STRIPE_PRICE_PLUS_MONTHLY'),
        'year' => env('STRIPE_PRICE_PLUS_YEARLY'),
    ],
];
