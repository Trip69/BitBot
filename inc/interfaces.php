<?php
namespace bitbot;

//Interfaces

interface pseudo_runable
{
    function init(array $options);
    function run();
    function get_is_running();
    function stop();
}

interface bitbot_recorder
{
    public function __construct(array $prams,array $options = null);
    public function get_site();
    public function set_record($function,$pair_str,$market_id);
    public function get_record($function,$pair_str);
    public function subscribe_channel($function,$pair_str);
    public function clear_record();
    public function test_code();
    public function connect();
    public function check_connection();
    public function record();
    public function disconnect();
}

interface bitbot_trade
{
//    function __construct($site,array $options, &$db);
    public function __construct(array $prams,array $options = null);
    function connect();
    function authenticate($api_key=null,$api_secret=null);
    function get_site();
    function get_account_id();
    function get_margin_pairs();
    function get_usd_pairs();
    function get_orders();
    function buy(order &$new_order);
    function sell(order &$new_order);
    function send_new_order(order &$new_order);
    function update_order($id,array $prams);
    function cancel_order($id);
    function __toString();
}

//Messaging
interface bitbot_message
{
    public function register_for_messages(&$object);
    public function receive_event(event &$event);
    public function receive_action(action &$action);
    public function send_action($to_object, action $action);
    public function send_event($to_object, event $event);
}

class event
{
    const test = 0;

    const up = 1;
    const down = 2;

    //scribe
    const price = 4;
    const moved_half_point = 8;
    const moved_point = 16;
    const volume = 32;
    const volume_zero = 64;
    const large_buy = 128;
    const large_sell = 256;
    const more_buying = 512;
    const more_selling = 1024;

    //analzer
    const az_bt_data = 2048;
    const az_turn = 4096;
    const az_spike = 8192;
    const az_movement = 16384;

    const period_short=60; //seconds
    const period_long=60*60; //seconds

    public $type = null;
    public $priority = null; //1 to ? 1 being highest
    public $market_id = 0;
    public $market_str = '';
    public $price = 0;

    public $a_data = null;
    public $data = null;
    public $send = false;
    public $actioned = false;
    public $recorded = false;

    public function __construct($type,$market_id,$priority,array &$a_data=null)
    {
        $this->type = $type;
        $this->market_id=$market_id;
        $this->priority=$priority;
        $this->a_data = $a_data;
    }

    public function set_var($var,$value,$set_send=true)
    {
        $this->$var = $value;
        if($set_send)
            $this->send=true;
    }

    public function set_ref($var,&$value,$set_send=true)
    {
        $this->$var = $value;
        if($set_send)
            $this->send=true;
    }

    public function set_bit($var,$value,$priority=null,$set_send=true)
    {
        $this->$var |= $value;
        if($set_send)
            $this->send=true;
        if($priority!==null && $this->priority == null)
            $this->priority = $priority;
        elseif($priority < $this->priority)
            $this->priority = $priority;
    }

    public function set_market($market_str,$market_id)
    {
        $this->market_str=$market_str;
        $this->market_id=$market_id;
    }

    public function has_flags($flags)
    {
        return ($this->type & $flags) == $flags;
    }

}

class action
{
    //trader actions
    const buy=1;
    const sell=2;
    //recorder actions
    const watch_ticker=4;
    const watch_trade=8;
    const watch_unregister=16;
    const report_trades=32;

    const market_bt=16;
    const market_alt_coin=64;

    const amount_low=128;
    const amount_med=256;
    const amount_high=512;

    public $type_flags=0;
    public $site_str=null;
    public $pair_str=null;
    public $market_str=null;
    public $market_id=null;
    public $trader_obj=null;
    public $actioned = false;
    public $recorded = false;
    public $order = null;

    public $send_to_function=null;

