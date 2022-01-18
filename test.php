<?php
namespace bitbot;
require 'inc/db.php';
//require 'inc/interfaces.php';

$arr=array();
$arr[]="one";
$arr[]="two";
$arr[]="three";
echo utils::array_is_associative($arr)?'true':'false';

exit(' arse');

const bita = 1;
const bitb = 2;
const bitc = 4;
const bitd = 8;
//elseif( ($data['price'] > ($pmin['price_open'] + $pmin['price_half_point'])) &! ($pmin['report_flags'] & (self::rt_ap | self::rt_ahp)))
//true && !(2 & (1|2))

$flags=0;
$flags|=bita;
$flags|=bitb;
$flags|=bitc;

if($flags == (bita | bitb | bitc))
    echo 'yeah they do ';


$a=array('a'=>1,'b'=>2);
$a['c']=new \stdClass();
$a['c']->balls = function() {echo 'nuts';};

$a['c']->balls();

class foo
{
    public $a=0;
    public $b=0;

    public function __construct(array $prams)
    {
        utils::set_prams($this,$prams);
    }
}

$bar=new foo(array('a'=>20,'b'=>100,'c'=>1000));





$finish=time()+5;
//for($a=4;$a>0;$a--)
//{
$a=4;
    while(time()<$finish)
    {
        if ($a==5 &! ($flags & bita))
        {
            $flags |= bita;
            echo time().' bita set'.PHP_EOL;

        }
        elseif ($a==4 &! ($flags & (bita |  bitb)))
        {
            $flags |= bitb;
            echo time().' bitb set'.PHP_EOL;
        }
        sleep(1);
    }
//}


///

class test
{
    function test_b()
    {
        return 'worked'.PHP_EOL;
    }

    function test_a()
    {
        $a='echo $this->test_b();';
        eval($a);
        return $a;
    }
}

$terter=new test();
$terter->test_a();


/////

$obj=new stdClass();
$obj->cid=time();
$test=array(0,'on',null,$obj);

$out=json_encode($test);
echo $out;

$flags=0;
$bits=explode(',','BUY');

while(true)
{
    if (true &! ($flags & bita))
    {
        $flags |= bita;
        echo time().' bita set'.PHP_EOL;

    }
    sleep(1);
}

?>