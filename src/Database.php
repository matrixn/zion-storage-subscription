<?php
namespace Zion;
use PDO;
final class Database {
 public PDO $pdo;
 public function __construct(?string $path=null) {
  $path ??= env('DB_PATH','storage/app.sqlite');
  if (!str_starts_with($path,'/') && $path!==':memory:') $path=dirname(__DIR__).'/'.$path;
  if ($path!==':memory:' && !is_dir(dirname($path))) mkdir(dirname($path),0700,true);
  $this->pdo=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=15000; PRAGMA journal_mode=WAL;');
 }
 public function migrate(): void {
  $this->pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
  foreach(glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) $this->pdo->exec(file_get_contents($file));
  // Preserve installations made before quota synchronization was introduced.
  if(!in_array('user_id',array_column($this->run('PRAGMA table_info(billing_events)')->fetchAll(),'name'),true))$this->pdo->exec('ALTER TABLE billing_events ADD COLUMN user_id TEXT');
  if(!in_array('quota_sync',array_column($this->run('PRAGMA table_info(account_controls)')->fetchAll(),'name'),true))$this->pdo->exec("ALTER TABLE account_controls ADD COLUMN quota_sync TEXT NOT NULL DEFAULT 'ready'");
  foreach(['users'=>['session_version'=>'INTEGER NOT NULL DEFAULT 0','is_deleted'=>'INTEGER NOT NULL DEFAULT 0','archived_at'=>'INTEGER','terms_version'=>"TEXT NOT NULL DEFAULT ''",'privacy_version'=>"TEXT NOT NULL DEFAULT ''",'billing_interval'=>"TEXT NOT NULL DEFAULT 'month'"], 'subscriptions'=>['billing_interval'=>"TEXT NOT NULL DEFAULT 'month'",'currency'=>"TEXT NOT NULL DEFAULT 'ron'"], 'checkout_sessions'=>['tax_bps'=>'INTEGER NOT NULL DEFAULT 0','price_id'=>'TEXT','billing_interval'=>"TEXT NOT NULL DEFAULT 'month'"], 'plan_changes'=>['tax_bps'=>'INTEGER NOT NULL DEFAULT 0','tax_rate_id'=>"TEXT NOT NULL DEFAULT ''",'base_amount'=>'INTEGER','billing_interval'=>"TEXT NOT NULL DEFAULT 'month'"]] as $table=>$columns){
   $existing=array_column($this->run('PRAGMA table_info('.$table.')')->fetchAll(),'name');
   foreach($columns as $name=>$type)if(!in_array($name,$existing,true))$this->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$type);
  }
  foreach(['users','subscriptions','checkout_sessions','plan_changes','billing_prices','invoices','fiscal_invoices','billing_events'] as $table){
   if(!in_array('stripe_mode',array_column($this->run('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true))$this->pdo->exec("ALTER TABLE ".$table." ADD COLUMN stripe_mode TEXT NOT NULL DEFAULT 'test' CHECK(stripe_mode IN ('test','live'))");
  }
  $this->pdo->exec("DROP INDEX IF EXISTS active_billing_price; CREATE UNIQUE INDEX IF NOT EXISTS active_billing_price_mode ON billing_prices(plan,interval,stripe_mode) WHERE status='active'");
  // Reconcile existing free accounts once, after legacy columns have been upgraded.
  if(!$this->one("SELECT key FROM integration_settings WHERE key='free-quota-5gb-v1'"))$this->transaction(function(){
   $this->run("INSERT INTO account_controls(user_id,quota_sync,updated_at) SELECT id,'pending',? FROM users WHERE selected_plan_id='free' AND storage_user_id IS NOT NULL ON CONFLICT(user_id) DO UPDATE SET quota_sync='pending',updated_at=excluded.updated_at",[time()]);
   $this->run("INSERT INTO integration_settings(key,value) VALUES('free-quota-5gb-v1','queued')");
  });
  // One-time read of former environment mappings. New purchases never use these RON IDs.
  if(!$this->one("SELECT key FROM integration_settings WHERE key='catalog_env_import_v1'"))$this->transaction(function(){
   foreach(['120gb','250gb','500gb'] as $plan)foreach(array_filter(array_map('trim',explode(',',env('STRIPE_PRICE_'.strtoupper($plan)).','.env('STRIPE_LEGACY_PRICE_'.strtoupper($plan))))) as $id)$this->run("INSERT OR IGNORE INTO billing_prices(price_id,plan,interval,currency,status,created_at,updated_at) VALUES(?,?,'month','ron','legacy',?,?)",[$id,$plan,time(),time()]);
   foreach($this->run("SELECT plan,stripe_price_id FROM subscriptions WHERE stripe_price_id IS NOT NULL AND plan IN ('120gb','250gb','500gb')")->fetchAll() as $s)$this->run("INSERT OR IGNORE INTO billing_prices(price_id,plan,interval,currency,status,created_at,updated_at) VALUES(?,?,'month','ron','legacy',?,?)",[$s['stripe_price_id'],$s['plan'],time(),time()]);
   foreach($this->run('SELECT from_plan,to_plan,from_price,to_price,currency FROM plan_changes')->fetchAll() as $r)foreach(['from','to'] as $side)if($r[$side.'_price'])$this->run("INSERT OR IGNORE INTO billing_prices(price_id,plan,interval,currency,status,created_at,updated_at) VALUES(?,?,'month',?,'legacy',?,?)",[$r[$side.'_price'],$r[$side.'_plan'],$r['currency'],time(),time()]);
   $this->run("INSERT INTO integration_settings(key,value) VALUES('catalog_env_import_v1','done')");
  });
 }
 public function run(string $sql,array $p=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($p); return $s; }
 public function one(string $sql,array $p=[]): ?array { return $this->run($sql,$p)->fetch() ?: null; }
 public function transaction(callable $fn): mixed {
  $this->pdo->exec('BEGIN IMMEDIATE');
  try { $r=$fn(); $this->pdo->exec('COMMIT'); return $r; } catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
 }
}
