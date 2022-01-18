<?php
namespace bitbot;

//CCXT
class ccxt_trader implements bitbot_trade
{

    private $ccxt = null;
    private $orders = null;

    public function __construct(array $prams,array $options = null)
    {
        //$class_string= '\ccxt\\'.$prams['site'];
        //$this->ccxt = new $class_string;
        $this->ccxt = new \ccxt\bitstamp();
    }

    public function connect()
    {
        $this->ccxt->fetch_markets();
    }

    public function get_site()
    {

    }

    public function get_account_id()
    {
        // TODO: Implement get_account_id() method.
    }

    public function authenticate($api_key=null,$api_secret=null)
    {}

    public function get_margin_pairs()
    {
        // TODO: Implement get_margin_pairs() method.
    }

    public function get_usd_pairs()
    {
        // TODO: Implement get_usd_pairs() method.
    }

    public function get_orders()
    {
        $this->orders = $this->ccxt->fetch_open_orders();
    }

    public function buy(order &$new_order)
    {
        // TODO: Implement buy() method.
    }

    public function sell(order &$new_order)
    {
        // TODO: Implement sell() method.
    }

    public function send_new_order(order &$new_order)
    {
        // TODO: Implement send_order() method.
    }

    public function update_order($id, array $prams)
    {
        // TODO: Implement update_order() method.
    }

    public function cancel_order($id)
    {
        // TODO: Implement cancel_order() method.
    }

    public function pair_update($pair, array &$data)
    {
        // TODO: Implement pair_update() method.
    }

    public function __toString()
    {
        // TODO: Implement __toString() method.
        return '';
    }

}

//Bitfinex
class bitfinex_trader extends bitbot_bitfinex implements bitbot_trade

{
    const ping_on_no_data_after=30; //seconds
    const connection_attempts=3;
    const connection_pause=30;//seconds

    private $db = null;
    private $site_info = null;
    private $margin_pairs = null;
    private $market_pairs = null;
    protected $authenticate_info = null;
    protected $function=0;

    private $trade_book=null;

    public function __construct(array $prams,array $options = null)
    {
        $prams['write_callback']=array($this,'receive_data');
        parent::__construct($prams,$options);
        $this->db = &$prams['db'];
        if (order::$db==null)
            order::$db = &$prams['db'];

        if(order::$bitfinex_order_seq_ass==null)
            order::$bitfinex_order_seq_ass=array_flip(order::bitfinex_order_seq);
        if(position::$bitfinex_position_seq_ass==null)
            position::$bitfinex_position_seq_ass=array_flip(position::bitfinex_position_seq);

        if(isset($prams['account_idx']))
        {
            $this->authenticate_info=$this->db->get_account_info($prams['account_idx']);
            $this->function=trader::get_function_keys($this->authenticate_info['function']);
            $this->trade_book=&$prams['trade_book']->get_book($this,$prams['account_idx']);
            //$a=1;
        }
        $this->site_info=$this->db->get_trader_site($prams['site']);
        $this->margin_pairs=$this->db->get_market_pairs($prams['site'],true);
        $this->market_pairs=$this->db->get_market_pairs($prams['site']);
        if ($this->site_info==null)
            throw new BitBotError('Site does not exisit or is disabled');
        $this->connect();
    }

    public function connect()
    {
        static $pause,$next_attempt;
        if($pause===true && time() < $next_attempt)
            return false;
        for($a=1;$a<self::connection_attempts+1;$a++)
        {
            $connected=parent::connect();
            if ($connected)
            {
                $pause=false;
                if (isset($this->authenticate_info['api_key']) && $this->authenticate_info['api_key']!==null)
                    return $this->authenticate($this->authenticate_info['api_key'],$this->authenticate_info['api_secret']);
                return true;
            } else {
                console($this->site." connecting failed Try $a of ".self::connection_attempts);
                if($a==self::connection_attempts)
                {
                    $pause=true;
                    $next_attempt=time()+self::connection_pause;
                }
            }

        }
    }

