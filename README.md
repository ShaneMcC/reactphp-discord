# reactphp-discord
ReactPHP Based Discord Bot Library

# Example

Simple example below:

```php
#!/usr/bin/env php
<?php
	require_once(__DIR__ . '/vendor/autoload.php');

	use shanemcc\discord\DiscordClient;
	use React\EventLoop\Factory;

	// Client ID of our bot.
	// (Create a new Application at https://discordapp.com/developers/applications/me)
	$clientID = '123456789123456789';
	$clientSecret = 'abcdefghijklmnopqrstuvwxyz012345';

	// Token for our Bot user for the above application.
	$token = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456';

	// Testing Server and channel. We will send a message here when we start up.
	$testServer = '123456789123456789';
	$testChannel = '123456789123456789';

	// Create the DiscordClient and let the user know how to invite the bot
	$client = new DiscordClient($clientID, $clientSecret, $token);
	echo 'Discord invite link: https://discordapp.com/oauth2/authorize?client_id=' . $clientID . '&scope=bot&permissions=536931328', "\n";

	// If we want to manage our own event loop we can, if we call connect()
	// without having given the bot a LoopInterface it will create and start
	// one itself.
	$loop = React\EventLoop\Factory::create();
	$client->setLoopInterface($loop);

	// Servers are not immediately available when the bot starts up, so wait
	// until we get the GUILD_CREATE message for the server we want to send the
	// test message to.
	//
	// This will also mean we will send the message to the channel as soon as we
	// are first invited to the server (if we are online) rather than failing to
	// send it if we were just trying to send it blind.
	$client->on('event.GUILD_CREATE', function(DiscordClient $client, int $shard, String $event, Array $data) use ($testServer, $testChannel) {
		// Check if this is the server we want.
		if ($data['id'] == $testServer) {
			// Check if the channel is known on the server.
			if ($client->validChannel($testServer, $testChannel)) {
				echo 'Sending test message.', "\n";

				// Send the message
				$client->sendChannelMessage($testServer, $testChannel, 'Bot Started.');
			}
		}
	});

	// We can also respond to things, so lets add an !echo command.
	//
	// We react whenever a message is created and respond to the same channel
	// with a reply.
	$client->on('event.MESSAGE_CREATE', function(DiscordClient $client, int $shard, String $event, Array $data) {
		// Don't respond to our own messages.
		if ($data['author']['id'] == $client->getMyInfo()['id']) { return; }

		// Get the command/message
		$bits = explode(' ', $data['content'], 2);
		if (count($bits) < 2) { return; }
		list($command, $message) = $bits;

		// If the command is the right one, deal with it:
		if (strtolower($command) == '!echo') {
			$message = '<@' . $data['author']['id'] . '>' . ' said: ' . $message;

			// We can respond to either server messages or private messages.
			//
			// Technically private messages are channels but the library deals
			// with them separately as it internally handles opening/closing of
			// channels needed for private messaging and allows us to just send
			// messages to user ids rather than needing to get a channel for
			// them manually.
			if (isset($data['guild_id'])) {
				$client->sendChannelMessage($data['guild_id'], $data['channel_id'], $message);
			} else {
				$client->sendPersonMessage($data['author']['id'], $message);
			}
		}
	});

	// Start a connection.
	// If we didn't pass a LoopInterface then one will be created and started
	// automatically when we do this.
	$client->connect();

	// We manage our own LoopInterface, so start it here.
	$loop->run();
```

# How to handle exceptions
```
        $client->on('DiscordClient.throwable', function (DiscordClient $client, \Throwable $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });
```
