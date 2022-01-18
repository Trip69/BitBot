<?php
namespace bitbot;
$session = bit_db::escape_string($_COOKIE['session_id']);
$bitbot_user = bitbot_user::get_user($session);
if ($bitbot_user===false) {
    header("Location: logon.htm");
    exit();
}
if(isset($access_req) && !($bitbot_user->get_access_flags() & $access_req))
    exit('Access Denied');