    public function __construct($type,array $prams)
    {
        utils::set_prams($this,$prams);
        /*
        $this->type_flags |= $type;
        if(isset($prams['site_str']));
            $this->site_str=$prams['site_str'];
        if(isset($prams['pair_str']));
            $this->pair_str=$prams['pair_str'];
        if(isset($prams['trader_obj']));
            $this->trader_obj = $prams['trader_obj'];
        */
    }

    public function set_callback(&$obj,$function)
    {
        $this->send_to_function=array($obj,$function);
    }

    public function has_flags($flags)
    {
        return $this->type_flags & $flags == $flags;
    }
}

class messaging
{
    public static function register_for_messages(&$object)
    {
        $na=null;
        switch(true)
        {
            case ($object instanceof trader):
                $na=array('trader'=>&$object);
                break;
            case ($object instanceof analyser):
                $na=array('analyser'=>&$object);
                break;
            case ($object instanceof scribe):
                $na=array('scribe'=>&$object);
                break;
        }
        return $na;
    }
}
//Messaging END

//Threading

class pseudo_thread
{
    const usleep=1000;
    const usleep_inc=10000;
    const usleep_max=1000000;
    const thread_default=array(
        'id'=>null,
        'start'=>null, //time
        'object'=>null,
        'class'=>null,
        'started'=>false,
        'running'=>false,
        'finished'=>false //time
    );
    const update_check_interval=30;//seconds

    private $threads=array();
    private $time_started=null;
    private $dy_sleep=100;
    private $enabled=false;
    private $thread_config_check=null;

    public function __construct()
    {
        $this->enabled=(bool)bit_db_uni::get_config('thread_enabled');
        $this->thread_config_check=time()+self::update_check_interval;
    }

    public function create_thread(&$object,array $options)
    {
        if($object === null)
            throw new BitBotError('No object passed to '.__FUNCTION__);
        $new_thread=$this::thread_default;
        $new_thread['id']=count($this->threads);
        $new_thread['object']=$object;
        $new_thread['class']=get_class($object);
        call_user_func(array($object,'init'),$options);
        $this->threads[$new_thread['id']]=$new_thread;
    }

    public function start(&$object)
    {
        foreach($this->threads as $id => &$thread_info)
            if ($thread_info['object']===$object)
            {
                $thread_info['start']=time();
                $thread_info['started']=true;
                $thread_info['running']=true;
                call_user_func(array($thread_info['object'],'run'));
                break;
            }
    }

    public function stop(&$object)
    {
        foreach($this->threads as $id => $thread_info)
        {
            if ($object==$thread_info['object'])
            {
                call_user_func(array($thread_info['object'],'stop'));
                $thread_info['running']=false;
                break;
            }
        }
    }

    public function run()
    {
        $last_update=null;
        $this->time_started=time();
        $thread_restart=false;
        do {
            $work_done=false;
            foreach($this->threads as $id => $thread_info)
            {
                //todo: implement return on run to state if work is done, if not in sleep
                $result=call_user_func(array($thread_info['object'],'run'));
                if($result===true)
                    $work_done=true;
                if($result===null)
                    console($thread_info['class'].' returned null');
            }
            if($work_done)
                $this->dy_sleep=self::usleep;
            elseif(!$work_done && $this->dy_sleep < self::usleep_max)
                $this->dy_sleep = $this->dy_sleep + self::usleep_inc;
            if(time()>$this->thread_config_check)
            {
                $this->enabled=(bool)bit_db_uni::get_config('thread_enabled');
                $thread_restart=(bool)bit_db_uni::get_config('thread_restart');
                if($last_update!==null && $last_update !== (int)bit_db_uni::get_config('thread_last_update'))
                {
                    global $argv;
                    if (!(isset ($argv[1]) && $argv[1]=='override=1'))
                    {
                        console("Thread: Dupe process detected");
                        exit('Dupe');
                    }
                }
                $last_update=time();
                bit_db_uni::set_config('thread_last_update',$last_update);
                $this->thread_config_check=time()+self::update_check_interval;

            }
            //if($this->dy_sleep = self::usleep_max) console('sleep at max');
            usleep($this->dy_sleep);
        } while ($this->get_threads_running() && $this->enabled && !$thread_restart);
        if($this->get_threads_running())
            foreach($this->threads as &$object)
                $this->stop($object);
        bit_db_uni::set_config('thread_restart',0);
        bit_db_uni::set_config('thread_exited',time());
    }

