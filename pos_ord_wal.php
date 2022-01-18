<?php
namespace bitbot;
if(!isset($_GET['site']))
{
    header('Location: pos_ord_wal.php?site=bitfinex');
    exit();
}

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

require_once 'template/template.php';
$_GET['site']=bit_db::escape_string($_GET['site']);

//POSITIONS
$position_table=new table();

//Add table headers
$headers=array('Exchange','Account Number','ID','Pair','Amount','Price','Manage');
$position_table->add_header($headers);
$db=new bit_db_trader();
$sites=bit_db_uni::get_site_ids(false,true);
$site_idx=null;
foreach($sites as $site)
    if($site['name']==$_GET['site'])
        $site_idx=$site['idx'];
if($site_idx===null)
    exit('Invalid site '.$_GET['site']);
$positions=$db->get_positions($site_idx);
foreach($positions as $idx => $position)
{
    $position_table->add_row(array(  $sites[$position['exchange_id']]['name'],
                                    $position['account_id'],
                                    $idx,
                                    $position['name'],
                                    $position['amount'],
                                    $position['price'],
                                    utils_htm::make_hidden_value($position['idx'].'_manage','no_manage').
                                    utils_htm::make_checkbox('',$position['idx'].'_manage','manage',
                                    (bool)$position['manage'])
                                )
                            );

}
$position_table->add_pre_htm('<form action="pos_ord_update.php" method="post">'.PHP_EOL);
$position_table->add_post_htm("<input type='submit' value='Submit'>\r\n</form>\r\n");

$out = new template();
$out->load_template('template/pos_ord_wal_main.htm');


$h11 = '';
foreach ($sites as $site)
    if($site['name']==$_GET['site'])
        $h11.=$site['name'].' ';
    else
        $h11.=utils_htm::make_anchor('accounts.php?site='.$site['name'],$site['name']).' ';

$out->add_item('h11',$h11);
$out->add_item('h21','Positions');
$out->add_item('table1',$position_table->get_table());

//ORDERS
$order_table=new table();

//Add table headers
$headers=array('Exchange','Account Number','ID','Pair','Type','Margin','Amount','Price','Status','Cancel');
$order_table->add_header($headers);
$orders=$db->get_orders($site_idx);
foreach($orders as $idx => $order)
{
    $order_table->add_row(array(
        $sites[$order['exchange_id']]['name'],
        $order['account_id'],
        $order['order_id'],
        $order['name'],
        ucfirst(strtolower($order['type'])),
        $order['margin']==1?'Y':' ',
        $order['amount'],
        $order['price'],
        $order['status'],
        'TODO:Cancel Button'
        )
    );

}
$order_table->add_pre_htm('<form action="pos_ord_update.php" method="post">'.PHP_EOL);
$order_table->add_post_htm("<input type='submit' value='Submit'>\r\n</form>\r\n");
$out->add_item('h22','Orders');
$out->add_item('table2',$order_table->get_table());

//Wallet
//ORDERS
$wallet_table=new table();

//Add table headers
$headers=array('Exchange','Account Number','ID','Type','Currency','Amount','Time');
$wallet_table->add_header($headers);
$wallets=$db->get_wallets($site_idx);
foreach($wallets as $idx => $wallet)
{
    $wallet_table->add_row(array(
            $wallet['name'], //exchange name
            $wallet['account_id'],
            $idx,
            bit_db_trader::wallet_type_str[$wallet['type']],
            $wallet['cur_code'],
            $wallet['amount'],
            $wallet['time']
        )
    );

}
//$order_table->add_pre_htm('<form action="pos_ord_update.php" method="post">'.PHP_EOL);
//$order_table->add_post_htm("<input type='submit' value='Submit'>\r\n</form>\r\n");
$out->add_item('h23','Wallets');
$out->add_item('table3',$wallet_table->get_table());


echo $out->get_output();