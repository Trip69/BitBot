<?php
namespace bitbot;
$run=false;
$connection=$this->get_connection('bitfinex',2);
$amount=self::calc_coins('bitfinex','BTCUSD',$this->bet_usd);
$new_order=new order(array('type'=>'MARKET','pair_str'=>'BTCUSD','amount'=>$amount,'call_back'=>array($this,'new_order')));
$sp=$new_order->amount>0?100-$this->stop_points:100+$this->stop_points;
$pp=$new_order->amount>0?100+$this->profit_points:100-$this->profit_points;
$oco_stop=round(self::calc_point($connection->get_site(),$new_order->pair_str,$sp),6);
$limit_oco=new order(array(
    'type'=>'LIMIT',
    'pair_str'=>$new_order->pair_str,
    'price_oco_stop'=>(string)$oco_stop,
    'price'=>self::calc_point($connection->get_site(),$new_order->pair_str,$pp),
    'amount'=>$new_order->amount*-1,
    'flags'=>order::flags['reduce_only']|order::flags['oco']));
console('DEBUG oco stop:'.$limit_oco->price_oco_stop.' price:'.$limit_oco->price);
$connection->send_new_order($limit_oco);

/*
$ne=new event(event::moved_point | event::price | event::down,self::$db_keys['market']['bitfinex']['XRPUSD'],1);
$this->receive_event($ne);
*/

/*
$amount=self::calc_coins('bitfinex','XRPUSD',100);
echo "DEBUG: XRP Amount:$amount".PHP_EOL;
*/

/*
//new order
$no=new order(array('type'=>'LIMIT','pair_str'=>'XRPUSD','price'=>'0.93','amount'=>'-38','call_back'=>array($this,'new_order')));
$this->connectors['bitfinex_2']->trade($no);
*/

/*
// Order cancel and update

$one_id=null;
foreach($this->connectors['bitfinex_2']->get_orders() as $id => $order)
    $one_id=$id;
$this->connectors['bitfinex_2']->cancel_order($one_id);
//$this->connectors['bitfinex_2']->update_order($one_id,array('price'=>0.80));
*/

/*
 * Action test
$tradeon=null;
foreach ($this->connectors as &$connection)
    if($connection->get_site()=='bitfinex')
    {
        $tradeon=$connection;
        break;
    }
    $na = new action(action::watch_unregister,$tradeon->get_site(),'XRPUSD');
    $this->send_action('scribe',$na);
////
$na = new action(action::watch_trade,$tradeon->get_site(),'XRPUSD');
$na->set_callback($tradeon,'pair_update');
$this->send_action('scribe',$na);
/////->

// Event test
$event =new event(event::moved_half_point|event::down,$this->db_keys['market']['bitfinex']['BTCUSD'],null);
$p_event = &$event;
$this->sell($p_event);
*/
console('*** Code Run');
