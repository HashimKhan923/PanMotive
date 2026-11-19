<?php

namespace App\Services\Shopify;

/** The store's access is gone (uninstalled, revoked, refresh failed) — the seller must connect again. */
class ShopifyAuthException extends \RuntimeException {}
