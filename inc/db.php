<?php
namespace bitbot;

date_default_timezone_set('Europe/Athens');

class BitBotError extends \Exception
{
    
}

class bit_db {
    /**
    * mysql connection
    * 
    * @var mixed
    */
    public static $link=null;
    /*
    public $db_info = array('exchanges'=>array(),
                            'pairs'=>array(),
                            'currencies'=>array());
    */
    
    public function __construct()
    {
        if(self::$link === null)
            self::connect();
    }
    
    public function __destruct(){
        //todo: do this properly
        $connected=false;
        foreach (self::$link as $val)
            if (!is_null($val))
                {
                    $connected=true;
                    break;
                }
        if ($connected)
            mysqli_close(self::$link);
        
    }

    public static function connect()
    {
        require_once 'db_account.php';
        if(self::$link === null)
            self::$link = mysqli_connect('localhost',db_account::$mysql_username,db_account::$mysql_password,db_account::$db_name);
        if(!self::$link)
            throw new BitBotError('Error connecting to db '.mysqli_error(),1);
    }

    public static $chars_rem = array ("'",'&amp;','&','"',"\\");
    public static $chars_rep = array ('',' And ',' And ','','');
    public static function remove_chars($text)
    {
        return str_replace(self::$chars_rem,self::$chars_rep,$text);
    }

    protected static function get_one_result($sql)
    {
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error '.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),2);
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return $row;
    }
    
    protected static function get_all_results($sql,$key=null,$value=null,$cast_int=false)
    {
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),3);
        $ret=array();
        if($key===null && $value===null && $cast_int===null)
        {
            $res=mysqli_fetch_all($result);
            mysqli_free_result($result);
            return $res;
        }

        while ($row = mysqli_fetch_assoc($result))
            if (!is_null($key) && is_null($value) && $cast_int === true)
            {
                foreach ($row as $index => &$item)
                    if (is_numeric($item))
                        $item  = (float)$item;
                $ret[$row[$key]]=$row;
            }
            elseif(!is_null($key) && is_null($value))
                $ret[$row[$key]]=$row;
            elseif (!is_null($key) &! is_null($value))
                $ret[$row[$key]]=$cast_int?(int)$row[$value]:$row[$value];
            /*
            */
            else
                $ret[]=$row;
        mysqli_free_result($result);
        return $ret;
    }

    protected static function insert_one_record($table,array $fields,array $values,$return_insert=false,$define_null=false)
    {
        if (!bit_db::check_values_valid($values,$define_null))
            throw new BitBotError('Invalid data passed to '.__FUNCTION__);
/*
        if ($table=='ticker' && $values[0]==255)// && ($values[1] < 0.4 || $values[1] > 0.5))
            console('M255 Bid :'.$values[1]);
*/
        $sql="INSERT INTO $table (".implode(',',$fields).") VALUES (".implode(',',$values).")";
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),3);
        if ($return_insert)
        {
            $sql_frag='';
            for ($a=0;$a<count($fields);$a++)
            {
                /*
                if (is_string($values[$a]))
                    $sql_frag.=$fields[$a].'="'.$values[$a].'"';
                else
                */
                $sql_frag.=$fields[$a].'='.$values[$a];
                if ($a<count($fields)-1)
                    $sql_frag.=' AND ';
            }
            $sql="SELECT * FROM $table WHERE $sql_frag";
            $res = bit_db::get_one_result($sql);
            if($res==null)
                throw new BitBotError('Inserted row not found with select'.PHP_EOL.$sql.PHP_EOL);
            return $res;
        }
    }
    
    protected static function replace_one_record($table,array $fields,array $values)
    {
        if (!bit_db::check_values_valid($values))
            throw new BitBotError('Invalid data passed to '.__FUNCTION__);

        $sql="REPLACE INTO $table (".implode(',',$fields).") VALUES (".implode(',',$values).")";
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),4);
    }
    
    protected static function update_one_record($table,array $fields,array $values,$where)
    {
        if (!bit_db::check_values_valid($values))
            throw new BitBotError('Invalid data passed to '.__FUNCTION__);
        $update_arr=array();
        for($a=0;$a<count($fields);$a++)
            $update_arr[]=$fields[$a].'='.$values[$a];
        $update_fields=implode(',',$update_arr);
        unset($update_arr);
        $sql="UPDATE $table
              SET $update_fields
              WHERE $where";
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),5);
    }
    
    protected static function remove_row($table,$where)
    {
        $sql="DELETE FROM $table
              WHERE $where";
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),5);
    }

    public static function count_rows($table,$where=null)
    {
        $where=$where!==null?"WHERE $where":null;
        $sql="SELECT COUNT(*) AS count FROM $table $where";
        $result=self::get_one_result($sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),5);
        return (int)$result['count'];
    }

    public static function max_column($table,$column)
    {
        $sql = "SELECT MAX($column) as da_max FROM $table WHERE 1";
        return (int)self::get_one_result($sql)['da_max'];
    }

    public static function set_autoincrement($table,$value)
    {
        $sql="ALTER TABLE $table AUTO_INCREMENT = $value";
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),5);
    }

    const table_columns=array(
        'orders'=>array('exchange_id','account_id','order_id','pair_id','margin','amount','type','status','price','time'),
        'positions'=>array('exchange_id','account_id','pair_id','margin','amount','status','price','manage','time'),
        'wallets'=>array('wallet_id','exchange_id','account_id','type','currency_id','amount','available','time'));

    protected static function move_row($table_a,$table_b,$where)
    {
        $cols=implode(',',self::table_columns[$table_a]);
        $sql="INSERT INTO $table_b ($cols) SELECT $cols FROM $table_a WHERE $where";
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),5);
        $sql="DELETE FROM $table_a WHERE $where";
        $result = mysqli_query(self::$link,$sql);
        if (!$result)
            throw new BitBotError(__FUNCTION__.' SQL Syntax Error'.PHP_EOL.$sql.PHP_EOL.mysqli_error(self::$link),5);
    }
    
    protected static function check_values_valid(array &$values,$define_null=false)
    {
        foreach($values as &$val)
        {
            if(is_string($val))
                $val="'$val'";
            elseif($val===null)
            {
                if($define_null)
                    $val='NULL';
                else
                {
                    console(__FUNCTION__.' null value passed from '.debug_backtrace()[2]['function']);
                    return false;
                }
            }
        }
        return true;        
    }

    public static function escape_string($text)
    {
        return mysqli_real_escape_string(bit_db::$link,$text);
    }

}

class bit_db_uni extends bit_db
{
    public static function get_site_connection_method($site)
    {
        $result = bit_db::get_one_result("SELECT connection,connection_url FROM exchange WHERE name ='$site'");
        if (isset($result['connection']))
            return $result;
        else
            throw new BitBotError("$site connection method not defined");
    }

