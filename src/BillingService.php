<?php
namespace Zion;
/** Contract keeps payment-provider details separate from account creation. */
interface BillingService {
 public function checkout(array $user): string;
 public function portal(array $user): string;
 public function receive(string $payload,string $signature): void;
}
