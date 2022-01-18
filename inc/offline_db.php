<?php
namespace bitbot;

class offline_db extends bit_db
{
    public function get_terminal($pair,$terminal)
    {
        $sort=$terminal=='start'?'ASC':'DESC';
        $sql="SELECT market.idx, trade.time FROM trade
              JOIN market on market_id = market.idx JOIN pair ON market.pairs_id = pair.idx
              WHERE name='$pair'
              ORDER BY trade.time $sort LIMIT 1";
        return self::get_one_result($sql);
    }

    public function high_low_minute($market_id,$minute)
    {
        $start=$minute->format('Y-m-d H:i:s');
        $finish=$minute->add(new \DateInterval('PT1M'))->format('Y-m-d H:i:s');
        $sql="SELECT MAX(price) as high,MIN(price) as low FROM trade WHERE market_id=$market_id AND time > '$start' AND time < '$finish'";
        return self::get_one_result($sql);
    }
}
?>