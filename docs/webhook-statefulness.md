# Webhook Statefulness Guide

## Overview

Webhook instances in the WP Webhook Framework are registered as singletons in the registry and must remain stateless. This design prevents race conditions when multiple WordPress hooks fire rapidly and ensures webhook instances remain reusable configuration objects.

## Key Principle

**Webhook instances should not store per-emission data.**

### Stateless (Configuration)

Set during `init()` and apply to all emissions from the webhook:

```php
public function init(): void {
    $this->max_consecutive_failures(3)
         ->timeout(60)
         ->webhook_url('https://api.example.com/endpoint')
         ->headers(['Authorization' => 'Bearer token123']);
}
```

Configuration methods:
- `max_consecutive_failures()` - Retry policy
- `timeout()` - Request timeout
- `webhook_url()` - Custom endpoint URL
- `headers()` - Static headers (auth tokens, API keys)

### Stateful (Emission Data)

Pass directly to `emit()` as parameters - never store on the instance:

```php
public function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
    $action = $update ? 'update' : 'create';
    $payload = $this->post_handler->prepare_payload($post_id);
    
    // Pass payload directly to emit() - don't store on $this
    $this->emit($action, 'post', $post_id, $payload);
}
```

Dynamic data:
- Payloads - Entity data that changes per emission
- Dynamic headers - Headers that vary per emission (not static auth headers)

## Why This Matters

### Race Condition Example (Bad - Don't Do This)

```php
// BAD: Storing data on instance properties creates race conditions
private array $current_payload = array();

public function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
    $this->current_payload = $this->post_handler->prepare_payload($post_id);
    $this->emit('update', 'post', $post_id);
}

// If two posts are saved rapidly:
// Thread 1: current_payload = post_123
// Thread 2: current_payload = post_456  <- Overwrites payload for post_123
// Thread 1: emit() <- Sends wrong payload!
```

### Correct Implementation (Good)

```php
// GOOD: Pass payload directly as parameter
public function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
    $payload = $this->post_handler->prepare_payload($post_id);
    $this->emit('update', 'post', $post_id, $payload);
}

// Each emission is self-contained - no shared state
```

## Exception: Request-Scoped Static State for Meta Deduplication

`Meta_Webhook` uses a static property `$processed_meta_updates` to track which `entity:id:meta_key` combinations have already been processed during the current request. This is intentional shared state - not per-instance data.

**Why:** Plugins like ACF or Meta Box fire their own hook (e.g. `acf/update_value`) *and* the underlying WordPress `update_post_metadata` filter. Both paths lead to `on_meta_update()`. Without deduplication, the same field change would emit two webhooks.

**How it works:**

1. The first hook that reaches `on_meta_update()` marks the key as processed and emits the webhook
2. The second hook checks the processed set and skips emission
3. Deletions clear the processed key so a delete webhook always fires, even if an update for the same key was already processed
4. The set is cleared on `shutdown`

This approach keeps all early-exit checks (equality, exclusions) running immediately on the first path. The second path is a cheap `isset()` + bail. It works generically with any plugin that fires both its own hook and the underlying WordPress meta filter.

## Summary

| Data Type | Storage Location | Set During | Example |
|-----------|------------------|------------|---------|
| Configuration | Instance properties | `init()` | `timeout()`, `max_consecutive_failures()`, static headers |
| Emission Data | Method parameters | Hook callbacks | Payloads, dynamic headers |

Following this pattern ensures your webhook implementations are thread-safe, predictable, and maintainable.

See [Custom Webhooks](./custom-webhooks.md) for implementation examples.