    public static function get_markets()
    {
        $sql = "SELECT market.idx AS 'code_id',pair.name AS 'market_name',exchange.name,margin,ticker,trades
                FROM market JOIN exchange ON market.exchange_id = exchange.idx JOIN pair on market.pairs_id = pair.idx";
        return self::get_all_results($sql,'code_id',null,true);
    }

    public static function get_recorded($exchange_id=null,$where=null)
    {
        if(!is_null($exchange_id))
            $exchange_id = "AND exchange_id=$exchange_id";
        if(is_null($where))
            $where='ticker=1 OR trades=1 OR arbitrage=1';
        $sql="SELECT exchange_id,market.idx,name_id,pair.name,margin,min_order,ticker,trades,arbitrage FROM market JOIN pair on market.pairs_id = pair.idx WHERE active = 1 AND ($where) $exchange_id";
        return self::get_all_results($sql,'idx');
    }

    public static function get_config($name)
    {
        return self::get_one_result("SELECT value FROM config WHERE name='$name'")['value'];
    }

    public static function set_config($name,$value)
    {
        self::update_one_record('config',array('value'),array($value),"name='$name'");
    }

    public static function set_exchange_config($idx,$setting,$value)
    {
        self::update_one_record('exchange',array($setting),array($value),"idx=$idx");
    }

    public static function get_site_id($site)
    {
        $sql="SELECT idx FROM exchange WHERE name='$site'";
        $result=self::get_one_result($sql);
        if (isset($result['idx']))
            return $result['idx'];
        return null;
    }

    public static function get_site_ids($record=false,$trade=false)
    {
        $record=$record===true?' AND record_enabled=1':null;
        $trade=$trade===true?' AND trade_enabled=1':null;
        $sql='SELECT name,idx FROM exchange WHERE enabled = 1'.$record.$trade;
        return self::get_all_results($sql,'idx');
    }

    public static function get_db_keys()
    {
        $db=array();
        $db['currency']=self::get_all_results('SELECT idx,cur_id FROM currency WHERE 1 ORDER BY cur_id ASC','cur_id','idx',true);
        $db['exchange']=self::get_all_results('SELECT idx,name FROM exchange WHERE enabled = 1 AND record_enabled = 1 ORDER BY name','name','idx',true);
        $db['pair']=self::get_all_results('SELECT idx,name_id FROM pair WHERE 1 ORDER BY name_id','name_id','idx',true);
        $db['account']=self::get_account_ids();
        foreach($db['exchange'] as $name => $key)
            $db['market'][$name]=self::get_all_results("SELECT market.idx,name_id FROM market JOIN pair ON pairs_id = pair.idx WHERE exchange_id=$key",'name_id','idx',true);
        $db['margin_market']=bit_db_key_lookup::margin_markets();
        return $db;
    }

    public static function get_market_id($site,$pair)
    {
        $sql="SELECT market.idx AS 'market_id' FROM market JOIN exchange on market.exchange_id = exchange.idx JOIN pair ON market.pair_id = pair.idx
              WHERE exchange.name='$site' AND pair_id='$pair'";
        if($result=self::get_one_result($sql))
            return $result['market_id'];
        else
            return null;
    }

    public static function get_account_ids()
    {
        return self::get_all_results( 'SELECT accounts.idx AS "accounts.idx", accounts.name, function, exchange.name FROM accounts JOIN exchange on exchange_id = exchange.idx WHERE 1 ORDER BY accounts.idx','accounts.idx',null,true);
    }

    public static function get_last_price($market_id)
    {
        $sql="SELECT price FROM trade WHERE market_id=$market_id ORDER BY time DESC LIMIT 1";
        $res = self::get_one_result($sql);
        if ($res==null)
        {
            $sql="SELECT last_price as price FROM ticker WHERE market_id=$market_id ORDER BY time DESC LIMIT 1";
            $res = self::get_one_result($sql);
        }
        if($res==null)
            return null;
        return (float)$res['price'];
    }

}

class bit_db_key_lookup extends bit_db
{
    public $exchange = array();
    public $currency = array();
    public $pair = array();
    public $market = array();
    
    public function __construct()
    {
        parent::__construct();
        $results = $this->get_all_results('SELECT idx,name FROM exchange WHERE enabled = 1 AND record_enabled = 1');
        foreach ($results as $exchange_id)
            $this->exchange[$exchange_id['name']]=(int)$exchange_id['idx'];
    }

    public function __destruct()
    {
        $this->exchange=null;
        $this->currency=null;
        $this->pair=null;
        $this->market=null;
    }

    public function get_site_key($site,\ccxt\Exchange $obj=null)
    {
        if (isset($this->exchange[$site]))
            return $this->exchange[$site];
        $result = $this->get_one_result("SELECT idx FROM exchange WHERE name='$site' AND enabled = 1 AND record_enabled = 1");
/*
TODO
        if(!$result)
        {
            $obj->
            $this->insert_one_record('exchange',
                array('name','cctx_class','url','connection','connection_url','enabled'),
                array($site,$obj->id,'https://www.'.$obj->id.'.com','ccxt',))
        }
*/
        $this->exchange[$result[$site]]=$result['idx'];
        return $result[$site];
    }
    
    public function getset_currency_key($currency_id,$currency_code)
    {
        if (isset($this->currency[$currency_id]))
            return $this->currency[$currency_id];
        $result = $this->get_one_result("SELECT idx FROM currency WHERE cur_id='$currency_id'");
        if (!$result)
            $result = $this->insert_one_record('currency',array('cur_id','cur_code'),array($currency_id,$currency_code),true);
        $this->currency[$currency_id]=(int)$result['idx'];
        return $this->currency[$currency_id];
    }
    