    private function get_threads_running()
    {
        $count=0;
        foreach($this->threads as $id => $thread_info)
        {
            if(call_user_func(array($thread_info['object'],'get_is_running')))
                $count++;
        }
        return $count>0;
    }
}

//Threading End

//Objects
class wallet
{
    const wallet_default=array('exchange'=>array(),'trading'=>array(),'margin'=>array(),'funding'=>array());

    private $wal = self::wallet_default;

    public function __construct()
    {

    }

    public function set_currency($wallet_type,$currecy_str,$amount,$interest_owed,$available)
    {
        $this->wal[$wallet_type][$currecy_str]=array('amount'=>$amount,'interest_owed'=>$interest_owed,'available'=>$available);
    }
}

class order
{
    public static $db=null;

    const order_types = array(
        'MARKET',
        'EXCHANGE MARKET',
        'LIMIT',
        'EXCHANGE LIMIT',
        'STOP',
        'EXCHANGE STOP',
        'TRAILING STOP',
        'EXCHANGE TRAILING STOP',
        'FOK',
        'EXCHANGE FOK',
        'STOP LIMIT',
        'EXCHANGE STOP LIMIT'
    );
    const fees=array(
        'bitfinex_margin'=>0.2, //percent fee
        'bitfinex_margin_offset'=>0.2, //percent
        'bitstamp'=>0.25); //percent fee
    const order_options = array(
        'delta'=>null,
        'price_aux_limit'=>null,
        'price_oco_stop'=>null, //decimal string
        'flags'=>null, //64 hide, 512 close position, 1048 ensure no flip (reduce only) 4096 post_only  - The post-only limit order option ensures the limit order will be added to the order book and not match with a pre-existing order.
        'gid'=>null, //group id
        'price_trailing'=>null,
        'tif'=>null);//date time human readable of cancel time.
    const flags=array(
        'hidden'=>64,
        'close'=>512,
        'reduce_only'=>1024,
        'post_only'=>4096,
        'oco'=>16384);
    const bitfinex_order_seq=array(
        0=>'id',
        1=>'group_id',
        2=>'uid', //bf client_id = my uid
        3=>'pair_str', //bf pair = my pair_str
        4=>'time_ms_create',
        5=>'time_ms-update',
        6=>'amount',
        7=>'amount_original',
        8=>'type',
        9=>'type_previous',
        12=>'flags',
        13=>'status',
        16=>'price',
        17=>'price_av',
        18=>'price_trailing',
        19=>'price_aux_limit',
        23=>'notify',
        25=>'place_id',
        28=>'BFX'); //goes to 31
    public static $bitfinex_order_seq_ass=null;

    public $account_id=null;

    public $id=null;
    public $uid=null;
    public $type=null;
    public $pair_str = '';
    public $amount = 0;
    public $margin = null;
    public $flags=0;
    public $price=0;
    public $price_oco_stop=0;
    public $status='';
    public $call_back=null;
    public $trader_obj=null;
    public $executed = false;

    //$id=null,$type,$pair_str,$amount,$price=null
    public function __construct(array $prams,$account_id=null)
    {
        $this->account_id=(int)$account_id;
        if(utils::array_is_associative($prams))
            utils::set_prams($this,$prams);
        else
            $this->bitfinex_order($prams);
    }

    public function update_order(array $prams)
    {
        utils::set_prams($this,$prams,true);
    }

