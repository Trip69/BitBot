<?php
namespace bitbot;

require_once 'inc/db.php';
$access_req=bitbot_user::access_flags['config'];
require_once 'inc/session.php';

if(!isset($_GET['conf']))
{
    header("Location: ".$_SERVER['HTTP_REFERER']);
    exit();
}
$conf=bit_db::escape_string($_GET['conf']);
switch ($conf)
{
    case 'service':
        $vars=array(
            array('var'=>'scribe','set'=>'recorder_enabled'),
            array('var'=>'analyser','set'=>'analyser_enabled'),
            array('var'=>'trader','set'=>'trader_enabled'),
            array('var'=>'thread','set'=>'thread_enabled'),
            array('var'=>'restart','set'=>'thread_restart'));
        foreach($vars as $var)
        {
            if (isset($_POST[$var['var']]))
            {
                $_POST[$var['var']] = bit_db::escape_string($_POST[$var['var']]);
                bit_db_uni::set_config($var['set'],1);
            }
            else
                bit_db_uni::set_config($var['set'],0);
        }
        header("Location: ".$_SERVER['HTTP_REFERER']);
        break;
    case 'exchange':
        $column=array('enabled'=>'enabled','record'=>'record_enabled','trade'=>'trade_enabled');
        foreach($_POST as $key => $post)
        {
            $key=bit_db::escape_string($key);
            $post=bit_db::escape_string($post);
            $exp=explode('_',$key);
            $idx=$exp[0];
            $setting=$column[$exp[1]];
            bit_db_uni::set_exchange_config($idx,$setting,(int)$post);
        }
        header("Location: ".$_SERVER['HTTP_REFERER']);
        exit();
        break;
    default:
        header("Location: ".$_SERVER['HTTP_REFERER']);
        exit();
}