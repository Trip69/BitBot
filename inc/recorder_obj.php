<?php
namespace bitbot;

class bitbot_ccxt implements bitbot_recorder
{
    const default_ccxt_item = array (
        'run_last'=>null,
        'run_next'=>null,
        'data_current'=>null,
        'data_last'=>null,
        'market_id'=>null,
        'pair'=>null,
        'symbol'=>null,
        'function'=>null,
        'last_ticker_price'=>null,
        'first_ticker_price'=>null,
        'daily_volume'=>null,
        'daily_high'=>null,
        'daily_low'=>null
    );

    const min_ccxt_timeout=10000;
    const max_ccxt_timeout=15000;
    const min_ccxt_req_interval=2; //seconds

    private $site='';
    private $arr_jobs = array();
    private $job_delay = 0;

    private $ccxt = null;
    private $callback = null;

    private $time_start=null;

    public $connection_fails=0;

    public function __construct(array $prams,array $options = null)
    {
        $this->site = $prams['site'];
        $obj = 'ccxt\\'.$prams['site'];
        $this->ccxt = new $obj;
        $this->callback = $prams['write_callback'];
    }

    public function get_site()
    {
        return $this->site;
    }

    public function set_record($function,$market,$market_id)
    {

        $new_job=$this::default_ccxt_item;
        switch($function)
        {
            case 'ticker':
                $new_job['pair']=$market;
                $new_job['market_id']=$market_id;
                $new_job['function']="fetch_$function";
                break;
        }
        $this->arr_jobs[]=$new_job;
    }

    public function get_record($function, $market_str)
    {
        // TODO: Implement get_record() method.
    }

    public function clear_record()
    {
        $this->arr_jobs=array();
    }

    public function test_code()
    {
        return true;
    }

    public function connect()
    {
        try {
            $this->ccxt->load_markets();
            foreach($this->arr_jobs as &$job)
                $job['symbol']=$this->ccxt->marketsById[strtolower($job['pair'])]['symbol'];
        } catch (\Exception $ex) {
            console ('Scribe: Error connecting to '.$this->site."\r\n$ex");
            return false;
        }
        //todo this is somehow wrong
        $this->job_delay=round(count($this->arr_jobs)/60,2);
        if($this->job_delay<$this::min_ccxt_req_interval)
            $this->job_delay+=$this::min_ccxt_req_interval;
        $count=0;
        foreach($this->arr_jobs as &$job)
        {
            $count++;
            $job['run_next']=time()+($this->job_delay*$count);
        }
        return true;
    }

    public function subscribe_channel($function, $pair_str)
    {
        // TODO: Implement subscribe_channel() method.
    }

    public function record()
    {
        if($this->time_start === null)
            $this->time_start=time();
        foreach($this->arr_jobs as &$job)
            if (time() > $job['run_next'])
            {
                $job['data_last']=$job['data_current'];
                $job['run_last'] = time();
                try {
                    switch($job['function'])
                    {
                        case 'fetch_ticker':
                            $job['data_current'] = $this->ccxt->{$job['function']}($job['symbol']);
                            $this->format_data($job,null);
                            break;
                    }
                    if ($this->ccxt->timeout > $this::min_ccxt_timeout)
                        $this->ccxt->timeout-=1000;
                } catch (\Exception $ex)
                {
                    if ($ex instanceof \ccxt\RequestTimeout)
                    {
                        if ($this->ccxt->timeout < $this::max_ccxt_timeout)
                            $this->ccxt->timeout+=1000;
                        console($this->site.' ccxt timeout getting '.$job['pair'].' ('.($this->ccxt->timeout/1000).'s) '.$this->job_delay.' delay.');
                    }
                    else
                        console($this->site.' ccxt error getting '.$job['pair']."\r\n$ex");
                }

                $job['run_next'] = time() + $this->job_delay;
                break;
            }
    }

    public function format_data(&$job,$data)
    {
        $prams=array($this,&$job,$job['data_current']);
        call_user_func_array($this->callback,$prams);
    }

    public function disconnect()
    {
        return true;
    }

    public function check_connection()
    {
        //todo some ping or smth?
        return true;
    }
}

class bitbot_bitfinex implements bitbot_recorder
{
    const bitfinex_connect = 1;
    const bitfinex_hb = 2;
    const bitfinex_data = 3;
    const bitfinex_subscribed = 4;
    const bitfinex_unsubscribed = 5;
    const bitfinex_pong = 6;
    const bitfinex_authenticated = 7;
    const bitfinex_authenticated_failed = 8;
    const bitfinex_order_fail = 9;
    const bitfinex_order_success = 10;

    const bitfinex_apikey_digest_invalid=10100;
    const bitfinex_already_authenticated=10101;
    const bitfinex_payload_error=10102;
    const bitfinex_authentication_sig_error=10103;
    const bitfinex_authentication_hmac_error=10104;
    const bitfinex_subscription_invalid=10300;
    const bitfinex_subscription_duplicate=10301;
    const bitfinex_subscription_limit=10305;
    const bitfinex_stopping=20051;

    const bitfinex_api_verion=2;//1.1 or 2

    const bitfinex_item_default=array(
        'market_id'=>null,
        'subscribed'=>false,
        'pair'=>null,
        'function'=>null,
        'channel'=>null,
        'callback'=>null,
        'hb'=>null,
        'last_ticker_price'=>null,
        'first_ticker_price'=>null,
        'daily_volume'=>null,
        'daily_high'=>null,
        'daily_low'=>null,
        'tickers'=>array());

    const websocket_timeout=10; //seconds
    const ws_hb_timeout = 60; //seconds
    const ws_subsciption_pause=1;//seconds;
    const ws_subsciption_pause_after=3;//number of subscriptions to bulk send
    const ws_timeouts = 5;

    protected $ws=null;
    protected $ws_last_data_time=null;

    protected $site='';
    protected $url='';
    protected $channel_count=0;

    public $connection_fails=0;

    protected $arr_worklist = array();
    protected $arr_channel = array();

    protected $callback=null;