    public function getset_market_key($site,array $market)
    {
        $market_id=null;
        $pair_id=null;
        if (isset($this->exchange[$site]))
            $exchange_id=$this->exchange[$site];
        else
            $exchange_id=(int)$this->get_site_key($site);
            
        if (isset($this->pair[$market['id']]))
            $pair_id=$this->pair[$market['i d']];
        else
        {
            $currency_a_id=$this->getset_currency_key($market['baseId'],$market['base']);
            $currency_b_id=$this->getset_currency_key($market['quoteId'],$market['quote']);
            $result = $this->get_one_result("SELECT idx FROM pair WHERE currency_a_id='$currency_a_id' AND currency_b_id='$currency_b_id'");
            if (!$result)
                $result = $this->insert_one_record('pair',array('name','name_id','currency_a_id','currency_b_id'),array(strtoupper($market['symbol']),$market['id'],$currency_a_id,$currency_b_id),true);
            $pair_id = (int)$result['idx'];
            //Change here to upper
            $this->pair[strtoupper($market['id'])]=$pair_id;
        }
        
        $result=null;
        if(isset($this->market[$site][$market['id']]))
            return $this->market[$site][$market['id']];
        else
        {
            $result = $this->get_one_result("SELECT idx,active,margin,min_order,max_order FROM market WHERE exchange_id=$exchange_id AND pairs_id=$pair_id");
            if (!$result)
            {
                if(!isset($market['info']['margin']))
                    $market['info']['margin']=0;
                if(isset($market['info']['minimum_order']))
                    $result = $this->insert_one_record('market',
                        array('exchange_id','pairs_id','active','margin','min_order_txt'),
                        array($exchange_id,$pair_id,(int)$market['active'],(int)$market['info']['margin'],$market['info']['minimum_order']),true);
                else
                    $result = $this->insert_one_record('market',
                        array('exchange_id','pairs_id','active','margin','min_order','max_order'),
                        array($exchange_id,$pair_id,(int)$market['active'],(int)$market['info']['margin'],$market['info']['minimum_order_size'],$market['info']['maximum_order_size']),true);
            }
            if ((bool)$result['active']!=(bool)$market['active'])
                $result = $this->update_one_record('market',array('active'),array((int)$market['active']),'idx='.(int)$result['idx']);
        }
        $market_id=(int)$result['idx'];
        $this->market[$site][$market['id']]=$market_id;
        return $market_id;
    }
    
    public function get_markets()
    {
        return $this->market;
    }
    
    public function check_markets($site,array $market_data)
    {
        $site_key = $this->get_site_key($site);
        if(!isset($this->market[$site]) or count($this->market[$site])==0)
            throw new BitBotError("$site not initilized");
        $a_key=array_keys($this->market[$site])[0];
        $case=ctype_upper($a_key)?'UPPER':'LOWER';
        $markets=$this->get_all_results("SELECT market.idx,$case(name_id) AS name_id,active,margin,min_order,max_order,min_order_txt FROM market JOIN pair ON market.pairs_id = pair.idx WHERE exchange_id=$site_key");
        //$this->market[$site]=array_change_key_case($this->market[$site],CASE_UPPER);
        $unset_arr=array();
        //for each db record
        foreach ($markets as $market)
        {
            if (!isset($this->market[$site][$market['name_id']]))
            {
                $this->update_one_record('market',array('active'),array(0),'market.idx='.$market['idx']);
                $unset_arr[]=$market['name_id'];
            }
            elseif ($this->market[$site][$market['name_id']]['active'] &! $market['active'])
                $this->update_one_record('market',array('active'),array(1),'market.idx='.$market['idx']);
            $item=utils::array_multidem_search($market_data,'id',$market['name_id']);
            if($item===null)
                continue;
            if((isset($item['info']['margin'])) && (bool)$market['margin'] !== (bool)$item['info']['margin'])
                $this->update_one_record('market',array('margin'),array((bool)$item['info']['margin']),'market.idx='.$market['idx']);
            if (isset($item['info']['minimum_order_size']) && isset($item['info']['maximum_order_size']))
            {
                if((float)$market['min_order']!==(float)$item['info']['minimum_order_size'])
                    $this->update_one_record('market',array('min_order'),array((float)$item['info']['minimum_order_size']),'market.idx='.$market['idx']);
                if((float)$market['max_order']!==(float)$item['info']['maximum_order_size'])
                    $this->update_one_record('market',array('max_order'),array((float)$item['info']['maximum_order_size']),'market.idx='.$market['idx']);
            }
            elseif (isset($item['info']['minimum_order']))
            {
                if($market['min_order_txt']!=$item['info']['minimum_order'])
                    $this->update_one_record('market',array('min_order_txt'),$item['info']['minimum_order'],'market.idx='.$market['idx']);
            }
        }
        foreach ($unset_arr as $remove)
            unset($this->market[$site][$remove]);
        $a=1;
    }

    public function get_lookup_array()
    {
        return array(
            'exchange'=>$this->exchange,
            'currency'=>$this->currency,
            'pair'=>$this->pair,
            'market'=>$this->market,
            'account'=>bit_db_uni::get_account_ids(),
            'margin_market'=>self::margin_markets());
    }

    public static function lookup_site_from_market_id(array $key_db,int $market_id)
    {
        foreach($key_db['market'] as $site_name => $market_arr)
        {
            $key = array_search($market_id,$market_arr,true);
            if($key!==false)
                return array('site_name'=>$site_name,'market_name'=>$key,'market_idx'=>$market_arr[$key]);
        }
        return false;
    }

    public static function format_symbol($text,$to_lower=false,$to_upper=false,$remove_slash=false)
    {
        if($to_lower)
            $text=strtolower($text);
        if($to_upper)
            $text=strtoupper($text);
        if($remove_slash)
            $text=str_replace('/','',$text);
        return $text;
    }

    public static function margin_markets()
    {
        $sql='SELECT market.idx AS idx, name_id FROM market JOIN pair ON pairs_id = pair.idx WHERE active=1 AND margin=1';
        return self::get_all_results($sql,'idx','name_id');
    }
}

class bit_db_recorder extends bit_db
{
    static $recorded = array ('ticker','trades');

    public function __construct()
    {
        parent::__construct();
    }

    public function get_record_sites()
    {
        $sql='SELECT DISTINCT name FROM exchange JOIN market ON market.exchange_id = exchange.idx WHERE enabled=1 AND record_enabled=1';
        $sites = $this->get_all_results($sql);
        $ret = array();
        foreach($sites as $site)
            $ret[$site['name']]=array();
        return $ret;
    }

    public function get_record_keys($site_name,$type)
    {
        if ($type!='ticker' && $type!='trades' && $type!='all')
            throw new BitBotError('Invalid Record Type');
        $type=$type=='all'?'(ticker=1 OR trades=1)':$type=$type.'=1';
        $sql="SELECT pair.name_id, market.idx, ticker, trades FROM market JOIN exchange ON exchange_id=exchange.idx JOIN pair ON pairs_id = pair.idx WHERE exchange.name = '$site_name' AND enabled = 1 AND active=1 AND $type";
        $res = $this->get_all_results($sql); //name_id
        $ret = array('ticker'=>array(),'trades'=>array());
        foreach ($res as $record_info)
            for($a=0;$a<count($this::$recorded);$a++)
            {
                if($record_info[$this::$recorded[$a]]=='1')
                    $ret[$this::$recorded[$a]][$record_info['name_id']]=array_slice($record_info,1,3,true);
            }
        return $ret;
    }
    
