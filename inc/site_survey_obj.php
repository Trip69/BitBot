<?php
namespace bitbot;

class site_survey
{
    private $db = null;
    private $sites = array();

    public function __construct(array $sites)
    {
        console('Bitcoin Site Survey By Trip v1.0');
        $this->db = new bit_db_key_lookup();
        $this->sites = $sites;
    }

    public function __destruct()
    {
        $this->sites = null;
        $this->db = null;
    }

    public function get_db_lookups()
    {
        return $this->db->get_lookup_array();
    }

    public function write_db_lookups_to_file()
    {

        try {
            $myfile = fopen('db_keys.json', 'w') or die('failed to create db export file');
            $save_data=json_encode($this->db);
            fwrite($myfile,$save_data);
            fclose($myfile);
            return true;
        } catch (\Exception $ex) {
            console('Error writting lookups to file '.$ex);
        }
    }

    public function get_markets()
    {
        return $this->db->get_markets();
    }

    public function check_all()
    {
        console('Checking '.count($this->sites). ' sites.');
        foreach ($this->sites as $site)
            if(!$this->check($site))
                return false;
        return true;
    }

    public function check($site)
    {
        $obj = "ccxt\\$site";
        $this->sites[$site] = new $obj;
        try
        {
            $markets = $this->sites[$site]->fetch_markets();
            $this->sites[$site]->setMarkets($markets);
            foreach ($this->sites[$site]->currencies as $currency)
                $this->db->getset_currency_key($currency['id'],$currency['code']);
            foreach ($this->sites[$site]->markets as $market)
                $this->db->getset_market_key($site,$market);
            $this->db->check_markets($site,$markets);
            console($site.' '.count($this->sites[$site]->currencies).' currencies in '.count($this->sites[$site]->markets).' markets.');

        } catch (\Exception $ex)
        {
            console("Error connecting to $site.\r\n$ex",false,true);
            return false;
        }
        return true;
    }

}
?>