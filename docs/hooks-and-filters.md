# Hooks and Filters

Control webhook behavior, payloads, and delivery using WordPress hooks and filters.

## Actions

### `wpwf_register_webhooks`

Register custom webhooks with the framework registry.

**Parameters:**
- `$registry` (`Webhook_Registry`) - The registry instance

```php
add_action( 'wpwf_register_webhooks', function ( Webhook_Registry $registry ): void {
    $registry->register( new My_Custom_Webhook() );
} );
```

See [Custom Webhooks](./custom-webhooks.md) for detailed webhook creation.

### `wpwf_webhook_success`

Fired when a webhook is successfully delivered.

**Parameters:**
- `$url` (string) - The webhook URL
- `$payload` (array<string,mixed>) - The webhook payload
- `$response` (array|WP_Error) - The HTTP response
- `$webhook` (`Webhook`) - The webhook instance

```php
add_action( 'wpwf_webhook_success', function ( string $url, array $payload, array $response, Webhook $webhook ): void {
    error_log( "Webhook '{$webhook->get_name()}' delivered: {$url}" );
}, 10, 4 );
```

### `wpwf_webhook_failed`

Fired when a webhook fails after all retries are exhausted, before blocking decision.

**Parameters:**
- `$url` (string) - The webhook URL
- `$response` (array|WP_Error) - The response from wp_remote_post
- `$failure_count` (int) - Current consecutive failure count
- `$max_failures` (int) - Maximum failures before blocking
- `$webhook` (`Webhook`) - The webhook instance

```php
add_action( 'wpwf_webhook_failed', function ( string $url, array $response, int $failure_count, int $max_failures, Webhook $webhook ): void {
    error_log( "Webhook '{$webhook->get_name()}' failed ({$failure_count}/{$max_failures}): {$url}" );
}, 10, 5 );
```

### `wpwf_webhook_blocked`

Fired when a webhook URL is blocked due to reaching the consecutive failure threshold.

**Parameters:**
- `$url` (string) - The webhook URL
- `$response` (array|WP_Error) - The response from wp_remote_post
- `$max_failures` (int) - Maximum failures threshold
- `$webhook` (`Webhook`) - The webhook instance

```php
add_action( 'wpwf_webhook_blocked', function ( string $url, array $response, int $max_failures, Webhook $webhook ): void {
    // Send Slack notification
    wp_remote_post( 'https://hooks.slack.com/services/...', array(
        'body' => wp_json_encode( array( 'text' => "Webhook blocked: {$url}" ) ),
    ) );
}, 10, 4 );
```

See [Notifications](./notifications.md) for built-in notification handlers.

## Filters

### `wpwf_payload`

Filter webhook payloads before scheduling. Return empty array to prevent webhook.

**Parameters:**
- `$payload` (array<string,mixed>) - The webhook payload data
- `$entity` (string) - The entity type (post, term, user, meta)
- `$id` (int|string) - The entity ID

**Returns:** array<string,mixed>

```php
// Prevent delete webhooks
add_filter( 'wpwf_payload', function ( array $payload, string $entity, int|string $id ): array {
    if ( 'delete' === $payload['action'] ) {
        return array();
    }
    return $payload;
}, 10, 3 );

// Enrich user webhooks
add_filter( 'wpwf_payload', function ( array $payload, string $entity, int|string $id ): array {
    if ( 'user' !== $entity ) {
        return $payload;
    }
    $user = get_userdata( (int) $id );
    if ( $user ) {
        $payload['email'] = $user->user_email;
    }
    return $payload;
}, 10, 3 );
```

### `wpwf_url`

Filter the webhook URL before scheduling.

**Parameters:**
- `$url` (string) - The webhook URL
- `$entity` (string) - The entity type
- `$id` (int|string) - The entity ID

**Returns:** string

```php
// Route by entity type
add_filter( 'wpwf_url', function ( string $url, string $entity, int|string $id ): string {
    return match ( $entity ) {
        'post' => 'https://api.example.com/webhooks/posts',
        'user' => 'https://api.example.com/webhooks/users',
        default => $url,
    };
}, 10, 3 );
```

### `wpwf_headers`

Filter HTTP headers before sending webhook requests.

