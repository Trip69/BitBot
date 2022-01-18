<?php
namespace bitbot;

class analyser implements bitbot_message, pseudo_runable
{
    const update_interval=30;
    const check_config_interval=60;//seconds

    //alarms
    const price_spike_pre_points=1;//percent (pre-pump) 10 mins 3% post pump
    const price_spike_post_points=2.75;//percent (pre-pump) 10 mins 3% post pump
    const price_spike_period=10; //minutes
    const price_movement_points=0.5;
    const arbitrage_points=0.3;
    const turn_frags=3;

    private $db_keys=null;
    private $db=null;

    private $markets = array();
    private $arbitrage_markets=null;
    private $arbitrage_pairs=null;
    private $arbitrage_offsets=null;

    private $messengers=array();

    const print_bitcoin=30;//seconds
    const bt_volocity_period=120;//seconds
    const bt_vol_trigger_high=1000;
    const bt_vol_trigger_low=500;
    private $bt_vol=null;
    private $bt_market_id=null;

    private $time_finish=null;
    private $time_check=null;
    private $time_last_check=null;
    private $time_print_bitcoin=null;
    private $time_config_check=null;
    private $time_analyser_start=null;

    private $enabled=null;
    private $pusdeo_running=false;

    public function __construct(array &$db_keys=null)
    {
        console('BitBot Analyser v0.2 By Trip. host:'.gethostname());

        if($db_keys==null)
            $this->db_keys=bit_db_uni::get_db_keys();
        else
            $this->db_keys=$db_keys;
        $db=new bit_db_util();
        foreach ($this->db_keys['market'] as $exchange_name => $market_arr)
            foreach ($market_arr as $code_id => $market_id)
            {
                $row=$db->get_row('market',$market_id,'pair');
                if(!(bool)$row['active'])
                    continue;
                $this->markets[$market_id]=array(
                    'code_id'=>$code_id,
                    'market_name'=>$row['name'],
                    'exchange_id'=>$row['exchange_id'],
                    'exchange_name'=>$exchange_name,
                    'margin'=>(bool)$row['margin'],
                    'ticker'=>(bool)$row['ticker'],
                    'trades'=>(bool)$row['trades'],
                    'first_price'=>null,
                    'last_price'=>null,
                    'high'=>0,
                    'low'=>100000,
                    'volume'=>(float)$row['daily_volume']);
            }
        $db=null;
        $this->db=new bit_db_reader();

        $this->bt_market_id=$this->db_keys['market']['bitfinex']['BTCUSD'];
        $this->bt_vol=$this->db->get_trade_volume_last($this->bt_market_id,$this::print_bitcoin);
    }

    function init(array $options)
    {
        $this->enabled=(bool)bit_db_uni::get_config('analyser_enabled');

        $this->time_finish=isset($options['duration'])?time()+$options['duration']:time()+60;
        $this->time_analyser_start=time();
        $this->time_check=time();
        $this->time_print_bitcoin=time() + $this::print_bitcoin;
        $this->time_config_check=time() + $this::check_config_interval;
        $this->pusdeo_running = true;

        $this->arbitrage_init();
    }

    //run function for analyser
    function run()
    {
        //Test Zone
        static $debug=true;
        if($debug)
        {
            static $reported = false;
            if(!$reported)
            {
                console('*** analyser debug "'.__FUNCTION__.'" code enabled');
                $reported = true;
            }
            $code=utils::get_code('test_analyser.php');
            if ($code!==false)
            {
                eval($code);
                console('*** analyser code run');
            }
        }
        //Finish Test Zone
        $work_done=false;
        if (($this->pusdeo_running && time() > $this->time_finish) || !$this->enabled)
            $this->stop();
        elseif (!$this->pusdeo_running)
            return false;
        if (time() > $this->time_check)
        {
            //work START
            $this->velocity_all(10*60,10,0.1);
            $this->turn_detector(10*60);
            $this->spike_detector($this::price_spike_pre_points,$this::price_spike_period*60);
            $this->check_all_movement(5*60,0,$this::price_movement_points);
            $this->check_all_arbitrage();
            $work_done=true;
            //work STOP
            if($this->time_last_check!==null && $this->time_last_check!=(int)bit_db_uni::get_config('analyser_last_update'))
            {
                console('Analyser Dupe Proccess runnging. Exiting.');
                exit('Analyser Dupe Proccess runnging. Exiting.');
            }
            $this->time_last_check=time();
            $this->db->set_setting('analyser_last_update',$this->time_last_check);
            $this->time_check = time() + $this::update_interval;
        }
        if (time() > $this->time_print_bitcoin)
        {
            $this->print_bitcoin();
            $this->time_print_bitcoin=time()+$this::print_bitcoin;
            $work_done=true;
        }
        if (time() > $this->time_config_check)
        {
            //todo: dupe implement check
            $this->enabled=(bool)bit_db_uni::get_config('analyser_enabled');
            $this->config_check_time=time() + $this::check_config_interval;
            $work_done=true;
        }
        return $work_done;

    }

