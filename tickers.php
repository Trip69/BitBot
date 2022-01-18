<?php
namespace bitbot;

//default page
if(!isset($_GET['site']))
{
    header('Location: tickers.php?volume=up&site=bitfinex');
    exit();
}

require_once 'inc/db.php';
//session and user stuff
require_once 'inc/session.php';
//document
require_once 'template/template.php';
$add_vars='&site='.$_GET['site'];
$ticker_table=new table();

//Add table headers
$ticker_table->add_header(array(    'Market<br/>'.utils_htm::make_anchor('tickers.php?market=up'.$add_vars,'▲').' '.utils_htm::make_anchor('tickers.php?market=down'.$add_vars,'▼'),
                                    '24h Change<br/>'.utils_htm::make_anchor('tickers.php?change=up'.$add_vars,'▲').' '.utils_htm::make_anchor('tickers.php?change=down'.$add_vars,'▼'),
                                    'Current',
                                    '2 min Av',
                                    '2 to 5 min Av',
                                    '5 to 15 min Av',
                                    '15 to 30 min Av',
                                    '30 to 60 Av',
                                    'Volume USD<br/>'.utils_htm::make_anchor('tickers.php?volume=up'.$add_vars,'▲').' '.utils_htm::make_anchor('tickers.php?volume=down'.$add_vars,'▼'),
                                    'Link'));
$db=new bit_db_reader();
$sites=$db->get_site_ids();
$site_idx=null;
foreach($sites as $site)
    if($site['name']==$_GET['site'])
        $site_idx=$site['idx'];
if($site_idx===null)
    exit('Invalid site '.$_GET['site']);
$recorded=$db->get_recorded($site_idx);
$markets=bit_db_uni::get_markets();
//add table rows
foreach ($recorded as $market_id => $ticker)
{
    $table=$markets[$market_id]['ticker']?'ticker':($markets[$market_id]['trades']?'trade':null);
    $last_ticker=$db->get_last_ticket((int)$ticker['idx']);
    $ticker_table->add_row(array(
                            $ticker['name'],
                            $last_ticker['daily_change_points'],
                            $last_ticker['last_price'],
                            $db->get_price_av_rel_now_between($table,(int)$ticker['idx'],60*2,0,true,true),
                            $db->get_price_av_rel_now_between($table,(int)$ticker['idx'],60*5,60*2,true,true),
                            $db->get_price_av_rel_now_between($table,(int)$ticker['idx'],60*15,60*5,true,true),
                            $db->get_price_av_rel_now_between($table,(int)$ticker['idx'],60*30,60*15,true,true),
                            $db->get_price_av_rel_now_between($table,(int)$ticker['idx'],60*60,60*30,true,true),
                            //number_format ($last_ticker['volume']), //makes readable but screws the column sort
                            $last_ticker['volume']*$last_ticker['last_price'], //volume usd
                            utils_htm::make_anchor('https://www.bitfinex.com/t/'.str_replace('/USDT',':USD',$ticker['name']),'Link')
                            ));
}
//sort by
if (isset($_GET['market']))
    $ticker_table->sort_by(0,$_GET['market']=='up'?false:true);
if (isset($_GET['change']))
    $ticker_table->sort_by(1,$_GET['change']=='up'?false:true);
if (isset($_GET['volume']))
    $ticker_table->sort_by(8,$_GET['volume']=='up'?false:true);
$ticker_table->add_tag_column_polarity(1);
$ticker_table->add_column_percentage(1);

//add compares for colour css
$ticker_table->add_column_compare(2,3,'up','up_mj','down','down_mj');
$ticker_table->add_column_compare(3,4,'up','up_mj','down','down_mj');
$ticker_table->add_column_compare(4,5,'up','up_mj','down','down_mj');
$ticker_table->add_column_compare(5,6,'up','up_mj','down','down_mj');
$ticker_table->add_column_compare(6,7,'up','up_mj','down','down_mj');
$ticker_table->add_tag_cell_value('BTC/USDT','bold');
$ticker_table->add_tag_cell_value('XRP/USDT','bold');
$ticker_table->add_tag_cell_value('ETH/USDT','bold');
$ticker_table->add_tag_cell_value('LTC/USDT','bold');
$ticker_table->add_tag_cell_value('DASH/USDT','bold');
//add margin gold css tag
foreach ($recorded as $ticker)
{
    if ($ticker['margin'])
    {
        $ticker_table->add_tag_cell_value($ticker['name'],'margin');
    }
}
$ticker_table->round_column(array(3,4,5,6));
$out = new template();
$out->load_template('template/ticker_main.htm');

//add header links
$h11 = '';
foreach ($sites as $site)
    if($site['name']==$_GET['site'])
        $h11.=$site['name'].' ';
    else
        $h11.=utils_htm::make_anchor('tickers.php?site='.$site['name'],$site['name']).' ';

$out->add_item('h11',$h11);
$out->add_item('h21',strftime('%H:%M'));
$out->add_item('table1',$ticker_table->get_table());
echo $out->get_output();
//$a=1;
?>