    public function __construct(array $prams,array $options = null)
    {
        $this->site=$prams['site'];
        $this->callback = $prams['write_callback'];
        $this->url=bit_db_uni::get_site_connection_method($prams['site'])['connection_url'];
    }

    public function set_record($function,$pair_str,$market_id)
    {
        if (!isset($this->arr_worklist[$function]))
            $this->arr_worklist[$function] = array();
        $this->arr_worklist[$function][$pair_str] = $this::bitfinex_item_default;
        $this->arr_worklist[$function][$pair_str]['market_id']=$market_id;
        $this->arr_worklist[$function][$pair_str]['function']=$function;
        $this->arr_worklist[$function][$pair_str]['pair']=$pair_str;
        $this->channel_count++;
        return $this->arr_worklist[$function][$pair_str];
    }

    public function get_record($function, $market_str)
    {
        if(isset($this->arr_worklist[$function][$market_str]))
            return $this->arr_worklist[$function][$market_str];
        return null;
    }

    public function clear_record()
    {
        $this->arr_worklist=array();
        $this->arr_channel = array();
    }

    /**
     * connects to ws and subscribes to channels returns false on failure
     * @return bool
     */
    public function connect()
    {
        $options=array($this::websocket_timeout);
        $this->ws=new \WebSocket\Client($this->url,$options);
        console('Connecting to '.$this->site);
        if(!$this->wait_for_data(null,$this::bitfinex_connect))
            return false;
        console('Connected to '.$this->site.'. Connecting '.$this->channel_count.' channels');
        $count=0;
        foreach ($this->arr_worklist as $function => $market_data_arr)
            foreach ($market_data_arr as $pair => $market_data)
            {
                $count++;
                $res=$this->connect_channel($market_data);
                switch(true)
                {
                    case $res === true:
                        break;
                    case $res == $this::bitfinex_subscription_invalid:
                        console($this->site." pair invalid: $pair");
                        unset($market_data_arr[$pair]);
                        break;
                    case $res === false:
                        return false;
                }
                $pause=time()+$this::ws_subsciption_pause;
                while ($count>$this::ws_subsciption_pause_after && time()<$pause)
                {
                    $this->wait_for_data(null,null);
                    $count=0;
                }
            }
        console($this->site.' subscribed to '.count($this->arr_channel).' channels');
        return true;
    }

    public function subscribe_channel($function, $pair_str)
    {
        $channel=self::get_record($function, $pair_str);
        if($channel==null)
            $channel=self::set_record($function,$pair_str,bit_db_uni::get_market_id($this->site,$pair_str));
        if(!isset($channel['subscribed']) |! $channel['subscribed'])
            self::connect_channel($channel);
    }

    public function disconnect()
    {
        $socket_dead = false;
        foreach($this->arr_channel as $channel => &$channel_info)
        {
            if (!$socket_dead && isset($channel_info['subscribed']) && $channel_info['subscribed']) //todo this is not always set
                $socket_dead=$this->disconnect_channel($channel_info);
            unset($this->arr_channel[$channel]);
        }
        if($this->ws->isConnected())
        {
            try {
                $this->ws->close();
                //$a = new \WebSocket\Client('asas'); //Testing functions
            } catch (\Exception $ex)
            {
                console($this->site." error disconnecting.\r\n$ex");
                //attempt to solve endless loop due to broken socket
                $this->ws=null;
                $this->ws=new \WebSocket\Client($this->url,array($this::websocket_timeout));
            }
        }
    }

    public function check_connection()
    {
        $hb_req=time()-$this::ws_hb_timeout;
        $dead=array();
        foreach ($this->arr_channel as $key => &$record_info)
            if ((bool)$record_info['subscribed'] && !is_null($record_info['hb']) && $record_info['hb']<$hb_req)
                $dead[$key]= &$record_info;
        if(count($dead)==count($this->arr_channel))
            return false;
        elseif ($this->ws->isConnected())
            foreach ($dead as $key => &$record_info)
            {
                console('Channel dead '.$record_info['pair'].' reconnecting');
                if(!$this->disconnect_channel($record_info))
                    return false; //trigger reconnect
                elseif (!!$this->connect_channel($record_info))
                    return false; //trigger reconnect
            }
        return true;
    }

    public function ping($echo=true)
    {
        $data = json_encode(array('event'=>'ping'));
        $this->wait_for_data($data,$this::bitfinex_pong,null,$echo);
    }

    public function get_site()
    {
        return $this->site;
    }

    public function get_last_data_time()
    {
        return $this->ws_last_data_time;
    }

    protected function connect_channel(&$item)
    {
        $version=$this::bitfinex_api_verion==2?'t':'';
        $data=array('event'=>'subscribe','channel'=>$item['function'],'pair'=>$version.$item['pair']);
        $data=json_encode($data);
        //console($this->site." subscribing to $pair");
        $res=$this->wait_for_data($data,$this::bitfinex_subscribed);
        switch (true)
        {
            case $res == $this::bitfinex_subscription_invalid:
            case $res == $this::bitfinex_subscribed:
            case $res === true:
                return $res;
            case $res == $this::bitfinex_subscription_duplicate:
                return true; //TODO I think this will work?
            case $res === false:
            default:
                //todo test this... doesn't seem to work.
                console("Resending request to subscribe to ".$item['pair']);
                return $this->wait_for_data($data,$this::bitfinex_subscribed);
        }
        return $res; //this _SHOULD_ be unreachable
    }

    public function disconnect_channel(array &$item)
    {
        $data=json_encode(array('event'=>'unsubscribe','chanId'=>$item['channel']));
        //if error or no this channel is still disconnected
        //console($this->site.' unsubscribing from '.$item['pair']);
        try {
            return !$this->wait_for_data($data,$this::bitfinex_unsubscribed);
        } catch (\Exception $ex)
        {
            console($this->site.' socket dead.');
            return false;
        }

    }

