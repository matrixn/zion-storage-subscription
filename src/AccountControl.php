<?php
namespace Zion;
final class AccountControl {
 public function __construct(private Database $db) {}
 public function get(string $id): array { return $this->db->one('SELECT * FROM account_controls WHERE user_id=?',[$id])??[]; }
 public function banned(string $id): bool { return !empty($this->get($id)['banned_at']); }
 public function manual(string $id): ?string { return $this->get($id)['manual_plan']??null; }
 public static function gatewayConfigured(): bool { return env('STORAGE_GATEWAY_ENFORCED')==='true' && strlen(env('STORAGE_GATEWAY_KEY'))>=32; }
 public static function seedAdmin(Database $db): bool {
  $email=strtolower(trim(env('ADMIN_EMAIL'))); $password=env('ADMIN_PASSWORD');
  if($email==='' && $password==='') return false;
  if(!filter_var($email,FILTER_VALIDATE_EMAIL) || !AccountService::passwordValid($password)) throw new \RuntimeException('ADMIN_EMAIL must be valid and ADMIN_PASSWORD must have 12-200 characters');
  $db->run('INSERT OR IGNORE INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)',[bin2hex(random_bytes(16)),$email,password_hash($password,PASSWORD_ARGON2ID),time()]);
  return true;
 }
}
