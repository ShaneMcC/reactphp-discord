<?php
	namespace shanemcc\discord;

	use React\HttpClient\Client as HTTPClient;
	use React\EventLoop\Factory as EventLoopFactory;
	use React\EventLoop\LoopInterface;

	use Ratchet\Client\Connector as RatchetConnector;
	use Ratchet\Client\WebSocket;
	use Ratchet\RFC6455\Messaging\MessageInterface as RatchetMessageInterface;

	use Evenement\EventEmitter;

	use \Exception;
	use \Throwable;

	/**
	 * Discord.
	 */
	class DiscordClient extends EventEmitter {
		/** @var LoopInterface LoopInterface that we are being run from. */
		private $loopInterface;

		/** @var EventEmitter Internal event emitter. */
		private $internalEmitter;

		private $clientID = '';
		private $clientSecret = '';
		private $token = '';

		private $httpClient = null;
		private $gwInfo = [];
		private $myInfo = [];
		private $shards = [];
		private $slowMessageQueue = [];

		private $guilds = [];
		private $personChannels = [];

		private $connectTime = 0;

		private $debug = false;

		private $disconnecting = false;

		/**
		 * Create a new IRCClient.
		 */
		public function __construct($clientID, $clientSecret, $token) {
			$this->internalEmitter = new EventEmitter();

			$this->clientID = $clientID;
			$this->clientSecret = $clientSecret;
			$this->token = $token;

			// Connection Handling
			$this->internalEmitter->on('shard.closed', [$this, 'shardClosed']);
			$this->internalEmitter->on('shard.message', [$this, 'gotMessage']);

			// OPCODE handling
			$this->internalEmitter->on('opcode.0', [$this, 'gotDispatch']);
			$this->internalEmitter->on('opcode.1', [$this, 'gotHeartBeat']);
			// ...
			$this->internalEmitter->on('opcode.7', [$this, 'gotReconnectRequest']);
			// ...
			$this->internalEmitter->on('opcode.9', [$this, 'gotInvalidSession']);
			$this->internalEmitter->on('opcode.10', [$this, 'gotHello']);
			$this->internalEmitter->on('opcode.11', [$this, 'gotHeartBeatAck']);

			// General Server Events
			$this->internalEmitter->on('event.READY', [$this, 'handleReadyEvent']);

			// Guild Events
			$this->internalEmitter->on('event.GUILD_CREATE', [$this, 'handleGuildCreate']);
			$this->internalEmitter->on('event.GUILD_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_DELETE', [$this, 'handleGuildDelete']);
			$this->internalEmitter->on('event.GUILD_ROLE_CREATE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_ROLE_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_ROLE_DELETE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_MEMBER_ADD', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_MEMBERS_CHUNK', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_MEMBER_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_MEMBER_REMOVE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_BAN_ADD', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_BAN_REMOVE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_EMOJIS_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.GUILD_INTEGRATIONS_UPDATE', [$this, 'noop']);

			// Channel Events
			$this->internalEmitter->on('event.CHANNEL_CREATE', [$this, 'handleChannelCreate']);
			$this->internalEmitter->on('event.CHANNEL_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.CHANNEL_DELETE', [$this, 'handleChannelDelete']);
			$this->internalEmitter->on('event.CHANNEL_PINS_UPDATE', [$this, 'noop']);

			// Message Events
			$this->internalEmitter->on('event.MESSAGE_CREATE', [$this, 'noop']);
			$this->internalEmitter->on('event.MESSAGE_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.MESSAGE_DELETE', [$this, 'noop']);
			$this->internalEmitter->on('event.MESSAGE_DELETE_BULK', [$this, 'noop']);

			$this->internalEmitter->on('event.MESSAGE_REACTION_ADD', [$this, 'noop']);
			$this->internalEmitter->on('event.MESSAGE_REACTION_REMOVE', [$this, 'noop']);
			$this->internalEmitter->on('event.MESSAGE_REACTION_REMOVE_ALL', [$this, 'noop']);

			// Other events.
			$this->internalEmitter->on('event.TYPING_START', [$this, 'noop']);
			$this->internalEmitter->on('event.PRESENCE_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.USER_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.VOICE_STATE_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.VOICE_SERVER_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.WEBHOOKS_UPDATE', [$this, 'noop']);
			$this->internalEmitter->on('event.MESSAGE_ACK', [$this, 'noop']);
		}

		private function reset() {
			$this->disconnecting = false;
			$this->httpClient = null;
			$this->gwInfo = [];
			$this->myInfo = [];
			$this->shards = [];
			$this->guilds = [];
			$this->personChannels = [];
			$this->connectTime = 0;
			$this->slowMessageQueue = [];
			$this->slowMessageTimerID = '';
			$this->cleanupTimerID = '';
		}

		private function doEmit(String $event, array $params = [], $internalOnly = false) {
			// Internal Emitter for our own events that users can't
			// add/remove things on.
			try {
				$this->internalEmitter->emit($event, $params);
			} catch (Throwable $t) { $this->showThrowable($t); }

			if ($internalOnly) { return; }

			// The public emitter includes a reference to us as the first
			// param.
			try {
				array_unshift($params, $this);

				$this->emit($event, $params);
			} catch (Throwable $t) { $this->showThrowable($t); }
		}

		/**
		 * Display exception information.
		 *
		 * @param Throwable $throwable The exception
		 */
		public function showThrowable(Throwable $throwable) {
			echo 'Throwable caught.', "\n";
			echo "\t", $throwable->getMessage(), "\n";
			foreach (explode("\n", $throwable->getTraceAsString()) as $t) {
				echo "\t\t", $t, "\n";
			}
		}

		/**
		 * Set the message loop to use.
		 *
		 * @param LoopInterface $loopInterface
		 * @return self
		 */
		public function setLoopInterface(LoopInterface $loopInterface) {
			if ($this->httpClient !== null) { throw new Exception('Already connected.'); }

			$this->loopInterface = $loopInterface;

			return $this;
		}

		/**
		 * Get the LoopInterface being used by this client.
		 *
		 * @return LoopInterface our loopInterface
		 */
		public function getLoopInterface(): LoopInterface {
			return $this->loopInterface;
		}


		/**
		 * Set if we are in debugging mode or not.
		 *
		 * @param bool $debug
		 * @return self
		 */
		public function setDebug(bool $debug) {
			$this->debug = $debug;

			return $this;
		}

		/**
		 * Get if we are in debugging mode or not.
		 *
		 * @return bool debugging mode enabled or not
		 */
		public function getDebug(): bool {
			return $this->debug;
		}

		/**
		 * Connect to Discord.
		 *
		 * @param Callable $error Callback if there is an error connecting.
		 */
		public function connect() {
			if ($this->httpClient !== null) { throw new Exception('Already connected.'); }

			$this->reset();
			$this->connectTime = time();

			$startLoop = false;
			if ($this->loopInterface == null) {
				$startLoop = true;
				$this->loopInterface = EventLoopFactory::create();
			}

			$this->httpClient = new HTTPClient($this->loopInterface);

			$this->getRequest('/users/@me', function ($data, $headers) {
				$this->myInfo = json_decode($data, true);
			})->end();

			$this->getRequest('/gateway/bot', function ($data, $headers) {
				$this->gwInfo = json_decode($data, true);
				if (isset($this->gwInfo['shards'])) {
					$this->connectToGateway($this->gwInfo['shards']);
				} else {
					echo 'Unknown response from API: ', $data, "\n";
				}
			})->end();

			$slowMessageTimerID = bin2hex(random_bytes(16));
			$this->slowMessageTimerID = $slowMessageTimerID;
			$this->getLoopInterface()->addPeriodicTimer(6, function($timer) use ($slowMessageTimerID) {
				if ($slowMessageTimerID != $this->slowMessageTimerID) { $this->getLoopInterface()->cancelTimer($timer); }

				if (!empty($this->slowMessageQueue)) {
					$message = array_shift($this->slowMessageQueue);

					if (is_array($message)) {
						call_user_func_array([$this, 'sendShardMessage'], $message);
					} else if (is_callable($message)) {
						call_user_func_array($message, []);
					}
				}
			});

			$cleanupTimerID =  bin2hex(random_bytes(16));
			$this->cleanupTimerID = $cleanupTimerID;
			$this->getLoopInterface()->addPeriodicTimer(60, function($timer) use ($cleanupTimerID) {
				if ($cleanupTimerID != $this->cleanupTimerID) { $this->getLoopInterface()->cancelTimer($timer); }
				$this->doCleanup();
			});

			if ($startLoop) { $this->getLoopInterface()->run(); }
		}

		public function getMyInfo(): Array {
			return $this->myInfo;
		}

		public function disconnect() {
			$this->disconnecting = true;

			foreach ($this->shards as &$item) {
				$item['conn']->close();
				unset($item['heartbeat_interval']);
			}

			$this->reset();
		}

		public function doCleanup() {
			// Cleanup old personChannels that we don't need any more.
			foreach ($this->personChannels as $id => $data) {
				if ($data['time'] < (time() - 300)) {
					$this->getRequest('/channels/' . $data['id'], null, 'DELETE')->end();
				}
			}
		}

		private function getRequest(String $endpoint, ?Callable $gotResponse = null, String $type = 'GET', Array $headers = []) {
			$headers['User-Agent'] = 'shanemcc/reactphp-discord (https://github.com/shanemcc/reactphp-discord, 0.1)';
			$headers['Authorization'] = 'Bot ' . $this->token;

			$url = 'https://discordapp.com/api/v6' . $endpoint;

			$request = $this->httpClient->request($type, $url, $headers);

			if (is_callable($gotResponse)) {
				$request->on('response', function ($response) use ($gotResponse) {
					$headers = $response->getHeaders();
				    $data = '';

					$response->on('data', function ($chunk) use (&$data) {
						$data .= $chunk;
					});

					$response->on('end', function() use ($headers, &$data, $gotResponse) {
						$gotResponse($data, $headers);
					});
				});
			}

			$request->on('error', function (\Exception $e) {
			    $this->showThrowable($e);
			});

			return $request;
		}

		private function connectToGateway($shards = 1) {
			foreach ($this->shards as &$item) {
				$item['conn']->close();
				unset($item['heartbeat_interval']);
			}

			$this->shards = [];

			for ($shard = 0; $shard < $shards; $shard++) {
				$this->connectShard($shard);
			}
		}

		private function connectShard(int $shard) {
			$connector = new RatchetConnector($this->getLoopInterface());

			if ($this->debug) { echo 'Connecting shard: ', $shard, "\n"; }

			$connector($this->gwInfo['url'] . '/?v=6&encoding=json')->then(function(WebSocket $conn) use ($shard) {
				if ($this->debug) { echo 'Connected shard: ', $shard, "\n"; }
				$this->shards[$shard] = ['conn' => $conn, 'seq' => null, 'ready' => false];
				$this->doEmit('shard.connected', [$shard]);

				$conn->on('message', function(RatchetMessageInterface $msg) use ($shard, $conn) {
					$this->doEmit('shard.message', [$shard, $msg], true);
				});

				$conn->on('close', function($code = null, $reason = null) use ($shard) {
					$this->doEmit('shard.closed', [$shard, $code, $reason]);
				});

			}, function(\Exception $e) use ($shard) {
				$this->doEmit('shard.connect.error', [$shard]);

				if ($this->debug) { echo 'Could not connect shard ', $shard, ': ', $e->getMessage(), "\n"; }

				$this->getLoopInterface()->addTimer(30, function() use ($shard) {
					if ($this->debug) { echo 'Trying again to connect shard: ', $shard, "\n"; }
					$this->connectShard($shard);
				});
			});
		}

		public function sendShardMessage(int $shard, int $opcode, $data, $sequence = null, $eventName = null) {
			$sendData = ['op' => $opcode, 'd' => $data];
			if ($opcode == 0) {
				$sendData['s'] = $sequence;
				$sendData['t'] = $eventName;
			}

			$this->shards[$shard]['conn']->send(json_encode($sendData));
		}

		public function gotMessage(int $shard, RatchetMessageInterface $msg) {
			$this->shards[$shard]['sentHB'] = false;

			$data = json_decode($msg->getPayload(), true);
			$opcode = $data['op'];

			if ($this->debug && empty($this->internalEmitter->listeners('opcode.' . $opcode))) {
				echo 'Got Unknown Message on shard ', $shard, ': ', $msg->getPayload(), "\n";
			}

			$this->doEmit('opcode.' . $opcode, [$shard, $opcode, $data]);
		}

		public function gotInvalidSession(int $shard, int $opcode, Array $data) {
			// Requeue an identify message, and try again.
			$this->sendIdentify($shard);
		}

		public function gotReconnectRequest(int $shard, int $opcode, Array $data) {
			// Requeue an identify message, and try again.
			$this->sendIdentify($shard);
		}

		public function gotHello(int $shard, int $opcode, Array $data) {
			$this->shards[$shard]['heartbeat_interval'] = $data['d']['heartbeat_interval'];
			$this->scheduleHeartbeat($shard);

			$this->sendIdentify($shard);
		}

		private function sendIdentify(int $shard) {
			$identify = [];
			$identify['token'] = $this->token;
			$identify['properties'] = ['os' => PHP_OS, 'browser' => 'shanemcc/reactphp-discord', 'library' => 'shanemcc/reactphp-discord'];
			$identify['compress'] = false;
			$identify['shard'] = [$shard, $this->gwInfo['shards']];

			echo 'Scheduling identify for shard ', $shard, "\n";
			$this->slowMessageQueue[] = [$shard, 2, $identify];
		}

		private function scheduleHeartbeat(int $shard) {
			if (!isset($this->shards[$shard]['heartbeat_interval'])) { return; }

			$delay = $this->shards[$shard]['heartbeat_interval'];

			$timerID = bin2hex(random_bytes(16));
			$this->shards[$shard]['timerID'] = $timerID;

			$this->getLoopInterface()->addTimer($delay / 1000, function() use ($shard, $timerID) {
				if (!isset($this->shards[$shard]['timerID'])) { return; }
				if ($this->shards[$shard]['timerID'] != $timerID) { return; }

				if ($this->shards[$shard]['sentHB']) {
					if ($this->debug) { echo 'Shard connection appears to be dead: ', $shard, "\n"; }

					$this->shards[$shard]['conn']->close();
				} else {
					if ($this->debug) { echo 'Sending heartbeat for shard: ', $shard, "\n"; }
					$this->shards[$shard]['sentHB'] = true;

					$this->sendShardMessage($shard, 1, $this->shards[$shard]['seq']);
					$this->scheduleHeartbeat($shard);
				}
			});
		}

		public function gotHeartBeat(int $shard, int $opcode, Array $data) {
			if ($this->debug) { echo 'Got HB on shard ', $shard, ': ', json_encode($data), "\n"; }
		}

		public function gotHeartBeatAck(int $shard, int $opcode, Array $data) {
			if ($this->debug) { echo 'Got HB Ack on shard ', $shard, ': ', json_encode($data), "\n"; }
		}

		public function gotDispatch(int $shard, int $opcode, Array $data) {
			$this->shards[$shard]['seq'] = $data['s'];

			$event = $data['t'];
			$eventData = $data['d'];

			if ($this->debug && empty($this->internalEmitter->listeners('event.' . $event))) {
				echo 'Got Unknown Event on shard ', $shard, ': ', json_encode($data), "\n";
			}
			$this->doEmit('event.' . $event, [$shard, $event, $eventData]);
		}

		public function noop(int $shard, String $event, Array $data) { }

		public function handleReadyEvent(int $shard, String $event, Array $data) {
			$this->shards[$shard]['ready'] = true;
			if ($this->debug) { echo 'Shard is ready: ', $shard, "\n"; }
		}

		public function handleGuildCreate(int $shard, String $event, Array $data) {
			$guildData = [];
			$guildData['name'] = $data['name'];
			$guildData['shard'] = $shard;
			$guildData['channels'] = [];

			echo 'Found new server on shard ', $shard, ': ', $guildData['name'], ' (', $data['id'], ')', "\n";

			foreach ($data['channels'] as $channel) {
				if ($channel['type'] == '0') {
					$guildData['channels'][$channel['id']] = [];
					$guildData['channels'][$channel['id']]['name'] = $channel['name'];

					echo "\t", 'Channel: ', $channel['name'], ' (', $channel['id'], ')', "\n";
				}
			}

			$this->guilds[$data['id']] = $guildData;
		}

		public function handleGuildDelete(int $shard, String $event, Array $data) {
			if (isset($this->guilds[$data['id']])) {
				echo 'Removed server on shard ', $shard, ': ', $this->guilds[$data['id']]['name'], ' (', $data['id'], ')', "\n";

				unset($this->guilds[$data['id']]);
			}
		}

		public function handleChannelCreate(int $shard, String $event, Array $data) {
			if ($data['type'] == '0' && isset($data['guild_id']) && isset($this->guilds[$data['guild_id']])) {
				$guildID = $data['guild_id'];
				$chanID = $data['id'];

				if (isset($this->guilds[$guildID]['channels'][$chanID])) { return; }

				$this->guilds[$guildID]['channels'][$chanID] = [];
				$this->guilds[$guildID]['channels'][$chanID]['name'] = $data['name'];

				echo 'Found new channel for server ', $this->guilds[$guildID]['name'], ' (', $guildID, ') on shard ', $shard, ': ', $data['name'], ' (', $chanID, ')', "\n";
			} else if ($data['type'] == '1') {
				$person = $data['recipients'][0];
				if (isset($this->personChannels[$person['id']])) { return; }

				$this->personChannels[$person['id']] = ['id' => $data['id'], 'time' => time()];
				echo 'Found new channel for person ', $person['username'], '#', $person['discriminator'], ' (', $person['id'], ') on shard ', $shard, ': ', $data['id'], "\n";
			} else if ($this->debug) {
				echo 'Found new channel on shard ', $shard, ': ', json_encode($data), "\n";
			}
		}

		public function handleChannelDelete(int $shard, String $event, Array $data) {
			if ($data['type'] == '0' && isset($data['guild_id']) && isset($this->guilds[$data['guild_id']])) {
				$guildID = $data['guild_id'];
				$chanID = $data['id'];
				unset($this->guilds[$guildID]['channels'][$chanID]);

				echo 'Removed channel on server ', $this->guilds[$guildID]['name'], ' (', $guildID, ') on shard ', $shard, ': ', $data['name'], ' (', $chanID, ')', "\n";
			} else if ($data['type'] == '1') {
				$person = $data['recipients'][0];
				unset($this->personChannels[$person['id']]);
				echo 'Removed channel for person ', $person['username'], '#', $person['discriminator'], ' (', $person['id'], ') on shard ', $shard, ': ', $data['id'], "\n";
			} else if ($this->debug) {
				echo 'Removed channel on shard ', $shard, ': ', json_encode($data), "\n";
			}

		}

		public function shardClosed(int $shard, $code = null, $reason = null) {
			if ($this->debug) { echo 'Shard ', $shard, ' closed (', $code, ' - ', $reason. ')', "\n"; }

			if (!$this->disconnecting) {
				$reconnectTime = 5;

				if ($code == 4003 || $code == 4004) {
					echo 'Error Connecting - authentication error - not attempting to reconnect.', "\n";
				} else {
					$this->getLoopInterface()->addTimer($reconnectTime, function() use ($shard) {
						if ($this->debug) { echo 'Reconnecting shard: ', $shard, "\n"; }
						$this->connectShard($shard);
					});
				}
			}
		}

		public function isReady(): bool {
			if (empty($this->shards)) { return FALSE; }

			foreach ($this->shards as $shard) {
				if (!$shard['ready']) {
					return false;
				}
			}

			return TRUE;
		}

		public function validServer(String $target): bool {
			return isset($this->guilds[$target]);
		}

		public function validChannel(String $server, String $target): bool {
			return isset($this->guilds[$server]['channels'][$target]);
		}

		public function validPerson(String $target): bool {
			return FALSE;
		}

		public function sendChannelMessage(String $server, String $channel, String $message) {
			if (!$this->validChannel($server, $channel)) { return; }

			$sendMessage = [];
			$sendMessage['content'] = $message;

			$data = json_encode($sendMessage);
			$headers = [];
			$headers['content-length'] = strlen($data);
			$headers['content-type'] = 'application/json';

			$this->getRequest('/channels/' . $channel . '/messages', null, 'POST', $headers)->end($data);
		}

		public function sendPersonMessage(String $person, String $message) {
			// if (!$this->validPerson($person)) { return; }

			if (isset($this->personChannelss[$person])) {
				$this->personChannels[$person]['time'] = time();
				$this->sendPersonChannelMessage($this->personChannels[$person]['id'], $message);
			} else {
				$data = json_encode(['recipient_id' => $person]);
				$headers = [];
				$headers['content-length'] = strlen($data);
				$headers['content-type'] = 'application/json';

				$this->getRequest('/users/@me/channels', function ($data, $headers) use ($message) {
					$data = json_decode($data, true);
					$this->sendPersonChannelMessage($data['id'], $message);
				}, 'POST', $headers)->end($data);
			}
		}

		public function sendPersonChannelMessage(String $personChannel, String $message) {
			$sendMessage = [];
			$sendMessage['content'] = $message;

			$data = json_encode($sendMessage);
			$headers = [];
			$headers['content-length'] = strlen($data);
			$headers['content-type'] = 'application/json';

			$this->getRequest('/channels/' . $personChannel . '/messages', null, 'POST', $headers)->end($data);
		}
	}
