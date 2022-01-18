<?php
namespace bitbot;

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

$db=new bit_db_trader();
$update=array();
foreach($_POST as $account_id => $value)
{
    $setting=explode('_',mysqli_real_escape_string($db::$link,$account_id));
    $position_id=(int)$setting[0];
    $name=$setting[1];
    $update[$position_id][$name]=$value=='manage';
}
foreach($update as $position_id => $record)
    $db->update_position_manage($position_id,$record['manage']);

header("Location: ".$_SERVER['HTTP_REFERER']);
?>