    public function get_site_connection_method($site)
    {
        $result = $this->get_one_result("SELECT connection,connection_url FROM exchange WHERE name ='$site'");
        if (isset($result['connection']))
            return $result;
        else
            throw new BitBotError("$site connection method not defined");
    }

    public function record_ticker($site,$market_id,$data)
    {
//        if ($market_id==255)
//            $a=1;
        $this->insert_one_record('ticker',array('market_id','bid','bid_size','ask','ask_size','daily_change','daily_change_points','last_price','volume','high','low'),
            array($market_id,$data['bid'],$data['bidVolume'],$data['ask'],$data['askVolume'],$data['change'],$data['percentage'],$data['last'],$data['baseVolume'],$data['high'],$data['low']),
            false,true);
        /*
        switch($site)
        {
            case 'bitfinex':
                array_unshift($data,$market_id);
                unset($data[1]);//ws_channel
                $this->insert_one_record('ticker',array('market_id','bid','bid_size','ask','ask_size','daily_change','daily_change_points','last_price','volume','high','low'),$data);
            break;
            case 'bitstamp':
                $this->insert_one_record('ticker',array('market_id','bid','bid_size','ask','ask_size','daily_change','daily_change_points','last_price','volume','high','low'),
                    array($market_id,$data['bid'],$data['bidVolume'],$data['ask'],$data['askVolume'],$data['change'],$data['percentage'],$data['info']['last'],$data['info']['volume'],$data['info']['high'],$data['info']['low']),
                    false,true);
                break;
        }
        */
            
    }
    
    public function record_trade($market_id,$amount,$price)
    {
        $this->insert_one_record('trade',array('market_id','amount','price'),array($market_id,$amount,$price));
    }

    const trade_minute_fields = array ('market_id','volume','volume_buy','volume_sell','price_open','price_high','price_last','report_flags','time');
    public function record_trade_minute(array $tm)
    {
        $values=array();
        foreach(self::trade_minute_fields as $field)
            if($field=='time')
                $values[]=date('Y-m-d H:i:s',$tm[$field]);
            else
                $values[]=$tm[$field];
        self::insert_one_record('trade_summary',self::trade_minute_fields,$values);
    }

    public function update_market($market_id,array $data)
    {
        $this->update_one_record('market',array_keys($data),array_values($data),"idx=$market_id");
    }

}

class bit_db_reader extends bit_db
{
    const price_column=array('trade'=>'price','ticker'=>'last_price');
    const table_price=array('ticker'=>'last_price','trade'=>'price');

    public function get_site_ids()
    {
        $sql='SELECT name,idx FROM exchange WHERE enabled = 1 AND record_enabled = 1';
        return $this->get_all_results($sql,'idx');
    }

    public function get_sites()
    {
        $sql="SELECT * FROM exchange WHERE 1";
        return self::get_all_results($sql,'idx');
    }

    public function set_setting($name,$value)
    {
        $this->update_one_record('config',array('value'),array($value),"name='$name'");
    }

    public function get_recorded($exchange_id=null,$where=null)
    {
        if(!is_null($exchange_id))
            $exchange_id = "AND exchange_id=$exchange_id";
        if(is_null($where))
            $where='ticker=1 OR trades=1 OR arbitrage=1';
        $sql="SELECT exchange_id,market.idx,name_id,name,margin,min_order,ticker,trades,arbitrage FROM market JOIN pair on market.pairs_id = pair.idx WHERE active = 1 AND ($where) $exchange_id";
        return $this->get_all_results($sql,'idx');
    }
    
    public function get_all_tickers($exchange=null,$sort = null)
    {
        $exchange=$exchange===null?'1':"exchange_id='$exchange'";
        $sort=$sort===null?'':' ORDER BY '.$sort;
        $sql="SELECT exchange_id,market.idx,name_id,name,ticker,trades,arbitrage FROM market JOIN pair on market.pairs_id = pair.idx WHERE $exchange $sort;";
        return $this->get_all_results($sql,'idx');
    }

    public function get_last_ticket($market_id)
    {
        $sql="SELECT * FROM ticker WHERE market_id=$market_id ORDER BY time DESC LIMIT 1";
        return $this->get_one_result($sql);
    }

    public function get_trade($market_id,$where=null,$order=null,$limit=1)
    {
        if(is_null($where))
            $where="WHERE market_id=$market_id";
        else
            $where="WHERE market_id=$market_id AND $where";
        if(!is_null($order))
            $order="ORDER BY $order";
        $limit_sql="LIMIT $limit";
        $sql="SELECT price,amount,time FROM trade $where $order $limit_sql";
        if($limit>1)
            return $this->get_all_results($sql);
        else
            return $this->get_one_result($sql);
    }

    //ticker or trade

    public function get_last($table,$market_id)
    {
        $sql="SELECT * FROM $table WHERE market_id=$market_id ORDER BY time DESC LIMIT 1";
        return $this->get_one_result($sql);
    }

    public function get_last_price($table,$market_id,$where = null)
    {
        if(!is_null($where))
            $where=" AND $where";
        $price_field=self::table_price[$table];
        $sql="SELECT $price_field AS 'last_price' FROM $table WHERE market_id=$market_id $where ORDER BY time DESC LIMIT 1";
        return $this->get_one_result($sql)['last_price'];
    }

    public function get_price_av_rel_now_between($table,$market_id,$start_s,$end_s,$round_sig=false,$null_zero=false)
    {
        if ($start_s < 0 || $end_s < 0)
            throw new BitBotError('Start_S and End_S need to be positive');
        $price_field=self::table_price[$table];
        $start_s="TIMESTAMP(NOW()-INTERVAL $start_s SECOND)";
        $end_s="TIMESTAMP(NOW()-INTERVAL $end_s SECOND)";
        $sql="SELECT AVG($price_field) as price FROM $table WHERE time > $start_s AND time < $end_s AND market_id=$market_id;";
        $ret=(float)$this->get_one_result($sql)['price'];
        if($round_sig)
            $ret=(float)utils::round_significat($ret);
        if ($null_zero && $ret==0)
            return null;
        return $ret;
    }

    public function get_av_rel_now_between($table, $market_id, $start_s, $end_s, $round_sig=false, $null_zero=false)
    {
        if ($start_s < 0 || $end_s < 0)
            throw new BitBotError('Start_S and End_S need to be positive');
        switch($table)
        {
            case 'trade':
                $column='price';
                break;
            case 'ticker':
                $column='last_price';
                break;
            default:
                throw new BitBotError("Table not supported :$table");
        }
        //TODO: Testing......
        $start_s="TIMESTAMP(NOW()-INTERVAL $start_s SECOND)";
        $end_s="TIMESTAMP(NOW()-INTERVAL $end_s SECOND)";

        $sql="SELECT AVG($column) as price FROM $table WHERE time > $start_s AND time < $end_s AND market_id=$market_id;";
        $ret=(float)$this->get_one_result($sql)['price'];
        if($round_sig)
            $ret=(float)utils::round_significat($ret);
        if ($null_zero && $ret==0)
            return null;
        return $ret;
    }