    function get_is_running()
    {
        return time() < $this->time_finish;
    }

    function stop()
    {
        if(!$this->pusdeo_running)
            return false;
        console('Analyser stopped');
        $this->pusdeo_running=false;
    }

    public static function get_recorded($market_arr,$market_id)
    {
        if($market_arr[$market_id]['trades'])
            return 'trade';
        elseif($market_arr[$market_id]['ticker'])
            return 'ticker';
    }

    //checked 1/10/2018
    public function arbitrage_init()
    {
        //setup
        if($this->arbitrage_markets===null)
        {
            //calculate all possible
            $this->arbitrage_markets=array();
            //$this->arbitrage_offsets=array();
            foreach($this->db_keys['market'] as $exchange_name_a => $markets_a)
            {
                foreach($this->db_keys['market'] as $exchange_name_b => $markets_b)
                {
                    if($exchange_name_a == $exchange_name_b)
                        break;

                    if(!isset($this->arbitrage_offsets[$exchange_name_a]))
                        $this->arbitrage_offsets[$exchange_name_a]=array($exchange_name_b=>array('offset'=>0,'pairs'=>array()));
                    if(!isset($this->arbitrage_offsets[$exchange_name_b]))
                        $this->arbitrage_offsets[$exchange_name_b]=array($exchange_name_a=>array('offset'=>0,'pairs'=>array()));

                    $same_a=array_intersect_key($markets_a,$markets_b);
                    if(count($same_a)>0)
                    {
                        //TODO: merge for more than 2 sites.
                        $this->arbitrage_markets[$exchange_name_a]=$same_a;
                        $this->arbitrage_markets[$exchange_name_b]=$same_a=array_intersect_key($markets_b,$markets_a);
                    }
                }
            }
            //note arbritage needs to be sected in both configs of server else this will error
            $config=$this->db->get_recorded(null,'arbitrage=1');
            $this->arbitrage_pairs=array();
            foreach($config as $market_id => $arb_info_arr)
            {
                if(!isset($this->arbitrage_pairs[$arb_info_arr['name_id']]))
                    $this->arbitrage_pairs[$arb_info_arr['name_id']]=array();
                $this->arbitrage_pairs[$arb_info_arr['name_id']][]=(int)$arb_info_arr['idx'];
            }
            foreach ($this->arbitrage_pairs as $pair_str => $arr_market_ids)
            {
                if($arr_market_ids[0] == null || $arr_market_ids[1] == null)
                {
                    unset($this->arbitrage_pairs[$pair_str]);
                    Console("Arbitrage pair $pair_str not properly configued, skipping");
                    continue;
                }
                $site_a = bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$arr_market_ids[0])['site_name'];
                $site_b = bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$arr_market_ids[1])['site_name'];
                $this->arbitrage_offsets[$site_a][$site_b]['pairs'][$pair_str]=null;//$arr_market_ids[1];
                $this->arbitrage_offsets[$site_b][$site_a]['pairs'][$pair_str]=null;//$arr_market_ids[0];
            }
            $a=1;
        }
    }

    public function check_all_arbitrage()
    {
        //calculate
        foreach ($this->arbitrage_pairs as $name_id => $market_ids)
            foreach ($market_ids as $key => $market_id)
            {
                if(!is_numeric($key))
                    continue;
                $table=$this->markets[$market_id]['trades']?'trade':($this->markets[$market_id]['ticker']?'ticker':null);
                if ($table===null)
                    continue;
                $this->arbitrage_pairs[$name_id][$market_id.'_5_min_av']=$this->db->get_price_av_rel_now_between($table,$market_id,(5*60),0);
                $this->arbitrage_pairs[$name_id][$market_id.'_price']=$this->db->get_last_price($table,$market_id);
                //$this->arbitrage_pairs[$name_id][$market_id.'_last']=$this->db->get_last_price($table,$market_id);
            }
        foreach ($this->arbitrage_pairs as $market_name => $market_a)
            foreach ($market_a as $key_a => $val_a)
            {
                if(!is_numeric($key_a ))
                    continue;
                foreach ($this->arbitrage_pairs as $market_name_b => $market_b)
                    if($market_name !== $market_name_b)
                        continue;
                    else
                        foreach ($market_b as $key_b => $val_b)
                        {
                            if(!is_numeric($key_b) || $val_a == $val_b)
                                continue;
                            //calc market diff average over last 5 minutes
                            if ($this->arbitrage_pairs[$market_name][$val_a.'_5_min_av'] == 0 || $this->arbitrage_pairs[$market_name][$val_b.'_5_min_av'] == 0)
                                continue;
                            $this->arbitrage_pairs[$market_name][$val_a.'_'.$val_b.'_5_min_av_points'] = utils::percentage($this->arbitrage_pairs[$market_name][$val_a.'_5_min_av']-$this->arbitrage_pairs[$market_name][$val_b.'_5_min_av'],$this->arbitrage_pairs[$market_name][$val_b.'_5_min_av']);

                            //calc offset
                            $site_a=bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$val_a)['site_name'];
                            $site_b=bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$val_b)['site_name'];
                            $this->arbitrage_offsets[$site_b][$site_a]['pairs'][$market_name]=$this->arbitrage_pairs[$market_name][$val_a.'_'.$val_b.'_5_min_av_points'];
                            $this->arbitrage_offsets[$site_a][$site_b]['pairs'][$market_name]=-$this->arbitrage_pairs[$market_name][$val_a.'_'.$val_b.'_5_min_av_points'];

                            if($this->arbitrage_pairs[$market_name][$val_a.'_'.$val_b.'_5_min_av_points'] < -($this::arbitrage_points + $this->arbitrage_offsets[$site_a][$site_b]['offset'])) //|| $this->arbitrage_pairs[$market_name][$val_a.'_'.$val_b.'_5_min_av_points'] > $this::arbitrage_points)
                            {
                                //$site_a=bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$val_a);
                                //$site_b=bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$val_b);
                                console("Arbitrage, 5 min av, $site_a. $site_b.".$market_name.' '.$this->arbitrage_pairs[$market_name][$val_a.'_'.$val_b.'_5_min_av_points'].'% ,'.utils::round_significat($this->arbitrage_pairs[$market_name][$val_a.'_5_min_av']).'/'.utils::round_significat($this->arbitrage_pairs[$market_name][$val_b.'_5_min_av']).'. Offset:'.$this->arbitrage_offsets[$site_a][$site_b]['offset'],false,true);
                            }
                            /*
                             * Never fired needs to be redone
                            $modifier = $this->arbitrage_pairs[$market_name][$val_a.'_price']*$this::arbitrage_points;
                            if($this->arbitrage_pairs[$market_name][$val_a.'_last_ticket']['ask'] + $modifier < $this->arbitrage_pairs[$market_name][$val_b.'_last_ticket']['bid'])
                            {
                                $site_a=bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$val_a);
                                $site_b=bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$val_b);
                                $points = utils::percentage($this->arbitrage_pairs[$market_name][$val_b.'_last_ticket']['bid']-$this->arbitrage_pairs[$market_name][$val_a.'_last_ticket']['ask'],$this->arbitrage_pairs[$market_name][$val_a.'_last_ticket']['ask']);
                                console('Arbitrage: '.$market_name.' Ask < Bid. '.$site_a['site_name'].' A:'.$this->arbitrage_pairs[$market_name][$val_a.'_last_ticket']['ask'].' '. $site_b['site_name'].' B:'.$this->arbitrage_pairs[$market_name][$val_b.'_last_ticket']['bid']." $points%",false,true);
                            }
                            */
                            //$this->arbitrage_pairs[$market_name][$val_a.'_'.$val_b.'_last_points'] = utils::percentage($this->arbitrage_pairs[$market_name][$val_a.'_last']-$this->arbitrage_pairs[$market_name][$val_b.'_last'],$this->arbitrage_pairs[$market_name][$val_b.'_last']);

                        }

            }
        //calc offsets
        foreach($this->arbitrage_offsets as $site_a => &$sub_sites)
            foreach($sub_sites as $site_b => &$siteb_data)
            {
                $siteb_data['total']=0;
                $siteb_data['count']=0;
                foreach($siteb_data['pairs'] as $pairs_str => $points)
                    if($points!==null)
                    {
                        $siteb_data['total']+=$points;
                        $siteb_data['count']++;
                    }
                if ($siteb_data['count']>0)
                    $siteb_data['offset']=$siteb_data['total']/$siteb_data['count'];
            }
