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
     * Laravel Nightwatch dashboard of production, linked from the admin menu. Empty hides the link.
     */
    'nightwatch_url' => env('NIGHTWATCH_DASHBOARD_URL', 'https://nightwatch.laravel.com/eu/environments/a2e22eba-0c1a-4407-94d4-5141b72e3981/dashboard'),
];