    public function wait_for_data($send,$code,$fail_code=null,$echo=true)
    {
        $result=null;
        try
        {
            $sanity=time()+$this::ws_hb_timeout;
            if($send!==null)
                $this->ws->send($send);
            do {
                $data = $this->ws->receive();
                if ($code===null && $data === null)
                    return null;
                elseif ($code)
                    while ($data === null && time()<$sanity)
                    {
                        $data = $this->ws->receive();
                        usleep(100);
                        if(time()>$sanity)
                            console($this->site.' timeout waiting for code #'.$code);
                    }
                $result = $this->parse_data($data,$echo);
                $this->ws_last_data_time=time();
                if (is_array($result) && $result['event']=='error')
                    return $result['code'];
                if($fail_code!==null && $result==$fail_code)
                    return $result;
                if(time() > $sanity)
                {
                    console("Timeout wait for $code");
                    return false;
                }
            } while ($code !== null &&  $result !== $code);
            //console("code :$code");
        } catch (\Exception $ex) {
            if (($ex instanceof \WebSocket\ConnectionException) && $ex->getCode() == 0)
            {
                console("Error waiting for data.\r\n$ex");
                return false;
            }
            else
                console("Error waiting for code: $code".PHP_EOL.$ex->getMessage());
            return false;
        }
        return $result;
    }

    //return event numbers
    public function parse_data($data,$echo=true)
    {
        $decode=json_decode($data);
        switch (true)
        {
            case (is_array($decode) && isset($decode[1]) && $decode[1]=='hb'):
                $this->arr_channel[$decode[0]]['hb']=time();
                return $this::bitfinex_hb;
            case (is_array($decode)):
                $result=$this->format_data($decode);
                if($result===true)
                    return $this::bitfinex_data;
                else
                    return $result;
            case (isset($decode->event)):
                switch($decode->event)
                {
                    case 'auth':
                        if ($decode->status=='OK')
                            console($this->site.' authentication OK');
                        $this->authenticate_info['authenticated'] = $decode->status=='OK';
                        return $decode->status=='OK'?$this::bitfinex_authenticated:$this::bitfinex_authenticated_failed;
                    case 'subscribed':
                        $this->arr_worklist[$decode->channel][$decode->pair]['channel']=$decode->chanId;
                        $this->arr_worklist[$decode->channel][$decode->pair]['pair']=$decode->pair;
                        $this->arr_worklist[$decode->channel][$decode->pair]['subscribed']=true;
                        $this->arr_worklist[$decode->channel][$decode->pair]['function']=$decode->channel;
                        $this->arr_worklist[$decode->channel][$decode->pair]['hb']=time();
                        $this->arr_channel[$decode->chanId]= &$this->arr_worklist[$decode->channel][$decode->pair];
                        //console('Subscribed to '.$decode->pair);
                        return $this::bitfinex_subscribed;
                    case 'unsubscribed':
                        $item = &$this->arr_channel[$decode->chanId];
                        $item['subscribed']=false;
                        $item['channel']=null;
                        //console('Unsubscribed drom '.$item['pair']);
                        return $this::bitfinex_unsubscribed;
                    case 'pong': //tested: works
                        if($echo)
                            console($this->site.' pong.');
                        return $this::bitfinex_pong;
                    case 'info':
                    case ($decode->event == 'info' && isset($decode->platform->status) && $decode->platform->status == 1):
                        //there is no code for this event
                        if($decode->version!==$this::bitfinex_api_verion)
                            throw new BitBotError($this->site.' api version is not '.$this::bitfinex_api_verion. ' but "'.$decode->version.'"');
                        return $this::bitfinex_connect;
                    case ($decode->event == 'info' && $decode->code == $this::bitfinex_stopping):
                        return $this::bitfinex_stopping;
                    case 'error':
                        console($this->site.' error '.$decode->msg.' '.$decode->code);
                        return array('event'=>'error','msg'=>$decode->msg,'code'=>$decode->code);
                }
            case $data===null:
                return null;
            default :
                console($this->site." unhandled data: ".var_dump($data),false,true);
                return 'error';
        }
    }

    //standardises data and passes to callback
    public function format_data($decode)
    {
        //console($decode);
        switch($this::bitfinex_api_verion)
        {
            case 1.1:
                //authenticated channel
                if($decode[0]==0)
                    switch ($decode[1])
                    {
                        case 'ps':
                        case 'ws':
                        case 'os':
                        default:
                            $prams=array($this,$decode[1],$decode[2]);
                            call_user_func_array($this->callback,$prams);
                            return true;
                    }
                elseif(is_null($this->arr_channel[$decode[0]]))
                {
                    console('Data for unknown channel');
                    return false;
                }
                //format data to that of ccxt for readability
                switch($this->arr_channel[$decode[0]]['function'])
                {
                    case 'ticker':
                        $decode['bid']=$decode[1];
                        $decode['bidVolume']=$decode[2];
                        $decode['ask']=$decode[3];
                        $decode['askVolume']=$decode[4];
                        $decode['change']=$decode[5];
                        $decode['percentage']=$decode[6];
                        $decode['last']=$decode[7];
                        $decode['baseVolume']=$decode[8];
                        $decode['high']=$decode[9];
                        $decode['low']=$decode[10];
                        break;
                    case 'trades':
                        /*
                        $fields=count($decode);
                        console($decode[1]);
                        $decode['price']=$decode[$fields-2];
                        $decode['amount']=$decode[$fields-1];
                         */
                        if(is_array($decode[1])) //historic trades sent first on trade channel
                            break;
                        switch($decode[1])
                        {
                            case 'te':
                                $decode['price']=$decode[4];
                                $decode['amount']=$decode[5];
                                break;
                            case 'tu':
                                $decode['price']=$decode[5];
                                $decode['amount']=$decode[6];
                                break;
                        }
                        break;
                    default:
                        console('unhandled data '.var_dump($decode));
                }
                break;
            case 2:
                //authenticated channel
                if($decode[0]==0)
                {
                    $prams=array($this,$decode[1],$decode[2]);
                    $return=call_user_func_array($this->callback,$prams);
                    return $return;
                }
                elseif(is_null($this->arr_channel[$decode[0]]))
                {
                    console('Data for unknown channel');
                    return false;
                }
                //format data to that of ccxt for readability
                switch($this->arr_channel[$decode[0]]['function'])
                {
                    case 'ticker':
                        $decode['bid']=$decode[1][0];
                        $decode['bidVolume']=$decode[1][1];
                        $decode['ask']=$decode[1][2];
                        $decode['askVolume']=$decode[1][3];
                        $decode['change']=$decode[1][4];
                        $decode['percentage']=$decode[1][5];
                        $decode['last']=$decode[1][6];
                        $decode['baseVolume']=$decode[1][7];
                        $decode['high']=$decode[1][8];
                        $decode['low']=$decode[1][9];
                        break;
                    case 'trades':
                        if(is_array($decode[1])) //historic trades sent first on trade channel
                        {
                            foreach($decode[1] as $key => &$historic)
                            {
                                $historic['amount']=$historic[2];
                                $historic['price']=$historic[3];
                            }
                            break;
                        }
                        /*
                        $fields=count($decode);
                        console($decode[1]);
                        $decode['price']=$decode[$fields-2];
                        $decode['amount']=$decode[$fields-1];
                        */
                        switch($decode[1])
                        {
                            case 'te':
                            case 'tu':
                                $decode['amount']=$decode[2][2];
                                $decode['price']=$decode[2][3];
                                break;
                        }
                        break;
                    default:
                        console('unhandled data '.var_dump($decode));
                }
                break;
        }
        $prams=array($this,&$this->arr_channel[$decode[0]],$decode);
        call_user_func_array($this->callback,$prams);
    }

