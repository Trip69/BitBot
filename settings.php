<?php
namespace bitbot;

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

require_once 'inc/file_utils.php';
require_once 'template/template.php';
$settings_page=new template();

$db = new bit_db_reader();
$exch_data=$db->get_sites();
$exch_table=new table();
$exch_table->add_header(array('Name','Enabled','Record','Trade'));
foreach($exch_data as $exchange)
    $exch_table->add_row(array(
        $exchange['name'],
        utils_htm::make_hidden_value($exchange['idx'].'_enabled','0').
        utils_htm::make_checkbox('',$exchange['idx'].'_enabled','1',$exchange['enabled']==1),
        utils_htm::make_hidden_value($exchange['idx'].'_record','0').
        utils_htm::make_checkbox('',$exchange['idx'].'_record','1',$exchange['record_enabled']==1),
        utils_htm::make_hidden_value($exchange['idx'].'_trade','0').
        utils_htm::make_checkbox('',$exchange['idx'].'_trade','1',$exchange['trade_enabled']==1)
    ));

$settings_page->load_template('template/settings.htm');
$settings_page->add_item('EXCHANGETABLE',$exch_table->get_table());

function tick_or_not($setting)
{
    $res=(bool)bit_db_uni::get_config($setting);
    if($res)
        return 'checked';
    else
        return '';
}

$arr_set=array(
    array('temp'=>'SCRIBE','set'=>'recorder_enabled'),
    array('temp'=>'ANALYSER','set'=>'analyser_enabled'),
    array('temp'=>'TRADE','set'=>'trader_enabled'),
    array('temp'=>'THREAD','set'=>'thread_enabled'),
    array('temp'=>'RESTART','set'=>'thread_restart'));
foreach ($arr_set as $setting)
    $settings_page->add_item($setting['temp'],tick_or_not($setting['set']));

$lines=300;
if(isset($_GET['log']) && is_numeric($_GET['log']))
    $lines = (int)bit_db::escape_string($_GET['log']);
$log_text=tailCustom('bitbot.log',$lines);
$settings_page->add_item('LOG',$log_text);
$log_error_text=tailCustom('error_log',$lines);
$settings_page->add_item('ERRORLOG',$log_error_text);
$log_cron_text=tailCustom('bit_bot_cron.log',$lines);
$settings_page->add_item('CRONLOG',$log_cron_text);
echo $settings_page->get_output();