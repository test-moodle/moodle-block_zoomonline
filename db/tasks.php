<?php

defined('MOODLE_INTERNAL') || die();



$tasks = array(



array(

     'classname' =>  'block_zoomonline\task\processmeetings',
     'blocking' => 0,
     'minute' => '*/15',       //run after every 15 mins
     'hour' => '*',
     'day' => '*',
     'dayofweek' => '*',
     'month' => '*'

)



);

?>