    public function authenticate($api_key=null,$api_secret=null)
    {
        if($this->authenticate_info===null)
            $this->authenticate_info=array('api_key'=>$api_key,'api_secret'=>$api_secret);
        $authNonce=time()*1000;
        $authPayload = 'AUTH'.$authNonce;
        $authSig = bin2hex(hash_hmac('sha384',$authPayload,$api_secret,true));
        $jdata=json_encode(array('apiKey'=>$api_key,'authSig'=>$authSig,'authNonce'=>$authNonce,'authPayload'=>$authPayload,'event'=>'auth'));
        return $this->wait_for_data($jdata,$this::bitfinex_authenticated,$this::bitfinex_authenticated_failed) == $this::bitfinex_authenticated;
    }

    public function get_site()
    {
        return parent::get_site();
    }

    public function get_account_id()
    {
        return (int)$this->authenticate_info['idx'];
    }

    public function get_can_buy()
    {
        if($this->function & trader::fn_none)
            return false;
        switch (true)
        {
            //no position and buying account
            case (count($this->trade_book['positions'])==0 && ($this->function & (trader::fn_buy | trader::fn_all))) :
                //buy-back for selling account
            case (count($this->trade_book['positions']) > 0 && isset($this->positions['XRPUSD']) && $this->trade_book['positions']['XRPUSD']->amount < 0) :
                return true;
            default:
                return false;
        }
    }

    public function get_can_sell()
    {
        if($this->function & trader::fn_none)
            return false;
        switch (true)
        {
            //no position and selling account
            case (count($this->trade_book['positions'])==0 && ($this->function & (trader::fn_sell | trader::fn_all))) :
                //sell for buying account
            case (count($this->trade_book['positions']) > 0 && isset($this->trade_book['positions']['XRPUSD']) && $this->trade_book['positions']['XRPUSD']->amount > 0) :
                return true;
            default:
                return false;
        }
    }

    public function get_margin_pairs()
    {
        return $this->margin_pairs;
    }

    public function get_usd_pairs()
    {
        return $this->market_pairs;
    }

    public function &get_orders()
    {
        return $this->trade_book['orders'];
    }

    public function buy(order &$new_order)
    {
        if(!$this->get_can_buy())
            return false;
        return $this->trade($new_order);
    }

    public function sell(order &$new_order)
    {
        if(!$this->get_can_sell())
            return false;
        if($new_order->amount>0)
            $new_order->amount *= -1;
        return $this->trade($new_order);
    }

    public function trade(order &$new_order)
    {
        $this->trade_book['orders_sent'][]=$new_order;
        console('DEBUG: Attempting to trade '.$new_order->pair_str);
        $result=$this->wait_for_data($new_order->get_bitfinex_encode('on'),bitbot_bitfinex::bitfinex_order_success,bitbot_bitfinex::bitfinex_order_fail);
        if($result == bitbot_bitfinex::bitfinex_order_success)
        {
            $new_order->trader_obj= &$this;
            console($new_order->echo_success(),false,true);
            //$data = &array(&$this,$new_order->pair_str,$new_order,&$this->trade_book);
            //todo this may not be needed as events are raised from auth channel.
            //call_user_func_array($this->trade_book['trader_callback'],$data); //$sender_obj, $pair, order $ord_request,orderbook &$order_book
        }
        return $result == bitbot_bitfinex::bitfinex_order_success;
    }

    public function send_new_order(order &$new_order)
    {
        $this->trade_book['orders_sent'][]=$new_order;
        console('DEBUG: Sending new order '.$new_order);
        $result=$this->wait_for_data($new_order->get_bitfinex_encode('on'),bitbot_bitfinex::bitfinex_order_success,bitbot_bitfinex::bitfinex_order_fail);
        if($result == bitbot_bitfinex::bitfinex_order_success)
        {
            $new_order->trader_obj= &$this;
            console($new_order->echo_success(),false,true);
            //$data = &array(&$this,$new_order->pair_str,$new_order,&$this->trade_book);
            //todo this may not be needed as events are raised from auth channel.
            //call_user_func_array($this->trade_book['trader_callback'],$data); //$sender_obj, $pair, order $ord_request,orderbook &$order_book
        }
        return $result == bitbot_bitfinex::bitfinex_order_success;
    }