    //updates prams and updates database
    public function bitfinex_order(array $data_arr)
    {
        foreach($data_arr as $key => $data)
        {
            if($key==3)
            {
                $this->margin=$data[0]=='t';
                $this->pair_str=substr($data,1);
            }
            elseif($data !==null && isset(self::bitfinex_order_seq[$key]))
                $this->{self::bitfinex_order_seq[$key]}=$data;
        }
        //if($this->account_id!==null) todo: this is not need I guess
        $this::$db->bitfinex_order_update($this->account_id,$data_arr);
        switch($this->status)
        {
            case 'CANCELED':
            case 'EXECUTED':
            case substr($this->status,0,8)=='EXECUTED':
                $this::$db->table_move_to_history('bitfinex',$this->account_id,'order',null,$this->id);
        }
    }

    public function get_bitfinex_encode($type_str)
    {
        $obj=new \stdClass();
        switch($type_str)
        {
            case 'oc':
                $obj->id = $this->id;
                break;
            case 'ou':
                $obj->id = $this->id;
            case 'on':
                $to_add=array_intersect_key(get_object_vars($this),self::order_options);
                foreach($to_add as $key => $value)
                    $obj->$key = $value;
                $obj->cid=time();
                $this->uid=$obj->cid;
                $obj->type=$this->type;
                $obj->symbol='t'.$this->pair_str;
                $obj->amount=(string)round($this->amount,6);
                switch($obj->type)
                {
                    case 'MARKET':
                    case 'EXCHANGE MARKET':
                        break;
                    default:
                        $obj->price = (string)$this->price;
                }
        }
        $data=array(0,$type_str,null,$obj);
        $json=json_encode($data);
        console('DEBUG: '.$json);
        return $json;
    }

    public function equals(array $order_data)
    {
        if(substr($order_data[3],1)<>$this->pair_str)
            return false;
        if($order_data[6]<>$this->amount)
            return false;
        if($order_data[8]<>$this->type)
            return false;
        switch($order_data[8])
        {
            case 'MARKET':
            case 'EXCHANGE MARKET':
                break;
            default:
                if($order_data[16]<>$this->price)
                    return false;
        }
//        if($order_data[2]<>$this->cid)
//            return false; this should be thatr case but seems they a slightly different nmumber
        if($order_data[16][0]=='t')
            $this->margin=true;
        $this->id = $order_data[0];
        return true;

    }

    public function __toString()
    {
        return $this->type.' '.$this->amount.' '.$this->pair_str.' $'.$this->price.' '.$this->status.' id:'.$this->id;
    }

    public function echo_success()
    {
        $transaction=$this->amount>0?'bought':'sold';
        return "Trader $transaction ".$this->amount.' '.$this->pair_str.' on '.$this->type;
    }

    public function get_profit_point()
    {
        switch(true)
        {
            case ($this->trader_obj instanceof bitfinex_trader):
                break;
            case ($this->trader_obj instanceof ccxt_trader):
                break;
        }

    }
}

class position
{
    const bitfinex_position_seq = array(
        0=>'pair_str',
        1=>'status',
        2=>'amount',
        3=>'price',
        4=>'margin_funding',
        5=>'funding_type',
        6=>'pl',
        7=>'pl_points',
        8=>'liquid_price',
        9=>'leverage'
    );
    public static $bitfinex_position_seq_ass = null;

    public $site_str=null;
    public $account_id=null;

    public $pair_str;
    public $amount;
    public $status;
    public $price;
    public $pl_points;
    public $pl;
    public $fee;
    public $db;
    public $db_id;

    //public function __construct($pair_str,$amount,$status,$price)
    public function __construct(array $prams)
    {
        utils::set_prams($this,$prams);
        $this->update_db();
    }

    //public function set_prams($amount,$status,$price)
    public function set_prams($prams)
    {
        utils::set_prams($this,$prams);
    }

    public function bitfinex_set_data(array $data)
    {
        foreach($data as $key => $val)
        {
            if($key==0)
                $val=substr($val,1);
            $property=self::bitfinex_position_seq[$key];
            $this->$property=$val;
        }
        $this->update_db();
    }

