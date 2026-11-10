<?php
/*
 * Call Center Position for FusionPBX
 * Copyright (c) 2026 Norman King
 * SPDX-License-Identifier: MIT
 * See the LICENSE file for the full license text.
 */

//load fusionpbx, which also registers the class autoloader
	require_once dirname(__DIR__, 4) . '/resources/require.php';

//run the service until it is asked to stop, and exit with its status
	try {
		$service = call_center_position_service::create();
		exit($service->run());
	}
	catch (Throwable $e) {
		fwrite(STDERR, "call_center_position: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n");
		exit(1);
	}
