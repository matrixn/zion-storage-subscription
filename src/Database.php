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
  if(!in_array('quota_sync',array_column($this->run('PRAGMA table_info(account_controls)')->fetchAll(),'name'),true))$this->pdo->exec("ALTER TABLE account_controls ADD COLUMN quota_sync TEXT NOT NULL DEFAULT 'ready'");
 }
 public function run(string $sql,array $p=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($p); return $s; }
 public function one(string $sql,array $p=[]): ?array { return $this->run($sql,$p)->fetch() ?: null; }
 public function transaction(callable $fn): mixed {
  $this->pdo->exec('BEGIN IMMEDIATE');
  try { $r=$fn(); $this->pdo->exec('COMMIT'); return $r; } catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
 }
}
