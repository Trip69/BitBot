<?php
namespace bitbot;
if(!isset($_POST['username']) || !isset($_POST['password']))
    exit('Bad Username/Password');
require_once 'inc/db.php';
$username=bit_db_uni::escape_string($_POST['username']);
$password=bit_db_uni::escape_string($_POST['password']);
$user = bitbot_user::logon($username,$password);
if($user === false)
    exit('Bad Username/Password');
$user->set_cookie();
header("Location: tickers.php");
?>