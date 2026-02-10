# Configuration

Configure webhook behavior using constants, methods, and filters.

## Configuration Constants

Define in `wp-config.php` for global webhook behavior.

### `WP_WEBHOOK_FRAMEWORK_URL`

Sets the webhook endpoint URL for all webhooks. Always takes precedence over filters and webhook-specific URLs.

```php
define( 'WP_WEBHOOK_FRAMEWORK_URL', 'https://api.example.com/webhook' );
```

**Precedence order:**
1. `WP_WEBHOOK_FRAMEWORK_URL` constant (highest)
2. Webhook-specific URL via `webhook_url()` method
3. `wpwf_url` filter
4. Exception thrown if none set

### Environment-Based Configuration

```php
if ( 'production' === WP_ENV ) {
    define( 'WP_WEBHOOK_FRAMEWORK_URL', 'https://api.example.com/webhook' );
} else {
    define( 'WP_WEBHOOK_FRAMEWORK_URL', 'https://staging-api.example.com/webhook' );
}
```

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

### Meta Emission Modes

The `Meta_Webhook` supports three emission modes via `emission_mode()`:

| Constant | Behavior | Default |
|----------|----------|---------|
| `Meta_Webhook::EMIT_META` | Only emit the meta-entity webhook | No |
| `Meta_Webhook::EMIT_BOTH` | Emit meta-entity webhook AND parent entity update | **Yes** |
| `Meta_Webhook::EMIT_ENTITY` | Only emit the parent entity update | No |

```php
use Citation\WP_Webhook_Framework\Webhooks\Meta_Webhook;

$registry = Service_Provider::get_registry();

$meta_webhook = $registry->get( 'meta' );
if ( $meta_webhook instanceof Meta_Webhook ) {
    // Meta changes only trigger parent entity updates (e.g. post),
    // no separate meta-entity webhooks are emitted.
    $meta_webhook->emission_mode( Meta_Webhook::EMIT_ENTITY );
}
```

In `EMIT_ENTITY` mode the Dispatcher deduplicates on `(url, action, entity, id)`,
so rapid meta changes on the same post collapse into a single delivery.

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

## Configuration Priority

### URL Priority
1. `WP_WEBHOOK_FRAMEWORK_URL` constant
2. Webhook-specific `webhook_url()` method
3. `wpwf_url` filter

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
