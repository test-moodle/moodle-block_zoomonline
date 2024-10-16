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

$string['pluginname'] = 'Zoom Online';

// General settings headings
$string['headerconfig'] = 'Zoom Online Configuration';
$string['descconfig'] = 'Configure the settings for the Zoom Online block.';

// Zoom Client ID and Secret
$string['clientid'] = 'Zoom Client ID';
$string['clientid_desc'] = 'Enter your Zoom OAuth Client ID.';
$string['clientsecret'] = 'Zoom Client Secret';
$string['clientsecret_desc'] = 'Enter your Zoom OAuth Client Secret.';
$string['accountid'] = 'Zoom Account ID';
$string['accountid_desc'] = 'Enter your Zoom Account ID used for OAuth.';

// AWS S3 Settings
$string['aws_key'] = 'AWS S3 Access Key';
$string['aws_key_desc'] = 'Enter your AWS S3 Access Key ID.';
$string['aws_secret'] = 'AWS S3 Secret Key';
$string['aws_secret_desc'] = 'Enter your AWS S3 Secret Access Key.';
$string['aws_region'] = 'AWS S3 Region';
$string['aws_region_desc'] = 'Enter the AWS region where your S3 bucket is located.';
$string['aws_bucket'] = 'AWS S3 Bucket Name';
$string['aws_bucket_desc'] = 'Enter the name of your AWS S3 bucket where videos will be stored.';

// Embed Type Options
$string['embedtype'] = 'Embed Type';
$string['embedtypedesc'] = 'Choose whether to embed videos in a Book or a Label.';
$string['embedbook'] = 'Book';
$string['embedlabel'] = 'Label';

// Attendance Tracking
$string['useattendance'] = 'Enable Attendance Tracking';
$string['useattendance_desc'] = 'Enable or disable attendance tracking for Zoom meetings.';

// Storage Type Options
$string['storagetype'] = 'Storage Type';
$string['storagetype_desc'] = 'Choose where to store recorded videos: in AWS S3 or locally on the Moodle server.';
$string['storagetype_aws'] = 'AWS S3';
$string['storagetype_local'] = 'Local Storage';

// Local Folder Storage Path
$string['localfolder'] = 'Local Storage Folder';
$string['localfolder_desc'] = 'Specify the path for storing videos locally on the Moodle server. This setting is only used when "Local Storage" is selected.';

// Admin setting checkboxes
$string['labelshowlowonly'] = 'Show only low values';
$string['displaysettingsdesc'] = 'Display only low values in settings page.';