    public function get_price_av_at($table,$market_id,$sql_time_str,$scope=30,$round_sig=false,$null_zero=false)
    {
        if ($scope < 0)
            throw new BitBotError('Scope need to be positive');
        $column='';
        switch($table)
        {
            case 'trade':$column='price';
                break;
            case 'ticker':$column='last_price';
                break;
            default:throw new BitBotError("Table not supported :$table");
        }
        $start_s="TIMESTAMP(($sql_time_str)-INTERVAL $scope SECOND)";
        $end_s="TIMESTAMP(($sql_time_str)+INTERVAL $scope SECOND)";
        $sql="SELECT AVG($column) as price FROM $table WHERE time > $start_s AND time < $end_s AND market_id=$market_id;";
        $ret=(float)$this->get_one_result($sql)['price'];
        if($round_sig)
            $ret=(float)utils::round_significat($ret);
        if ($null_zero && $ret==0)
            return null;
        return $ret;
    }

    public function get_high_low_rel_now($table,$market_id,$time_mins)
    {
        $column=null;
        switch($table)
        {
            case 'trade':$column='price';
                break;
            case 'ticker':$column='last_price';
                break;
            default:throw new BitBotError("Table not supported :$table");
        }//, MIN(time)
        $sql="SELECT MIN($column) AS low, MAX($column) AS high, UNIX_TIMESTAMP(MIN(time)) AS early, UNIX_TIMESTAMP(MAX(time)) AS late
              FROM $table
              INNER JOIN (SELECT MAX(time) as latest FROM $table) t ON TRUE 
              WHERE market_id=$market_id AND time >= (latest - interval $time_mins MINUTE);";
        return self::get_one_result($sql);
    }

    public function get_high_low_in_minute($table,$market_id,$time_minute)
    {
        $column=null;
        switch($table)
        {
            case 'trade':$column='price';
                break;
            case 'ticker':$column='last_price';
                break;
            default:throw new BitBotError("Table not supported :$table");
        }
        $time_end=$time_minute+60;
        $sql="SELECT MIN($column) AS low, MAX($column) AS high
              FROM $table
              WHERE market_id=$market_id AND time >= FROM_UNIXTIME($time_minute) AND time <= FROM_UNIXTIME($time_end)";
        return self::get_one_result($sql);
    }


    //volume

    public function get_trade_volume_rel_now_between($market_id,$start_s,$end_s,$relative=true)
    {
        if($relative)
        {
            //TODO: Testing......
            if ($start_s < 0 || $end_s < 0)
                throw new BitBotError('Start_S and End_S need to be positive');
            $start_s="TIMESTAMP(NOW()-INTERVAL $start_s SECOND)";
            $end_s="TIMESTAMP(NOW()-INTERVAL $end_s SECOND)";
        } else {
            $start_s="FROM_UNIXTIME($start_s)";
            $end_s="FROM_UNIXTIME($end_s)";
        }
        $sql="SELECT AVG(amount) as volume_av,SUM(amount) as volume_total FROM trade WHERE time > $start_s AND time < $end_s AND market_id=$market_id;";
        $res = $this->get_one_result($sql);
        return utils::array_cast_as($res,'float');
    }

    public function get_trade_volume_last($market_id,$duration)
    {
        $trade=$this->get_trade($market_id,null,'time DESC',1);
        return $this->get_trade_volume_rel_now_between($market_id,time($trade['time'])-$duration,time($trade['time']),false);
    }

}

class bit_db_trader extends bit_db
{
    public static $site_ids=array();
    public static $currency_ids=array();
    public static $pair_ids=array();

    public function __construct()
    {
        parent::__construct();
        self::$currency_ids=$this->get_currencies();
        self::$site_ids=$this->get_trader_sites();
        self::$pair_ids=$this->get_pairs();
    }

    public function get_trader_site($site)
    {
        $sql="SELECT * FROM exchange WHERE name='$site' AND enabled = 1 AND trade_enabled = 1";
        return $this->get_one_result($sql);
    }

    public function get_trader_sites()
    {
        $sql="SELECT * FROM exchange WHERE enabled = 1 AND trade_enabled = 1";
        return $this->get_all_results($sql,'name');
    }

    public function get_accounts($site_id)
    {
        $sql="SELECT * FROM accounts WHERE exchange_id=$site_id";
        return self::get_all_results($sql,'idx');
    }

    public function get_account_info($account_id)
    {
        $sql="SELECT * FROM accounts WHERE idx=$account_id";
        return self::get_one_result($sql);
    }

    public function get_currencies()
    {
        $sql='SELECT * FROM currency WHERE 1';
        return $this->get_all_results($sql,'cur_id');
    }

    public function get_pairs()
    {
        $sql='SELECT * FROM pair WHERE 1';
        return $this->get_all_results($sql,'name_id');
    }

    public function get_positions($exchange_id=null)
    {
        $exchange_id=$exchange_id!==null?"WHERE exchange_id=$exchange_id":null;
        $sql = "SELECT *, positions.idx as 'positions.idx', pair.idx as 'pair.idx' FROM positions JOIN pair on pair_id = pair.idx $exchange_id";
        return self::get_all_results($sql,'positions.idx');
    }

    public function get_orders($exchange_id=null)
    {
        $exchange_id=$exchange_id!==null?"WHERE exchange_id=$exchange_id":null;
        $sql = "SELECT *, orders.idx as 'orders.idx', pair.idx as 'pair.idx' FROM orders JOIN pair on pair_id = pair.idx $exchange_id";
        return self::get_all_results($sql,'orders.idx');
    }

    public function get_wallets($exchange_id=null)
    {
        $exchange_id=$exchange_id!==null?"WHERE exchange_id=$exchange_id":null;
        $sql = "SELECT *, wallets.idx as 'wallets.idx' FROM wallets JOIN currency ON currency_id = currency.idx JOIN exchange on exchange_id = exchange.idx $exchange_id";
        return self::get_all_results($sql,'wallets.idx');
    }

    public function update_position_manage($id,$state)
    {
        self::update_one_record('positions',array('manage'),array($state),"idx=$id");
    }

