<?php
/*
 * Call Center Position for FusionPBX
 * Copyright (c) 2026 Norman King
 * SPDX-License-Identifier: MIT
 * See the LICENSE file for the full license text.
 */

	//application details
		$apps[$x]['name'] = "Call Center Position";
		$apps[$x]['uuid'] = "550af405-5c0a-45b5-ad65-262d642260b0";
		$apps[$x]['category'] = "Switch";
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "1.0";
		$apps[$x]['license'] = "MIT";
		$apps[$x]['url'] = "https://github.com/NS-Innovations/fusionpbx_call_center_position_announce";
		$apps[$x]['description']['en-us'] = "Tell callers waiting in a call center queue their position in line.";
		$apps[$x]['description']['en-gb'] = "Tell callers waiting in a call center queue their position in line.";

	//permission details
		$y=0;
		//the call center app declares this permission without any group, which hides the announce position
		//select on the queue edit page, so this app gives it the same groups as the other announce permissions
		$apps[$x]['permissions'][$y]['name'] = "call_center_announce_position";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;

	//default settings
		$y=0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "6b4f1708-7023-44a2-9503-89cbe6182353";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "call_center_position";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "enabled";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "boolean";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Enable or disable the call center position service.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "43d0ff89-2317-431b-b43c-d5f4dd2ab76d";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "call_center_position";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "interval";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "30";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Seconds between position announcements to a waiting caller. Minimum 10. Can be overridden per domain.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "b5cb9335-fceb-42e0-afe7-be3f2e9d363a";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "call_center_position";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "sound_before";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "ivr/ivr-you_are_number.wav";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Sound played before the position number, or none to leave it out. A relative path uses the language and voice of the queue.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "c172ad38-a648-4d93-895d-6a613548b769";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "call_center_position";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "sound_after";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "ivr/ivr-in_line.wav";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Sound played after the position number, or none to leave it out. A relative path uses the language and voice of the queue.";
		$y++;

?>
