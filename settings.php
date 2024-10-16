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

defined('MOODLE_INTERNAL') || die();




if ($ADMIN->fulltree) {

//    $settings = new admin_settingpage('block_zoomonline', get_string('pluginname', 'block_zoomonline'));

    // General Zoom settings
    $settings->add(new admin_setting_heading(
        'zoomonline/settingsheader',
        get_string('headerconfig', 'block_zoomonline'),
        get_string('descconfig', 'block_zoomonline')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoomonline/conf',
        get_string('labelshowlowonly', 'block_zoomonline'),
        get_string('displaysettingsdesc', 'block_zoomonline'),
        '0'
    ));

    $settings->add(new admin_setting_configtext(
        'block_zoomonline/client_id',
        get_string('clientid', 'block_zoomonline'),
        get_string('clientid_desc', 'block_zoomonline'),
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_zoomonline/client_secret',
        get_string('clientsecret', 'block_zoomonline'),
        get_string('clientsecret_desc', 'block_zoomonline'),
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_zoomonline/account_id',
        get_string('accountid', 'block_zoomonline'),
        get_string('accountid_desc', 'block_zoomonline'),
        '', PARAM_TEXT
    ));

    // Embed type setting
    $settings->add(new admin_setting_configselect(
        'block_zoomonline/embedtype',
        get_string('embedtype', 'block_zoomonline'),
        get_string('embedtypedesc', 'block_zoomonline'),
        'book',
        [
            'book' => get_string('embedbook', 'block_zoomonline'),
            'label' => get_string('embedlabel', 'block_zoomonline')
        ]
    ));

    // Attendance tracking setting
    $settings->add(new admin_setting_configcheckbox(
        'block_zoomonline/useattendance',
        get_string('useattendance', 'block_zoomonline'),
        get_string('useattendance_desc', 'block_zoomonline'),
        0
    ));

    // Storage type setting
    $options = [
        'aws' => get_string('storagetype_aws', 'block_zoomonline'),
        'local' => get_string('storagetype_local', 'block_zoomonline'),
    ];
    $settings->add(new admin_setting_configselect(
        'block_zoomonline/storagetype',
        get_string('storagetype', 'block_zoomonline'),
        get_string('storagetype_desc', 'block_zoomonline'),
        'aws',
        $options
    ));

    // AWS Settings
    $settings->add(new admin_setting_configtext(
        'block_zoomonline/aws_key',
        get_string('aws_key', 'block_zoomonline'),
        get_string('aws_key_desc', 'block_zoomonline'),
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_zoomonline/aws_secret',
        get_string('aws_secret', 'block_zoomonline'),
        get_string('aws_secret_desc', 'block_zoomonline'),
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_zoomonline/aws_region',
        get_string('aws_region', 'block_zoomonline'),
        get_string('aws_region_desc', 'block_zoomonline'),
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_zoomonline/aws_bucket',
        get_string('aws_bucket', 'block_zoomonline'),
        get_string('aws_bucket_desc', 'block_zoomonline'),
        '', PARAM_TEXT
    ));

    // Local Folder Storage Path
    $settings->add(new admin_setting_configtext(
        'block_zoomonline/localfolder',
        get_string('localfolder', 'block_zoomonline'),
        get_string('localfolder_desc', 'block_zoomonline'),
        '/local/zoom_videos', PARAM_TEXT
    ));
}

$PAGE->requires->js_init_code("
    require(['jquery'], function($) {
        function toggleStorageSettings() {
            var storageType = $('#id_s_block_zoomonline_storagetype').val();
            if (storageType === 'aws') {
                $('#admin-block_zoomonline_aws_key').parent().show();
                $('#admin-block_zoomonline_aws_secret').parent().show();
                $('#admin-block_zoomonline_aws_region').parent().show();
                $('#admin-block_zoomonline_aws_bucket').parent().show();
                $('#admin-block_zoomonline_localfolder').parent().hide();
            } else {
                $('#admin-block_zoomonline_aws_key').parent().hide();
                $('#admin-block_zoomonline_aws_secret').parent().hide();
                $('#admin-block_zoomonline_aws_region').parent().hide();
                $('#admin-block_zoomonline_aws_bucket').parent().hide();
                $('#admin-block_zoomonline_localfolder').parent().show();
            }
        }
        toggleStorageSettings();
        $('#id_s_block_zoomonline_storagetype').change(function() {
            toggleStorageSettings();
        });
    });
");