    //https://docs.bitfinex.com/v2/reference#ws-input-order-update
    public function update_order($order_id, array $prams)
    {
        if(isset($this->trade_book['orders'][$order_id]))
        {
            $order=$this->trade_book['orders'][$order_id];
            $order->update_order($prams);
            $result=$this->wait_for_data($order->get_bitfinex_encode('ou'),bitbot_bitfinex::bitfinex_order_success,bitbot_bitfinex::bitfinex_order_fail);
            return $result==bitbot_bitfinex::bitfinex_order_success;
        }
        return null;
    }

    public function cancel_order($id)
    {
        $result=$this->wait_for_data($this->trade_book['orders'][$id]->get_bitfinex_encode('oc'),bitbot_bitfinex::bitfinex_order_success,bitbot_bitfinex::bitfinex_order_fail);
        return $result==bitbot_bitfinex::bitfinex_order_success;
    }

    //Function for processing & directing registered channel data
    public function receive_data($caller,$info,$data)
    {
        //console(__CLASS__.' '.__FUNCTION__.' '.$info);
        $return=null;
        $update_trader=false;
        $calc=new bitfinex_calc($this->authenticate_info['idx']);
        switch($info)
        {
            //https://docs.bitfinex.com/v2/reference#ws-auth-wallets
            case 'wu': //wallet update
            case 'ws': //Wallet Snapshot
                //todo add UNSETTLED_INTEREST & !!BALANCE_AVAILABLE!!
                //$exiting_wallets=array();
                $wallet_str='';
                if(is_array($data[0]))
                    foreach($data as $item)
                    {
                        $this->db->wallet_update($this->site_info['name'],$this->authenticate_info['idx'],$item[0],$item[1],$item[2],$item[4]);
                        $wallet_str.=' '.$item[1].' '.$item[2].'/'.($item[4]==null?'?':$item[4]);
                        $this->trade_book['wallet']->set_currency($item[0],$item[1],$item[2],$item[3],$item[4]);
                        if($item[4]==null)
                            $calc->add('wallet_margin_'.$item[1]);
                    }
                else
                {
                    $this->db->wallet_update($this->site_info['name'],$this->authenticate_info['idx'],$data[0],$data[1],$data[2],$data[4]);
                    $wallet_str.=' '.$data[1].' '.$data[2].'/'.($data[4]==null?'?':$data[4]);
                    $this->trade_book['wallet']->set_currency($data[0],$data[1],$data[2],$data[3],$data[4]);
                    if($data[4]==null)
                        $calc->add('wallet_margin_'.$data[1]);
                }
                $this->db->wallet_delete_empty($this->site_info['name'],$this->authenticate_info['idx']);
                $update_trader=true;
                console($this->site.' account '.$this->authenticate_info['idx'].'. Wallet update '.$wallet_str,false,true);
                break;
            case 'os': //Order Snapshots -> https://docs.bitfinex.com/v2/reference#ws-auth-orders
                console($this->site.' account '.$this->authenticate_info['idx'].'. '.count($data).' orders',false,true);
                $exiting_orders=array();
                foreach($data as $item)
                {
                    //console('os DEBUG id:'.$item[0].' uid:'.$item[2]);
                    $exiting_orders[]=$item[order::$bitfinex_order_seq_ass['id']];//$item[0];
                    $order = trade_book::bitfinex_update_or_new_order($this,$item);
                    console($this->site.' account '.$this->authenticate_info['idx'].' '.$order,false,true);
                }
                //console(trade_book::debug_queue_keys($this));
                $update_trader=true;
                //clear db of items orphaned due to program termination
                if(count($exiting_orders)>0)
                    $this->db->table_move_to_history($this->site,$this->authenticate_info['idx'],'order',$exiting_orders);
                else
                    $this->db->table_move_to_history($this->site,$this->authenticate_info['idx'],'order',null,null,true);
                break; //order snapshot array
            case 'on': //order new
            case 'ou': //order update
               // console('ou DEBUG id:'.$data[0].' uid:'.$data[2]);
                $order_type=$info=='on'?'New order':'Order update';
                $order = trade_book::bitfinex_update_or_new_order($this,$data);
                //console(trade_book::debug_queue_keys($this));
                console($this->site.' account '.$this->authenticate_info['idx'].". $order_type ".$order,false,true);
                break;
            case 'oc': //order cancel / complete
                //console('oc DEBUG id:'.$data[0].' uid:'.$data[2]);
                $order = trade_book::bitfinex_update_or_new_order($this,$data);
                //console(trade_book::debug_queue_keys($this));
                console($this->site.' account '.$this->authenticate_info['idx'].' order '.$order,false,true);
                $update_trader=true;
                break;
            case 'oc_multi': //order cancel multiple
            case 'ox_multi': //multiple order operations (in array)
                break;
            case 'n': //order success / failure
                //console('n DEBUG id:'.$data[4][0].' uid:'.$data[4][2]);
                //console(trade_book::debug_queue_keys($this));
                //if(isset($data[4]) && is_array($data[4])) console('DEBUG id:'.$data[4][0].' uid:'.$data[4][2]);
                if(isset($data[6])&&$data[6]=='ERROR')
                {
                    console('Error: '.$data[7]);
                    if(isset($data[4])&&$data[4]!==null)
                        trade_book::bitfinex_order_result($this,$info,false,$data[4]);
                    else
                        console('DEBUG:'.$data[1]);
                    $return = bitbot_bitfinex::bitfinex_order_fail;
                }
                elseif(isset($data[6])&&$data[6]=='SUCCESS')
                {
                    console($this->site.' '.$data[7]);
                    //information about requests
                    if(isset($data[1]) && substr($data[1],strlen($data[1])-3) == 'req')
                        $return = bitbot_bitfinex::bitfinex_order_success;
                    elseif(isset($data[4]) && $data[4]!==null)
                        trade_book::bitfinex_order_result($this,$info,true,$data[4]);
                    else
                        console('DEBUG:'.$data[1]);
                    $return = bitbot_bitfinex::bitfinex_order_success;
                }
                //console(trade_book::debug_queue_keys($this));
                $update_trader=true;
                break;
            case 'pn'://Position new update snapshot c? cancel?
            case 'pu':
            case 'pc':
                //console('DEBUG:'.$info);
                //console(var_dump($data));
                $pair=substr($data[0],1);
                if(isset($this->trade_book['positions'][$pair]))
                    $this->trade_book['positions'][$pair]->bitfinex_set_data($data);
                else
                    $this->trade_book['positions'][$pair] = position::bitfinex_new_position($this->db,$this->authenticate_info['idx'],$data);
                console('Position update '.$this->trade_book['positions'][$pair],false,true);
                //remove closed
                if($this->trade_book['positions'][$pair]->status=='CLOSED') //todo: this for some fucking reason doesn't fire
                {
                    unset($this->trade_book['positions'][$pair]);
                    $update_trader=true;
                }
                break;
/*
                //-->old code
                $this->db->position_update($this->site_info['name'],$this->authenticate_info['idx'],$pair,$data[2],$data[0][0]=='t',$data[1],$data[3]);//$site,$pair_str,$amount,$margin,$status,$price
                if(isset($this->trade_book['positions'][$pair]))
                {
                    $this->trade_book['positions'][$pair]->set_prams(array('amount'=>$data[2],'status'=>$data[1],'price'=>$data[3],'pl_points'=>$data[4]));
                    //Positions closed means what? the position is still open.
                    if($data[1]='CLOSED' && $data[2]==0)
                    {
                        console($this->site." position $pair closed",false,true);
                    }
                } else {
                    $np=new position(array('pair_str'=>$pair,'amount'=>$data[2],'status'=>$data[1],'price'=>$data[3],'pl_points'=>$data[4],'db'=>&$this->db));
                    $this->trade_book['positions'][$pair]=$np;
                }
                $update_trader=true;
                $poitions_str=' '.$pair.' '.$data[2].' bp:'.$data[3].' p:'.round($data[2]*$data[4],2).'.';
                console($this->site.' account '.$this->authenticate_info['idx'].'. position update.'.$poitions_str,false,true);
                break;
*/
            case 'ps'://position snapshot? //Position closed is sent unenclosed in
                foreach($data as $item)
                {
                    $pair=substr($item[0],1);
                    if(isset($this->trade_book['positions'][$pair]))
                        $this->trade_book['positions'][$pair]->bitfinex_set_data($item);
                    else
                        $this->trade_book['positions'][$pair] = position::bitfinex_new_position($this->db,$this->authenticate_info['idx'],$item);
                    console('Position '.$this->trade_book['positions'][$pair],false,true);
                    if($this->trade_book['positions'][$pair]->pl==null)
                        $calc->add('position_t'.$pair);
                }
                //remove orphaned positions
                $ids=array();
                foreach($this->trade_book['positions'] as $pair => $position)
                    $ids[]=$position->db_id;
                $this->db->table_move_to_history('bitfinex',$this->trade_book['account_id'],'position',$ids);
                break;
                /*
                //Old code
                $poitions_str='';
                if(is_array($data) && isset($data[0]) && is_array($data[0]))
                {
                    //ps
                    $exiting_positions=array();
                    foreach($data as $item)
                    {
                        $pair=substr($item[0],1);
                        $this->db->position_update($this->site_info['name'],$this->authenticate_info['idx'],$pair,$item[2],$item[0][0]=='t',$item[1],$item[3]);//$site,$pair_str,$amount,$margin,$status,$price
                        $exiting_positions[]=bit_db_trader::$pair_ids[$pair]['idx'];
                        if(isset($this->trade_book['positions'][$pair]))
                            $this->trade_book['positions'][$pair]->set_prams(array('amount'=>$item[2],'status'=>$item[1],'price'=>$item[3],'pl_points'=>$item[4]));
                        else
                            $this->trade_book['positions'][$pair]=new position(array('pair_str'=>$pair,'amount'=>$item[2],'status'=>$item[1],'price'=>$item[3],'pl_points'=>$item[4]));
                        $poitions_str.=' '.$pair.' '.$item[2].' bp:'.$item[3].' p:'.round($item[2]*$item[4],2).'.';
                    }
                    $this->db->table_move_to_history($this->site,$this->authenticate_info['idx'],'position',$exiting_positions);
                    $update_trader=true;
                    console($this->site.' account '.$this->authenticate_info['idx'].'. '.count($data).' positions.'.$poitions_str,false,true);
                }
                //single update
                elseif(count($data)==0)
                    //blank & therefore flush to history
                    $this->db->table_move_to_history($this->site,$this->authenticate_info['idx'],'position',null,null,true);
                break;
                */
            case 'ts': //Trade Snapshots
                break;
            case 'te': //Executed Trades (partial detail)
                //console('DEBUG: te');
                break;
            case 'tu': //Executed Trades (full detail)
                //console('tu DEBUG id:'.$data[0].' uid:'.$data[2]);
                //console(trade_book::debug_queue_keys($this));
                trade_book::bitfinex_order_result($this,'tu',true,$data);
                /*
                if(isset($this->trade_book['orders'][$data[0]]))
                {
                    $this->trade_book['orders'][$data[0]]->executed = true;
                    $this->trade_book['orders_complete'][$data[0]]=$this->trade_book['orders'][$data[0]];

                }
                elseif(isset($this->trade_book['orders'][$data[0]]))
                    console('DEBUG: '.$data[0].' not found in orders');
                    console('DEBUG: tu.'.var_dump($data));
                break;
                */
            case 'fos': //Funding Offers
            case 'fon': //New Offer
            case 'fls': //Funding Loans
            case 'fcs': ////Funding Credits
            case 'fcc': //Unknown
            case 'fiu': //Funding Info
            case 'ftu': //Guess Funding Trades update
            case 'fte': //Funding Trades
            case 'fcu': //Funding Trades
            case 'fcn': //unknown something about fUSD and has ACTIVE
            case 'bu'://err dunno
                break;
            default:
                console('Uncaught bitfinex_trader data');
                console($info);
                console(var_dump($data));
                return true;
        }
        if($update_trader)
        {
            $this->trade_book['last_code']=$info;
            call_user_func_array(
                $this->trade_book['trader_callback'],
                array('site'=>$this->get_site(),'trade_book'=>&$this->trade_book));
        }
        if($calc->get_send())
            self::wait_for_data($calc->get_request(),null);
        return $return;
    }

