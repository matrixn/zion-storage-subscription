<?php
declare(strict_types=1);
namespace Zion;

final class BillingProfileService
{
    public const FIELDS = ['type','name','company_name','tax_id','registration_number','country','county','city','address','postal_code','phone','billing_email'];

    public function __construct(private Database $db) {}

    public function get(string $userId): ?array
    {
        return $this->db->one('SELECT * FROM billing_profiles WHERE user_id=?', [$userId]);
    }

    public static function validate(array $input, array $account): array
    {
        $data = [];
        foreach (self::FIELDS as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new \InvalidArgumentException('billing.invalid');
            $data[$field] = trim($input[$field] ?? '');
        }
        $data['name'] = $data['name'] ?: $account['name'];
        $data['billing_email'] = strtolower($data['billing_email'] ?: $account['email']);
        $data['country'] = strtoupper($data['country']);
        foreach ($data as $value) {
            if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', $value)) throw new \InvalidArgumentException('billing.invalid');
        }
        if (!in_array($data['type'], ['individual','company'], true)) throw new \InvalidArgumentException('billing.invalid');
        foreach (['name','county','city','address','postal_code'] as $field) {
            if ($data[$field] === '' || mb_strlen($data[$field]) > ($field === 'address' ? 500 : 150)) throw new \InvalidArgumentException('billing.invalid');
        }
        if (!preg_match('/^[A-Z]{2}$/D', $data['country']) || !filter_var($data['billing_email'], FILTER_VALIDATE_EMAIL) || strlen($data['billing_email']) > 254) throw new \InvalidArgumentException('billing.invalid');
        if (mb_strlen($data['phone']) > 40 || mb_strlen($data['registration_number']) > 80) throw new \InvalidArgumentException('billing.invalid');
        if ($data['type'] === 'company') {
            if ($data['company_name'] === '' || mb_strlen($data['company_name']) > 200 || !preg_match('/^[A-Za-z0-9 .\/-]{2,40}$/D', $data['tax_id'])) throw new \InvalidArgumentException('billing.invalid');
        } else {
            $data['company_name'] = $data['tax_id'] = $data['registration_number'] = '';
        }
        return $data;
    }

    public function save(string $userId, array $data): void
    {
        $u = $this->db->one('SELECT name,email FROM users WHERE id=?', [$userId]);
        if (!$u) throw new \InvalidArgumentException('billing.invalid');
        $data = self::validate($data, $u);
        $columns = implode(',', self::FIELDS);
        $update = implode(',', array_map(fn($f) => $f.'=excluded.'.$f, self::FIELDS));
        $this->db->run(
            'INSERT INTO billing_profiles(id,user_id,'.$columns.',created_at,updated_at) VALUES('.implode(',', array_fill(0, count(self::FIELDS)+4, '?')).') ON CONFLICT(user_id) DO UPDATE SET '.$update.',updated_at=excluded.updated_at',
            array_merge([bin2hex(random_bytes(16)), $userId], array_values($data), [time(), time()])
        );
    }
}
