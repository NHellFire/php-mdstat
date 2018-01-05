#!/usr/bin/php
<?php
/*
    PHP mdstatus
    Copyright (C) 2015  Nathan Rennie-Waldock

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

function format_time ($seconds) {
	$time = array();

	$time["week"] = floor($seconds / 604800);
	$seconds -= ($time["week"] * 604800);

	$time["day"] = floor($seconds / 86400);
	$seconds -= ($time["day"] * 86400);

	$time["hour"] = floor($seconds / 3600);
	$seconds -= ($time["hour"] * 3600);

	$time["min"] = floor($seconds / 60);
	$seconds -= ($time["min"] * 60);

	$time["sec"] = round($seconds);

	if ($time["week"] != 1) $week = " weeks";
	else $week = " week";
	if ($time["day"] != 1) $day = " days";
	else $day = " day";
	if ($time["hour"] != 1) $hr = " hrs";
	else $hr = " hr";
	if ($time["min"] != 1) $min = " mins";
	else $min = " min";
	if ($time["sec"] != 1) $sec = " secs";
	else $sec = " sec";

	$ret = "";

	if ($time["week"])
		$ret .= sprintf("%s%s ", $time["week"], $week);
	if ($time["day"])
		$ret .= sprintf("%s%s ", $time["day"], $day);
	if ($time["hour"])
		$ret .= sprintf("%s%s ", $time["hour"], $hr);
	if ($time["min"])
		$ret .= sprintf("%s%s ", $time["min"], $min);
	if ($time["sec"])
		$ret .= sprintf("%s%s ", $time["sec"], $sec);
	if (empty($ret))
		$ret = "0 secs";

	return trim($ret);
}


function format_bytes ($bytes) {
	$suf = array("B", "kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB");

	for ($i = 1, $x = 0; $i <= count($suf); $i++, $x++) {
		if ($bytes < pow(1000, $i) || $i == count($suf))
			return number_format($bytes/pow(1024, $x), 2)." ".$suf[$x];
	}
}





$lines = file("/proc/mdstat", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$mdstatus = array();

$mdstatus["supported_types"] = array();

if (preg_match('/^Personalities : ([\[\]a-z0-9 ]+])/', $lines[0], $matches)) {
	foreach (explode(" ", $matches[1]) as $type) {
		$mdstatus["supported_types"][] = substr($type, 1, -1);
	}
}

for ($linepos = 1, $linecount = count($lines); $linepos < $linecount; $linepos++) {
	$arr = explode(" : ", $lines[$linepos]);
	if (count($arr) == 2) {
		$dev = $arr[0];
		$mdstatus["devices"][$dev] = array();

		$details = explode(" ", $arr[1]);

		$mdstatus["devices"][$dev]["status"] = $details[0];
		$mdstatus["devices"][$dev]["level"] = $details[0] == "inactive" ? "none" : $details[1];

		for ($i = 2, $details_count = count($details); $i < $details_count; $i++) {
			if (preg_match('/^([a-z0-9]+)\[([0-9]+)\](?:\(([SF])\))?$/', $details[$i], $disk)) {
				$mdstatus["devices"][$dev]["disks"][$disk[1]] = array("raid_index" => $disk[2], "status" => @$disk[3]);
			}
		}
		uasort($mdstatus["devices"][$dev]["disks"], create_function('$a,$b', 'if ($a["raid_index"] == $b["raid_index"]) { return 0; } return ($a["raid_index"] < $b["raid_index"]) ? -1 : 1;'));

		$options = $lines[$linepos + 1];
		//var_dump($options);

		$mdstatus["devices"][$dev]["volume_size"] = preg_match('/([0-9]+) blocks/', $options, $matches) ? $matches[1] * 512 * 2 : -1;
		$mdstatus["devices"][$dev]["chunk_size"] = preg_match('/([0-9]+)k chunk/', $options, $matches) ? $matches[1] : -1;
		$mdstatus["devices"][$dev]["algorithm"] = preg_match('/algorithm ([0-9]+)/', $options, $matches) ? $matches[1] : -1;
		$mdstatus["devices"][$dev]["persistent_superblock"] = !is_int(strpos($options, "super non-persistent"));

		if (preg_match('/\[([0-9]+)\/([0-9]+)\]/', $options, $matches)) {
			$mdstatus["devices"][$dev]["registered"] = $matches[1];
			$mdstatus["devices"][$dev]["active"] = $matches[2];
		}

		$action = $lines[$linepos + 2];
		if (preg_match('/([a-z]+) *= *([0-9\.]+)%/', $action, $matches)) {
			$mdstatus["devices"][$dev]["action"] = array("name" => $matches[1], "percent" => $matches[2]);
			$mdstatus["devices"][$dev]["action"]["eta"] = preg_match('/finish=([0-9\.]+)min/', $action, $matches) ? $matches[1] * 60 : -1;
			$mdstatus["devices"][$dev]["action"]["speed"] = preg_match('/speed=([0-9]+)K\/sec/', $action, $matches) ? $matches[1] * 1024: -1;
		} else {
			$action = trim(file_get_contents("/sys/block/$dev/md/sync_action"));
			$progress = explode(" / ", trim(file_get_contents("/sys/block/$dev/md/sync_completed")));
			$speed = trim(file_get_contents("/sys/block/$dev/md/sync_speed"));
			if (!$speed) {
				$speed = -1;
			}
			$percent = $eta = -1;

			if (count($progress) == 2) {
				$percent = ($progress[0] / $progress[1]) * 100;
				if ($speed > 0) {
					$eta = ($progress[1] - $progress[0]) / $speed;
				}
			}

			$mdstatus["devices"][$dev]["action"] = array("name" => $action, "percent" => $percent, "eta" => $eta, "speed" => $speed * 1024);
		}
	}
}

$mdstatus["unused_devices"] = preg_match('/^unused devices: (.*)$/', $lines[$linecount - 1], $matches) ? explode(" ", str_replace(array("<", ">"), "", $matches[1])) :  array("?");

//print_r($mdstatus);

printf("Supported Types: %s\n", implode(" | ", $mdstatus["supported_types"]));
printf("Unused Devices: %s\n", implode(" | ", $mdstatus["unused_devices"]));

printf("\nDevices:\n");
foreach ($mdstatus["devices"] as $dev => $mddata) {
	printf("\t%s:\n", $dev);

	printf("\t\tActive Disks: %d/%d\n", $mddata["active"], $mddata["registered"]);
	printf("\t\tSize: %s\n", format_bytes($mddata["volume_size"]));
	printf("\t\tStatus: %s\n", $mddata["status"]);

	printf("\t\tAction: %s", $mddata["action"]["name"]);
	if ($mddata["action"]["percent"] >= 0) {
		printf(" (%.1f%% - ETA: %s @ %s/s)", $mddata["action"]["percent"], format_time($mddata["action"]["eta"]), format_bytes($mddata["action"]["speed"]));
	}
	printf("\n");

	printf("\t\tDisk Status:\n");
	foreach ($mddata["disks"] as $disk => $diskdata) {
		switch ($diskdata["status"]) {
			case "F": $diskdata["status"] = "Failed"; break;
			case "S": $diskdata["status"] = "Spare"; break;
			case "": $diskdata["status"] = is_int(strpos(file_get_contents("/sys/block/$dev/md/dev-$disk/state"), "in_sync")) ? "Online" : "Rebuilding"; break;
		}
		printf("\t\t\t%s[%d]: %s\n", $disk, $diskdata["raid_index"], $diskdata["status"]);
	}

}