    public function __toString()
    {
        if(isset($this->authenticate_info))
            return $this->site.'_'.$this->authenticate_info['idx'];
        else
            return $this->site.'_null';
    }
}

//Trader
class trader implements bitbot_message, pseudo_runable
{
    const fn_buy=1;
    const fn_sell=2;
    const fn_none=4;
    const fn_all=8;

    const default_runtime=60*60;//seconds
    const check_config_interval=60;//seconds
    const reauthenticate_interval=20*60;//seconds
    const alt_coin_buy = 3;

    public static $db_keys=null;
    private $db=null;

    private $messengers=array();
    private $connectors=array();
    private $margin_pairs=array();
    private $usd_market_pairs=array();

    private $watched_channels=array();
    private $bet_usd;
    private $stop_points=null;
    private $profit_points=null;

    private $pair_score=array();
    public $trade_book=null;

    private $enabled=true;
    private $pusdeo_running=false;
    private $time_trader_start=null;
    private $time_check=null;
    //private $time_reauth=null;
    private $time_finish=null;
    private $dupe_check=false;

    public static function get_function_keys($string)
    {
        $bits=explode(',',$string);
        $ret=0;
        foreach ($bits as $bit)
            switch ($bit)
            {
                case 'LONG':
                case 'BUY':
                    $ret |= trader::fn_buy;
                    break;
                case 'SHORT':
                case 'SELL':
                    $ret |= trader::fn_sell;
                    break;
                case 'NONE':
                    $ret |= trader::fn_none;
                    break;
                case 'ALL':
                    $ret |= trader::fn_all;

            }
        return $ret;
    }