    public function test_code()
    {
        $data = json_encode(array('event'=>'ping'));
        $this->wait_for_data($data,$this::bitfinex_pong);
    }

    public function record()
    {
        static $timeout=0;
        try {
            $data = $this->ws->receive();
            if($data === null)
                return false;
            $result = $this->parse_data($data);
            switch($result)
            {
                case $this::bitfinex_stopping:
                    $this->disconnect();
                    return true;
                case $this::bitfinex_subscription_limit:
                    console('subscription limit');
                    //todo this will happen
                    return true;
                default:
                    $timeout=0;
            }

        } catch (\Exception $ex) {
            //various socket errors the number is always 0
            if($ex instanceof \WebSocket\ConnectionException)
            {
                $timeout++;
                if($timeout>$this::ws_timeouts)
                    throw $ex;
                //$ping=json_encode(array('event'=>'ping'));
                console($this->site." socket error. ($timeout/".$this::ws_timeouts.")\r\n$ex");
//                if(!$this->wait_for_data($ping,$this::bitfinex_pong)); throw $ex;
            }
        }
        return true;
    }

}

class bitbot_bitstamp extends \trip69\Pusher\Client implements bitbot_recorder
{
    const pusher_default=array(
        'market_id'=>null,
        'subscribed'=>false,
        'pair'=>null,
        'function'=>null,
        'channel'=>null,
        'callback'=>null,
        'last_ticker_price'=>null,
        'first_ticker_price'=>null,
        'daily_volume'=>null,
        'daily_high'=>null,
        'daily_low'=>null);
    
    private $write_callback=null;
    private $arr_worklist = array();

    private $channel_count=0;

    public function __construct(array $prams,array $options = null)
    {
        $site_info=bit_db_uni::get_site_connection_method($prams['site']);
        parent::__construct($prams['site'],$site_info['connection_url']);
        $db=null;
        $this->write_callback = $prams['write_callback'];
    }

    public function get_site()
    {
        return $this->site;
    }

    public function connect()
    {
        console('Connecting to '.$this->site);
        $res = parent::connect();
        if (!$res)
            return false;
        $count = 0;
        console('Connected to '.$this->site.' Connecting '.count($this->arr_worklist['trades']).' channels');
        foreach ($this->arr_worklist as $function => &$market_data_arr)
        {
            foreach ($market_data_arr as $pair => &$market_data)
            {
                $market_data['bitsmap_subs_str']='live_trades_'.strtolower($market_data['pair']);
                $res=$this->subscribe($market_data['bitsmap_subs_str']);
                switch(true)
                {
                    case $res === true:
                        $count++;
                        $market_data['subscribed']=true;
                        break;
                    case $res === false:
                        return false;
                    default:
                        console($this->site.' unhandled connect data '.var_dump($res));
                }
            }
            $bitstamp_event_str='';
            if($function=='trades')
                $bitstamp_event_str='trade';
            $this->add_return_events($bitstamp_event_str);
        }
        console($this->site." subscribed to $count channels");
        return true;
    }

    public function subscribe_channel($function, $pair_str)
    {
        $market_data = null;
        if(!isset($this->arr_worklist[$function][$pair_str]))
            $market_data = &self::set_record($function,$pair_str,bit_db_uni::get_market_id($this->site,$pair_str));
        if(!isset($market_data['bitsmap_subs_str']))
            $market_data['bitsmap_subs_str']='live_trades_'.strtolower($market_data['pair']);
        if($this->subscribe($market_data['bitsmap_subs_str']))
            $market_data['subscribed']=true;
        else
            console($this->site.' error subscribing to '.$market_data['pair']);

    }

    public function disconnect()
    {
        foreach ($this->arr_worklist as $function => $market_data_arr)
            foreach ($market_data_arr as $pair => &$market_data)
            {
                $this->unsubscribe('live_trades_'.strtolower($market_data['pair']));
                $market_data['subscribed']=false;
            }
        parent::disconnect();
    }

    public function check_connection()
    {
        try {
            $this->ping();
        } catch (\Exception $ex)
        {
            console($this->site.' ping failed'.$ex);
            return false;
        }
        return true;
    }

    public function set_record($function, $pair_str, $market_id)
    {
        if (!isset($this->arr_worklist[$function]))
            $this->arr_worklist[$function] = array();
        $this->arr_worklist[$function][$pair_str] = $this::pusher_default;
        $this->arr_worklist[$function][$pair_str]['market_id']=$market_id;
        $this->arr_worklist[$function][$pair_str]['function']=$function;
        $this->arr_worklist[$function][$pair_str]['pair']=$pair_str;
        $this->channel_count++;
        return $this->arr_worklist[$function][$pair_str];
    }

