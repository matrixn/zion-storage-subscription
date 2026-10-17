<?php
namespace Zion;
final class Plans {
 public function all(): array { return require dirname(__DIR__).'/config/plans.php'; }
 public function get(string $slug): ?array { foreach($this->all() as $p) if($p['slug']===$slug) return $p+['currency'=>'ron']; return null; }
}