    public function __construct(array &$db_keys=null)
    {
        console('BitBot Trader v0.1 by Trip :). host:'.gethostname());
        if($db_keys===null)
            self::$db_keys=bit_db_uni::get_db_keys();
        else
            self::$db_keys=$db_keys;
        $this->db = new bit_db_trader();
        $this->trade_book=new trade_book($this);
    }

    public function init(array $options)
    {
        $sites=$this->db->get_trader_sites();
        foreach($sites as $name => $site_info)
        {
            $this->watched_channels[$name]=array();
            $accounts=$this->db->get_accounts($site_info['idx']);
            switch ($site_info['connection'])
            {
                case 'bitfinex':
                    $class='bitbot\\'.$site_info['connection'].'_trader';
                    foreach($accounts as $idx => $account_info)
                        $this->connectors[$site_info['name'].'_'.$idx] = new $class(array(
                                                                                    'site'=>$site_info['name'],
                                                                                    'account_idx'=>$idx,
                                                                                    'db'=>&$this->db,
                                                                                    'trade_book'=>&$this->trade_book));
                    $this->margin_pairs[$site_info['name']] = $this->connectors[$site_info['name'].'_'.$idx]->get_margin_pairs();
                    $this->usd_market_pairs[$site_info['name']] = $this->connectors[$site_info['name'].'_'.$idx]->get_usd_pairs();
                    utils::array_multidem_remove_key_value($this->margin_pairs[$site_info['name']],'daily_volume_usd',0);
                    utils::array_sort_key($this->margin_pairs[$site_info['name']],'daily_volume_usd');
                    break;
                case 'ccxt':
                    switch($site_info['name'])
                    {
                        case 'bitstamp':
                            $this->connectors[$site_info['name']] = new ccxt_trader($site_info['name'],array(),$this->db);
                            $this->usd_market_pairs[$site_info['name']] = $this->connectors[$site_info['name']]->get_usd_pairs();
                            break;
                        case 'karken':
                            $this->connectors[$site_info['name']] = new ccxt_trader($site_info['name'],array(),$this->db);
                            $this->usd_market_pairs[$site_info['name']] = $this->connectors[$site_info['name']]->get_usd_pairs();
                            break;
                    }
                    break;
            }
        }
        $this->enabled=(bool)bit_db_uni::get_config('trader_enabled');
        $this->stop_points=(float)bit_db_uni::get_config('trader_stop_points');
        $this->profit_points=(float)bit_db_uni::get_config('trader_profit_points');
        $this->bet_usd=(int)bit_db_uni::get_config('trader_bet_usd');
        $this->time_finish=isset($options['duration'])?time()+$options['duration']:time()+self::default_runtime;
        $this->time_trader_start=time();
        $this->time_check=time();
        $this->time_config_check=time() + self::check_config_interval;
        $this->pusdeo_running = true;

        //Maintenance
        $this->db->maint_clear_dead_positions();
    }

