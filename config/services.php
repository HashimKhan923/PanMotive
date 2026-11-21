<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'easypost' => [
        'key' => env('EASYPOST_API_KEY'),
        'webhook_secret' => env('EASYPOST_WEBHOOK_SECRET'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base' => rtrim(env('OPENAI_API_BASE', 'https://api.openai.com/v1'), '/'),
    ],

    // Which provider AiClient uses. 'auto' = OpenAI if its key is set, else Anthropic.
    'ai' => [
        'provider' => env('AI_PROVIDER', 'auto'),
    ],

    // "Continue with Google" for shoppers (Google Cloud → OAuth client, type "Web application").
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // The shopping app's own Google client ids (iOS, Android), comma separated: tokens from
        // native "Sign in with Google" are issued to these.
        'app_client_ids' => env('GOOGLE_APP_CLIENT_IDS'),
    ],

    // "Sign in with Apple" in the shopping app: the app's bundle id (e.g. com.panmotive.app), comma separated.
    'apple' => [
        'client_ids' => env('APPLE_CLIENT_IDS', 'com.panmotive.app'),
        // Apple Developer team id: switches on links that open the app (apple-app-site-association).
        'team_id' => env('APPLE_TEAM_ID'),
    ],

    // Android App Links: the app's package and its signing certificate's SHA-256 (from Google Play).
    'android' => [
        'package' => env('ANDROID_PACKAGE', 'com.panmotive.app'),
        'cert_sha256' => env('ANDROID_CERT_SHA256'),
    ],

    // Push notifications to the shopping app go through Expo's push service.
    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    // Facebook Pages + Instagram auto-posting (one Meta app). Without an app id + secret,
    // sellers get the share kit only. META_CONFIG_ID = a Facebook Login for Business configuration.
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'config_id' => env('META_CONFIG_ID'),
        'graph_version' => env('META_GRAPH_VERSION', 'v27.0'),
    ],

    // SMS marketing. Without all three, the SMS channel is hidden — texts are never faked.
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],


    // "Connect Shopify" — the Panmotive app made in Shopify's Dev Dashboard (Client ID + Secret).
    // Redirect URL to allow there: https://sell.<domain>/integrations/shopify/callback
    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_inventory'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),
    ],

];