    public function get_record($function, $market_str)
    {
        if(isset($this->arr_worklist[$function][$market_str]))
            return $this->arr_worklist[$function][$market_str];
        return null;
    }

    public function clear_record()
    {
        $this->arr_worklist=array();
        $this->arr_channel = array();
    }

    public static function extract_pair($json_data)
    {
        if(isset($json_data->channel))
        {
            $bits=explode('_',$json_data->channel);
            $bit=array_pop($bits);
            return strtoupper($bit);
        }
        else
            return null;
    }

    public function record()
    {
        static $timeout=0;
        try {
            $result = $this->receive();
            if($result===null)
                return false;
            switch($result)
            {
                case $result==null:
                    return null;
                case (isset($result->event) && $result->event='trade') :
                    if($result->data=='{}')
                        return null;
                    $data=json_decode($result->data);
                    $pair=$this::extract_pair($result);
                    $job=&$this->arr_worklist['trades'][$pair];
                    $pram=array(
                        $this,
                        &$job,
                        array('amount'=>$data->amount,'price'=>$data->price));

                    call_user_func_array($this->write_callback,$pram);
                    break;
                default:
                    console($this->site.' data '.var_dump($result));
                    $timeout=0;
            }
        } catch (\Exception $ex) {
            //various socket errors the number is always 0
            if($ex instanceof \WebSocket\ConnectionException)
            {
                $timeout++;
                if($timeout>$this::data_wait_timeout)
                    throw $ex;
                //$ping=json_encode(array('event'=>'ping'));
                console($this->site." socket error. ($timeout/".$this::data_wait_timeout.")\r\n$ex");
//                if(!$this->wait_for_data($ping,$this::bitfinex_pong)); throw $ex;
            }
        }
        return true;
    }
/*
    private function parse_data($data,$echo=true)
    {
        $decode=json_decode($data);
        switch (true)
        {
            case $decode==null:
                return null;

            default:
                console($this->site." unhandled data: ".var_dump($data),false,true);
                return 'error';
        }
    }
*/
    public function test_code()
    {
        // TODO: Implement test_code() method.
    }

}

class scribe implements bitbot_message, pseudo_runable
{
    const connection_attempts = 3;
    const connection_error_wait = 30;//seconds
    const default_runtime=60*60;//seconds

    const update_interval=10;
    const check_config_interval=60;
    const large_trade_val=20000;
    const alert_points=0.002; //0.2%
    const highlight_points=0.5; //0.5%
    const highlight_volume_req = 500000;
    const highlight_other_channel_points=0.75;

    const site_fails_for_long_pause=9;
    const long_connection_error_pause=20;//seconds
    const short_connection_error_pause=3;//seconds

    private $db = null;
    private $db_key_lookup=null;

    private $messengers = array();
    private $watchers = array('trade'=>array(),'tickers'=>array());

    private $bitbot_cons = array();
    private $arr_sites = array();

    private $time_start = null;
    private $time_finish = null;

    private $time_do_update=null;
    private $time_check_config=null;

    const rt_ap = 1,rt_ahp = 2,rt_bp = 4,rt_bhp = 8;
    const recent_trades_defaults=array(     'volume'=>0,'volume_buy'=>0,'volume_sell'=>0,
                                            'price_open'=>0,'price_high'=>0,'price_low'=>PHP_INT_MAX,'price_last'=>0,
                                            'price_point'=>0,'price_half_point'=>0,
                                            'market_id'=>null,'time'=>0,'report_flags'=>0,'large_trade_val'=>0,'recorded'=>false);
    const recent_trades_retain_minutes=60;
    private $recent_trades=array();
    private $recent_trade_minute=null;

    private $enabled = null;
    private $pseudo_running=null;

    private $arr_highlight=array();

    public function __construct(array &$db_keys)
    {
        console('BitBot Recorder v0.2 By Trip. host:'.gethostname());

        $this->db_key_lookup = $db_keys;
        $this->db = new bit_db_recorder();
        $this->load_config();
    }

    public function __destruct()
    {
        $this->db_key_lookup = null;
        $this->db = null;
        foreach($this->bitbot_cons as  &$connection)
            $connection=null;
        $this->bitbot_cons=null;
    }

    public function load_config($create_objects=true)
    {
        //get market_idx tick trade and connection method
        //TODO there is a bug here on '$item = &$site_function['connection'][$site_function['connection']['connection']];'
        $this->arr_sites=$this->db->get_record_sites();
        $callback=array($this,'write_data');
        $item=null;
        foreach ($this->arr_sites as $site_name => &$site_function)
        {
            if($create_objects)
            {
                $site_function=$this->db->get_record_keys($site_name,'all');
                $site_function['connection']=$this->db->get_site_connection_method($site_name);
                $create_obj_str='\\bitbot\\bitbot_'.$site_function['connection']['connection'];
                $ref_obj_str=$site_function['connection']['connection'];
                $site_function['connection'][$ref_obj_str]=new $create_obj_str(array('site'=>$site_name,'write_callback'=>$callback));
                $this->bitbot_cons[$site_name] = &$site_function['connection'][$ref_obj_str];
                $item = &$site_function['connection'][$ref_obj_str];
                if(!isset($this->recent_trades[$site_name]))
                    $this->recent_trades[$site_name]=array();
            } else {
                $temp_arr=$this->db->get_record_keys($site_name,'all');
                foreach ($temp_arr as $function => $pair_arr)
                    $site_function[$function]=$pair_arr;
                $item = &$this->bitbot_cons[$site_name];
                $item->clear_record();
            }
            foreach($site_function as $function => $pair_arr)
                if(in_array($function,bit_db_recorder::$recorded))
                    foreach($pair_arr as $pair_name => $pair_data)
                    {
                        $item->set_record($function,$pair_name,(int)$pair_data['idx']);
                        if($function=='trades' && !isset($this->recent_trades[$site_name][$pair_name]))
                            $this->recent_trades[$site_name][$pair_name]=array();

                    }
        }
    }