    private function update_db()
    {
        //$site_name,$account_id,$table,array $exiting_items=null,$completed_id=null,$all=false
        $this->db->table_move_to_history(
            $this->site_str,
            $this->account_id,
            'position',
            null,
            $this->db_id,
            false);
        if($this->status!=='CLOSED')
        {
            $ret= $this->db->position_update(
                $this->site_str,
                $this->account_id,
                $this->pair_str,
                $this->amount,
                true,
                $this->status,
                $this->price);
            $this->db_id=(int)$ret;
        }
    }

    public static function bitfinex_new_position(&$db,$account_id,array $data_arr)
    {
        $prams=array();
        foreach($data_arr as $key => $data)
        {
            if($key==0)
                $prams[self::bitfinex_position_seq[$key]]=substr($data,1);
            elseif($data !==null && isset(self::bitfinex_position_seq[$key]))
                $prams[self::bitfinex_position_seq[$key]]=$data;
            elseif($data !==null)
                console('DEBUG:data not recorded bitfinex_order_seq key:'.$key.' data:'.$data);
        }
        $prams['site_str']='bitfinex';
        $prams['account_id']=$account_id;
        $prams['db']=&$db;
        $ret = new position($prams);
        return $ret;
    }

    public function __toString()
    {
        return implode(' ',array(
            $this->site_str,
            $this->account_id,
            $this->pair_str,
            $this->amount,
            $this->price,
            $this->pl)); //todo pl is not updating after calc so meh fix this
    }
}

class trade_book
{

    const account_defaults = array(
        'wallet'=>null,
        'orders_sent'=>array(),
        'orders_complete'=>array(),
        'orders_fail'=>array(),
        'orders'=>array(),
        'positions'=>array(),
        'function'=>'',
        'last_code'=>'',
        'trader_callback'=>array()
        );

    public static $sites = array();
    public static $bookmarks = array();

    public function __construct(&$trader_obj)
    {
        //fill sites
        $sites=bit_db_uni::get_site_ids(false,true);
        foreach($sites as $site)
            self::$sites[$site['name']]=array('accounts'=>array());

        //fill accounts
        $accounts=bit_db_uni::get_account_ids();
        foreach($accounts as $idx => $account)
        {
            self::$sites[$account['name']]['accounts'][$idx]=self::account_defaults;
            self::$sites[$account['name']]['accounts'][$idx]['function']=$account['function'];
            self::$sites[$account['name']]['accounts'][$idx]['wallet']=new wallet();
            self::$sites[$account['name']]['accounts'][$idx]['trader_callback']=array($trader_obj,'connector_update');
            self::$sites[$account['name']]['accounts'][$idx]['account_id']=$idx;
        }
    }

    public static function &get_book(bitbot_trade &$trader,$account)
    {
        self::$bookmarks[(string)$trader]= &self::$sites[$trader->get_site()]['accounts'][$account];
        return self::$sites[$trader->get_site()]['accounts'][$account];
    }

    public static function bitfinex_order_result(bitbot_trade &$trader,$function,$success,array $order_data)
    {
        switch(true)
        {
            case ($function=='n' && $success):
                //moves orders from sent to orders
                foreach(self::$bookmarks[(string)$trader]['orders_sent'] as $key => $order)
                    if($order->uid == $order_data[2] || $order->id == $order_data[0])// || $order->equals($order_data))
                    {
                        unset(self::$bookmarks[(string)$trader]['orders_sent'][$key]);
                        $order->bitfinex_order($order_data);
                        self::$bookmarks[(string)$trader]['orders'][$order->id]=$order;
                        return $order;
                    }
                //fixes uid as key in orders and updates
                foreach(self::$bookmarks[(string)$trader]['orders'] as $key => $order)
                {
                    if($key == $order->uid) // || $order->equals($order_data)
                    {
                        unset(self::$bookmarks[(string)$trader]['orders'][$key]);
                        $order->bitfinex_order($order_data);
                        self::$bookmarks[(string)$trader]['orders'][$order->id] = $order;
                        return $order;
                    } elseif($order->uid == $order_data[2] || $order->id == $order_data[0])
                        $order->bitfinex_order($order_data);
                }
                break;
            case ($function=='tu'):
                foreach(self::$bookmarks[(string)$trader]['orders'] as $id => $order)
                {
                    if($order->uid == $order_data[2] || $order->id == $order_data[0])
                        $order->bitfinex_order($order_data);
                    //console('DEBUG tu order status:'.$order->status);
                    if(substr($order->status,0,8)=='EXECUTED')
                    {
                        unset(self::$bookmarks[(string)$trader]['orders'][$id]);
                        self::$bookmarks[(string)$trader]['orders_completed'][$order->id] = $order;
                    }
                }
                break;
        }
        return false;
    }

