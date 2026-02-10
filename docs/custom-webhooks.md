# Custom Webhooks

Create custom webhook implementations using the registry pattern.

## Creating a Webhook

Extend the abstract `Webhook` class and implement `init()`:

```php
class Custom_Webhook extends \Citation\WP_Webhook_Framework\Webhook {
    
    public function __construct() {
        parent::__construct( 'my_custom_webhook' );
        
        $this->max_retries( 3 )
             ->max_consecutive_failures( 5 )
             ->timeout( 30 )
             ->webhook_url( 'https://api.example.com/custom' )
             ->headers( array( 'Authorization' => 'Bearer token123' ) );
    }
    
    public function init(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }
        
        add_action( 'my_custom_action', array( $this, 'handle_action' ) );
    }
    
    public function handle_action( array $data ): void {
        $this->emit( 'action_triggered', 'custom', $data['id'], array(
            'custom_data' => $data,
            'timestamp'   => time(),
        ) );
    }
}
```

## Registering Webhooks

```php
add_action( 'wpwf_register_webhooks', function ( Webhook_Registry $registry ): void {
    $registry->register( new Custom_Webhook() );
} );
```

## Configuration Methods

| Method | Description | Default |
|--------|-------------|---------|
| `max_retries(int)` | Retry attempts per failed event | 0 |
| `max_consecutive_failures(int)` | Failures before blocking (0 disables) | 10 |
| `timeout(int)` | HTTP timeout in seconds (1-300) | 10 |
| `enabled(bool)` | Enable/disable webhook | true |
| `webhook_url(string)` | Custom endpoint URL | None |
| `headers(array)` | Additional HTTP headers | [] |
| `notifications(array)` | Notification handlers to enable | [] |

See [Configuration](./configuration.md) for detailed options.

## Plugin Integration Examples

### WooCommerce Orders

```php
class WooCommerce_Order_Webhook extends \Citation\WP_Webhook_Framework\Webhook {
    
    public function __construct() {
        parent::__construct( 'woocommerce_orders' );
        
        $this->max_consecutive_failures( 3 )
             ->timeout( 45 )
             ->webhook_url( 'https://api.example.com/woocommerce/orders' );
    }
    
    public function init(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }
        
        add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
    }
    
    public function on_new_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        $this->emit( 'created', 'woocommerce_order', $order_id, array(
            'total'  => $order->get_total(),
            'status' => $order->get_status(),
        ) );
    }
    
    public function on_status_changed( int $order_id, string $old_status, string $new_status ): void {
        $this->emit( 'status_changed', 'woocommerce_order', $order_id, array(
            'old_status' => $old_status,
            'new_status' => $new_status,
        ) );
    }
}
```

### Contact Form 7

```php
class CF7_Webhook extends \Citation\WP_Webhook_Framework\Webhook {
    
    public function __construct() {
        parent::__construct( 'cf7_submissions' );
        $this->timeout( 20 );
    }
    
    public function init(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }
        
        add_action( 'wpcf7_mail_sent', array( $this, 'on_submit' ) );
    }
    
    public function on_submit( $contact_form ): void {
        $submission = \WPCF7_Submission::get_instance();
        if ( ! $submission ) {
            return;
        }
        
        $this->emit( 'submitted', 'cf7_form', $contact_form->id(), array(
            'form_title' => $contact_form->title(),
            'data'       => $submission->get_posted_data(),
        ) );
    }
}
```

### Gravity Forms

```php
class Gravity_Forms_Webhook extends \Citation\WP_Webhook_Framework\Webhook {
    
    public function __construct() {
        parent::__construct( 'gravity_forms' );
        $this->timeout( 30 );
    }
    
    public function init(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }
        
        add_action( 'gform_after_submission', array( $this, 'on_submit' ), 10, 2 );
    }
    
    public function on_submit( array $entry, array $form ): void {
        $this->emit( 'submitted', 'gravity_form', $form['id'], array(
            'form_title' => $form['title'],
            'entry_id'   => $entry['id'],
        ) );
    }
}
```

## Multiple Endpoints for the Same Entity

Register additional instances of a built-in webhook class with a unique name.
Each instance has its own URL, retry policy, timeout, and failure tracking:

```php
add_action( 'wpwf_register_webhooks', function ( Webhook_Registry $registry ): void {
    $analytics = new \Citation\WP_Webhook_Framework\Webhooks\Post_Webhook( 'post_analytics' );
    $analytics->webhook_url( 'https://analytics.example.com/posts' )
              ->timeout( 10 );
    $registry->register( $analytics );
} );
```

This is recommended over modifying the built-in webhooks whenever different
endpoints require different configuration (timeouts, retries, headers, etc.).

See [Configuration](./configuration.md#multiple-endpoints-for-the-same-entity) for a detailed example.

## Conditional Registration

Check plugin availability before registering:

```php
add_action( 'wpwf_register_webhooks', function ( Webhook_Registry $registry ): void {
    if ( class_exists( 'WooCommerce' ) ) {
        $registry->register( new WooCommerce_Order_Webhook() );
    }
    
    if ( defined( 'WPCF7_VERSION' ) ) {
        $registry->register( new CF7_Webhook() );
    }
    
    if ( class_exists( 'GFForms' ) ) {
        $registry->register( new Gravity_Forms_Webhook() );
    }
} );
```

## Registry Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `register(Webhook)` | void | Register and initialize |
| `get(string)` | Webhook\|null | Get by name |
| `has(string)` | bool | Check existence |
| `get_all()` | array | Get all webhooks |
| `get_enabled()` | array | Get enabled only |
| `unregister(string)` | bool | Remove webhook |

## Webhook Statefulness

Webhook instances are singletons - **never store per-emission data** on the instance.

```php
// CORRECT: Pass data directly to emit()
public function handle( array $data ): void {
    $this->emit( 'action', 'entity', $data['id'], array( 'data' => $data ) );
}

// WRONG: Storing on instance causes race conditions
private array $current_data; // NEVER DO THIS
```

See [Webhook Statefulness](./webhook-statefulness.md) for details.

## Best Practices

1. **Check `is_enabled()`** in `init()` before registering hooks
2. **Check plugin existence** before registering integration webhooks
3. **Keep webhooks stateless** - pass data to `emit()`, don't store on instance
4. **Use meaningful names** - lowercase with underscores
5. **Set appropriate timeouts** - consider endpoint response times
