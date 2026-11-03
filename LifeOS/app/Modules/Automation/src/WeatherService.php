<?php
declare(strict_types=1);
/** Explicit opt-in Open-Meteo current conditions, with a bounded daily cache. */
final class WeatherService {
    public static function configure(bool $enabled,string $latitude,string $longitude): void {
        if ($enabled && (!is_numeric($latitude) || !is_numeric($longitude) || (float)$latitude < -90 || (float)$latitude > 90 || (float)$longitude < -180 || (float)$longitude > 180)) throw new InvalidArgumentException(t('Enter valid latitude and longitude.'));
        Db::set('weather_enabled',$enabled ? '1' : '0'); Db::set('weather_latitude',$latitude); Db::set('weather_longitude',$longitude); Db::set('weather_cache','');
    }
    public static function parse(array $response): string {
        $c=$response['current'] ?? [];
        if (!is_numeric($c['temperature_2m'] ?? null) || !is_numeric($c['precipitation'] ?? null) || !is_string($c['time'] ?? null)) throw new InvalidArgumentException(t('Weather response unavailable.'));
        return 'Weather: '.round((float)$c['temperature_2m'],1).' °C, precipitation '.round((float)$c['precipitation'],1).' mm · Open-Meteo · '.$c['time'];
    }
    public static function brief(): ?string {
        if (Db::setting('weather_enabled')!=='1') return null;
        $cached=json_decode(Db::setting('weather_cache'),true);
        if (is_array($cached) && ($cached['date'] ?? '')===date('Y-m-d')) return $cached['text'];
        try {
            if (!function_exists('curl_init')) throw new RuntimeException(t('cURL is required.'));
            $lat=Db::setting('weather_latitude'); $lon=Db::setting('weather_longitude');
            if (!is_numeric($lat) || !is_numeric($lon)) throw new RuntimeException(t('Coordinates missing.'));
            $url='https://api.open-meteo.com/v1/forecast?'.http_build_query(['latitude'=>$lat,'longitude'=>$lon,'current'=>'temperature_2m,precipitation','timezone'=>'Asia/Colombo','forecast_days'=>1]);
            $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA);
            $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
            if (!is_string($raw) || strlen($raw)>100000 || $code!==200) throw new RuntimeException(t('Weather request failed.'));
            $text=self::parse(json_decode($raw,true,32,JSON_THROW_ON_ERROR));
        } catch (Throwable $error) { $text='Weather unavailable. Check the connection and saved coordinates in Automations.'; }
        Db::set('weather_cache',json_encode(['date'=>date('Y-m-d'),'text'=>$text],JSON_THROW_ON_ERROR)); return $text;
    }
}
