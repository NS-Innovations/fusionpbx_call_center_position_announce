<?php
/*
 * Call Center Position for FusionPBX
 * Copyright (c) 2026 Norman King
 * SPDX-License-Identifier: MIT
 * See the LICENSE file for the full license text.
 */

/**
 * Tells callers waiting in a call center queue their position in line.
 *
 * Once a second the service asks mod_callcenter for the members of every queue. They come back in the
 * order mod_callcenter serves them, so the position of a caller in a queue that has Announce Position
 * set to true is one more than the number of callers ahead of them that are still waiting or being
 * offered to an agent. A caller who is waiting is sent the announcement with uuid_broadcast every
 * interval seconds, which plays it over the music on hold and lets the music carry on after.
 */
class call_center_position_service extends service {

	/**
	 * the name of the app
	 *
	 * @var string
	 */
	const app_name = 'call_center_position';

	/**
	 * the uuid of the app
	 *
	 * @var string
	 */
	const app_uuid = '550af405-5c0a-45b5-ad65-262d642260b0';

	/**
	 * the sound played before the number when the setting is not valid
	 *
	 * @var string
	 */
	const default_sound_before = 'ivr/ivr-you_are_number.wav';

	/**
	 * the sound played after the number when the setting is not valid
	 *
	 * @var string
	 */
	const default_sound_after = 'ivr/ivr-in_line.wav';

	/**
	 * seconds between reads of the queues and their settings from the database
	 *
	 * @var int
	 */
	const queue_refresh = 15;

	/**
	 * database object
	 *
	 * @var database
	 */
	private $database;

	/**
	 * the connection to the switch, made again after it drops
	 *
	 * @var event_socket|null
	 */
	private $esl = null;

	/**
	 * whether the service does any work, read with the queues so a change is picked up without a restart
	 *
	 * @var bool
	 */
	private $enabled = true;

	/**
	 * the queues that announce the position, keyed by the mod_callcenter queue name
	 *
	 * @var array
	 */
	private $queues = [];

	/**
	 * when the queues were last read from the database
	 *
	 * @var int
	 */
	private $queues_loaded = 0;

	/**
	 * when each caller in a queue is next told their position, keyed by the session uuid
	 *
	 * @var array
	 */
	private $next_announcement = [];

	/**
	 * whether a failed read of the queues was already logged, so it is logged once until a read works
	 *
	 * @var bool
	 */
	private $warned_select = false;

	/**
	 * the sound settings already warned about, so a bad value is logged once and not every pass
	 *
	 * @var array
	 */
	private $warned_sounds = [];

	/**
	 * Reloads settings from the config file and the database.
	 *
	 * @return void
	 */
	public function reload_settings(): void {
		//re-read the config file to get any possible changes
		parent::$config->read();

		//use the connection fusionpbx opened
		$this->database = database::new(['config' => parent::$config]);

		//read the queues and the settings again on the next pass
		$this->queues_loaded = 0;
	}

	/**
	 * Runs the service loop until the service is asked to stop.
	 *
	 * @return int The exit status.
	 */
	public function run(): int {

		//reload the settings
		$this->reload_settings();

		//service work is handled here
		while ($this->running) {

			//read the queues and the settings, which also turns the service off or on
			if (time() - $this->queues_loaded >= self::queue_refresh) {
				$this->load_queues();
			}

			//the service can be turned off without stopping it
			if ($this->enabled) {
				$this->announce_positions();
			}

			//the interval is in whole seconds, so checking once a second is precise enough
			sleep(1);
		}
		return 0;
	}

