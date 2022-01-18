<?php
namespace bitbot;

$orverride=false;
$load_trader=false;
$load_analyser=false;

foreach($argv as $arg)
{
    switch ($arg)
    {
        case 'override=1':
            $orverride=true;
            break;
        case 'trader=true':
            $load_trader=true;
            break;
        case 'analyser=true':
            $load_analyser=true;
            break;
    }
}

//load composer functions
require __DIR__ . '/inc/vendor/autoload.php';
require_once __DIR__ . '/inc/interfaces.php';
if ($load_trader)
    require_once __DIR__ . '/inc/recorder_obj.php';
require_once __DIR__ . '/inc/db.php';
$bD_util=new bit_db_util();

if($orverride)
    console('Override in effect');
if (!$orverride && $bD_util->get_process_running('analyser'))
{
    console('Analyser is already running',false,true);
    exit;
}
$bD_util=null;

if($load_analyser)
    require_once 'inc/analyser_obj.php';

if ($load_trader)
    require_once 'inc/trader_obj.php';

if($load_analyser)
    $analyser = new analyser();
if ($load_trader)
    $trader = new trader();
if($load_analyser && $load_trader)
{
    $trader->register_for_messages($analyser);
    $analyser->register_for_messages($trader);
}

$thread = new pseudo_thread();

if ($load_trader)
{
    $thread->create_thread($trader,array('duration'=>60*60*24,'sites'=>array('bitfinex','bitstamp')));
    $thread->start($trader);
}
if($load_analyser)
{
    $thread->create_thread($analyser,array('duration'=>60*60*24));
    $thread->start($analyser);
}
$thread->run();
?>