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
	
	error_reporting(E_ALL);

	require __DIR__ . '/vendor/autoload.php';

	use Sharkord\Sharkord;
	use Sharkord\Models\Message;
	use Sharkord\Models\User;
	use Sharkord\Events;

	/*
	* Supports pulling environment variables from .env file as well as Docker container.
	* Hardcode your values at your own peril
	* Example uses SHARKORD_IDENTITY, SHARKORD_PASSWORD and SHARKORD_HOST env vars.
	*/
	$sharkord = new Sharkord(
		config: [
			'identity' 	=> $_ENV['SHARKORD_IDENTITY'] ?? 'your-username',
 			'password'	=> $_ENV['SHARKORD_PASSWORD'] ?? 'your-password',
 			'host'		=> $_ENV['SHARKORD_HOST'] ?? 'server.example.com',
		],
		logLevel: 'Notice',
		reconnect: true,
		maxReconnectAttempts: 5	
	);
	
	/*
	* If you want to use dynamically loaded commands as per the examples directory
	* uncomment the below along with the preg_match if statement further down
	*/
	# $sharkord->commands->loadFromDirectory(__DIR__ . '/Commands');

	$sharkord->on(Events::READY, function(User $bot) use ($sharkord) {
 		$sharkord->logger->notice("Logged in as {$bot->name} and ready to chat!");
	});

	$sharkord->on(Events::MESSAGE_CREATE, function(Message $message) use ($sharkord) {
		
		$sharkord->logger->notice(sprintf(
			"[#%s] %s: %s",
			$message->channel->name,
			$message->author->name,
			$message->content
		));
		
		/*
		* Uncomment if you're using dynamically loaded Commands.
		* Make sure to delete the ping/pong if statement.
		*/
		# if (preg_match('/^!([a-zA-Z]{2,})(?:\s+(.*))?$/', $message->content, $matches)) {
		#	 $sharkord->commands->handle($message, $matches);
		# }
		
		if ($message->content == '!ping') $message->reply('Pong!');
		
	});

	$sharkord->run();

?>
```

## Next Steps

- Browse the [API Reference](/api) to explore available models, managers, and collections.
- Read the [Guides](/guides) for deeper topics like permissions, reactions, and DMs.
- Check the [Examples](/examples) for ready-to-use command patterns.
