---
title: Examples
description: Practical bot.php setups and command examples for SharkordPHP.
---

This page covers practical patterns you'll use in a real bot. Each example is self-contained and ready to drop into your project.

## bot.php Setups

### Minimal Bot

The simplest possible setup — connects, logs in, and responds to `!ping`.

```php
<?php

    declare(strict_types=1);

    require __DIR__ . '/vendor/autoload.php';

    use Sharkord\Sharkord;
    use Sharkord\Events;
    use Sharkord\Models\Message;
    use Sharkord\Models\User;

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $sharkord = new Sharkord(
        config: [
            'identity' => $_ENV['SHARKORD_IDENTITY'],
            'password' => $_ENV['SHARKORD_PASSWORD'],
            'host'     => $_ENV['SHARKORD_HOST'],
        ],
    );

    $sharkord->on(Events::READY, function(User $bot): void {
        echo "Ready as {$bot->name}!\n";
    });

    $sharkord->on(Events::MESSAGE_CREATE, function(Message $message): void {
        if ($message->content === '!ping') {
            $message->reply('Pong!');
        }
    });

    $sharkord->run();
```

---

### Bot With Command Folder

The recommended setup for any bot with more than one or two commands. Drop `.php` files into `Commands/` and they are registered automatically.

```php
<?php

    declare(strict_types=1);

    require __DIR__ . '/vendor/autoload.php';

    use Sharkord\Sharkord;
    use Sharkord\Events;
    use Sharkord\Models\Message;
    use Sharkord\Models\User;

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

    $sharkord->commands->loadFromDirectory(__DIR__ . '/Commands');

    $sharkord->on(Events::READY, function(User $bot) use ($sharkord): void {
        $sharkord->logger->notice("Logged in as {$bot->name}!");
    });

    $sharkord->on(Events::MESSAGE_CREATE, function(Message $message) use ($sharkord): void {
        if (preg_match('/^!([a-zA-Z]{2,})(?:\s+(.*))?$/', $message->content, $matches)) {
            $sharkord->commands->handle($message, $matches);
        }
    });

    $sharkord->run();
```

---

### Bot With a Scheduled Task

Uses the `Scheduler` to broadcast a message to a channel every 60 seconds. The timer is registered inside the `READY` event so the bot is fully connected before any messages are sent.

```php
<?php

    declare(strict_types=1);

    require __DIR__ . '/vendor/autoload.php';

    use Sharkord\Sharkord;
    use Sharkord\Events;
    use Sharkord\Models\User;

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $sharkord = new Sharkord(
        config: [
            'identity' => $_ENV['SHARKORD_IDENTITY'],
            'password' => $_ENV['SHARKORD_PASSWORD'],
            'host'     => $_ENV['SHARKORD_HOST'],
        ],
    );

    $sharkord->on(Events::READY, function(User $bot) use ($sharkord): void {

        $sharkord->logger->notice("Ready as {$bot->name}!");

        // Post a status message to #general every 60 seconds.
        $sharkord->scheduler->every(60.0, 'heartbeat', function() use ($sharkord): void {
            $sharkord->channels->get('general')?->sendMessage('Still running!');
        });

    });

    $sharkord->run();
```

---

### Bot That Listens to Multiple Events

```php
<?php

    declare(strict_types=1);

    require __DIR__ . '/vendor/autoload.php';

    use Sharkord\Sharkord;
    use Sharkord\Events;
    use Sharkord\Models\Message;
    use Sharkord\Models\User;
    use Sharkord\Models\Channel;

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $sharkord = new Sharkord(
        config: [
            'identity' => $_ENV['SHARKORD_IDENTITY'],
            'password' => $_ENV['SHARKORD_PASSWORD'],
            'host'     => $_ENV['SHARKORD_HOST'],
        ],
    );

    // Greet users when they come online.
    $sharkord->on(Events::USER_JOIN, function(User $user) use ($sharkord): void {
        $sharkord->channels->get('general')?->sendMessage("Welcome online, {$user->name}!");
    });

    // Log when a message is deleted.
    $sharkord->on(Events::MESSAGE_DELETE, function(array $data) use ($sharkord): void {
        $sharkord->logger->notice("Message {$data['id']} was deleted.");
    });

    // Announce new channels.
    $sharkord->on(Events::CHANNEL_CREATE, function(Channel $channel) use ($sharkord): void {
        $sharkord->channels->get('general')?->sendMessage("New channel created: #{$channel->name}");
    });

    $sharkord->run();
```

---

## Command Examples

All of the following are standalone files that go inside your `Commands/` folder.

### Ping

A simple health-check command.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Ping implements CommandInterface {

        public function getName(): string        { return 'ping'; }
        public function getDescription(): string { return 'Checks if the bot is responsive.'; }
        public function getPattern(): string     { return '/^ping$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {
            $message->channel->sendTyping();
            $message->reply('Pong!');
        }

    }