    public function stop()
    {
        foreach ($this->connectors as &$connection)
        {
            $connection->disconnect();
        }
        $this->pusdeo_running = false;
        console('Trader Stopped');
    }

    public function get_is_running()
    {
        return $this->pusdeo_running && $this->enabled && time()<$this->time_finish;
    }

    public function &get_connection($site,$account)
    {
        foreach($this->connectors as &$connection)
            if($connection->get_site()==$site && $connection->get_account_id()==$account)
                return $connection;
        return null;
    }

    //Run function for trader
    public function run()
    {
        if(time()>$this->time_finish)
            $this->stop();
        if(!$this->enabled || !$this->pusdeo_running)
            return false;
        $work_done=false;

        //TEST CODE SELECTION
        static $debug=true;
        if($debug)
        {
            static $reported = false;
            if(!$reported)
            {
                console('*** trader debug "'.__FUNCTION__.'" code enabled');
                $reported=true;
            }
            $code=utils::get_code('test_trader.php');
            if ($code!==false)
            {
                //$self = &$this;
                eval($code);
                console('*** trader debug "'.__FUNCTION__.'" code run');
            }
        }
        //END TEST CODE SELECTION

        foreach ($this->connectors as &$connection)
            if ($connection instanceof bitfinex_trader)
            {
                $test = $connection->wait_for_data(null,null);
                if ($test===false) //error
                {
                    $connection->disconnect();
                    $connection->connect();
                    $work_done=true;
                }
                elseif ($connection->get_last_data_time()+bitfinex_trader::ping_on_no_data_after<time())
                {
                    $connection->ping(false);
                    $work_done=true;
                }
            }
            elseif ($connection instanceof cctx_trader)
            {
                //todo this function
            }
        if(time() > $this->time_config_check)
        {
            if($this->dupe_check && $this->time_config_check<>(int)bit_db_uni::get_config('trader_last_update'))
            {
                console('Trader Dupe Proccess runnging. Exiting.');
                exit('Trader Dupe Proccess runnging. Exiting.');
            }
            $this->time_config_check=time() + $this::check_config_interval;
            bit_db_uni::set_config('trader_last_update',$this->time_config_check);
            $this->enabled=(bool)bit_db_uni::get_config('trader_enabled');
            //todo check connections enabled flag in dB
            if(!$this->dupe_check)
                $this->dupe_check=true;
            $work_done=true;
        }
/*
        if(time() > $this->time_reauth)
        {
            console('DEBUG: Reauth');
            foreach ($this->connectors as &$connection)
                $connection->authenticate();
            $this->time_reauth+=self::reauthenticate_interval;
            $work_done=true;
        }
*/
        return $work_done;
    }

