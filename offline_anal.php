<?php
namespace bitbot;
require_once 'inc/db.php';
require_once 'inc/offline_db.php';

const rd_points=1;
const tspan=10;

$db=new offline_db();
$start=$db->get_terminal('BTC/USDT','start');

$bc_mk_id=$start['idx'];
$time_current=date_create($start['time']);
$end=$db->get_terminal('BTC/USDT','end');
$time_finish=date_create($end['time'])->sub(new \DateInterval('PT10M'));

$oneMinute=new \DateInterval('PT1M');
$fifteenMinutes=new \DateInterval('PT15M');
$one_point=0;

$pump=array();
$dump=array();

do
{
    //echo $current->format('Y-m-d H:i:s').PHP_EOL;
    $highlow_current=$db->high_low_minute($bc_mk_id,$time_current);
    if($highlow_current['high']==null || $highlow_current['low']==null)
    {
        $time_current->add($oneMinute);
        continue;
    }
    $one_point=$highlow_current['low']*0.01;
    $time_end=date_create($time_current->format('Y-m-d H:i:s'))->add($fifteenMinutes);
    $highlow_end=$db->high_low_minute($bc_mk_id,$time_end);

    if($highlow_end['high']>$highlow_current['low']+$one_point)
    {
        do {
            $time_end->add($oneMinute);
            $next_min=$db->high_low_minute($bc_mk_id,$time_end);
        } while ($next_min['high'] > $highlow_end['high']);
        $time_end->sub($oneMinute);
        $pump[]=array(
            'start'=>$time_current->format('Y-m-d H:i:s'),
            'finish'=>$time_end->format('Y-m-d H:i:s'),
            'low'=>$highlow_current['low'],
            'high'=>$highlow_end['high']
        );
        $time_current=date_create($time_end->format('Y-m-d H:i:s'));
    }
    $time_current->add($oneMinute);
} while ($time_current < $time_finish);
echo 'yay';
?>