//        $a=1;
    }

    public function check_all_movement($start,$stop,$points)
    {
        foreach ($this->markets as $market_id => $market_info)
            if ($market_info['ticker'])
                $this->check_movement($market_id,$start,$stop,$points);
    }

    public function check_movement($market_id,$start,$stop,$points)
    {
        $table=$this->markets[$market_id]['trades']?'trade':($this->markets[$market_id]['ticker']?'ticker':null);
        if($table===null)
            return;
        $start_price = (float)$this->db->get_last_price($table,$market_id,"time<=NOW()+$start");
        if ($start_price==0)
            return null;
        $end_price = (float)$this->db->get_last_price($table,$market_id,"time<=NOW()+$stop");

        if($this->markets[$market_id]['high']<$start_price)
            $this->markets[$market_id]['high']=$start_price;
        if($this->markets[$market_id]['high']<$end_price)
            $this->markets[$market_id]['high']=$end_price;
        if($this->markets[$market_id]['low']>$start_price)
            $this->markets[$market_id]['low']=$start_price;
        if($this->markets[$market_id]['low']>$end_price)
            $this->markets[$market_id]['low']=$end_price;

        //$time_period='';
        if ($this->markets[$market_id]['last_price']==$end_price)
            return false;
        if((int)$stop==0)
        {
            $this->markets[$market_id]['last_price']=$end_price;
            $time_period=date('h:i',time()+$start).' to now';
        } else {
            $time_period=date('h:i',time()+$start).' to '.date('h:i',time()+$stop);
        }
        $average = $this->db->get_price_av_rel_now_between($table,$market_id,$start,$stop);
        if(!is_null($average))
        {
            $average=utils::round_significat($average);
            $average=" ($average av)";
        }

        $change_points=utils::percentage($end_price-$start_price,$start_price);
        $end_price=utils::round_significat($end_price);
        $high=utils::round_significat($this->markets[$market_id]['high']);
        $low=utils::round_significat($this->markets[$market_id]['low']);
        $margin=$this->markets[$market_id]['margin']?'*':'';

        if($change_points>$points || $change_points<-$points)
        {
            $direction=$change_points>$points?'up':'down';
            $event=new event(event::az_movement,2);
            $direction_bit=$change_points>$points?event::up:event::down;
            $event->set_bit('type',$event);
            $event->set_market($this->markets[$market_id]['code_id'],$market_id);
            $event->price=$end_price;
            $this->send_event('trader',$event);
            console("$time_period ".$this->markets[$market_id]['code_id']."$margin $direction $change_points%$average to $end_price ($high/$low)",false,true);
            return true;
        }
        return null;
    }

    public function turn_detector($period_seconds)
    {
        //todo this needs work.
        $time_frags=$period_seconds/$this::turn_frags;
        foreach($this->db_keys['exchange'] as $name => $id)
        {
            $markets=$this->db->get_recorded($id);
            foreach ($markets as $market_id => $market_info)
                $av_ar = array();
            $table=$this->markets[$market_id]['trades']?'trade':($this->markets[$market_id]['ticker']?'ticker':null);
            if($table===null)
                continue;
            for ($a=0;$a<$this::turn_frags;$a++)
                $av_ar[]=$this->db->get_price_av_rel_now_between($table,$market_info['idx'],$period_seconds+$time_frags+($time_frags*$a),$period_seconds+($time_frags*$a));
            for($a=1;$a<count($av_ar);$a++)
            {
                if($av_ar[$a-1]>$av_ar[$a])
                    break;
                if($a==count($av_ar)-1)
                {
                    if($av_ar[$a-1]>$av_ar[$a])
                    {
                        $margin=$this->markets[$market_info['idx']]['margin']?'*':'';
                        $event=new event(event::az_turn | event::down,$market_id,2);
                        $this->send_event('trader',$event);
                        console("$name. Drop after run ".$market_info['name'].$margin,false,true);
                    }
                }
            }
            for($a=1;$a<count($av_ar);$a++)
            {
                if($av_ar[$a-1]<$av_ar[$a])
                    break;
                if($a==count($av_ar)-1)
                {
                    if($av_ar[$a-1]<$av_ar[$a])
                    {
                        $margin=$this->markets[$market_info['idx']]['margin']?'*':'';
                        $event=new event(event::az_turn | event::up,$market_id,2);
                        $this->send_event('trader',$event);
                        console("$name. Rise after drop ".$market_info['name'].$margin,false,true);
                    }
                }
            }
        }
        //console('Runs Finished',false,true);
    }

    public function spike_detector($points,$period_seconds)
    {
        //TODO:finish this
        foreach($this->markets as $market_id => &$trade_records)
        {
            $table=$this->markets[$market_id]['trades']?'trade':($this->markets[$market_id]['ticker']?'ticker':null);
            if($table===null)
                continue;
            $trade_records['10_min_price'] = array();
            $high_low=$this->db->get_high_low_rel_now($table,$market_id,self::price_spike_period);
            if ($high_low['high']===null||$high_low['low']===null) continue;
            $trade_records['10_min_price']['high']=(float)$high_low['high'];
            $trade_records['10_min_price']['low']=(float)$high_low['low'];
            if((int)$high_low['late']<time()-60)  continue;
            $trade_records['10_min_price']['first']=(int)$high_low['early'];
            $trade_records['10_min_price']['last']=(int)$high_low['late'];

            $high_low=$this->db->get_high_low_in_minute($table,$market_id,$trade_records['10_min_price']['first']);
            if ($high_low['high']===null||$high_low['low']===null) continue;
            $trade_records['10_min_price']['first_low']=(float)$high_low['low'];
            $trade_records['10_min_price']['first_high']=(float)$high_low['high'];
            $high_low=$this->db->get_high_low_in_minute($table,$market_id,$trade_records['10_min_price']['last']);
            if ($high_low['high']===null||$high_low['low']===null) continue;
            $trade_records['10_min_price']['last_low']=(float)$high_low['low'];
            $trade_records['10_min_price']['last_high']=(float)$high_low['high'];

            $trade_records['10_min_price']['low_to_high_points']=utils::percentage($trade_records['10_min_price']['last_high']-$trade_records['10_min_price']['first_low'],$trade_records['10_min_price']['first_low']);
            $trade_records['10_min_price']['high_to_low_points']=utils::percentage($trade_records['10_min_price']['last_low']-$trade_records['10_min_price']['first_high'],$trade_records['10_min_price']['first_high']);

            $check_mins=false;
            $margin=$this->markets[$market_id]['margin']?'*':'';
            if($trade_records['10_min_price']['low_to_high_points'] > self::price_spike_pre_points && $trade_records['10_min_price']['high_to_low_points'] > self::price_spike_pre_points)
            {
                console($trade_records['exchange_name'].'. 10min Price Spike: '.$trade_records['market_name']."$margin up ".$trade_records['10_min_price']['low_to_high_points']."% to ".utils::round_significat($trade_records['10_min_price']['last_high']),false,true);
                $event=new event(event::az_spike | event::up,$market_id,1);
                $this->send_event('trader',$event);
                $check_mins=true;
            }
            elseif($trade_records['10_min_price']['high_to_low_points'] < -self::price_spike_pre_points && $trade_records['10_min_price']['low_to_high_points'] < -self::price_spike_pre_points)
            {
                console($trade_records['exchange_name'].'. 10min Price Drop: '.$trade_records['market_name']."$margin down ".$trade_records['10_min_price']['high_to_low_points']."% to ".utils::round_significat($trade_records['10_min_price']['last_low']),false,true);
                $event=new event(event::az_spike | event::down,$market_id,1);
                $this->send_event('trader',$event);
                $check_mins=true;
            }
            //Exit before intensive check
            if(!$check_mins)
                return;
            //todo finish this
            $trade_records['10_min_price']['slice']=array();
            for($a=1;$a<=10;$a++)
                $trade_records['10_min_price']['slice'][$a]=$this->db->get_high_low_in_minute($table,$market_id,$trade_records['10_min_price']['first']+(60*$a));
            //$a=1;
        }
    }
