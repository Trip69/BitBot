<?php
namespace bitbot;

require __DIR__ . '/inc/vendor/autoload.php';
require_once __DIR__ . '/inc/interfaces.php';
require_once __DIR__ . '/inc/recorder_obj.php';
require_once __DIR__ . '/inc/db.php';

$db_util=new bit_db_util();
$orverride=isset($argv[1]) && $argv[1]=='override=1';
if($orverride)
    console('Override in effect');
//todo: should change this to bit_bot
if (!$orverride && $db_util->get_process_running('recorder'))
{
    console('Recorder is already running',false,true);
    exit(0);
}

/*
$delete_data=gethostname()=='Desktop'?24*7:24*7*8;
$db_util->delete_data_by_time('ticker', $delete_data);
$db_util->delete_data_by_time('trade', $delete_data);
*/
$db_util=null;


//survey
require_once __DIR__ . '/inc/site_survey_obj.php';
$sites=array('bitfinex','bitstamp');
$survey = new site_survey($sites);
for ($a=1;$a<4;$a++)
    try
    {
        if ($survey->check_all())
            break;
    } catch (\Exception $ex)
    {
        console("Error surveying sites $a/3");
        if ($a=3)
            exit('Error surveying sites');
        sleep(30);
    }

require_once 'inc/analyser_obj.php';
require_once 'inc/trader_obj.php';

$lookups = $survey->get_db_lookups();
$survey=null;

$scribe = new scribe($lookups);
$analyser = new analyser($lookups);
$trader = new trader($lookups);

$trader->register_for_messages($analyser);
$trader->register_for_messages($scribe);
$analyser->register_for_messages($trader);
$analyser->register_for_messages($scribe);
$scribe->register_for_messages($trader);
$scribe->register_for_messages($analyser);

$thread= new pseudo_thread();
$thread->create_thread($scribe,array('duration'=>60*60*24));
$thread->create_thread($analyser,array('duration'=>60*60*24));
$thread->create_thread($trader,array('duration'=>60*60*24,'sites'=>array('bitfinex','bitstamp')));
$thread->start($scribe);
$thread->start($analyser);
$thread->start($trader);
$thread->run();
?>