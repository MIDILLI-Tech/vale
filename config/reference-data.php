<?php

declare(strict_types=1);

return [
    'requirements' => [
        'min_php'             => '8.3.0',
        'min_mysql'           => '8.4.0',
        'min_mariadb'         => '11.8.0',
        'min_pgsql'           => '18.0.0',
        'required_extensions' => [
            'pdo'        => 'PHP Data Objects (Base database abstraction)',
            'mbstring'   => 'Multibyte string handling',
            'json'       => 'JSON data encoding/decoding',
            'openssl'    => 'SSL/TLS for secure connections',
            'fileinfo'   => 'File type detection for uploads',
            'sodium'     => 'Sodium cryptography extension (required for signature verification)',
            'zip'        => 'Zip compression (required to apply updates)',
        ],
        'recommended_extensions' => [
            'bcmath'         => 'Arbitrary precision mathematics (used for shop totals, VAT, coupons and gateway amounts; falls back to float math when missing)',
            'xml'            => 'XML document parsing and manipulation',
            'dom'            => 'Document Object Model XML extension (recommended for Twig/Dompdf)',
            'gd'             => 'GD Graphics Library (required for thumbnail generation)',
            'intl'           => 'Internationalization support',
            'curl'           => 'Faster HTTP requests for payments, webhooks & APIs (automatic fallback to allow_url_fopen when missing)',
            'Zend OPcache'   => 'Opcode caching for improved PHP performance',
        ],
    ],
    'mail_drivers' => [
        'mailgun' => [
            'name'        => 'Mailgun',
            'signup_url'  => 'https://www.mailgun.com/',
            'api_key_url' => 'https://app.mailgun.com/settings/api_keys',
            'api_url'     => 'https://api.mailgun.net/v3',
        ],
        'sendgrid' => [
            'name'        => 'SendGrid',
            'signup_url'  => 'https://sendgrid.com/',
            'api_key_url' => 'https://app.sendgrid.com/settings/api_keys',
            'api_url'     => 'https://api.sendgrid.com/v3',
        ],
        'resend' => [
            'name'        => 'Resend',
            'signup_url'  => 'https://resend.com/',
            'api_key_url' => 'https://resend.com/api-keys',
            'api_url'     => 'https://api.resend.com',
        ],
        'unosend' => [
            'name'        => 'Unosend',
            'signup_url'  => 'https://unosend.co/',
            'api_key_url' => 'https://app.unosend.co/api-keys',
            'api_url'     => 'https://api.unosend.co',
        ],
    ],
    'currencies' => [
        ['code' => 'AUD', 'label' => 'Australian Dollar', 'symbol' => 'A$'],
        ['code' => 'BRL', 'label' => 'Brazilian Real', 'symbol' => 'R$'],
        ['code' => 'CAD', 'label' => 'Canadian Dollar', 'symbol' => 'CA$'],
        ['code' => 'CHF', 'label' => 'Swiss Franc', 'symbol' => 'CHF'],
        ['code' => 'CNY', 'label' => 'Chinese Yuan', 'symbol' => '¥'],
        ['code' => 'CZK', 'label' => 'Czech Koruna', 'symbol' => 'Kč'],
        ['code' => 'DKK', 'label' => 'Danish Krone', 'symbol' => 'kr'],
        ['code' => 'EUR', 'label' => 'Euro', 'symbol' => '€'],
        ['code' => 'GBP', 'label' => 'British Pound', 'symbol' => '£'],
        ['code' => 'HKD', 'label' => 'Hong Kong Dollar', 'symbol' => 'HK$'],
        ['code' => 'HUF', 'label' => 'Hungarian Forint', 'symbol' => 'Ft'],
        ['code' => 'IDR', 'label' => 'Indonesian Rupiah', 'symbol' => 'Rp'],
        ['code' => 'ILS', 'label' => 'Israeli Shekel', 'symbol' => '₪'],
        ['code' => 'INR', 'label' => 'Indian Rupee', 'symbol' => '₹'],
        ['code' => 'JPY', 'label' => 'Japanese Yen', 'symbol' => '¥'],
        ['code' => 'KRW', 'label' => 'Korean Won', 'symbol' => '₩'],
        ['code' => 'MXN', 'label' => 'Mexican Peso', 'symbol' => '$'],
        ['code' => 'MYR', 'label' => 'Malaysian Ringgit', 'symbol' => 'RM'],
        ['code' => 'NGN', 'label' => 'Nigerian Naira', 'symbol' => '₦'],
        ['code' => 'NOK', 'label' => 'Norwegian Krone', 'symbol' => 'kr'],
        ['code' => 'NZD', 'label' => 'New Zealand Dollar', 'symbol' => 'NZ$'],
        ['code' => 'PHP', 'label' => 'Philippine Peso', 'symbol' => '₱'],
        ['code' => 'PLN', 'label' => 'Polish Zloty', 'symbol' => 'zł'],
        ['code' => 'RON', 'label' => 'Romanian Leu', 'symbol' => 'lei'],
        ['code' => 'SEK', 'label' => 'Swedish Krona', 'symbol' => 'kr'],
        ['code' => 'SGD', 'label' => 'Singapore Dollar', 'symbol' => 'S$'],
        ['code' => 'THB', 'label' => 'Thai Baht', 'symbol' => '฿'],
        ['code' => 'TRY', 'label' => 'Turkish Lira', 'symbol' => '₺'],
        ['code' => 'AED', 'label' => 'UAE Dirham', 'symbol' => 'د.إ'],
        ['code' => 'UAH', 'label' => 'Ukrainian Hryvnia', 'symbol' => '₴'],
        ['code' => 'USD', 'label' => 'US Dollar', 'symbol' => '$'],
        ['code' => 'VND', 'label' => 'Vietnamese Dong', 'symbol' => '₫'],
        ['code' => 'ZAR', 'label' => 'South African Rand', 'symbol' => 'R'],
    ],
    'country_codes' => [
        'AD', 'AE', 'AF', 'AG', 'AL', 'AM', 'AO', 'AR', 'AT', 'AU', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF',
        'BG', 'BH', 'BI', 'BJ', 'BN', 'BO', 'BR', 'BS', 'BT', 'BW', 'BY', 'BZ', 'CA', 'CD', 'CF', 'CG',
        'CH', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ',
        'EC', 'EE', 'EG', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FM', 'FR', 'GA', 'GB', 'GD', 'GE', 'GH', 'GM',
        'GN', 'GQ', 'GR', 'GT', 'GW', 'GY', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IN', 'IQ', 'IR',
        'IS', 'IT', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KW', 'KZ', 'LA', 'LB', 'LC',
        'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MG', 'MH', 'ML', 'MM',
        'MN', 'MR', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NE', 'NG', 'NI', 'NL', 'NO', 'NP',
        'NR', 'NZ', 'OM', 'PA', 'PE', 'PG', 'PH', 'PK', 'PL', 'PT', 'PW', 'PY', 'QA', 'RO', 'RS', 'RU',
        'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SI', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST',
        'SV', 'SY', 'SZ', 'TD', 'TG', 'TH', 'TJ', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
        'UA', 'UG', 'US', 'UY', 'UZ', 'VC', 'VE', 'VN', 'VU', 'WS', 'YE', 'ZA', 'ZM', 'ZW',
    ],
    // BCP 47 / ISO 639-1 language codes — used anywhere a *language* must be
    // picked (subtitle `srclang`, …). Display names are resolved at render time
    // with Intl.DisplayNames in the viewer's own language, so only the codes
    // are stored here. Deliberately separate from country_codes: a subtitle
    // language has nothing to do with the site's content languages.
    'languages' => [
        'af', 'ar', 'bn', 'bg', 'ca', 'zh', 'hr', 'cs', 'da', 'nl', 'en', 'et',
        'fi', 'fr', 'de', 'el', 'he', 'hi', 'hu', 'is', 'id', 'it', 'ja', 'ko',
        'lv', 'lt', 'ms', 'no', 'fa', 'pl', 'pt', 'pa', 'ro', 'ru', 'sr', 'sk',
        'sl', 'es', 'sv', 'tl', 'ta', 'th', 'tr', 'uk', 'ur', 'vi',
    ],
    'timezones' => [
        'America/Sao_Paulo'   => 'São Paulo (UTC-3)',
        'America/New_York'    => 'New York (UTC-5/-4)',
        'America/Chicago'     => 'Chicago (UTC-6/-5)',
        'America/Denver'      => 'Denver (UTC-7/-6)',
        'America/Los_Angeles' => 'Los Angeles (UTC-8/-7)',
        'UTC'                 => 'UTC',
        'Europe/London'       => 'London (UTC+0/+1)',
        'Europe/Berlin'       => 'Berlin (UTC+1/+2)',
        'Europe/Paris'        => 'Paris (UTC+1/+2)',
        'Europe/Madrid'       => 'Madrid (UTC+1/+2)',
        'Europe/Amsterdam'    => 'Amsterdam (UTC+1/+2)',
        'Europe/Rome'         => 'Rome (UTC+1/+2)',
        'Europe/Istanbul'     => 'Istanbul (UTC+3)',
        'Asia/Dubai'          => 'Dubai (UTC+4)',
        'Asia/Karachi'        => 'Karachi (UTC+5)',
        'Asia/Kolkata'        => 'Mumbai/Kolkata (UTC+5:30)',
        'Asia/Bangkok'        => 'Bangkok (UTC+7)',
        'Asia/Singapore'      => 'Singapore (UTC+8)',
        'Asia/Tokyo'          => 'Tokyo (UTC+9)',
        'Australia/Sydney'    => 'Sydney (UTC+10/+11)',
    ],
    'signup_urls' => [
        'payment_gateways' => [
            'stripe' => [
                'name'       => 'Stripe',
                'signup_url' => 'https://dashboard.stripe.com/register',
                'api_urls'   => [
                    'test' => 'https://dashboard.stripe.com/test/apikeys',
                    'live' => 'https://dashboard.stripe.com/apikeys',
                ],
            ],
            'paypal' => [
                'name'       => 'PayPal',
                'signup_url' => 'https://www.paypal.com/bizsignup/',
                'api_urls'   => [
                    'test' => 'https://developer.paypal.com/dashboard/applications/sandbox',
                    'live' => 'https://developer.paypal.com/dashboard/applications/live',
                ],
            ],
            'mollie' => [
                'name'       => 'Mollie',
                'signup_url' => 'https://my.mollie.com/dashboard/signup/',
                'api_urls'   => [
                    'test' => 'https://my.mollie.com/dashboard/developers/api-access-tokens/create',
                    'live' => 'https://my.mollie.com/dashboard/developers/api-access-tokens/create',
                ],
            ],
            'twocheckout' => [
                'name'       => '2Checkout',
                'signup_url' => 'https://www.2checkout.com/signup/',
                'api_urls'   => [
                    'test' => 'https://secure.2checkout.com/cpanel/webhooks_api.php',
                    'live' => 'https://secure.2checkout.com/cpanel/webhooks_api.php',
                ],
            ],
            'checkoutcom' => [
                'name'       => 'Checkout.com',
                'signup_url' => 'https://www.checkout.com/contact-sales',
                'api_urls'   => [
                    'test' => 'https://dashboard.sandbox.checkout.com/developers/keys',
                    'live' => 'https://dashboard.checkout.com/developers/keys',
                ],
            ],
        ],
        'channels' => [
            'google_merchant' => 'https://www.google.com/retail/solutions/merchant-center/',
            'bing'            => 'https://ads.microsoft.com/en-us/solutions/bing-merchant-center',
            'meta'            => 'https://business.facebook.com/commerce',
            'pinterest'       => 'https://www.pinterest.com/business/create/',
            'idealo'          => 'https://partner.idealo.com/',
            'tiktok'          => 'https://seller.tiktokglobalshop.com/',
            'shopify'         => 'https://www.shopify.com/',
            'yandex'          => 'https://partner.market.yandex.ru/',
        ],
        'verification' => [
            'google' => 'https://search.google.com/search-console',
            'bing'   => 'https://www.bing.com/webmasters',
            'yandex' => 'https://webmaster.yandex.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe payment method types
    |--------------------------------------------------------------------------
    | Label map for the chips in the Stripe admin card (Settings › E-commerce
    | › Payment). This is NOT the option list: the methods the merchant can
    | actually accept are fetched from their own Stripe account
    | (payment_method_configurations) and anything Stripe returns is rendered
    | with a humanised label. This map only supplies nicer names plus the
    | pre-fetch / offline fallback chips.
    | `card` already covers Apple Pay / Google Pay, so wallet entries are
    | never offered as separate payment_method_types values.
    */
    'stripe_payment_methods' => [
        'card'       => 'Card (Visa, Mastercard, Amex, Apple Pay, Google Pay…)',
        'link'       => 'Link',
        'paypal'     => 'PayPal',
        'ideal'      => 'iDEAL',
        'bancontact' => 'Bancontact',
        'sepa_debit' => 'SEPA Direct Debit',
        'klarna'     => 'Klarna',
    ],

    /*
    |--------------------------------------------------------------------------
    | Third-party service lists
    |--------------------------------------------------------------------------
    | Centralized list of third-party services displayed across the admin UI.
    | Accessed in templates via the Twig global `services_list`.
    |
    | Sections:
    |   - cron:             Free cron-job services
    |   - uptime:           Free external uptime monitors (deep health checks)
    |   - webhook_test:     Free webhook test/debug endpoints
    |   - exchange_rate:    Free exchange-rate API providers
    */
    'services' => [
        /*
        |----------------------------------------------------------------------
        | Cron job services — Settings → Cron → HTTP endpoint help text
        |----------------------------------------------------------------------
        */
        'cron' => [
            ['name' => 'cron-job.org',       'url' => 'https://cron-job.org'],
            ['name' => 'EasyCron',           'url' => 'https://www.easycron.com'],
            ['name' => 'SetCronJob',         'url' => 'https://www.setcronjob.com'],
        ],

        /*
        |----------------------------------------------------------------------
        | External uptime monitors — Settings → Maintenance → Scheduled Tasks.
        | Point any of these at the deep health URL to alert on ok/degraded.
        |----------------------------------------------------------------------
        */
        'uptime' => [
            ['name' => 'Uptime Kuma',  'url' => 'https://github.com/louislam/uptime-kuma'],
            ['name' => 'Better Stack', 'url' => 'https://betterstack.com/uptime'],
            ['name' => 'UptimeRobot',  'url' => 'https://uptimerobot.com'],
        ],

        /*
        |----------------------------------------------------------------------
        | Webhook test endpoints — Settings → Webhooks → endpoint help text
        |----------------------------------------------------------------------
        */
        'webhook_test' => [
            ['name' => 'httpbin.org',        'url' => 'https://httpbin.org/'],
            ['name' => 'webhook.site',       'url' => 'https://webhook.site/'],
        ],

        /*
        |----------------------------------------------------------------------
        | Exchange rate providers — Settings → E-Commerce → Exchange Rates.
        |   value      — stored in `fx_provider` setting
        |   label      — shown in the <select> dropdown
        |   needs_key  — if true, the API-key input field is revealed
        |   url        — provider website, shown as a link in the help text
        |   desc       — extra hint (optional)
        |----------------------------------------------------------------------
        */
        'exchange_rate' => [
            [
                'value'            => 'frankfurter',
                'label'            => 'Frankfurter (free, no key, 220+ currencies)',
                'needs_key'        => false,
                'url'              => 'https://frankfurter.dev',
                'desc'             => 'Blended daily rates from 100+ central banks. No quotas, no key.',
                'api_url_template' => 'https://api.frankfurter.app/latest?from={base}',
            ],
            [
                'value'            => 'openexchangerates',
                'label'            => 'Open Exchange Rates (free key, 1,000 req/mo)',
                'needs_key'        => true,
                'url'              => 'https://openexchangerates.org',
                'desc'             => '',
                'api_url_template' => 'https://openexchangerates.org/api/latest.json?app_id={key}',
            ],
            [
                'value'            => 'exchangerateapi',
                'label'            => 'ExchangeRate-API (free key, 1,500 req/mo)',
                'needs_key'        => true,
                'url'              => 'https://www.exchangerate-api.com',
                'desc'             => '',
                'api_url_template' => 'https://v6.exchangerate-api.com/v6/{key}/latest/{base}',
            ],
        ],
    ],
    'shipping_carriers' => [
        ['value' => 'araskargo',    'label' => 'Aras Kargo',     'url_template' => 'https://www.araskargo.com.tr/tr/takip?trackingNumber={tracking_number}'],
        ['value' => 'bring',        'label' => 'Bring',          'url_template' => 'https://tracking.bring.com/tracking/{tracking_number}'],
        ['value' => 'chronopost',   'label' => 'Chronopost',     'url_template' => 'https://www.chronopost.fr/tracking-no-cms?trackingNumber={tracking_number}'],
        ['value' => 'colissimo',    'label' => 'Colissimo',      'url_template' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking_number}'],
        ['value' => 'correos',      'label' => 'Correos',        'url_template' => 'https://www.correos.es/ss/Satellite/localizacion?trackingnumber={tracking_number}'],
        ['value' => 'dhl',          'label' => 'DHL',            'url_template' => 'https://www.dhl.com/de-de/home/sendungsverfolgung.html?tracking-id={tracking_number}&submit=1'],
        ['value' => 'dpd',          'label' => 'DPD',            'url_template' => 'https://www.dpd.com/tracking/?parcel={tracking_number}'],
        ['value' => 'fedex',        'label' => 'FedEx',          'url_template' => 'https://www.fedex.com/apps/fedextrack/?tracknumbers={tracking_number}'],
        ['value' => 'gls',          'label' => 'GLS',            'url_template' => 'https://gls-group.com/GUEST/Tracking?trackingNumber={tracking_number}'],
        ['value' => 'hermes',       'label' => 'Hermes',         'url_template' => 'https://www.myhermes.de/empfangen/sendungsverfolgung/?sendungsnummer={tracking_number}'],
        ['value' => 'parcelforce',  'label' => 'ParcelForce',    'url_template' => 'https://www.parcelforce.com/track-trace?trackNumber={tracking_number}'],
        ['value' => 'postnl',       'label' => 'PostNL',         'url_template' => 'https://postnl.nl/track-trace/?B={tracking_number}'],
        ['value' => 'ptt',          'label' => 'PTT Kargo',      'url_template' => 'https://gonderitakip.ptt.gov.tr/Track?key={tracking_number}'],
        ['value' => 'royalmail',    'label' => 'Royal Mail',     'url_template' => 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}'],
        ['value' => 'schenker',     'label' => 'Schenker',       'url_template' => 'https://www.dbschenker.com/global/track-and-trace?waybill={tracking_number}'],
        ['value' => 'suratkargo',   'label' => 'Sürat Kargo',    'url_template' => 'https://www.suratkargo.com.tr/Takip?code={tracking_number}'],
        ['value' => 'tnt',          'label' => 'TNT',            'url_template' => 'https://www.tnt.com/express/de_de/site/shipping-tools/tracking.html?searchType=con&cons={tracking_number}'],
        ['value' => 'ups',          'label' => 'UPS',            'url_template' => 'https://www.ups.com/track?tracknum={tracking_number}'],
        ['value' => 'usps',         'label' => 'USPS',           'url_template' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking_number}'],
        ['value' => 'yurticikargo', 'label' => 'Yurtiçi Kargo',  'url_template' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code={tracking_number}'],
    ],
    'sso_providers' => [
        'google'    => [
            'label'       => 'Google',
            'url'         => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'   => 'https://oauth2.googleapis.com/token',
            'user_url'    => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'signup_url'  => 'https://console.cloud.google.com/auth/clients/create',
        ],
        'microsoft' => [
            'label'       => 'Microsoft',
            'url'         => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url'   => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'user_url'    => 'https://graph.microsoft.com/v1.0/me',
            'signup_url'  => 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
        ],
        'twitter'   => [
            'label'       => 'Twitter / X',
            'url'         => 'https://twitter.com/i/oauth2/authorize',
            'token_url'   => 'https://api.twitter.com/2/oauth2/token',
            'user_url'    => 'https://api.twitter.com/2/users/me?user.fields=profile_image_url,name',
            'signup_url'  => 'https://developer.x.com/en/portal/dashboard',
        ],
        'github'    => [
            'label'       => 'GitHub',
            'url'         => 'https://github.com/login/oauth/authorize',
            'token_url'   => 'https://github.com/login/oauth/access_token',
            'user_url'    => 'https://api.github.com/user',
            'emails_url'  => 'https://api.github.com/user/emails',
            'signup_url'  => 'https://github.com/settings/applications/new',
        ],
        'facebook'  => [
            'label'       => 'Facebook',
            'url'         => 'https://www.facebook.com/v12.0/dialog/oauth',
            'token_url'   => 'https://graph.facebook.com/v12.0/oauth/access_token',
            'user_url'    => 'https://graph.facebook.com/me?fields=id,name,email,picture',
            'signup_url'  => 'https://developers.facebook.com/apps/',
        ],
        'steam'     => [
            'label'       => 'Steam',
            'url'         => 'https://steamcommunity.com/openid/login',
            'token_url'   => '',
            'user_url'    => '',
            'api_url'     => 'https://api.steampowered.com',
            'signup_url'  => 'https://steamcommunity.com/dev',
        ],
    ],
    'captcha_urls' => [
        'recaptcha' => [
            'signup_url' => 'https://www.google.com/recaptcha/admin/create',
            'frame_src'  => 'https://www.google.com',
            'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
            'script_url' => 'https://www.google.com/recaptcha/api.js',
        ],
        'hcaptcha' => [
            'signup_url' => 'https://dashboard.hcaptcha.com/sites/new',
            'frame_src'  => 'https://newassets.hcaptcha.com',
            'verify_url' => 'https://api.hcaptcha.com/siteverify',
            'script_url' => 'https://js.hcaptcha.com/1/api.js',
        ],
        'turnstile' => [
            'signup_url' => 'https://www.cloudflare.com/products/turnstile/',
            'frame_src'  => 'https://challenges.cloudflare.com',
            'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'script_url' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        ],
    ],
    'payment_api' => [
        'stripe'      => ['api_base' => 'https://api.stripe.com'],
        'paypal'      => [
            'api_base_sandbox' => 'https://api-m.sandbox.paypal.com',
            'api_base_live'    => 'https://api-m.paypal.com',
            // Shopper redirect targets after POST /checkout (302 to the
            // approve URL). Merged into the form-action CSP directive —
            // without these the browser blocks the PayPal handoff.
            'checkout_src' => 'https://www.paypal.com https://www.sandbox.paypal.com',
        ],
        'mollie'      => [
            'api_base'     => 'https://api.mollie.com/v2',
            'checkout_src' => 'https://www.mollie.com https://checkout.mollie.com',
        ],
        'checkoutcom' => [
            'api_base_sandbox' => 'https://api.sandbox.checkout.com',
            'api_base_live'    => 'https://api.checkout.com',
            'checkout_src'     => 'https://pay.checkout.com',
        ],
        'twocheckout' => [
            'api_base'     => 'https://api.2checkout.com/rest/6.0',
            'checkout_url' => 'https://secure.2checkout.com/checkout',
            'checkout_src' => 'https://secure.2checkout.com',
        ],
    ],
    'media_embeds' => [
        'youtube' => [
            'embed_url' => 'https://www.youtube.com/embed/',
            'frame_src' => 'https://www.youtube.com',
        ],
        'youtube_nocookie' => [
            'frame_src' => 'https://www.youtube-nocookie.com',
        ],
        'vimeo' => [
            'embed_url' => 'https://player.vimeo.com/video/',
            'frame_src' => 'https://player.vimeo.com',
        ],
        'stripe_js' => [
            'frame_src'  => 'https://js.stripe.com',
            'script_url' => 'https://js.stripe.com/v3/',
        ],
        'disqus' => [
            'script_suffix' => '.disqus.com/embed.js',
            'frame_src'     => 'https://disqus.com https://*.disqus.com',
        ],
        'giscus' => [
            'frame_src'     => 'https://giscus.app',
        ],
        'facebook_comments' => [
            'sdk_url'   => 'https://connect.facebook.net/{locale}/sdk.js'
                . '#xfbml=1&version=v25.0&appId={app_id}&autoLogAppEvents=1',
            'frame_src' => 'https://*.facebook.com',
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Media file type catalog
    |--------------------------------------------------------------------------
    | Single source of truth for uploadable file types. `config/media.php`
    | derives allowed_extensions + allowed_mime_types from this catalog, and
    | MediaUploadService / MediaScanService use it for the extension ↔ MIME
    | consistency check. Adding a new type means adding ONE line here (plus a
    | serving entry in the root index.php static map when it must render).
    |
    | Key   — allowed lowercase file extension (no leading dot)
    | Value — accepted content detections. Several formats are detected
    |         inconsistently across libmagic versions (rar versions, tar,
    |         OOXML containers), so an extension may list multiple MIME types.
    |
    | Never add executable/server-side extensions (php, phtml, htaccess, exe,
    | sh, …) — MediaFileTypes::BLOCKED_EXTENSIONS is enforced in code
    | regardless of this catalog. Never add `text/html` (stored XSS: uploads
    | are served from the same origin).
    */
    /*
    |--------------------------------------------------------------------------
    | Media serving types (Content-Type by extension)
    |--------------------------------------------------------------------------
    | Content-Type sent to the browser for uploads/assets served as static
    | files (root index.php fallback handler + web server configs mirror
    | these). Unlike `media_file_types` (validation, multiple detections per
    | extension) this is the ONE type the browser receives per extension.
    | Unknown extensions are served without an explicit Content-Type plus
    | X-Content-Type-Options: nosniff, so they can never be sniffed into an
    | executable type. Never map an extension to text/html (stored XSS).
    */
    'media_serve_types' => [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'ico'  => 'image/x-icon',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg'  => 'image/svg+xml',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska',
        'mp3'  => 'audio/mpeg',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'rtf'  => 'application/rtf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'  => 'application/zip',
        'rar'  => 'application/x-rar-compressed',
        '7z'   => 'application/x-7z-compressed',
        'gz'   => 'application/gzip',
        'tar'  => 'application/x-tar',
        'bz2'  => 'application/x-bzip2',
        'srt'  => 'application/x-subrip',
        'sub'  => 'text/plain',
        'vtt'  => 'text/vtt',
    ],

    'media_file_types' => [
        // Images
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg'  => 'image/svg+xml',

        // Video
        'mp4'  => 'video/mp4',
        'webm' => ['video/webm', 'video/x-matroska'],
        'mkv'  => 'video/x-matroska',

        // Audio
        'mp3' => 'audio/mpeg',

        // Documents
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'rtf'  => ['text/rtf', 'application/rtf', 'text/plain'],
        'docx' => [
            'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'xlsx' => [
            'application/zip',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],

        // Archives (octet-stream keeps header-less/older-libmagic detections working)
        'zip' => 'application/zip',
        'rar' => [
            'application/x-rar',
            'application/x-rar-compressed',
            'application/vnd.rar',
        ],
        '7z'  => ['application/x-7z-compressed', 'application/octet-stream'],
        'gz'  => ['application/gzip', 'application/x-gzip'],
        'tar' => ['application/x-tar', 'application/octet-stream'],
        'bz2' => ['application/x-bzip2', 'application/octet-stream'],

        // Subtitles
        'srt' => ['application/x-subrip', 'text/plain'],
        'sub' => ['application/x-subrip', 'text/plain'],
        'vtt' => ['text/vtt', 'text/plain'],
    ],

    'geo' => [
        'ip2location' => [
            'country' => [
                'download_url' => 'https://download.ip2location.com/lite/IP2LOCATION-LITE-DB1.BIN.ZIP',
                'bin_file'     => 'IP2LOCATION-LITE-DB1.BIN',
                'local_file'   => 'ip2location-db1.bin',
            ],
            'city' => [
                'download_url'  => null,
                'download_page' => 'https://www.ip2location.com/free/city-multilingual',
                'signup_url'    => 'https://www.ip2location.com/register?id=lite',
                'bin_file'      => 'IP2LOCATION-LITE-DB3.BIN',
                'local_file'    => 'ip2location-db3.bin',
            ],
        ],
        'maxmind' => [
            'city' => [
                'download_url'  => null,
                'download_page' => 'https://dev.maxmind.com/geoip/geolite2-free-geolocation-data',
                'signup_url'    => 'https://www.maxmind.com/en/geolite2/signup',
                'local_file'    => 'GeoLite2-City.mmdb',
            ],
        ],
        'dbip' => [
            'city' => [
                'download_url'  => null,
                'download_page' => 'https://db-ip.com/db/lite.php',
                'signup_url'    => 'https://db-ip.com/db/lite.php',
                'local_file'    => 'dbip-city-lite.mmdb',
            ],
        ],
    ],
    'analytics_excluded_prefixes' => [
        '/admin',
        '/login',
        '/logout',
        '/api/',
        '/favicon.ico',
        '/robots.txt',
        '/ai-robots.txt',
        '/sitemap.xml',
        '/webhooks/',
        '/cron/',
        '/downloads/',
        '/t/',
        '/assets/',
        '/preview/',
    ],
    'documentation' => [
        'url' => 'https://docs.getvale.org',
    ],
    'import_export_urls' => [
        'shopify' => 'https://admin.shopify.com/products',
        'etsy'    => 'https://www.etsy.com/your/shops/me/download',
        'ebay'    => 'https://www.ebay.com/sh/reports/',
        'amazon'  => 'https://sellercentral.amazon.de/listing/reports',
    ],
    'dashboard_news' => [
        'feed_url'     => 'http://getvale.org/blog/feed.xml?category=Vale&tag=news',
        'view_all_url' => 'http://getvale.org/category/vale',
    ],
];