**Parameters:**
- `$headers` (array<string,string>) - The HTTP headers
- `$entity` (string) - The entity type
- `$id` (int|string) - The entity ID
- `$webhook` (`Webhook`) - The webhook instance

**Returns:** array<string,string>

```php
// Add authentication
add_filter( 'wpwf_headers', function ( array $headers, string $entity, int|string $id, Webhook $webhook ): array {
    $token = get_option( 'api_token' );
    if ( is_string( $token ) && '' !== $token ) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    return $headers;
}, 10, 4 );
```

### `wpwf_excluded_meta`

Exclude specific meta keys from triggering webhooks.

**Parameters:**
- `$excluded_keys` (array<int,string>) - Meta keys to exclude
- `$meta_key` (string) - The current meta key
- `$meta_type` (string) - The meta type (post, term, user)
- `$object_id` (int) - The object ID

**Returns:** array<int,string>

**Default excluded:** `_edit_lock`, `_edit_last`, `_acf_changed`, `_acf_cache_*`

```php
add_filter( 'wpwf_excluded_meta', function ( array $excluded_keys, string $meta_key, string $meta_type, int $object_id ): array {
    $excluded_keys[] = '_my_internal_field';
    return $excluded_keys;
}, 10, 4 );
```

### `wpwf_failure_notification_email`

Filter failure notification email data. Return `false` to prevent email.

**Parameters:**
- `$email_data` (array{recipient: string, subject: string, message: string, headers: array, url: string, error_message: string, response: mixed})
- `$url` (string) - The webhook URL that failed
- `$response` (mixed) - The response from wp_remote_post

**Returns:** array|false

```php
// Custom recipient
add_filter( 'wpwf_failure_notification_email', function ( array $email_data, string $url, mixed $response ): array {
    $email_data['recipient'] = 'webhooks@example.com';
    return $email_data;
}, 10, 3 );

// Disable email notifications
add_filter( 'wpwf_failure_notification_email', fn() => false );
```

### `wpwf_max_consecutive_failures`

Filter the maximum consecutive failures threshold before URL blocking.

**Parameters:**
- `$max_failures` (int) - The threshold
- `$webhook_name` (string) - The webhook name

**Returns:** int

```php
add_filter( 'wpwf_max_consecutive_failures', function ( int $max_failures, string $webhook_name ): int {
    return 'critical_webhook' === $webhook_name ? 3 : $max_failures;
}, 10, 2 );
```

### `wpwf_timeout`

Filter webhook request timeout in seconds.

**Parameters:**
- `$timeout` (int) - The timeout (1-300)
- `$webhook_name` (string) - The webhook name

**Returns:** int

```php
add_filter( 'wpwf_timeout', function ( int $timeout, string $webhook_name ): int {
    return 'bulk_sync_webhook' === $webhook_name ? 120 : $timeout;
}, 10, 2 );
```

### `wpwf_retry_base_time`

Filter the base time (seconds) for exponential backoff. Default: 60.

**Parameters:**
- `$base_time` (int) - Base time in seconds
- `$webhook_name` (string) - The webhook name
- `$retry_count` (int) - Current retry attempt

**Returns:** int

### `wpwf_retry_delay`

Filter the final calculated retry delay.

**Parameters:**
- `$delay` (int) - Calculated delay in seconds
- `$retry_count` (int) - Current retry attempt
- `$webhook_name` (string) - The webhook name

**Returns:** int

```php
// Static 5-minute delay
add_filter( 'wpwf_retry_delay', fn() => 300 );
```

## Payload Structure

Standard payload sent with all webhooks:

```json
{
  "action": "create|update|delete",
  "entity": "post|term|user|meta",
  "id": 123,
  "post_type": "post"
}
```

**Entity-specific fields:**
- **Post**: `post_type`
- **Term**: `taxonomy`
- **User**: `roles` (array)
- **Meta**: `acf_field_key`, `acf_field_name` (if ACF field)

## Filter Priority

Filters are applied in this order:

1. `wpwf_payload` - Modify or prevent payload
2. `wpwf_url` - Customize webhook URL
3. `wpwf_headers` - Customize HTTP headers
4. `wpwf_excluded_meta` - Filter meta keys (meta webhooks only)
