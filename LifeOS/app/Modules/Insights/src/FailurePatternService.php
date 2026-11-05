<?php
declare(strict_types=1);
/** Local descriptive grouping with evidence; never an inferred psychological cause. */
final class FailurePatternService {
    private static function words(string $text): array {
        $text=mb_strtolower($text);
        $words=preg_split('/[^\pL\pM\pN]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
        $stop=['the','and','was','were','with','that','this','from','have','had','not','for','but','because','again','did','didnt','too','when','my','me','it','to','of','in','on','is','a','an','i','we','මම','සහ','නිසා','එක','නැහැ','நான்','மற்றும்','ஒரு','இது','எனது'];
        return array_values(array_unique(array_filter($words,fn($w)=>mb_strlen($w)>=3&&!in_array($w,$stop,true))));
    }
    public static function related(string $until): array {
        $until=min(valid_date($until),date('Y-m-d',strtotime('+1 day')));
        $rows=Db::all("SELECT id,area,what_happened,root_cause,lesson,action,created_at FROM lessons WHERE created_at>=datetime(?,'-90 days') AND created_at<datetime(?) ORDER BY created_at,id LIMIT 250",[$until,$until]);
        $themes=[
            'Planning and preparation'=>[['deadline','deadlines','planning','planned','unprepared','estimate','estimation','late','forgot','සැලසුම්','ප්‍රමාද','අමතක','திட்டமிடல்','தாமதம்','மறந்தேன்'], 'Try one preparation checklist or an earlier reminder, then review whether it helped.'],
            'Distractions and interruptions'=>[['distraction','distractions','distracted','interruptions','interrupted','phone','notifications','social','අවධානය','දුරකථනය','கவனச்சிதறல்','தொலைபேசி'], 'Try one protected focus period and record what interrupted it.'],
            'Rest and workload'=>[['tired','fatigue','exhausted','sleep','overloaded','overload','overwork','විවේක','නින්ද','වෙහෙස','தூக்கம்','சோர்வு'], 'Compare the recorded workload with available time and choose one rest or scheduling adjustment.'],
            'Communication and expectations'=>[['communication','misunderstood','unclear','requirements','feedback','clarify','අපැහැදිලි','සන්නිවේදන','தெளிவற்ற','தகவல்தொடர்பு'], 'Confirm one unclear requirement or expectation before the next attempt.'],
            'Practice and checking'=>[['practice','practise','testing','untested','unchecked','rushed','mistake','review','පුහුණු','වැරදි','பயிற்சி','தவறு'], 'Choose one small practice or checking step and record the result.'],
        ];
        $out=[];$signatures=[];
        foreach($themes as $label=>[$terms,$suggestion]){
            $matches=[];
            foreach($rows as $row){$tokens=self::words($row['root_cause'].' '.$row['what_happened']);$matched=array_values(array_intersect($terms,$tokens));
                if($matched)$matches[]=$row+['matched'=>$matched];
            }
            if(count($matches)<2)continue;
            $ids=array_column($matches,'id');$signature=implode(',',$ids);if(isset($signatures[$signature]))continue;$signatures[$signature]=true;
            $out[]=['label'=>t($label),'reason'=>t('These entries contain related words. This is a possible theme to review, not a proven cause.'),'suggestion'=>t($suggestion),'count'=>count($matches),'ids'=>$ids,'areas'=>array_values(array_unique(array_column($matches,'area'))),'evidence'=>array_map(fn($r)=>['id'=>$r['id'],'date'=>$r['created_at'],'text'=>mb_substr($r['root_cause']?:$r['what_happened'],0,240),'matched'=>$r['matched']],$matches)];
        }
        // Also discover vocabulary shared across entries without a predefined theme.
        $tokens=array_map(fn($r)=>self::words($r['root_cause']),$rows);$pairs=0;
        for($i=0;$i<count($rows);$i++)for($j=$i+1;$j<count($rows);$j++){
            if(++$pairs>32000)break 2;
            $common=array_values(array_intersect($tokens[$i],$tokens[$j]));$union=array_unique([...$tokens[$i],...$tokens[$j]]);
            if(count($common)<2||count($common)/max(1,count($union))<.4||$tokens[$i]===$tokens[$j])continue;
            $signature=$rows[$i]['id'].','.$rows[$j]['id'];if(isset($signatures[$signature]))continue;$signatures[$signature]=true;
            $evidence=[];foreach([$rows[$i],$rows[$j]]as $row)$evidence[]=['id'=>$row['id'],'date'=>$row['created_at'],'text'=>mb_substr($row['root_cause'],0,240),'matched'=>$common];
            $out[]=['label'=>t('Similar wording across entries'),'reason'=>t('Shared words:').' '.implode(', ',$common),'suggestion'=>t('Compare these entries and choose one small preventive action to test.'),'count'=>2,'ids'=>[$rows[$i]['id'],$rows[$j]['id']],'areas'=>array_values(array_unique([$rows[$i]['area'],$rows[$j]['area']])),'evidence'=>$evidence];
            if(count($out)>=12)break 2;
        }
        return array_slice($out,0,12);
    }
}
