<?php
require_once ($_SERVER['DOCUMENT_ROOT'] . "/config.php");
global $CFG, $PAGE, $DB, $USER;
require_login();

$dataroot = $CFG->dataroot;

if(isset($_POST['id'])){
    $id = $_POST['id'];
    $visible = $_POST['visible'];
    $table = 'block_zoomonline';
    $arr = ['id' => $id, 'visible' => $visible];
    //var_dump($arr);
    $DB->update_record_raw($table, $arr);
}
?>