//
    public function velocity_all($period_seconds,$chunks,$trigger)
    {
        foreach ($this->markets as $market_id => $market_info)
            if ($market_info['trades'] || $market_info['ticker'])
                $this->velocity($market_id,$period_seconds,$chunks,$trigger);
    }

    public function velocity($market_id,$period_seconds,$chunks,$trigger)
    {
        $table=$this->markets[$market_id]['trades']?'trade':($this->markets[$market_id]['ticker']?'ticker':null);
        if($table===null)
            return;
        $time_chunks=$period_seconds/$chunks;
        $vel_arr=array();
        for($a=0;$a<$chunks-1;$a++)
        {
            $av_price = $this->db->get_av_rel_now_between($table, $market_id,  $period_seconds - ($a * $time_chunks),$period_seconds - (($a + 1) * $time_chunks)); //positive values passed good.
            if ($av_price == 0)
                continue;
            $time_period=time()-($period_seconds - ($a * $time_chunks));
            $price_start=$this->db->get_price_av_at($table, $market_id,'(NOW() - INTERVAL '.($period_seconds - ($a * $time_chunks)).' SECOND)',$time_chunks/2);
            if ($price_start == 0)
                continue;
            $points=round((($av_price-$price_start)/$price_start)*100,4);
            if($points!=0)
                $vel_arr[] = array('period'=>$time_period,'points'=>$points,'price_start'=>$price_start,'price_av'=>$av_price);
        }
        //Array is ordered by time oldest first.
        $ar_count=count($vel_arr);
        switch(true)
        {
            case $ar_count < 2 :
                return null;
            case $ar_count < $chunks/2:
                return array_pop($vel_arr)['points'];

        }
        $av=utils::array_multidem_sum($vel_arr,'points')/count($vel_arr);
        for ($a=count($vel_arr)-1;$a>count($vel_arr)-3;$a--)
        {
            if($vel_arr[$a]['points']<$av)
                return array_pop($vel_arr)['points']; //last two are less than average
        }
        $last=$vel_arr[count($vel_arr)-1];
        if($last['points']>$trigger)
        {
            $margin=$this->markets[$market_id]['margin']?'*':'';
            $site=bit_db_key_lookup::lookup_site_from_market_id($this->db_keys,$market_id);
            console($site['site_name'].' '.$this->markets[$market_id]['market_name']."$margin gaining ".$last['points'].'% '.$period_seconds.'s '.utils::round_significat($last['price_av']),false,true);
        }
        return $vel_arr;
        //$hmm=2;
    }

    public function print_bitcoin()
    {
        $ticker=$this->db->get_last_ticket($this->bt_market_id);
        $vel=$this->velocity($this->bt_market_id,$this::bt_volocity_period,10,0.1);
        $vol=$this->db->get_trade_volume_rel_now_between($this->bt_market_id,$this::print_bitcoin,0);
        $btc_data=array();
        if(is_array($vel)){
            $vel=array_pop($vel)['points'];
            $btc_data['volocity']=$vel!==null?$vel:0;
        }
        elseif(is_null($vel))
            $vel='-';
        $btc_vol_pri=2;
        if(is_numeric($vel) && ($vel > 0.02 || $vel < -0.02))
        {
            $vel.='**';
            $btc_vol_pri=1;
        }

        $vol_str=$vol['volume_total']!=0?round($vol['volume_total'],2):'-';
        $btc_data['volume_total']=$vol['volume_total'];
        $btc_data['last_price']=$ticker['last_price'];
        console('BC - L:'.$ticker['last_price']." Vel:$vel Vol:$vol_str",false,true);
        $event=new event(event::az_bt_data,$this->bt_market_id,$btc_vol_pri,$btc_data);
        $this->send_event('trader',$event);

        if($this->bt_vol['volume_total']>$this::bt_vol_trigger_high)
        {
            console('***BUY Trigger HIGH***',false,true);
            $event=new event(event::volume | event::up,$this->bt_market_id,1);
            $this->send_event('trader',$event);
        }
        elseif($this->bt_vol['volume_total'] < -$this::bt_vol_trigger_high)
        {
            console('***SELL Trigger HIGH***',false,true);
            $event=new event(event::volume | event::down,$this->bt_market_id,1);
            $this->send_event('trader',$event);
        }
        elseif($this->bt_vol['volume_total']>$this::bt_vol_trigger_low || (is_numeric($vel) && ($vel > 0.02)))
        {
            console('***BUY Trigger LOW***',false,true);
            $event=new event(event::volume | event::up,$this->bt_market_id,2);
            $this->send_event('trader',$event);
        }
        elseif($this->bt_vol['volume_total'] < -$this::bt_vol_trigger_low || (is_numeric($vel) && ($vel < -0.02)))
        {
            console('***SELL Trigger LOW***',false,true);
            $event=new event(event::volume | event::down,$this->bt_market_id,2);
            $this->send_event('trader',$event);
        }
        $this->bt_vol=$vol;
    }

    public function register_for_messages(&$object)
    {
        $this->messengers=array_merge($this->messengers,messaging::register_for_messages($object));
    }

    public function receive_event(event &$event)
    {

    }

    public function receive_action(action &$action)
    {

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

}
?>