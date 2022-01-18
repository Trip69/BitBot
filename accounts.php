<?php
namespace bitbot;

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

require_once 'template/template.php';
if(!isset($_GET['site']))
{
    header('Location: accounts.php?site=bitfinex');
    exit();
}
//$add_vars='&site='.$_GET['site'];
$account_table=new table();

//Add table headers
$headers=array('Exchange','Account Number','Name','Function','Enabled');
$account_table->add_header($headers);
$db=new bit_db_trader();
$sites=bit_db_uni::get_site_ids(false,true);
$site_idx=null;
foreach($sites as $site)
    if($site['name']==$_GET['site'])
        $site_idx=$site['idx'];
if($site_idx===null)
    exit('Invalid site '.$_GET['site']);
$accounts=$db->get_accounts($site_idx);
foreach($accounts as $idx => $account)
{
    $account_table->add_row(array(  $sites[$account['exchange_id']]['name'],
                                    $account['idx'],
                                    $account['name'],
                                    $account['function'],
                                    utils_htm::make_hidden_value($account['idx'].'_enabled','no_enabled').
                                    utils_htm::make_checkbox('',$account['idx'].'_enabled','enabled',
                                    (bool)$account['enabled'])
                                )
                            );

}
$account_table->add_pre_htm('<form action="account_update.php" method="post">'.PHP_EOL);
$account_table->add_post_htm("<input type='submit' value='Submit'>\r\n</form>\r\n");

$out = new template();
$out->load_template('template/accounts_main.htm');


$h11 = '';
foreach ($sites as $site)
    if($site['name']==$_GET['site'])
        $h11.=$site['name'].' ';
    else
        $h11.=utils_htm::make_anchor('accounts.php?site='.$site['name'],$site['name']).' ';

$out->add_item('h11',$h11);
$out->add_item('table1',$account_table->get_table());
echo $out->get_output();