    public function init(array $options = null)
    {
        $this->time_start = time();
        if(isset($options['duration']))
            $this->time_finish=time()+$options['duration'];
        $this->time_do_update=time()+self::update_interval;
        $this->time_check_config=time()+self::check_config_interval;

        $this->enabled=(bool)bit_db_uni::get_config('recorder_enabled');
        $this->recent_trade_minute=round(time()/60)*60;
        //$this->init_new_minute($this->recent_trade_minute);
        $this->pseudo_running=true;

        console('Recording Started');

        foreach($this->bitbot_cons as  &$connection)
            for($a=0;$a<$this::connection_attempts;$a++)
                try
                {
                    $result = $connection->connect();
                    if($result)
                        break;
                    else
                        $connection->disconnect();
                } catch (\Exception $ex) {
                    console('Error connecting to '.$connection->get_site()." $a/3");
                    if($a==$this::connection_attempts-1)
                        //todo one site maybe down don't want the entire program to fail
                        return false;
                    sleep($this::connection_error_wait);
                }

    }

    public function get_is_running()
    {
        return $this->pseudo_running && $this->enabled && (time() < $this->time_finish);
    }

    public function run()
    {
        if ($this->pseudo_running && time()>$this->time_finish)
            $this->stop();
        if(!$this->pseudo_running || !$this->enabled)
            return false;
        static $check_for_dup=null;
        $work_done=false;
        foreach($this->bitbot_cons as  &$connection)
            try {
                if($connection->record())
                    $work_done=true;
                $connection->connection_fails=0;
            } catch (\Exception $ex)
            {
                $connection->connection_fails++;
                for ($a=0;$a<$this::connection_attempts;$a++)
                {
                    console($connection->get_site().' connection error ('.$connection->connection_fails.'). Reconnecting');
                    $connection->disconnect();
                    if($connection->connection_fails>$this::site_fails_for_long_pause)
                        sleep($this::long_connection_error_pause);
                    else
                        sleep($this::short_connection_error_pause);
                    if ($connection->connect())
                        break;
                    else
                        $connection->connection_fails++;
                }
            }
        if (time() > $this->time_do_update)
        {
            if($check_for_dup===true && $this->time_do_update<>(int)bit_db_uni::get_config('recorder_last_update'))
            {
                console('Scribe Dupe Proccess runnging. Exiting.');
                exit('Scribe Dupe Proccess runnging. Exiting.');
            }
            $this->time_do_update = time() + $this::update_interval;
            bit_db_uni::set_config('recorder_last_update',$this->time_do_update);
            $this->enabled=(bool)bit_db_uni::get_config('recorder_enabled');
            $check_for_dup=true;
            $this->check_connection();
            //console('Time remaining '.($finish-time()).'s');
            $work_done=true;
        }
        if (time() > $this->time_check_config)
        {
            //todo check if configuration has changed as below.
            //console('Checking config',false,true);
            $change=$this->check_config();
            if($change)
            {
                console('Scribe configuration has changed, restarting');
                $check_for_dup=null;
                $this->stop();
                $this->load_config(false);
                $this->init();
            }
            $this->time_check_config=time() + $this::check_config_interval;
            $work_done=true;
        }
        //testing *********************************************
        /*
                    static $testing=true;
                    if($testing && time() > $this->start_time + 60)
                    {
                        foreach($this->bitbot_cons as  &$connection)
                            $connection->test_code();
                        $testing = false;
                    }
        */
        //testing STOP ***************************************
        return $work_done;
    }