    public function get_market_pairs($site,$margin_only=false)
    {
        $margin_txt=$margin_only?'AND margin = 1':'';
        $sql="SELECT market.idx AS idx,exchange.name,pair.name,pair.name_id,min_order,max_order,margin,daily_volume,daily_high,daily_low,daily_change_points,ticker.time
              FROM market 
              JOIN exchange ON exchange_id = exchange.idx 
              JOIN pair on pairs_id = pair.idx 
              JOIN ticker ON market.idx = market_id  
              WHERE active = 1 $margin_txt AND exchange.name='$site' AND
              ticker.idx = (
                SELECT MAX(ticker.idx)
                FROM ticker
                WHERE market.idx = market_id
              )";
        $res=$this->get_all_results($sql,'idx',null,true);
        foreach ($res as $key => $row)
            $res[$key]['daily_volume_usd']=(($res[$key]['daily_high']+$res[$key]['daily_low'])/2)*$res[$key]['daily_volume'];
        return $res;
    }

    const wallet_type=array('exchange'=>0,'trading'=>1,'margin'=>2,'funding'=>3);
    const wallet_type_str=array(0=>'exchange',1=>'trading',2=>'margin',3=>'funding');

    public function wallet_update($site_name,$account_id,$wallet_name,$currency_name,$amount,$availiable)
    {
        $headers=array('wallet_id','exchange_id','account_id','type','currency_id','amount');
        if ($availiable!==null)
            $headers[]='available';

        $data=array($account_id.'_'.$this::wallet_type[$wallet_name].'_'.self::$currency_ids[$currency_name]['idx'],
            self::$site_ids[$site_name]['idx'],
            $account_id,$this::wallet_type[$wallet_name],
            self::$currency_ids[$currency_name]['idx'],
            $amount);
        if ($availiable!==null)
            $data[]=$availiable;

        $this->replace_one_record('wallets',$headers,$data);
        $this->insert_one_record('wallet_history',$headers,$data);
    }

    public function wallet_delete_empty($site_name,$account_id)
    {
        self::remove_row('wallets','exchange_id='.self::$site_ids[$site_name]['idx'].' AND account_id='.$account_id.' AND amount=0');
    }

    public function order_update($site_name,$account_id,$id,$pair,$amount,$type,$margin,$status,$price)
    {
        $sql="SELECT orders.idx AS idx ,exchange_id FROM orders JOIN exchange on exchange_id = exchange.idx WHERE exchange.name='$site_name' AND account_id=$account_id AND order_id=$id";
        $result=$this->get_one_result($sql);
        if(isset($result['idx']))
            $this->update_one_record('orders',array('amount','status','price'),array($amount,$status,$price),"idx=".$result['idx']);
        else
        {
            $this->insert_one_record('orders',
                array('exchange_id','account_id','order_id','pair_id','margin','amount','type','status','price'),
                array(self::$site_ids[$site_name]['idx'],$account_id,$id,self::$pair_ids[$pair]['idx'],(int)$margin,$amount,$type,$status,$price));
        }
    }

    public function bitfinex_order_update($account_id,array $data)
    {
        $id=$data[order::$bitfinex_order_seq_ass['id']];
        $pair=substr($data[order::$bitfinex_order_seq_ass['pair_str']],1);
        $margin=$data[order::$bitfinex_order_seq_ass['pair_str']][0]=='t';
        $sql="SELECT orders.idx AS idx ,exchange_id FROM orders JOIN exchange on exchange_id = exchange.idx WHERE exchange.name='bitfinex' AND account_id=$account_id AND order_id=$id";
        $result=$this->get_one_result($sql);
        if(isset($result['idx']))
        {
            $cols=array('amount','price');
            $vals=array($data[order::$bitfinex_order_seq_ass['amount']],
                        $data[order::$bitfinex_order_seq_ass['price']]);
            if($data[order::$bitfinex_order_seq_ass['status']]!==null)
            {
                $cols[]='status';
                $vals[]=$data[order::$bitfinex_order_seq_ass['status']];
            }
            $this->update_one_record('orders',$cols,$vals,"idx=".$result['idx']);
        }
        else
        {
            $this->insert_one_record('orders',
                array('exchange_id','account_id','order_id','pair_id','margin','amount','type','status','price'),
                array(
                    self::$site_ids['bitfinex']['idx'],
                    $account_id,
                    $id,
                    self::$pair_ids[$pair]['idx'],
                    (int)$margin,
                    $data[order::$bitfinex_order_seq_ass['amount']],
                    $data[order::$bitfinex_order_seq_ass['type']],
                    $data[order::$bitfinex_order_seq_ass['status']],
                    $data[order::$bitfinex_order_seq_ass['price']]));
        }
    }

    public function table_move_to_history($site_name,$account_id,$table,array $exiting_items=null,$completed_id=null,$all=false)
    {
        $tables=$table.'s';
        $column='';
        switch($table)
        {
            case 'position':
                $column='pair';
                break;
            default:
                $column=$table;
                break;
        }
        if($exiting_items!==null)
        {
            $sql="SELECT $tables.idx AS idx,".$column."_id FROM $tables JOIN exchange on $tables.exchange_id = exchange.idx WHERE exchange.name='$site_name' AND account_id=$account_id";
            $items=$this->get_all_results($sql,'idx');
            foreach ($items as $key => $item)
                if(in_array($item[$column.'_id'],$exiting_items))
                    unset($items[$key]);
            foreach ($items as $key => $item)
            {
                if(is_string($item[$column.'_id']))
                    $item[$column.'_id']="'".$item[$column.'_id']."'";
                $this->move_row($tables,$table.'_history',$column.'_id='.$item[$column.'_id']." AND account_id=$account_id");
            }
        }
        if ($completed_id!==null)
            $this->move_row($tables,$table.'_history',$column.'_id='.$completed_id." AND account_id=$account_id");
        if($all)
            $this->move_row($tables,$table.'_history',"account_id=$account_id");
        //redundant to rework
        //$count=parent::count_rows($tables);
        //if ($count==0) parent::set_autoincrement($tables,parent::max_column($table.'_history','idx')+1);
    }

    public function position_update($site_name,$account_id,$pair_str,$amount,$margin,$status,$price) //todo missing funding and liquiquidation
    {
        if(!isset(self::$pair_ids[$pair_str]))
            throw new BitBotError("$pair_str not found in ".__FUNCTION__);
        $pair_id=(int)self::$pair_ids[$pair_str]['idx'];
        $margin=(int)$margin;
        $sql="SELECT positions.idx AS idx ,exchange_id FROM positions JOIN exchange on exchange_id = exchange.idx WHERE exchange.name='$site_name' AND account_id=$account_id AND pair_id=$pair_id AND margin=$margin";
        $result=$this->get_one_result($sql);
        if(isset($result['idx']))
        {
            $this->update_one_record('positions',array('amount','status','price'),array($amount,$status,$price),"idx=".$result['idx']);
            return $result['idx'];
        }
        else
        {
            $ret =$this->insert_one_record('positions',
                array('exchange_id','account_id','pair_id','margin','amount','status','price'),
                array(self::$site_ids[$site_name]['idx'],$account_id,$pair_id,$margin,$amount,$status,$price),
                true);
            return $ret['idx'];
        }
    }

