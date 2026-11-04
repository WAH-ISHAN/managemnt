<?php
declare(strict_types=1);
final class LocaleService {
    public static function language(): string { $locale=Db::setting('locale','en'); return in_array($locale,['en','si','ta'],true) ? $locale : 'en'; }
    public static function translate(string $text): string {
        $locale=self::language();
        if ($locale==='en') return $text;
        static $dictionary=null;
        if ($dictionary===null) { $dictionary=[
            'Today'=>['අද','இன்று'],'Planner'=>['දින සැලසුම','தினத் திட்டம்'],'Daily Planner'=>['දින සැලසුම','தினத் திட்டம்'],
            'Overview'=>['දළ විශ්ලේෂණය','கண்ணோட்டம்'],'Work management'=>['වැඩ කළමනාකරණය','பணி மேலாண்மை'],'Money'=>['මුදල්','பணம்'],
            'Investments'=>['ආයෝජන','முதலீடுகள்'],'University'=>['විශ්වවිද්‍යාලය','பல்கலைக்கழகம்'],'Goals'=>['ඉලක්ක','இலக்குகள்'],
            'Goals & Growth'=>['ඉලක්ක හා වර්ධනය','இலக்குகளும் வளர்ச்சியும்'],'Your freedom'=>['ඔබේ නිදහස','உங்கள் சுதந்திரம்'],
            'Career'=>['වෘත්තීය ගමන','தொழில் வளர்ச்சி'],'Documents'=>['ලේඛන','ஆவணங்கள்'],'Study focus'=>['අවධානයෙන් පාඩම් කරමු','கற்றலில் கவனம்'],
            'Lessons & growth'=>['පාඩම් හා වර්ධනය','பாடங்களும் வளர்ச்சியும்'],'Review & insights'=>['සමාලෝචන හා අදහස්','மீளாய்வும் நுண்ணறிவும்'],
            'Settings'=>['සැකසුම්','அமைப்புகள்'],'Automation'=>['ස්වයංක්‍රීයකරණය','தானியக்கம்'],'Automations'=>['ස්වයංක්‍රීයකරණ','தானியக்கங்கள்'],
            'Every day'=>['දිනපතා','ஒவ்வொரு நாளும்'],'Your money'=>['ඔබේ මුදල්','உங்கள் பணம்'],'Learning & life'=>['ඉගෙනීම හා ජීවිතය','கற்றலும் வாழ்க்கையும்'],
            'More tools'=>['තවත් මෙවලම්','மேலும் கருவிகள்'],'Your workspace'=>['ඔබේ වැඩ අවකාශය','உங்கள் பணியிடம்'],
            'Modules & language'=>['මොඩියුල හා භාෂාව','தொகுதிகளும் மொழியும்'],'Find'=>['සොයන්න','தேடு'],'Menu'=>['මෙනුව','பட்டி'],'Add'=>['එක් කරන්න','சேர்'],'Lock'=>['අගුළු දමන්න','பூட்டு'],
            'Find a page or action'=>['පිටුවක් හෝ ක්‍රියාවක් සොයන්න','பக்கம் அல்லது செயலைத் தேடு'],'Saved on this laptop'=>['මෙම පරිගණකයේ සුරැකේ','இந்தக் கணினியில் சேமிக்கப்பட்டது'],
            'Choose your persona'=>['ඔබට ගැළපෙන භූමිකාව තෝරන්න','உங்கள் வகையைத் தேர்ந்தெடுக்கவும்'],'Employee'=>['සේවකයෙක්','பணியாளர்'],
            'University student'=>['විශ්වවිද්‍යාල සිසුවෙක්','பல்கலைக்கழக மாணவர்'],'School student'=>['පාසල් සිසුවෙක්','பள்ளி மாணவர்'],
            'Freelancer'=>['ස්වාධීන සේවා සපයන්නෙක්','சுயதொழிலாளர்'],'Business owner'=>['ව්‍යාපාර හිමිකරුවෙක්','வணிக உரிமையாளர்'],'Job seeker'=>['රැකියා සොයන්නෙක්','வேலை தேடுபவர்'],
            'Language'=>['භාෂාව','மொழி'],'Your name'=>['ඔබේ නම','உங்கள் பெயர்'],'Starter pack'=>['ආරම්භක පැකේජය','தொடக்கத் தொகுப்பு'],
            'No starter pack'=>['ආරම්භක පැකේජයක් නැත','தொடக்கத் தொகுப்பு வேண்டாம்'],'Create my workspace'=>['මගේ වැඩ අවකාශය සාදන්න','எனது பணியிடத்தை உருவாக்கு'],
            'Save language'=>['භාෂාව සුරකින්න','மொழியைச் சேமி'],'Apply persona defaults'=>['භූමිකාවේ පෙරනිමි යොදන්න','வகையின் இயல்புநிலைகளைப் பயன்படுத்து'],
            'Modules'=>['මොඩියුල','தொகுதிகள்'],'Enabled'=>['සක්‍රියයි','இயக்கப்பட்டது'],'Sidebar order'=>['පැති තීරුවේ පිළිවෙළ','பக்கப்பட்டி வரிசை'],
            'Save modules'=>['මොඩියුල සුරකින්න','தொகுதிகளைச் சேமி'],'Always on'=>['සැමවිටම සක්‍රියයි','எப்போதும் இயங்கும்'],
            'Welcome to LifeOS'=>['LifeOS වෙත සාදරයෙන් පිළිගනිමු','LifeOS இற்கு வரவேற்கிறோம்'],
        ];
        foreach (glob(dirname(__DIR__).'/locales/*.php') ?: [] as $file) $dictionary=array_replace($dictionary,require $file);
        }
        $translated=$dictionary[$text][$locale==='si' ? 0 : 1] ?? $text;
        if($translated!==$text && trim($text)!==$text){preg_match('/^\s*/u',$text,$before);preg_match('/\s*$/u',$text,$after);return $before[0].trim($translated).$after[0];}
        return $translated;
    }
}
