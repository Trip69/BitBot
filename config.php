<?php
namespace bitbot;

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

if (!isset($_GET['exchange']))
{
    header('Location: config.php?exchange=bitfinex');
    exit();
}

$session = bit_db::escape_string($_COOKIE['session_id']);
$bitbot_user = bitbot_user::get_user($session);
if ($bitbot_user===false)
{
    header("Location: logon.htm");
    exit();
}
if(!($bitbot_user->get_access_flags() & bitbot_user::access_flags['config']))
    exit('Access Denied');

require_once 'template/template.php';

$config_table=new table(null, null);
$config_table->add_header(array('Market','Record Tickers','Record Trades','Arbitrage'));

$db=new bit_db_reader();
$sites=$db->get_site_ids();

$site_config_links='';
$recorded=null;
$exchange_name='bitfinex';

foreach($sites as $exchange)
    $site_config_links.=utils_htm::make_anchor('config.php?exchange='.$exchange['name'],$exchange['name']).'<br/>';

if (isset($_GET['exchange']))
{
    $_GET['exchange']=$db->escape_string($_GET['exchange']);
    $exchange_name=$_GET['exchange'];
    $idx=utils::array_multidem_search($sites,'name',$_GET['exchange'])['idx'];
    $recorded=$db->get_all_tickers($idx,'name');
}
else
    $recorded=$db->get_all_tickers(null,'name');

foreach ($recorded as $market_id => $market)
{
    $config_table->add_row(array(
                                $market['name'],
                                utils_htm::make_hidden_value($market['idx'].'_ticker','no_ticker').
                                utils_htm::make_checkbox('',$market['idx'].'_ticker','ticker',
                                (bool)$market['ticker']),
                                utils_htm::make_hidden_value($market['idx'].'_trade','no_trade').
                                utils_htm::make_checkbox('',$market['idx'].'_trade','trade',
                                (bool)$market['trades']),
                                utils_htm::make_hidden_value($market['idx'].'_arbitrage','no_arbitrage').
                                utils_htm::make_checkbox('',$market['idx'].'_arbitrage','arbitrage',
                                (bool)$market['arbitrage']))
                           );
}
$config_table->add_pre_htm('<form action="config_update.php" method="post">'.PHP_EOL);
$config_table->add_post_htm("<input type='submit' value='Submit'>\r\n</form>\r\n");
$config_table->add_tag_cells_instr('USDT','bold');
$out = new template(utils_htm::make_hidden_value($market['idx'],'no_trade'));
$out->load_template('template/config_main.htm');
$out->add_item('h11',$exchange_name);
$out->add_item('table1',$config_table->get_table());
$out->add_item( 'exchange_links',$site_config_links);
echo $out->get_output();
//$a=1;
?>