    public function maint_clear_dead_positions()
    {
        self::remove_row('positions','amount=0');
    }
}

class bit_db_util extends bit_db
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get_process_running($process)
    {
        switch ($process)
        {
            case 'recorder': //should be scribe
            case 'trader':
            case 'analyser':
            case 'thread':
                $update=$process.'_last_update';
                $res=self::get_one_result("SELECT value FROM config WHERE name='$update'");
                return (int)$res['value'] > (time()-(scribe::update_interval * 2));
            default:
                throw new BitBotError("Invalid Process");
        }
    }
    
    public function delete_data_by_time($table,$hours)
    {
        if (is_null($table)||is_null($hours))
            throw new \bitbot\BitBotError('Null passed to delete_data_by_time');
        $this->remove_row($table,"time < DATE_SUB(NOW(),INTERVAL $hours HOUR)");
    }
    
    public function update_market_config($market_id,$ticker=null,$trades=null,$arbitrage=null)
    {
        $fields=array();
        $values=array();

        if(!is_null($ticker))
        {
            $fields[]='ticker';
            $values[]=(int)$ticker;
        }
        if(!is_null($trades))
        {
            $fields[]='trades';
            $values[]=(int)$trades;
        }
        if(!is_null($arbitrage))
        {
            $fields[]='arbitrage';
            $values[]=(int)$arbitrage;
        }

        $this->update_one_record('market',$fields,$values,"idx=$market_id");
    }

    public function update_account_config($account_id,$enabled=null)
    {
        $fields=array();
        $values=array();
        if(!is_null($enabled))
        {
            $fields[]='enabled';
            $values[]=(int)$enabled;
        }
        $this->update_one_record('accounts',$fields,$values,"idx=$account_id");

    }

    public function get_db_keys()
    {
        $db=array();
        $db['currency']=$this->get_all_results('SELECT idx,cur_id FROM currency WHERE 1','cur_id','idx',true);
        $db['exchange']=$this->get_all_results('SELECT idx,name FROM exchange WHERE enabled = 1 AND record_enabled = 1','name','idx',true);
        $db['pair']=$this->get_all_results('SELECT idx,name_id FROM pair WHERE 1','name_id','idx',true);
        foreach($db['exchange'] as $name => $key)
            $db['market'][$name]=$this->get_all_results("SELECT market.idx,name_id FROM market JOIN pair ON pairs_id = pair.idx WHERE exchange_id=$key",'name_id','idx',true);
        return $db;
    }
    
    public function get_value($table,$key,$column)
    {
        $ret = $this->get_one_result("SELECT $column FROM $table WHERE idx=$key");
        if ($ret!==null && isset($ret[$column]))
            return $ret[$column];
        return null;
    }

    public function get_row($table,$key,$join=null)
    {
        if($join=='pair')
            $join='JOIN pair ON market.pairs_id = pair.idx';
        return $this->get_one_result("SELECT * FROM $table $join WHERE $table.idx=$key");
    }
    
}

class bit_db_user extends bit_db
{
    public static function logon($username,$password)
    {
        $password_md5 = md5($password);
        $sql="SELECT idx,config,session FROM users WHERE name='$username' AND password='$password_md5'";
        $res = self::get_one_result($sql);
        if ($res==null)
            return false;
        if($res['session']!==null)
            return $res;
        $session = md5('arse'.time());
        self::update_one_record('users',array('session'),array("$session"),'idx='.$res['idx']);
        $res['session']=$session;
        return $res;
    }

    public static function get_session($session_id)
    {
        if(strlen($session_id)!==32)
            return false;
        $sql="SELECT idx,config FROM users WHERE session='$session_id'";
        $res=self::get_one_result($sql);
        if ($res==null)
            return false;
        return $res;
    }
}

class utils
{
    static function get_is_cli()
    {
        return (php_sapi_name() === 'cli');
    }

    static function remove_slash($text,$lowercase=false)
    {
        if ($lowercase)
            return strtolower(str_replace('/','',$text));
        else
            return str_replace('/','',$text);
    }
    
    static  function percentage($valueA,$valueB,$precision=2)
    {
        if ($valueA==$valueB) return '0';
        return round(($valueA/$valueB)*100,$precision);
/*
        $ret=round(($valueA/$valueB)*100,$precision);
        if($ret==0)
        {
            $a=1;
        }
        return $ret;
*/
    }
    
    static function non_zero_balance($balance_data)
    {
        $ret=array();
        foreach($balance_data as $sym => $balance)
        {
            if(ctype_lower($sym))
                continue;
            if($balance['total'] > 0)
                $ret[]=$sym.':'.$balance['free'].'/'.$balance['total'];
        }
        return implode(',',$ret);
    }
    
    static function roundUpToAnyInt($n,$x=5)
    {
        return (round($n)%$x === 0) ? round($n) : round(($n+$x/2)/$x)*$x;
    }
    
    static function roundUpAnyDecimal($n,$x=10,$places=2)
    {
        $dec=utils::get_decimal($n,$places);
        $dec_round=utils::roundUpToAnyInt($dec,$x);
        if ($n>1)
            return round($n,0)+($dec_round/(pow(10,$places)));
        else
            return $dec_round/(pow(10,$places));
    }
    
    static function get_decimal($n,$places=2)
    {
        $whole = floor($n);      // 1
        $fraction = round($n - $whole,$places); // .25
        return substr($fraction,2);
    }
    
    static function countDecimals($fNumber) 
    { 
        $fNumber = floatval($fNumber); 
        for ( $iDecimals = 0; $fNumber != round($fNumber, $iDecimals); $iDecimals++ ); 
        return $iDecimals; 
    }
    
    static function round_significat($fNumber)
    {
        //TODO: There is a nicer way to do this
        $fNumber=(float)$fNumber;
        switch(true)
        {
            case $fNumber>1:
                return round($fNumber,2);
                break;
            case $fNumber>0.1:
                return round($fNumber,3);
                break;
            case $fNumber>0.01:
                return round($fNumber,4);
                break;
            case $fNumber>0.001:
                return round($fNumber,5);
                break;
            case $fNumber>0.0001:
                return round($fNumber,6);
                break;
            default:
                return $fNumber;
        }
    }
    
    static function array_pseudo_pop(array &$ar)
    {
        end($ar);
        $key=key($ar);
        return $ar[$key];
    }