    //main function for data recording and low level processing.
    public function write_data($caller,&$job_info,$data)
    {
        //Testing
        //testing STO
        $site=$caller->get_site();
        switch ($job_info['function'])
        {
            case 'ticker':
            case 'fetch_ticker':
                //$job_info['tickers'][time()]=$job_info; < causing memory problems on host server
                //console($site.' ticker '.$this->channel_ids[$site][$decode[0]]->pair.' '.implode(',',$decode));
                $this->db->record_ticker($site,$job_info['market_id'],$data);
                if($job_info['last_ticker_price']==null)
                {
                    $job_info['last_ticker_price']=$data['last'];
                    $job_info['first_ticker_price']=$data['last'];
                }
                else
                {
                    $job_info['daily_volume']=$data['baseVolume'];
                    $job_info['daily_high']=$data['high'];
                    $job_info['daily_low']=$data['low'];
                    //significant ticker change
                    $points=utils::percentage($job_info['last_ticker_price']-$data['last'],$job_info['last_ticker_price'],2);
                    if ($points>=$this::highlight_other_channel_points||$points<=-$this::highlight_other_channel_points)
                    {
                        $direction=$job_info['last_ticker_price']<$data['last']?'down':'up';
                        $points_since_start=utils::percentage($job_info['last_ticker_price']-$data['last'],$job_info['first_ticker_price'],2);
                        $points_since_start=" ($points_since_start%)";
                        $highlight=($data['baseVolume']>$this::highlight_volume_req)&&($points>1||$points<-1)?'* ':'';
                        console($highlight.$site.' TI '.$job_info['pair'].' '.$direction." $points% to ".$data['last'].$points_since_start,false,true);
                        $job_info['last_ticker_price']=$data['last'];
                    }
                }
                break;
            case 'trades':
                $highlight=array_search($job_info['pair'],$this->arr_highlight)!==false?'* ':'';
                //array of historic trades from ws bitfninex
                if(isset($data[1]))
                {
                    if(is_array($data[1]))
                    {
                        $large_trade = $this::large_trade_val / $data[1][0]['price'];
                        foreach($data[1] as $trade)
                        {
                            $volume = $trade['amount'];
                            if ($volume<0)
                                $volume *= -1;
                            if ($volume > $large_trade)
                            {
                                console($highlight.$site.' Large trade '.$job_info['pair'].' '.$trade['amount']. ' @ '.$trade['price']);
                                $this->db->record_trade($job_info['market_id'],$trade['amount'],$trade['price']);
                            }
                        }
                        break; //break out of switch, do not record
                    }
                    if(!isset($data['amount']))
                    {
                        console('DEBUG: No Amount');
                        break;
                    }
                    static $te_ids=array();
                    if($data[1]=='te') //te is incomplete tu is complete. For speed te should be used
                        $te_ids[]=$data[0];
                    if($data[1]=='tu' && ($key = array_search($data[0], $te_ids)) !== false)
                    {
                        unset($te_ids[$key]);
                        break; //break out of switch, do not record
                    }
                }
                //Testing
                static $debug=true;
                //Testing END

                //Code start
                $this->db->record_trade($job_info['market_id'],$data['amount'],$data['price']);

                //new event,may not be sent.
                static $event;

                //new minute
                if(time()>$this->recent_trade_minute+60)
                    $this->recent_trade_minute += 60;
                //init new minute
                if(!isset($this->recent_trades[$site][$job_info['pair']][$this->recent_trade_minute]))
                {
                    $this->recent_trades[$site][$job_info['pair']][$this->recent_trade_minute]=self::recent_trades_defaults;
                    $this->recent_trades[$site][$job_info['pair']][$this->recent_trade_minute]['time']=$this->recent_trade_minute;
                }


                $pmin = &$this->recent_trades[$site][$job_info['pair']][$this->recent_trade_minute];
                $pset = &$this->recent_trades[$site][$job_info['pair']];
                $plmin = null;
                for($a=count($pset)-2;$a>0;$a--)
                    if(isset($this->recent_trades[$site][$job_info['pair']][$this->recent_trade_minute - (60 * $a)]))
                    {
                        $plmin=&$this->recent_trades[$site][$job_info['pair']][$this->recent_trade_minute - (60 * $a)];
                        break;
                    }

                //init new minute for market_id
                if($pmin['price_open']==0)
                {
                    $pmin['market_id']=$job_info['market_id'];
                    $pmin['pair_id']=$this->db_key_lookup['pair'][$job_info['pair']];
                    $pmin['price_open']=$data['price'];
                    $pmin['price_point']=$data['price']/100;
                    $pmin['price_half_point']=$data['price']/200;
                    $pmin['large_trade_val']=$this::large_trade_val / $data['price'];
                    //record last minute if there was data
                    if($plmin!==null &! $plmin['recorded'])
                    {
                        $this->db->record_trade_minute($plmin);
                        $plmin['recorded']=true;
                    }
                    if(isset($pset[$this->recent_trade_minute - (self::recent_trades_retain_minutes * 60)]))
                        unset($pset[$this->recent_trade_minute - (self::recent_trades_retain_minutes * 60)]);
                    //init event, only happens on first run.
                    if ($event===null)
                        $event=new event(0,0,0);
                    //if($job_info['pair']=='XRPUSD') console('DEBUG: New Minute m_id:'.$pmin['market_id']); //Works as expected
                }

                //record volumes & report large trades
                switch(true)
                {
                    case $data['amount'] < -$pmin['large_trade_val']:
                        console($highlight.$site." Large *SELL* ".$job_info['pair'].' '.utils::round_significat($data['amount']). ' @ '.$data['price'],false,true);
                        $event->set_bit('type',event::large_sell,2);
                    case $data['amount']<0:
                        $pmin['volume_sell']+=$data['amount'];
                        $pmin['volume']+=$data['amount']*-1;
                        break;
                    case $data['amount'] > $pmin['large_trade_val']:
                        console($highlight.$site." Large *BUY* ".$job_info['pair'].' '.utils::round_significat($data['amount']). ' @ '.$data['price'],false,true);
                        $event->set_bit('type',event::large_buy,2);
                    case $data['amount']>0:
                        $pmin['volume_buy']+=$data['amount'];
                        $pmin['volume']+=$data['amount'];
                        break;
                }

                //report minimal price movement
                if($pmin['price_last']>0)
                {
                    $change_needed=$pmin['price_last'] * $this::alert_points;
                    //console('cn:'.$change_needed.' p:'.$data['price'].' lp:'.$pmin['price_last']);
                    if( $data['price'] < $pmin['price_last'] - $change_needed  || $data['price'] > $pmin['price_last'] + $change_needed)
                    {
                        $direction=null;
                        $event_bit=null;
                        if($data['price'] < $pmin['price_last'])
                        {
                            $direction = 'down';
                            $event_bit = event::price | event::down;
                        } else {
                            $direction = 'up';
                            $event_bit = event::price | event::up;
                        }
                        $event->set_bit('type',$event_bit,2);
                        $event->set_ref('data',$pset);
                        $points=utils::percentage($data['price'] - $pmin['price_last'],$pmin['price_last'],2);
                        $margin=isset($this->db_key_lookup['margin_market'][$job_info['market_id']])?'*':'';
                        $highlight.=($points>=$this::highlight_points||$points<=-$this::highlight_points)?'* ':'';
                        console($highlight.$site.' TR '.$job_info['pair'].$margin.' '.$direction." $points% to ".$data['price'],false,true);
                        //console($highlight.$site.' '.$job_info['pair'].' '.$direction." $points% to ".$data['price'].' '.$debug,false,true); //DEBUG
                    }
                }

                //record prices high def: low=0 high=maxint
                //if($job_info['pair']=='XRPUSD') console('DEBUG price_last XRP:'.$pmin['price_last']); //THE WORKS AS EXPECTED
                $pmin['price_last']=$data['price'];
                if($data['price'] > $pmin['price_high'])
                    $pmin['price_high']=$data['price'];
                if($data['price'] < $pmin['price_low'])
                    $pmin['price_low']=$data['price'];

                //detect and notify on point and half point movement
                if( ($data['price'] > ($pmin['price_open'] + $pmin['price_point'])) && !($pmin['report_flags'] & self::rt_ap))
                {
                    $pmin['report_flags'] |= self::rt_ap;
                    $event->set_bit('type',$event::price | $event::up | $event::moved_half_point | $event::moved_point,1);
                    $event->set_ref('data',$pset);
                    console($site.' '.$job_info['pair'].' GAIN > 1% to '.$data['price'],false,true);
                }
                elseif( ($data['price'] > ($pmin['price_open'] + $pmin['price_half_point'])) && !($pmin['report_flags'] & (self::rt_ap | self::rt_ahp)))
                {
                    //console('DEBUG:'.$pmin['report_flags']);
                    $pmin['report_flags'] |= self::rt_ahp;
                    $event->set_bit('type',$event::price | $event::up | $event::moved_half_point,2);
                    $event->set_ref('data',$pset);
                    console($site.' '.$job_info['pair'].' GAIN > 0.5% to '.$data['price'],false,true);
                }
                elseif( ($data['price'] < ($pmin['price_open'] - $pmin['price_point'])) && !($pmin['report_flags'] & self::rt_bp))
                {
                    $pmin['report_flags'] |= self::rt_bp;
                    $event->set_bit('type',$event::price | $event::down | $event::moved_half_point | $event::moved_point,1);
                    $event->set_ref('data',$pset);
                    console($site.' '.$job_info['pair'].' LOST > 1%  '.$data['price'],false,true);
                }
                elseif( ($data['price'] < ($pmin['price_open'] - $pmin['price_half_point'])) && !($pmin['report_flags'] & (self::rt_bhp | self::rt_bp)))
                {
                    //console('DEBUG:'.$pmin['report_flags']);
                    $pmin['report_flags'] |= self::rt_bhp;
                    $event->set_bit('type',$event::price | $event::down | $event::moved_half_point,2);
                    $event->set_ref('data',$pset);
                    console($site.' '.$job_info['pair'].' LOST > 0.5% to '.$data['price'],false,true);
                }

                if($debug)
                {
                    static $reported = false;
                    if(!$reported)
                    {
                        console('*** scribe debug "'.__FUNCTION__.'" code enabled');
                        $reported=true;
                    }
                    $code=utils::get_code('test_recorder.php');
                    if ($code!==false)
                        eval($code);
                }

                //call watchers, if any
                if(isset($this->watchers['trade'][$site.'_'.$job_info['pair']]))
                {
                    $action = $this->watchers['trade'][$site.'_'.$job_info['pair']];
                    $data=array('job_info'=>$job_info['pair'],'pmin'=>&$pmin,'order'=>$action->order);
                    call_user_func_array($action->send_to_function,$data);
                }

                if($event->send)
                {
                    $event->market_id=$job_info['market_id'];
                    $event->market_str=$job_info['pair'];
                    if($pmin['volume_buy']>$pmin['volume_sell'])
                        $event->set_bit('type',event::more_buying);
                    else
                        $event->set_bit('type',event::more_selling);
                    //$pmin['report_flags']=$event->type; //WHY????
                    $this->send_event('trader',$event);
                    $event=new event(0,0,0);
                }

                break;
            default:
                console(__CLASS__.' '.__FUNCTION__.' Unhandled data: '.var_dump($data));
        }
    }

