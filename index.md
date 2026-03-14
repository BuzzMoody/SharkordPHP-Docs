---
title: API Reference
description: Auto-generated reference for all SharkordPHP classes, managers, collections, and models.
---

The API reference is generated automatically from the DocBlocks in the [SharkordPHP](https://github.com/BuzzMoody/SharkordPHP) source code on every push to `main`. It is always in sync with the latest release.

## Structure

**Models** are read/write data objects representing Sharkord entities (messages, users, channels, etc.). Most models expose methods for actions like `edit()`, `delete()`, or `reply()`.

**Managers** are the primary interface for interacting with the Sharkord API. They're accessed directly off the `$sharkord` instance (e.g. `$sharkord->channels`, `$sharkord->users`).

**Collections** are typed, array-accessible caches that managers delegate storage to. You rarely need to interact with them directly, but they're documented here for completeness.

**Commands** contains the `CommandInterface` contract and the `CommandRouter` used to register and dispatch bot commands.

## Async returns

Nearly all manager and model methods that communicate with the server return a `React\Promise\PromiseInterface`. Chain `.then()` to handle the resolved value and `.catch()` to handle errors.

```php
$sharkord->channels->get('general')?->fetch()->then(function (\Sharkord\Models\Channel $channel): void {
    echo "Topic: {$channel->topic}\n";
})->catch(function (\Throwable $e): void {
    echo "Error: {$e->getMessage()}\n";
});
```
