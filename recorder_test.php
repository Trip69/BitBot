<?php
namespace bitbot;

//load composer functions
require __DIR__ . '/inc/vendor/autoload.php';
require_once __DIR__ . '/inc/interfaces.php';
require_once __DIR__ . '/inc/recorder_obj.php';
require_once __DIR__ . '/inc/db.php';
$db_util=new bit_db_util();

$orverride=isset($argv[1]) && $argv[1]=='override=1';
if($orverride)
    console('Override in effect');
if (!$orverride && $db_util->get_process_running('recorder'))
{
    console('Recorder is already running',false,true);
    exit;
}
/*
$delete_data=gethostname()=='Desktop'?24*7:24*7*4*12;
$db_util->delete_data_by_time('ticker', $delete_data);
$db_util->delete_data_by_time('trade', $delete_data);
*/
$db_util=null;


$sites=array('bitfinex','bitstamp');

//testing *********************************************

//testing STOP ****************************************

require_once __DIR__ . '/inc/site_survey_obj.php';
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
$lookups=$survey->get_db_lookups();
$scribe = new scribe($lookups);
$survey=null;

$thread= new pseudo_thread();
$thread->create_thread($scribe,array('duration'=>60*60*24));
$thread->start($scribe);
$thread->run();
?>