	/**
	 * Reads whether the service is enabled and the queues that announce the position, with the settings
	 * of their domain. When the database can not be read the queues read before are kept.
	 *
	 * @return void
	 */
	private function load_queues(): void {
		//try again in a second when the database is not there
		if (!$this->database->is_connected()) {
			$this->database->connect();
			if (!$this->database->is_connected()) {
				return;
			}
		}

		//the global setting turns the service off or on
		//without caching, a change made on the settings page is seen by this process within the refresh
		$settings = new settings(['database' => $this->database, 'category' => 'call_center_position', 'allow_caching' => false]);
		$this->enabled = $settings->get('call_center_position', 'enabled', true);
		if (!$this->enabled) {
			$this->queues = [];
			$this->next_announcement = [];
			$this->queues_loaded = time();
			$this->close_switch_connection();
			return;
		}

		//get the queues with announce position turned on
		$sql = "select q.queue_extension, q.domain_uuid, d.domain_name ";
		$sql .= "from v_call_center_queues as q ";
		$sql .= "inner join v_domains as d on d.domain_uuid = q.domain_uuid ";
		$sql .= "where q.queue_announce_position = 'true' ";
		$sql .= "and d.domain_enabled = true ";
		$rows = $this->database->select($sql, null, 'all');
		unset($sql);
		if ($rows === false) {
			//keep the queues read before and try again on the next pass
			if (!$this->warned_select) {
				$this->warned_select = true;
				$this->warning("the call center queues could not be read, trying again every second");
			}
			return;
		}
		$this->warned_select = false;

		//build the queue list using the settings of each domain
		$queues = [];
		$domain_settings = [];
		if (is_array($rows) && @sizeof($rows) != 0) {
			foreach ($rows as $row) {
				//get the settings of the domain once, only the category of this app
				$domain_uuid = $row['domain_uuid'];
				if (!isset($domain_settings[$domain_uuid])) {
					$domain_settings[$domain_uuid] = new settings(['database' => $this->database, 'domain_uuid' => $domain_uuid, 'category' => 'call_center_position', 'allow_caching' => false]);
				}
				$settings = $domain_settings[$domain_uuid];

				//keep the interval long enough for the announcement to finish
				$interval = (int) $settings->get('call_center_position', 'interval', 30);
				if ($interval < 10) {
					$interval = 10;
				}

				//mod_callcenter names the queue extension@domain_name
				$queue_name = $row['queue_extension'].'@'.$row['domain_name'];
				$queues[$queue_name]['interval'] = $interval;
				//when the service began announcing in this queue, so callers who waited before a restart or
				//before the queue was turned on are first told one interval later, not all at once
				$queues[$queue_name]['since'] = $this->queues[$queue_name]['since'] ?? time();
				$queues[$queue_name]['sound_before'] = $this->sound($settings->get('call_center_position', 'sound_before', self::default_sound_before), self::default_sound_before);
				$queues[$queue_name]['sound_after'] = $this->sound($settings->get('call_center_position', 'sound_after', self::default_sound_after), self::default_sound_after);
			}
		}
		unset($rows);

		$this->queues = $queues;
		$this->queues_loaded = time();
		$this->debug("announcing the position in ".count($queues)." queue(s)");
	}

	/**
	 * Returns the connection to the switch, opening a new one when there is none or it dropped. A new
	 * connection starts with an empty buffer, so a reply cut off when the switch went away is not read
	 * as the start of the next one.
	 *
	 * @return event_socket|null
	 */
	private function switch_connection() {
		if ($this->esl !== null && $this->esl->is_connected()) {
			return $this->esl;
		}
		if ($this->esl !== null) {
			$this->esl->close();
			$this->esl = null;
		}
		$esl = new event_socket;
		if (!$esl->connect() || !$esl->is_connected()) {
			return null;
		}
		$this->esl = $esl;
		return $this->esl;
	}

	/**
	 * Closes the connection to the switch while there is nothing to announce.
	 *
	 * @return void
	 */
	private function close_switch_connection(): void {
		if ($this->esl !== null) {
			$this->esl->close();
			$this->esl = null;
		}
	}

