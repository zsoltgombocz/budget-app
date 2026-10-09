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
];