    //updates or creates new bitinex order and returns it
    public static function &bitfinex_update_or_new_order(bitbot_trade &$trader, array $data)
    {
        if(isset(self::$bookmarks[(string)$trader]['orders'][(string)$data[order::$bitfinex_order_seq_ass['id']]]))
        {
            $order = self::$bookmarks[(string)$trader]['orders'][(string)$data[order::$bitfinex_order_seq_ass['id']]];
            $order->bitfinex_order($data);
            switch($order->status)
            {
                case 'CANCELED':
                case substr($order->status,0,8)=='EXECUTED':
                case 'EXECUTED':
                    self::$bookmarks[(string)$trader]['orders_complete'][(string)$order->id]=$order;
                    unset(self::$bookmarks[(string)$trader]['orders'][(string)$data[order::$bitfinex_order_seq_ass['id']]]);
                    break;

            }
            return $order;
        }
        else {
            $no = new order($data, $trader->get_account_id());
            self::$bookmarks[(string)$trader]['orders'][(string)$no->id] = $no;
            return $no;
        }
    }

    public static function debug_queue_keys(bitbot_trade &$trader)
    {
        //$ret='';
        //if(count(self::$bookmarks[(string)$trader]['orders_sent'])>0)
            $ret='sent_orders :'.implode(',',array_keys(self::$bookmarks[(string)$trader]['orders_sent'])).PHP_EOL;
        //if(count(self::$bookmarks[(string)$trader]['orders'])>0)
            $ret.='order :'.implode(',',array_keys(self::$bookmarks[(string)$trader]['orders'])).PHP_EOL;
        //if(count(self::$bookmarks[(string)$trader]['orders_complete'])>0)
            $ret.='order_complete :'.implode(',',array_keys(self::$bookmarks[(string)$trader]['orders_complete'])).PHP_EOL;
        return $ret;
    }

}

class bitfinex_calc
{
    public $vals=array();
    private $vals_flat=array();
    private $send=false;
    public static $last_calc=array(
        'account_id'=>null,
        'vals'=>null,
        'time'=>null);

    private $account_id;

    public function __construct($account_id)
    {
        $this->account_id = $account_id;
    }

    public function add($value)
    {
        $this->vals[]=array($value);
        $this->vals_flat[]=$value;
        $this->send=true;
    }

    public function get_send()
    {
        //no data in request
        if(!$this->send)
            return false;
        //nothing ever sent or different account;
        if(self::$last_calc['account_id']==null || self::$last_calc['account_id'] !== $this->account_id)
            return true;
        //same request same account within 5 seconds, stops looping request when the server seems unable to provide information
        if(self::$last_calc['vals']==implode(',',$this->vals_flat)
            && (self::$last_calc['time'] + 5 > time())
            && self::$last_calc['account_id'] == $this->account_id)
                return false;
        return true;

    }

    public function get_request()
    {
        $data = array(0,'calc',null,$this->vals);
        self::$last_calc['account_id']=$this->account_id;
        self::$last_calc['vals']=implode(',',$this->vals_flat);
        self::$last_calc['time']=time();
        return json_encode($data);
    }

}
?>