    public function check_connection()
    {
        foreach($this->bitbot_cons as  &$connection)
        {
            if(!$connection->check_connection())
            {
                $connection->disconnect();
                $connection->connect();
            }
        }
    }

    public function check_config()
    {
        $test=array();
        $sites=array_keys($this->db->get_record_sites());
        foreach($sites as $site)
            $test[$site]=$this->db->get_record_keys($site,'all');
        return !utils::array_multidem_a_in_b($test,$this->arr_sites);
        /*
        //this  works
        foreach($test as $site => $functions)
            foreach($functions as $function => $pairs)
                if(!utils::array_multidem_compare($this->arr_sites[$site][$function],$pairs))
                    $change=true;
        return $change;
        */
    }

    public function stop()
    {
        foreach($this->bitbot_cons as  &$connection)
            $connection->disconnect();
        $this->pseudo_running=false;
        console('Scribe Stopped');
    }

    //messages
    public function register_for_messages(&$object)
    {
        $this->messengers=array_merge($this->messengers,messaging::register_for_messages($object));
    }

    public function receive_event(event &$event)
    {}

    public function receive_action(action &$action)
    {
        $con=null;
        foreach($this->bitbot_cons as &$connection)
            if($connection->get_site()==$action->site_str)
            {
                $con=$connection;
                break;
            }
        if($con===null)
            return false;
        switch (true)
        {
            case ($action->type_flags & action::watch_trade):
                $channel=$con->get_record('trade',$action->pair_str);
                if($channel==null)
                    $con->set_record('trade',$action->pair_str,$this->db_key_lookup['market'][$con->get_site()][$action->pair_str]);
                $con->subscribe_channel('trade',$action->pair_str);
                $this->watchers['trade'][$action->site_str.'_'.$action->pair_str]=$action;
                break;
            case ($action->type_flags & action::watch_unregister):
                if(isset($this->watchers['trade'][$action->site_str.'_'.$action->pair_str]))
                    unset($this->watchers['trade'][$action->site_str.'_'.$action->pair_str]);
                break;
        }

    }

    public function send_action($to_object, action $action)
    {
        if(isset($this->messengers[$to_object]))
        {
            $paction = &$action;
            call_user_func(array(&$this->messengers[$to_object],'receive_action'),$paction);
        }

    }

    public function send_event($to_object, event $event)
    {
        if(isset($this->messengers[$to_object]))
            call_user_func_array(array(&$this->messengers[$to_object],'receive_event'),array(&$event));
    }

    public function get_watchers(&$connector=null)
    {
        if ($connector==null)
            return $this->watchers;
        $watch_arr=array('all'=>array(),'trade'=>array(),'ticker'=>array());
        $con_site=$connector->get_site();
        foreach ($this->watchers as $function => $action)
        {
            if(count($action)==0)
                continue;
            else
            {
                $split=explode($action,'_');
                if($split[0]==$connector->get_site())
                {
                    $watch_arr[$function]=$split[1];
                    $watch_arr['all']=$split[1];
                }
            }
        }
        if (count($watch_arr['trade']) + count($watch_arr['ticker']) > 0)
            return $watch_arr;
        else
            return null;
    }
}

?>