	/**
	 * Sends the position to every waiting caller whose announcement is due.
	 *
	 * @return void
	 */
	private function announce_positions(): void {
		//nothing to do without a queue
		if (empty($this->queues)) {
			$this->next_announcement = [];
			$this->close_switch_connection();
			return;
		}

		//connect to the switch, which reconnects after the switch restarts
		$esl = $this->switch_connection();
		if ($esl === null) {
			return;
		}

		//get the members of every queue in one request, in the order mod_callcenter serves them; json keeps
		//a | in a caller id name from breaking the columns
		$command = json_encode(['command' => 'callcenter_config', 'data' => ['arguments' => 'member list']]);
		$response = $esl->request('api json '.$command);
		$result = is_string($response) ? json_decode($response, true) : null;
		if (!is_array($result) || !is_array($result['response'] ?? null)) {
			//keep every timer as it is and try again on the next pass
			return;
		}

		//group the members by queue, only the queues that announce the position
		$members = [];
		foreach ($result['response'] as $member) {
			if (!is_array($member)) {
				continue;
			}
			$queue_name = $member['queue'] ?? '';
			if (isset($this->queues[$queue_name])) {
				$members[$queue_name][] = $member;
			}
		}

		$now = time();
		$in_queue = [];
		foreach ($members as $queue_name => $list) {
			$queue = $this->queues[$queue_name];

			//callers with the same score are put in the order they joined, so two callers who joined in
			//the same second do not swap places between announcements
			usort($list, function ($a, $b) {
				return ((int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0))
					?: ((int) ($a['joined_epoch'] ?? 0) <=> (int) ($b['joined_epoch'] ?? 0))
					?: strcmp((string) ($a['session_uuid'] ?? ''), (string) ($b['session_uuid'] ?? ''));
			});

			//count the callers still ahead, waiting or being offered to an agent
			$position = 0;
			foreach ($list as $member) {
				$state = $member['state'] ?? '';
				if ($state != 'Waiting' && $state != 'Trying') {
					continue;
				}
				$position++;

				//remember the caller while an agent is being tried, so a missed agent does not restart the timer
				$session_uuid = $member['session_uuid'] ?? '';
				if (!is_uuid($session_uuid)) {
					continue;
				}
				$in_queue[$session_uuid] = true;

				//the first announcement comes one interval after joining the queue, or after coming back to
				//it, since a caller who resumes an abandoned place keeps the time they first joined
				if (!isset($this->next_announcement[$session_uuid])) {
					$joined = max((int) ($member['joined_epoch'] ?? 0), (int) ($member['rejoined_epoch'] ?? 0));
					if ($joined <= 0) {
						$joined = $now;
					}
					$this->next_announcement[$session_uuid] = max($joined, $queue['since']) + $queue['interval'];
				}

				//a caller due while an agent is ringing is told one interval later, not as soon as the agent misses
				if ($state != 'Waiting') {
					if ($now >= $this->next_announcement[$session_uuid]) {
						$this->next_announcement[$session_uuid] = $now + $queue['interval'];
					}
					continue;
				}

				//only speak to a caller who is due
				if ($now < $this->next_announcement[$session_uuid]) {
					continue;
				}
				$this->next_announcement[$session_uuid] = $now + $queue['interval'];

				//build the announcement
				$number_files = self::number_files($position);
				if (empty($number_files)) {
					continue;
				}
				$files = array_merge(array_filter([$queue['sound_before']]), $number_files, array_filter([$queue['sound_after']]));

				//play it to the caller only, over the music on hold
				$esl->request('bgapi uuid_broadcast '.$session_uuid.' file_string://'.implode('!', $files).' aleg');
				$this->debug("told ".$session_uuid." in ".$queue_name." they are number ".$position);
			}
		}

		//forget the callers who have left the queues
		$this->next_announcement = array_intersect_key($this->next_announcement, $in_queue);
	}

	/**
	 * Returns the sound files that say a number, using the digits of the voice.
	 *
	 * @param int $number The number to say, from 1 to 999.
	 *
	 * @return array The files in the order they are played, or an empty array when the number is out of range.
	 */
	private static function number_files(int $number): array {
		if ($number < 1 || $number > 999) {
			return [];
		}
		$files = [];
		if ($number >= 100) {
			$files[] = 'digits/'.intdiv($number, 100).'.wav';
			$files[] = 'digits/hundred.wav';
			$number %= 100;
		}
		if ($number >= 20) {
			$files[] = 'digits/'.(intdiv($number, 10) * 10).'.wav';
			$number %= 10;
		}
		if ($number > 0) {
			$files[] = 'digits/'.$number.'.wav';
		}
		return $files;
	}

	/**
	 * Checks a sound setting can be passed to the switch as one file of a file string.
	 *
	 * @param mixed  $value   The value of the setting.
	 * @param string $default The sound to use when the value is not valid.
	 *
	 * @return string The sound, or an empty string when the setting is none to leave the part out.
	 */
	private function sound($value, string $default): string {
		$value = trim((string) $value);
		//fusionpbx drops a setting with an empty value, so none is the way to leave the part out
		if ($value === '' || strtolower($value) === 'none') {
			return '';
		}
		//a space would split the command and a ! would split the file string
		if (!preg_match('/^[A-Za-z0-9_\.\/\-]+$/', $value)) {
			if (!isset($this->warned_sounds[$value])) {
				$this->warned_sounds[$value] = true;
				$this->warning("ignoring the sound '".$value."', using ".$default);
			}
			return $default;
		}
		return $value;
	}

	/**
	 * Shows the version of this service.
	 *
	 * @return void
	 */
	protected static function display_version(): void {
		echo "1.0\n";
	}

	/**
	 * Adds the command line options of this service.
	 *
	 * @return void
	 */
	protected static function set_command_options() {

	}

}
