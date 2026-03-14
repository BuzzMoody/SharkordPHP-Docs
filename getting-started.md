---
title: Getting Started
description: Install SharkordPHP and write your first bot in minutes.
---

SharkordPHP is a ReactPHP-based framework for building bots on the [Sharkord](https://github.com/Sharkord/sharkord) chat platform. It handles WebSocket connections, event dispatching, and entity caching so you can focus on writing commands and logic.

## Requirements

- PHP 8.5 or higher
- Composer

## Installation

```bash
composer require buzzmoody/sharkordphp
```

## Quick Start

```php
<?php

    declare(strict_types=1);

    require __DIR__ . '/vendor/autoload.php';

    use Sharkord\Sharkord;
    use Sharkord\Models\Message;

    $sharkord = new Sharkord(
        config: [
            'identity' => $_ENV['CHAT_USERNAME'],
            'password' => $_ENV['CHAT_PASSWORD'],
            'host'     => $_ENV['CHAT_HOST'],
        ],
        logLevel:            'Notice',
        reconnect:           true,
        maxReconnectAttempts: 5,
    );

    $sharkord->on('ready', function () use ($sharkord): void {
        $sharkord->logger->notice("Logged in as {$sharkord->bot->name}!");
    });

    $sharkord->on('message', function (Message $message): void {
        if ($message->content === '!ping') {
            $message->reply('Pong!');
        }
    });

    $sharkord->run();
```

## Next Steps

- Browse the [API Reference](/api) to explore available models, managers, and collections.
- Read the [Guides](/guides) for deeper topics like permissions, reactions, and DMs.
- Check the [Examples](/examples) for ready-to-use command patterns.
