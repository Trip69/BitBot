<?php
namespace bitbot;

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

$db=new bit_db_util();
$update=array();
foreach($_POST as $account_id => $value)
{
    $setting=explode('_',mysqli_real_escape_string($db::$link,$account_id));
    $account_id=(int)$setting[0];
    $name=$setting[1];
    $update[$account_id][$name]=$value=='enabled';
}
foreach($update as $account_id => $record)
    $db->update_account_config($account_id,$record['enabled']);

header("Location: ".$_SERVER['HTTP_REFERER']);
?>