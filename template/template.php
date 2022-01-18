<?php
namespace bitbot;
class template
{
    private $template_text=null;
    private $replace = array();
    private $output=null;
    
    public function load_template($file_path)
    {
        $this->template_text=file_get_contents($file_path);
        if ($this->template_text==null)
            throw new BitBotError('File Load Failed');
    }
    
    public function set_template($text)
    {
        $this->template_text=$text;
    }
    
    public function add_item($key,$text)
    {
        $this->replace[$key]=$text;
    }
    
    public function get_output()
    {
        $this->output=$this->template_text;
        foreach ($this->replace as $key => $item)
            $this->output=str_replace('X'.$key.'X',$item,$this->output);
        return $this->output;
    }
}

class table
{
    public $headers=array();
    public $row=array();
    public $tag_polar=array();
    public $percentage=array();
    public $tag_cells=array();
    public $tag_cells_instr=array();
    public $round=array();
    public $compare_colums=array();
    public $compare_major_points = 0.5;
    public $pre_htm='';
    public $post_htm='';
    
    private $table_id=null;
    private $table_class=null;
    
    public function __construct($id=null,$class=null)
    {
        $this->table_id = $id;
        $this->table_class = $class;
    }
    
    public function add_tag_column_polarity($number)
    {
        $this->tag_polar[]=$number;
    }
    
    public function add_tag_cell_value($value,$class)
    {
        $this->tag_cells[$value][]=$class;
    }

    public function add_tag_cells_instr($value,$class)
    {
        $this->tag_cells_instr[$value]=$class;
    }
    
    public function add_column_percentage($number)
    {
        $this->percentage[]=$number;
    }

    public function getset_compare_major_points($points=null)
    {
        if($points!==null)
            $this->compare_major_points=$points;
        return $this->compare_major_points;
    }
    public function add_column_compare($a,$b,$tag_pos,$tag_pos_mj,$tag_neg,$tag_neg_mj)
    {
        $this->compare_colums[]=array('col_a'=>$a,'col_b'=>$b,'tag_pos'=>$tag_pos,'tag_pos_mj'=>$tag_pos_mj,'tag_neg'=>$tag_neg,'tag_neg_mj'=>$tag_neg_mj);
    }
    
    static $sort_column_num=0;
    static $sort_decending=false;
    public static function compare($a,$b)
    {
        if ($a[table::$sort_column_num]==$b[table::$sort_column_num])
            return 0;
        if (table::$sort_decending)
            return ($a[table::$sort_column_num]<$b[table::$sort_column_num]) ? -1 : 1;
        else
            return ($a[table::$sort_column_num]>$b[table::$sort_column_num]) ? -1 : 1;
    }
    
    public static function round_number($value)
    {
        switch(true)
        {
            case ($value > 100):
                return round($value,2);
            case ($value > 1):
                return round($value,4);
            case ($value > 0.1):
                return round($value,4);
            case ($value > 0.01):
                return round($value,5);
            case ($value === null):
                return null;
            default:
                return round($value,6);
        }
    }

    public static function percentage(float $val_a,float $val_b)
    {
        return (float)(($val_a-$val_b)/$val_b) * 100;
    }

    public function sort_by($colum,$decending=true)
    {
        \bitbot\table::$sort_column_num=$colum;
        \bitbot\table::$sort_decending = $decending;
        usort($this->row, array('\bitbot\table','compare'));
    }
    
    public function round_column($number)
    {
        if (is_array($number))
            $this->round = array_merge($this->round,$number);
        else
            $this->round[]=$number;
    }
    
    public function add_header(array $header)
    {
        $this->headers = $header;
    }
    
    public function add_pre_htm($htm)
    {
        $this->pre_htm.=$htm;
    }

    public function add_post_htm($htm)
    {
        $this->post_htm.=$htm;
    }
    
    public function add_row(array $item)
    {
        $this->row[]=$item;
    }
    
    public function get_table()
    {
        if (count($this->row) > 0 && count($this->headers) != count($this->row[0]))
            throw new BitBotError('Column Row missmatch');
        $output=$this->pre_htm;
        $id=is_null($this->table_id)?'':'id="'.$this->table_id."'";
        $class=is_null($this->table_class)?'':'class="'.$this->table_class."'";
        $output.= "<table $id $class>\r\n<tr>";
        for ($a=0;$a<count($this->headers);$a++)
        {
            $output.='<th>'.$this->headers[$a].'</th>';
            if ($a==count($this->headers)-1)
                $output.= "</tr>\r\n";
        }
        for ($a=0;$a<count($this->row);$a++)
        {
            $output.= "<tr>";
            for ($b=0;$b<count($this->row[$a]);$b++)
            {
                $td='<td>';
                $value=$this->row[$a][$b];
                //add colour to percentage column
                if(array_search($b,$this->tag_polar)!==false)
                    $td=$this->row[$a][$b]>0?'<td class="up">':'<td class="down">';
                //add a class to specific values
                if (is_string($value))
                    foreach($this->tag_cells_instr as $search => $tag)
                        if(strpos($value,$search)!==false)
                            $td='<td class="'.$tag.'">';
                //add a class to some instr text
                if (is_string($value) && array_key_exists($value,$this->tag_cells))
                    $td='<td class="'.implode($this->tag_cells[$value],' ').'">';
                //make a percentage of a column
                if(array_search($b,$this->percentage)!==false)
                    $value=($value*100).'%';
                //compare columns
                foreach($this->compare_colums as $compare)
                    if ($compare['col_a']==$b)
                    {
                        if (is_null($value) || is_null($this->row[$a][$compare['col_b']]))
                            break;
                        if ((float)$value > (float)$this->row[$a][$compare['col_b']])
                        {
                            if($this::percentage($value,$this->row[$a][$compare['col_b']]) >= $this->compare_major_points)
                            {
                                $td='<td class="'.$compare['tag_pos_mj'].'">';
                            }
                            else
                                $td='<td class="'.$compare['tag_pos'].'">';
                        }
                        elseif ((float)$value < (float)$this->row[$a][$compare['col_b']])
                        {
                            if($this::percentage($value,$this->row[$a][$compare['col_b']]) <= -$this->compare_major_points)
                                $td='<td class="'.$compare['tag_neg_mj'].'">';
                            else
                                $td='<td class="'.$compare['tag_neg'].'">';
                        }
                    }
                //round columns
                if(array_search($b,$this->round)!==false)
                    $value=table::round_number($value);
                $output.= $td.$value.'</td>';
                if ($b==count($this->row[$a])-1) 
                    $output.= "</tr>\r\n";
//                if (count($this->row[$a])-1)
//                    $output.= "</tr>\r\n";
            }
        }
        $output.= "</table>\r\n";
        $output.= $this->post_htm;
        return $output;
    }
    
    public function write()
    {
        echo $this->get_table();
    }
}

class utils_htm 
{
    static function make_anchor($href,$text,$id=null,$class=null)
    {
        $id=is_null($id)?'':"id='$id'";
        $class=is_null($class)?'':"class='$class'";
        return "<a href='$href' $id $class>$text</a>";
    }
    
    static function make_checkbox($text,$name,$value,$checked=false,$id=null,$class=null)
    {
        $id=is_null($id)?'':"id='$id'";
        $class=is_null($class)?'':"class='$class'";
        $checked=$checked?'checked':'';
        return "<input type='checkbox' name='$name' value='$value' $checked $id $class>$text<br>\r\n";
    }
    
    static function make_hidden_value($name,$value)
    {
        return "<input type='hidden' name='$name' value='$value'>\r\n";
    }
}
?>