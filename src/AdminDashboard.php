<?php
declare(strict_types=1);
namespace Zion;

/** Read-only local operational metrics; amounts keep their original currency. */
final class AdminDashboard {
 public function __construct(private Database $db) {}
 public function snapshot(?int $now=null): array {
  $now??=time();$since=$now-30*86400;$mode=(new IntegrationSettings($this->db))->stripe()['mode'];
  $clients=$this->db->one("SELECT COUNT(*) total,COALESCE(SUM(u.created_at>=?),0) new30,COALESCE(SUM(u.status='active' AND c.banned_at IS NULL),0) active,COALESCE(SUM(u.email_verified_at IS NULL),0) unverified,COALESCE(SUM(c.banned_at IS NOT NULL),0) banned,COALESCE(SUM(c.manual_plan IS NOT NULL),0) manual,COALESCE(SUM(u.storage_quota_bytes),0) allocated FROM users u LEFT JOIN account_controls c ON c.user_id=u.id",[$since]);
  $subscriptions=$this->db->one("SELECT COUNT(*) total,COALESCE(SUM(status='active' AND paid_confirmed=1),0) active,COALESCE(SUM(status='active' AND paid_confirmed=1 AND billing_interval='year'),0) yearly,COALESCE(SUM(status IN ('past_due','unpaid','incomplete')),0) attention FROM subscriptions WHERE stripe_mode=?",[$mode]);
  $queues=[
   'changes'=>$this->count("SELECT COUNT(*) n FROM plan_changes WHERE stripe_mode=? AND status IN ('creating','paid','applying','retry','review')",[$mode]),
   'fiscal'=>$this->count("SELECT COUNT(*) n FROM fiscal_invoices WHERE stripe_mode=? AND status IN ('pending','processing','failed')",[$mode]),
   'mail'=>$this->count('SELECT COUNT(*) n FROM outbox WHERE sent_at IS NULL'),
   'mail_failed'=>$this->count('SELECT COUNT(*) n FROM outbox WHERE sent_at IS NULL AND attempts>0'),
   'webhooks'=>$this->count("SELECT COUNT(*) n FROM billing_events WHERE stripe_mode=? AND status<>'complete'",[$mode]),
   'quota'=>$this->count("SELECT COUNT(*) n FROM account_controls WHERE quota_sync<>'ready' OR access_sync<>'ready'"),
  ];
  return [
   'storage'=>(new ImmichStatistics($this->db))->overview(),
   'clients'=>$clients,'subscriptions'=>$subscriptions,'queues'=>$queues,
   'revenue'=>$this->db->run("SELECT UPPER(currency) currency,SUM(amount) amount,COUNT(*) payments FROM (SELECT id,amount,currency,created_at,stripe_mode FROM invoices UNION ALL SELECT p.invoice_id,p.amount,p.currency,p.paid_at,p.stripe_mode FROM plan_changes p WHERE p.paid_at IS NOT NULL AND p.invoice_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM invoices i WHERE i.id=p.invoice_id)) WHERE created_at>=CAST(? AS INTEGER) AND created_at<=CAST(? AS INTEGER) AND amount>0 AND stripe_mode=? GROUP BY UPPER(currency) ORDER BY currency",[$since,$now,$mode])->fetchAll(),
   'plans'=>$this->db->run('SELECT selected_plan_id plan,COUNT(*) clients FROM users GROUP BY selected_plan_id')->fetchAll(),
   'recent'=>$this->db->run('SELECT name,email,selected_plan_id,status,created_at FROM users ORDER BY created_at DESC LIMIT 5')->fetchAll(),
   'renewals'=>$this->db->run("SELECT s.plan,s.billing_interval,s.currency,s.renewal_at,u.email FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE s.stripe_mode=? AND s.status='active' AND s.paid_confirmed=1 AND s.renewal_at>=? AND s.renewal_at<=? ORDER BY s.renewal_at LIMIT 8",[$mode,$now,$now+7*86400])->fetchAll(),
   'now'=>$now,'stripe_mode'=>$mode,
  ];
 }
 private function count(string $sql,array $params=[]): int {return (int)$this->db->one($sql,$params)['n'];}
}
