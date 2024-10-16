<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Version details
 *
 * @package    block_zoomonline
 * @copyright  Ciarán Mac Donncha
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2024090413;        // The current plugin version (Date: YYYYMMDDXX)
$plugin->requires  = 2021051700;        // Requires this Moodle version (Moodle 3.11 or later)
$plugin->component = 'block_zoomonline'; // Full name of the plugin (used for diagnostics)
$plugin->cron      = 300;               // Time interval for cron in seconds (5 minutes).

// Define plugin dependencies
$plugin->dependencies = [
    'local_guzzle' => 2020050400,        // Requires local_guzzle plugin with this version or later.
];


