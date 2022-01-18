<?php
namespace bitbot;

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

$db=new bit_db_util();
$update=array();
foreach($_POST as $market_id => $value)
{
    $setting=explode('_',mysqli_real_escape_string($db::$link,$market_id));
    $market_id=(int)$setting[0];
    $name=$setting[1];
    $update[$market_id][$name]=$value=='ticker'||$value=='trade'||$value=='arbitrage';
}
foreach($update as $market_id => $record)
    $db->update_market_config($market_id,$record['ticker'],$record['trade'],$record['arbitrage']);

header("Location: ".$_SERVER['HTTP_REFERER']);
?>