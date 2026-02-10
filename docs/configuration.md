# Configuration

Configure webhook behavior using methods, filters, and the registry.

## Webhook Configuration Methods

Configure individual webhooks using chainable methods.

| Method | Description | Default |
|--------|-------------|---------|
| `webhook_url(string)` | Custom endpoint URL | None |
| `max_consecutive_failures(int)` | Failures before blocking (0 disables) | 10 |
| `max_retries(int)` | Retry attempts per failed event | 0 |
| `timeout(int)` | HTTP timeout in seconds (1-300) | 10 |
| `enabled(bool)` | Enable/disable webhook | true |
| `headers(array)` | Custom HTTP headers | [] |
| `notifications(array)` | Enable notification handlers | [] |

### Chainable Configuration

```php
$webhook->webhook_url( 'https://api.example.com' )
        ->max_consecutive_failures( 5 )
        ->max_retries( 3 )
        ->timeout( 60 )
        ->headers( array( 'Authorization' => 'Bearer token' ) )
        ->notifications( array( 'blocked' ) );
```

## Registry Configuration

Access and configure webhooks through the registry:

```php
$registry = Service_Provider::get_registry();

$post_webhook = $registry->get( 'post' );
if ( null !== $post_webhook ) {
    $post_webhook->webhook_url( 'https://api.example.com/posts' )
                 ->max_consecutive_failures( 3 )
                 ->timeout( 30 );
}
```

## Notification Configuration

Notifications are **opt-in** per webhook. Enable specific handlers:

```php
add_action( 'wpwf_register_webhooks', function ( Webhook_Registry $registry ): void {
    $post_webhook = $registry->get( 'post' );
    if ( null !== $post_webhook ) {
        $post_webhook->notifications( array( 'blocked' ) );
    }
} );
```

**Built-in handlers:**
- `'blocked'` - Email notification when webhook URL is blocked

See [Notifications](./notifications.md) for custom handlers.

## Filter-Based Configuration

Use WordPress filters for dynamic configuration. See [Hooks and Filters](./hooks-and-filters.md) for complete reference.

**Key filters:**
- `wpwf_url` - Dynamic URL routing
- `wpwf_headers` - Dynamic headers
- `wpwf_payload` - Payload modification
- `wpwf_excluded_meta` - Meta key filtering

## Options-Based Configuration

Store configuration in WordPress options for admin control:

```php
add_action( 'wpwf_register_webhooks', function ( Webhook_Registry $registry ): void {
    $enabled = get_option( 'webhook_enabled' );
    if ( ! $enabled ) {
        return;
    }
    
    $api_url   = get_option( 'webhook_api_url' );
    $api_token = get_option( 'webhook_api_token' );
    
    if ( ! is_string( $api_url ) || ! is_string( $api_token ) ) {
        return;
    }
    
    $webhook = new Custom_Webhook();
    $webhook->webhook_url( $api_url )
            ->headers( array( 'Authorization' => 'Bearer ' . $api_token ) );
    
    $registry->register( $webhook );
} );
```

## Multiple Endpoints for the Same Entity

Register additional webhook instances instead of modifying the built-in ones.
Each instance has its own URL, retry policy, timeout, and failure tracking:

```php
add_action( 'wpwf_register_webhooks', function ( Webhook_Registry $registry ): void {
    // Analytics endpoint - fast timeout, no retries
    $analytics = new \Citation\WP_Webhook_Framework\Webhooks\Post_Webhook( 'post_analytics' );
    $analytics->webhook_url( 'https://analytics.example.com/posts' )
              ->timeout( 10 );
    $registry->register( $analytics );

    // CRM endpoint - generous timeout, retries enabled
    $crm = new \Citation\WP_Webhook_Framework\Webhooks\Post_Webhook( 'post_crm' );
    $crm->webhook_url( 'https://crm.example.com/webhook' )
        ->max_retries( 5 )
        ->timeout( 60 )
        ->notifications( array( 'blocked' ) );
    $registry->register( $crm );
} );
```

Each instance registers its own WordPress hooks and dispatches independently.
This keeps configuration, failure tracking, and retry logic fully isolated.

## Configuration Priority

### URL Priority
1. Webhook-specific `webhook_url()` method
2. `wpwf_url` filter

### Headers Priority
1. Webhook-specific `headers()` method
2. `wpwf_headers` filter (merged)

### Payload Priority
1. Original payload from entity handler
2. `wpwf_payload` filter

## Failure Monitoring Defaults

| Setting | Default |
|---------|---------|
| Failure Threshold | 10 consecutive failures |
| Block Duration | 1 hour (auto-unblock) |
| Email Notification | On first block (if enabled) |

See [Failure Handling](./failure-handling.md) for configuration.

## Security Considerations

### API Key Management

Never hardcode API keys:

```php
// wp-config.php
define( 'WEBHOOK_API_KEY', 'your-secret-key' );

// In code
$webhook->headers( array( 'Authorization' => 'Bearer ' . WEBHOOK_API_KEY ) );
```

### URL Validation

```php
add_filter( 'wpwf_url', function ( string $url, string $entity, int|string $id ): string {
    // Require HTTPS in production
    if ( 'production' === WP_ENV && ! str_starts_with( $url, 'https://' ) ) {
        return '';
    }
    return $url;
}, 10, 3 );
```

### Payload Sanitization

```php
add_filter( 'wpwf_payload', function ( array $payload, string $entity, int|string $id ): array {
    unset( $payload['password'], $payload['secret_key'] );
    return $payload;
}, 10, 3 );
```