    static function array_ucase_keys(array &$ar)
    {
        foreach($ar as $key => $val)
            if(!ctype_upper($key))
            {
                $ar[strtoupper($key)]=$val;
                unset($ar[$key]);
            }
    }
    
    static function array_multidem_search(array &$arr,$key,$value,array $append=null)
    {
        $return=null;
        foreach ($arr as $inner_key => &$inner_val)
        {
            if(is_array($inner_val))
            {
                $search=utils::array_multidem_search($inner_val, $key,$value,$append);
                if ($search!==null)
                {
                    if ($return===null)
                        $return = array();
                    $return[] = $search;
                }

            }
            elseif ($inner_key == $key && $inner_val == $value)
            {
                if ($append !== null)
                    foreach($append as $key => $val)
                        $arr[$key]=$val;
                return $arr;
            }
        }
        if(is_array($return))
        {
            if(count($return)==1)
                return $return[0];
            elseif(count($return)>1)
                return $return;
        }
        else
            return null;
    }

    static function array_multidem_key_search(array &$arr,$key)
    {
        $return=null;
        foreach ($arr as $inner_key => &$inner_arr)
            if(is_array($inner_arr))
            {
                $result=utils::array_multidem_key_search($inner_arr, $key);
                if($result!==null)
                {
                    if($return===null)
                        $return=array();
                    $return[]=$result;
                }
            }
            elseif ($inner_key == $key)
                return $inner_arr;

        if(count($return)==1)
            return $return[0];
        elseif(count($return)>0)
            return $return;
        return null;
    }

    static function array_multidem_sum(&$arr,$key)
    {
        $total=0;
        foreach($arr as $ar)
            $total+=$ar[$key];
        return $total;
    }

    static function array_multidem_a_in_b(array $arrA,array $arrB)
    {
        foreach($arrA as $key => $vals)
        {
            if(!isset($arrB[$key]))
                return false;
            if(is_array($vals) && is_array($arrB[$key]))
            {
                if(!utils::array_multidem_a_in_b($vals,$arrB[$key]))
                    return false;
            }
            elseif($vals !== $arrB[$key])
                return false;
        }
        return true;
    }

    static function array_multidem_remove_key_value(array &$arr,$key,$value)
    {
        foreach($arr as $k => $v)
            if(isset($v[$key]) && $v[$key]==$value)
                unset($arr[$k]);
    }

    static function array_change_key(array &$arr,$old_key,$new_key)
    {
        $arr[$new_key]=$arr[$old_key];
        unset($arr[$old_key]);
        return $arr;
    }

    static function array_cast_as(array &$arr,$type)
    {
        foreach($arr as &$val)
            settype($val,$type);
        return $arr;
    }

    static function array_key_compare($a,$b)
    {
        global $array_sort_key;
        if($a[$array_sort_key]==$b[$array_sort_key])
            return 0;
        elseif ($a[$array_sort_key] > $b[$array_sort_key])
            return -1;
        else return 1;
    }

    static function array_sort_key(array &$arr,$key)
    {
        global $array_sort_key;
        $array_sort_key = $key;
        usort($arr,array('\bitbot\utils','array_key_compare'));
    }

    static function array_is_associative($array) //true if array('arse'=>'butt') false if array('arse)
    {
        for ($k = 0, reset($array) ; $k === key($array) ; next($array))
            ++$k;
        return !is_null(key($array));
    }

    static function quote($text)
    {
        return "'$text'";
    }

    static function get_code($file_name)
    {
        if(gethostname()!=='Desktop')
            return false;
        if(!file_exists("test/$file_name"))
            return false;

        static $functions = array();
        if(!isset($functions[$file_name]))
            $functions[$file_name]=time()+30;
        elseif (time() < $functions[$file_name])
            return false;
        else
            $functions[$file_name]=time()+30;

        $handle = fopen("test/$file_name", "r");
        $line_no=0;
        $out='';
        if ($handle)
        {
            while (($line = fgets($handle)) !== false)
            {
                $line_no++;
                switch($line_no)
                {
                    case 1:
                        if($line=='<?php'.PHP_EOL);
                            continue 2;
                    case 3:
                        if($line=='$run=false;'.PHP_EOL)
                            return false;
                        else
                            $out.='$run=false;'.PHP_EOL;
                        break;
                    case 2:
                    default:
                        $out.=$line;
                }
            }
            fclose($handle);
        } else {
            throw new BitBotError('Cant open file');
        }
        file_put_contents("test/$file_name", '<?php'.PHP_EOL.$out);
        return $out;
    }

    static function set_prams(&$obj,array &$prams,$cast_as_string=false)
    {
        foreach ($obj as $key => $val)
            if(isset($prams[$key]))
                if(is_array($val))
                    $obj->$key=array_merge($obj->$key,$prams[$key]);
                else
                    $obj->$key = $cast_as_string?(string)$prams[$key]:$prams[$key];
    }

}

class bitbot_user
{

    const access_flags = array ('none'=>0,'config'=>1);

    private $id, $access, $session_id;

    public function __construct($id,$access,$session_id)
    {
        $this->id = $id;
        $this->access = $access;
        $this->session_id = $session_id;
    }

    public function get_access_flags()
    {
        return $this->access;
    }

    public function set_cookie()
    {
        setcookie('session_id', $this->session_id, time() + (86400 * 30), "/"); // 86400 = 1 day
    }

    public static function logon($username,$password)
    {
        $res=bit_db_user::logon($username,$password);
        if($res==false)
            return false;
        $access_flags=0;
        if($res['config'])
            $access_flags|=bitbot_user::access_flags['config'];
        return new bitbot_user($res['idx'],$access_flags,$res['session']);

    }

    public static function get_user($session_id)
    {
        $session_id = bit_db::escape_string($session_id);
        $res = bit_db_user::get_session($session_id);
        if ($res===false)
            return false;
        $access_flags=0;
        if($res['config'])
            $access_flags|=bitbot_user::access_flags['config'];
        return new bitbot_user($res['idx'],$access_flags,$session_id);
    }

}

function console($message,$pad=false,$time=false)
{
    if(is_array($message))
        $message=implode(',',$message);
    if ( substr($message,0,5)=='ccxt\\')
        $message = substr($message,5);
    if($pad)
    {
        $split=explode(' ',$message);
        $message='';
        foreach($split as $bit)
        {
            if (!ctype_upper($bit))
                $message.=str_pad($bit,10,' ',STR_PAD_RIGHT);
            else
                $message.="$bit ";
        }
    }
    if ($time)
        $message=date('H:i').' '.$message;
    echo $message.PHP_EOL;
    file_put_contents('bitbot.log', $message.PHP_EOL , FILE_APPEND | LOCK_EX);
}

bit_db::connect();

?>