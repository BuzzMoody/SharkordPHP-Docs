---
title: Getting Started
description: How to install SharkordPHP and run your first bot locally.
---

SharkordPHP is a PHP bot framework for [Sharkord](https://github.com/Sharkord/sharkord) built on [ReactPHP](https://reactphp.org/). This guide walks you through setting up a working bot from scratch on your local machine.

## Requirements

- PHP **8.5** or higher
- [Composer](https://getcomposer.org/)
- A Sharkord account with bot credentials

To check your PHP version, run:

```bash
php -v
```

## Installation

Create a folder for your bot and install SharkordPHP via Composer:

```bash
mkdir my-bot
cd my-bot
composer require buzzmoody/sharkordphp
```

Composer will create a `vendor/` folder and a `composer.json` file automatically.

## Project Structure

A minimal bot only needs two things: an entry file and a folder for commands.

```
my-bot/
├── vendor/          ← Created by Composer, do not edit
├── Commands/        ← Your command files go here
├── .env             ← Your credentials (never commit this)
├── bot.php          ← Your entry file
└── composer.json
```

## Storing Your Credentials

Never hardcode your username or password directly in PHP files. Instead, store them in a `.env` file in your project root:

```ini
SHARKORD_IDENTITY=your-bot-username
SHARKORD_PASSWORD=your-bot-password
SHARKORD_HOST=your.server.com
```

SharkordPHP ships with `vlucas/phpdotenv`, which loads this file automatically. Add `.env` to your `.gitignore` so it is never committed to version control:

```
/vendor/
.env
```

## Creating the Entry File

Create `bot.php` in your project root:

```php
<?php

    declare(strict_types=1);

    error_reporting(E_ALL);

    require __DIR__ . '/vendor/autoload.php';

    use Sharkord\Sharkord;
    use Sharkord\Events;
    use Sharkord\Models\Message;
    use Sharkord\Models\User;

    // Load credentials from .env
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $sharkord = new Sharkord(
        config: [
            'identity' => $_ENV['SHARKORD_IDENTITY'],
            'password' => $_ENV['SHARKORD_PASSWORD'],
            'host'     => $_ENV['SHARKORD_HOST'],
        ],
        logLevel:            'Notice',
        reconnect:           true,
        maxReconnectAttempts: 5
    );

    // Auto-load all command files from the Commands folder
    $sharkord->commands->loadFromDirectory(__DIR__ . '/Commands');

    $sharkord->on(Events::READY, function(User $bot) use ($sharkord): void {
        $sharkord->logger->notice("Logged in as {$bot->name} and ready to go!");
    });

    $sharkord->on(Events::MESSAGE_CREATE, function(Message $message) use ($sharkord): void {

        // Route any !command messages to the command system
        if (preg_match('/^!([a-zA-Z]{2,})(?:\s+(.*))?$/', $message->content, $matches)) {
            $sharkord->commands->handle($message, $matches);
        }

    });

    $sharkord->run();
```

## Creating Your First Command

Create a file at `Commands/Ping.php`:

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Ping implements CommandInterface {

        public function getName(): string {
            return 'ping';
        }

        public function getDescription(): string {
            return 'Checks if the bot is responsive.';
        }

        public function getPattern(): string {
            return '/^ping$/';
        }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {
            $message->reply('Pong!');
        }

    }
```

Any `.php` file you drop into the `Commands/` folder that implements `CommandInterface` will be discovered and registered automatically — no additional wiring required.

## Running the Bot

From your project root:

```bash
php bot.php
```

You should see your bot come online in Sharkord and respond to `!ping` in any channel it has access to.

## Logging

The log level is controlled by the `logLevel` parameter when constructing `Sharkord`. The available levels from most to least verbose are:

| Level | When to use |
|---|---|
| `debug` | Development — logs every raw WebSocket payload |
| `info` | Detailed operational messages |
| `notice` | Normal but significant events (recommended default) |
| `warning` | Something unexpected but recoverable happened |
| `error` | Something failed |

Set it to `'debug'` while developing to see every event and RPC call:

```php
$sharkord = new Sharkord(
    config: [...],
    logLevel: 'debug',
);
```

## Reconnection

By default, the bot will automatically attempt to reconnect if the connection drops, up to 5 times using exponential backoff (2s, 4s, 8s, 16s, 32s). You can adjust this:

```php
$sharkord = new Sharkord(
    config: [...],
    reconnect:           true,
    maxReconnectAttempts: 10,
);
```

Set `reconnect: false` to disable reconnection entirely, which is useful during development when you want the process to exit cleanly on disconnect.

## Next Steps

- Read the [Examples](/guides/examples) page for practical command patterns
- Browse the [API Reference](/api) for full documentation on every class