    //Messaging

    public function register_for_messages(&$object)
    {
        $this->messengers=array_merge($this->messengers,messaging::register_for_messages($object));
    }

    //main function for determining trades
    public function receive_event(event &$event)
    {
        switch(true)
        {
            case ($event->has_flags(event::moved_point | event::price | event::up)):
                $this->trade($event,$this->bet_usd);
                break;
            case ($event->has_flags(event::moved_half_point | event::price | event::up)):
                //$this->trade($event,38);
                break;
            case ($event->has_flags(event::moved_point | event::price | event::down)):
                $this->trade($event,-$this->bet_usd);
                break;
            case ($event->has_flags(event::moved_half_point | event::price | event::down)):
                //$this->trade($event,38);
                break;
            case ($event->has_flags(event::price | event::up)):
                break;
            case ($event->has_flags(event::price | event::down)):
                break;
            case ($event->has_flags(event::large_buy)):
                break;
            case ($event->has_flags(event::large_sell)):
                break;
        }
    }

    public function receive_action(action &$action)
    {


    }

    public function send_action($to_object, action $action)
    {
        if(isset($this->messengers[$to_object]))
        {
            if($action->has_flags(action::watch_trade))
                $this->watched_channels[$action->site_str][]=$action->pair_str;
            call_user_func_array(array(&$this->messengers[$to_object],'receive_action'),array(&$action));
        }

    }