```

---

### Say

Repeats whatever the user types after `!say`.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Say implements CommandInterface {

        public function getName(): string        { return 'say'; }
        public function getDescription(): string { return 'Repeats a message.'; }
        public function getPattern(): string     { return '/^say$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {

            if (empty($args)) {
                $message->reply('Usage: !say <text>');
                return;
            }

            $message->channel->sendMessage($args);

        }

    }
```

---

### User Info

Displays information about a user. Accepts a name or ID as an argument, or defaults to the message author.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class UserInfo implements CommandInterface {

        public function getName(): string        { return 'userinfo'; }
        public function getDescription(): string { return 'Displays info about a user.'; }
        public function getPattern(): string     { return '/^userinfo$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {

            $user = empty($args) ? $message->author : $sharkord->users->get($args);

            if (!$user) {
                $message->reply("No user found with that name or ID.");
                return;
            }

            $message->reply(
                "**{$user->name}** (ID: {$user->id}) — Status: {$user->status}"
            );

        }

    }
```

---

### Announce

Sends a message to a specific channel. Only callable by the server owner.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Announce implements CommandInterface {

        public function getName(): string        { return 'announce'; }
        public function getDescription(): string { return 'Sends an announcement to #announcements.'; }
        public function getPattern(): string     { return '/^announce$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {

            if (!$message->author->isOwner()) {
                $message->reply("Only the server owner can post announcements.");
                return;
            }

            if (empty($args)) {
                $message->reply("Usage: !announce <text>");
                return;
            }

            $channel = $sharkord->channels->get('announcements');

            if (!$channel) {
                $message->reply("Couldn't find an #announcements channel.");
                return;
            }

            $channel->sendMessage($args)->then(function() use ($message): void {
                $message->reply("Announcement posted!");
            });

        }

    }
```

---

### Send a File

Attaches a file to a message using `MessageBuilder`.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Builders\MessageBuilder;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Logs implements CommandInterface {

        public function getName(): string        { return 'logs'; }
        public function getDescription(): string { return 'Posts the latest log file.'; }
        public function getPattern(): string     { return '/^logs$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {

            if (!$message->author->isOwner()) {
                $message->reply("Only the server owner can retrieve logs.");
                return;
            }

            $logPath = '/var/log/app.log';

            if (!file_exists($logPath)) {
                $message->reply("No log file found.");
                return;
            }

            $builder = MessageBuilder::create()
                ->setReply($message->author)
                ->setContent('Here is the latest log file:')
                ->addFile($logPath, 'text/plain');

            $message->channel->sendMessage($builder)
                ->catch(function(\Throwable $e) use ($message): void {
                    $message->reply("Couldn't attach the log: " . $e->getMessage());
                });

        }

    }
```

---

### React to a Message

Adds an emoji reaction to the message that triggered the command.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Thumbs implements CommandInterface {

        public function getName(): string        { return 'thumbs'; }
        public function getDescription(): string { return 'Reacts to your message with 👍.'; }
        public function getPattern(): string     { return '/^thumbs$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {

            $message->react('👍')
                ->catch(function(\Throwable $e) use ($sharkord): void {
                    $sharkord->logger->error("React failed: " . $e->getMessage());
                });

        }

    }
```

---

### DM a User

Sends a direct message to the user who triggered the command.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Secret implements CommandInterface {

        public function getName(): string        { return 'secret'; }
        public function getDescription(): string { return 'Sends you a secret message via DM.'; }
        public function getPattern(): string     { return '/^secret$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {

            $message->author->sendDm("This is a secret only you can see!")
                ->then(function() use ($message): void {
                    $message->reply("Check your DMs!");
                })
                ->catch(function(\Throwable $e) use ($message): void {
                    $message->reply("Couldn't send you a DM: " . $e->getMessage());
                });

        }

    }
```

---

### Help

Lists all registered commands and their descriptions. This command reads directly from the router, so it stays up to date automatically as you add commands.

```php
<?php

    use Sharkord\Commands\CommandInterface;
    use Sharkord\Models\Message;
    use Sharkord\Sharkord;

    class Help implements CommandInterface {

        public function getName(): string        { return 'help'; }
        public function getDescription(): string { return 'Lists all available commands.'; }
        public function getPattern(): string     { return '/^help$/'; }

        public function handle(Sharkord $sharkord, Message $message, string $args, array $matches): void {

            $lines = [];

            foreach ($sharkord->commands->getCommands() as $command) {
                $lines[] = "!{$command->getName()} — {$command->getDescription()}";
            }

            if (empty($lines)) {
                $message->reply("No commands are registered.");
                return;
            }

            $message->reply(implode("\n", $lines));

        }

    }
```
