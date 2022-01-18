<?php
/*
$this->db->order_update(
    $this->site_info['name'],
    $this->authenticate_info['idx'],
    $item[0],substr($item[3],1),
    $item[6],
    $item[8],
    $item[3][0]=='t',
    $item[13],
    $item[16]);//ID,Pair,Amount(left),type,status,price
*/

//$this->db->bitfinex_order_update($this->authenticate_info['idx'],$data);
/*
$this->db->order_update(
    $this->site_info['name'],
    $this->authenticate_info['idx'],
    $data[0],substr($data[3],1),
    $data[6],
    $data[8],
    $data[3][0]=='t',
    $data[13],$data[16]);//ID,Pair,Amount(left),type,status,price
*/
//$no=new order($data,$this->authenticate_info['idx']);
/*
$no=new order(array(
    'id'=>$data[0],
    'pair'=>substr($data[3],1),
    'amount'=>[6],
    'type'=>$data[8],
    'margin'=>$data[3][0]=='t',
    'status'=>$data[13],
    'price'=>$data[16]));
*/
//$this->trade_book['orders'][$data[0]]=$no;

/*
if(isset($this->trade_book['orders'][$data[0]]))
{
    $this->trade_book['orders_complete'][$data[0]]=$this->trade_book['orders'][$data[0]];
    console($this->site.' account '.$this->authenticate_info['idx'].' order '.$this->trade_book['orders'][$data[0]]);
    unset($this->trade_book['orders'][$data[0]]);
}
elseif (isset($this->trade_book['orders_complete'][$data[0]]))
    //todo: remove this if it never fires
    console('DEBUG: '.$data[0].' found in completed orders '.var_dump($data));
else
    //todo: remove this if it never fires
    console('DEBUG: '.$data[0].' not found in orders or completed orders '.var_dump($data));
*/