    public function send_event($to_object, event $event)
    {
        if(isset($this->messengers[$to_object]))
            call_user_func_array(array(&$this->messengers[$to_object],'receive_event'),array(&$event));
    }

    //watched channel data arrives here
    public function pair_update(array &$data)
    {
        console(__CLASS__.' '.__FUNCTION__.' got update');
    }

    //messages from exchange connectors arrive here/
    public function connector_update($site,array &$trade_book)
    {
    }



    //Trading
    public static function calc_coins($site,$pair,$usd)
    {
        $pair_id=self::$db_keys['market'][$site][$pair];
        $price=bit_db_uni::get_last_price($pair_id);
        return $usd/$price;
    }

    public static function calc_point($site,$pair,$point)
    {
        $pair_id=self::$db_keys['market'][$site][$pair];
        $price=bit_db_uni::get_last_price($pair_id);
        return $price*($point/100);
    }

    //main trading function only trading btc atm
    public function trade(event &$event,$amount_usd)
    {
        /*
        $event_data=utils::array_pseudo_pop($event->data);
        $amount=$event->type & event::moved_point?50:38;
        if($event->type & event::down && $amount > 0)
            $amount *= -1;
        */
        $new_order=null;
        switch ($event->market_id)
        {
            case (self::$db_keys['market']['bitfinex']['BTCUSD']):
                $amount=self::calc_coins('bitfinex','BTCUSD',$amount_usd);
                $new_order=new order(array('type'=>'MARKET','pair_str'=>'BTCUSD','amount'=>$amount,'call_back'=>array($this,'new_order')));
                break;
            /*
            case (self::$db_keys['market']['bitfinex']['XRPUSD']):
                $amount=self::calc_coins('bitfinex','XRPUSD',$amount_usd);
                $new_order=new order(array('type'=>'MARKET','pair_str'=>'XRPUSD','amount'=>$amount,'call_back'=>array($this,'new_order')));
                break;
            case (self::$db_keys['market']['bitfinex']['ETHUSD']):
                $amount=self::calc_coins('bitfinex','ETHUSD',$amount_usd);
                $new_order=new order(array('type'=>'MARKET','pair_str'=>'ETHUSD','amount'=>$amount,'call_back'=>array($this,'new_order')));
                break;
            */
            default:
                return false;
        }
        $function=$amount>0?'buy':'sell';
        foreach ($this->connectors as &$connection)
            if($connection->$function($new_order))
            {
                //100-$this->stop_points
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
                $na = new action(action::watch_trade,array('site_str'=>$connection->get_site(),'pair_str'=>$new_order->pair_str,'order'=>&$new_order,'trader_obj'=>$connection));
                $na->set_callback($this,'pair_update');
                $this->send_action('scribe',$na);
                break;
            }
    }

}

?>