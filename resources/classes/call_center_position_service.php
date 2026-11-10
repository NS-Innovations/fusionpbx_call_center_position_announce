<?php
/*
 FusionPBX
 Version: MPL 1.1

 The contents of this file are subject to the Mozilla Public License Version
 1.1 (the "License"); you may not use this file except in compliance with
 the License. You may obtain a copy of the License at
 http://www.mozilla.org/MPL/

 Software distributed under the License is distributed on an "AS IS" basis,
 WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 for the specific language governing rights and limitations under the
 License.

 The Original Code is FusionPBX

 The Initial Developer of the Original Code is
 Mark J Crane <markjcrane@fusionpbx.com>
 Portions created by the Initial Developer are Copyright (C) 2008-2026
 the Initial Developer. All Rights Reserved.

 Contributor(s):
 Mark J Crane <markjcrane@fusionpbx.com>
 Norman King <norman@nsinnovations.net>
*/

/**
 * Tells callers waiting in a call center queue their position in line.
 *
 * Once a second the service asks mod_callcenter for the members of every queue that has Announce
 * Position set to true. The members come back in the order mod_callcenter serves them, so the
 * position of a caller is one more than the number of callers ahead of them that are still waiting
 * or being offered to an agent. A caller who is waiting is sent the announcement with uuid_broadcast
 * every interval seconds, which plays it over the music on hold and lets the music carry on after.
 */
class call_center_position_service extends service {

	/**
	 * the sounds used when a setting is empty or not valid
	 */
	const default_sound_before = 'ivr/ivr-you_are_number.wav';
	const default_sound_after = 'ivr/ivr-in_line.wav';

	/**
	 * seconds between reads of the queues and their settings from the database
	 */
	const queue_refresh = 15;

	/**
	 * database object
	 * @var database
	 */
	private $database;

	/**
	 * settings object
	 * @var settings
	 */
	private $settings;

	/**
	 * whether the service does any work
	 * @var bool
	 */
	private $enabled;

	/**
	 * the queues that announce the position, keyed by the mod_callcenter queue name
	 * @var array
	 */
	private $queues = [];

	/**
	 * when the queues were last read from the database
	 * @var int
	 */
	private $queues_loaded = 0;

	/**
	 * when each caller in a queue is next told their position, keyed by the session uuid
	 * @var array
	 */
	private $next_announcement = [];

	/**
	 * Reloads settings from the config file and the database.
	 *
	 * @return void
	 */
	public function reload_settings(): void {
		//re-read the config file to get any possible changes
		parent::$config->read();

		//connect to the database
		$this->database = new database(['config' => parent::$config]);

		//get the settings using the global defaults
		$this->settings = new settings(['database' => $this->database]);

		//get the service settings
		$this->enabled = $this->settings->get('call_center_position', 'enabled', true);

		//read the queues again on the next pass
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

			//make sure the database connection is available
			while (!$this->database->is_connected()) {
				$this->database->connect();
				$this->settings = new settings(['database' => $this->database]);
				sleep(1);
			}

			//the service can be turned off without stopping it
			if ($this->enabled) {
				if (time() - $this->queues_loaded >= self::queue_refresh) {
					$this->load_queues();
				}
				$this->announce_positions();
			}

			//the interval is in whole seconds, so checking once a second is precise enough
			sleep(1);
		}
		return 0;
	}

	/**
	 * Reads the queues that announce the position, with the settings of their domain.
	 *
	 * @return void
	 */
	private function load_queues(): void {
		//get the queues with announce position turned on
		$sql = "select q.queue_extension, q.domain_uuid, d.domain_name ";
		$sql .= "from v_call_center_queues as q ";
		$sql .= "inner join v_domains as d on d.domain_uuid = q.domain_uuid ";
		$sql .= "where cast(q.queue_announce_position as text) = 'true' ";
		$sql .= "and d.domain_enabled = true ";
		$rows = $this->database->select($sql, null, 'all');
		unset($sql);

		//build the queue list using the settings of each domain
		$queues = [];
		$domain_settings = [];
		if (is_array($rows) && @sizeof($rows) != 0) {
			foreach ($rows as $row) {
				//get the settings of the domain once
				$domain_uuid = $row['domain_uuid'];
				if (!isset($domain_settings[$domain_uuid])) {
					$domain_settings[$domain_uuid] = new settings(['database' => $this->database, 'domain_uuid' => $domain_uuid]);
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
	 * Sends the position to every waiting caller whose announcement is due.
	 *
	 * @return void
	 */
	private function announce_positions(): void {
		//nothing to do without a queue
		if (empty($this->queues)) {
			$this->next_announcement = [];
			return;
		}

		//connect to the switch, which reconnects after the switch restarts
		$esl = event_socket::create();
		if (!$esl->connected()) {
			return;
		}

		$now = time();
		$in_queue = [];
		foreach ($this->queues as $queue_name => $queue) {
			//get the members in the order mod_callcenter serves them, json keeps a | in a caller id name from breaking the columns
			$command = json_encode(['command' => 'callcenter_config', 'data' => ['arguments' => 'queue list members', 'queue_name' => $queue_name]]);
			$response = $esl->request('api json '.$command);
			$result = is_string($response) ? json_decode($response, true) : null;
			if (!is_array($result) || !is_array($result['response'] ?? null)) {
				continue;
			}

			//count the callers still ahead, waiting or being offered to an agent
			$position = 0;
			foreach ($result['response'] as $member) {
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

				//the first announcement comes one interval after joining the queue
				if (!isset($this->next_announcement[$session_uuid])) {
					$this->next_announcement[$session_uuid] = (int) ($member['joined_epoch'] ?? $now) + $queue['interval'];
				}

				//only speak to a caller who is waiting and is due
				if ($state != 'Waiting' || $now < $this->next_announcement[$session_uuid]) {
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
	 * @return string The sound, or an empty string when the setting is empty to leave the part out.
	 */
	private function sound($value, string $default): string {
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		//a space would split the command and a ! would split the file string
		if (!preg_match('/^[A-Za-z0-9_\.\/\-]+$/', $value)) {
			$this->warning("ignoring the sound '".$value."', using ".$default);
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
