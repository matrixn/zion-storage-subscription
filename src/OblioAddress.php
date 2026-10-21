<?php
declare(strict_types=1);
namespace Zion;

final class OblioAddress {
 public const COUNTIES=['AB'=>'Alba','AR'=>'Arad','AG'=>'Arges','BC'=>'Bacau','BH'=>'Bihor','BN'=>'Bistrita-Nasaud','BT'=>'Botosani','BV'=>'Brasov','BR'=>'Braila','BZ'=>'Buzau','CS'=>'Caras-Severin','CL'=>'Calarasi','CJ'=>'Cluj','CT'=>'Constanta','CV'=>'Covasna','DB'=>'Dambovita','DJ'=>'Dolj','GL'=>'Galati','GR'=>'Giurgiu','GJ'=>'Gorj','HR'=>'Harghita','HD'=>'Hunedoara','IL'=>'Ialomita','IS'=>'Iasi','IF'=>'Ilfov','MM'=>'Maramures','MH'=>'Mehedinti','MS'=>'Mures','NT'=>'Neamt','OT'=>'Olt','PH'=>'Prahova','SM'=>'Satu Mare','SJ'=>'Salaj','SB'=>'Sibiu','SV'=>'Suceava','TR'=>'Teleorman','TM'=>'Timis','TL'=>'Tulcea','VS'=>'Vaslui','VL'=>'Valcea','VN'=>'Vrancea','B'=>'Bucuresti'];
 public static function ascii(string $value): string {return strtr($value,['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','Ă'=>'A','Â'=>'A','Î'=>'I','Ș'=>'S','Ş'=>'S','Ț'=>'T','Ţ'=>'T']);}
 public static function normalize(array $profile): array {
  if(strtoupper($profile['country'])!=='RO')return ['country'=>$profile['country'],'state'=>trim($profile['county']),'city'=>trim($profile['city'])];
  $county=self::ascii(trim($profile['county']));
  $county=preg_replace('/^(?:RO[- ]|judetul\s+|judet\s+|jud\.?\s*)/i','',$county);
  $key=mb_strtolower(preg_replace('/[\s-]+/','',$county));$name=null;
  foreach(self::COUNTIES as $code=>$candidate)if($key===strtolower($code) || $key===strtolower(str_replace([' ','-'],'',$candidate)))$name=$candidate;
  if(!$name)throw new \InvalidArgumentException('billing.invalid');
  $city=self::ascii(trim($profile['city']));$city=preg_replace('/^(?:municipiul|mun\.?|orasul|oras\.?|comuna|com\.?)\s+/i','',$city);
  $city=preg_replace('/\s+/',' ',$city);
  if($city==='')throw new \InvalidArgumentException('billing.invalid');
  // Preserve an explicitly supplied sector; never invent one from incomplete data.
  $city=mb_convert_case($city,MB_CASE_TITLE,'UTF-8');
  return ['country'=>'Romania','state'=>$name,'city'=>$city];
 }
}
