<?php

/**
 * Organization administration (until the admin UI of M11a exists).
 *
 *   php scripts/org.php create "Universidad Demo" admin@example.com     organization + its first org_admin
 *   php scripts/org.php add-member <org_id> user@example.com instructor   set a member's role (org_admin|instructor|student|read_only)
 *   php scripts/org.php list                                               organizations and member counts
 *
 * Users must already exist (registered and verified). Every change is written to the audit log.
 */

declare(strict_types=1);

use EduCloud\Core\Request;
use EduCloud\Core\Ulid;
use EduCloud\Modules\Tenants\TenantRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \EduCloud\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$db = $app->db();
$tenants = new TenantRepository($db);
$args = array_slice($argv, 1);
$cli = new Request(method: 'CLI', path: '/scripts/org.php', ip: '127.0.0.1', requestId: Ulid::generate());

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};
$userId = static function (string $email) use ($db, $fail): int {
    $id = $db->scalar("SELECT id FROM users WHERE email = ? AND status = 'active'", [strtolower(trim($email))]);
    return $id === null ? $fail("No active user with email $email") : (int) $id;
};

switch ($args[0] ?? '') {
    case 'create':
        if (count($args) !== 3 || mb_strlen(trim($args[1])) < 3) {
            $fail('usage: php scripts/org.php create "<name>" <admin-email>');
        }
        $admin = $userId($args[2]);
        $org = $db->transaction(static function () use ($tenants, $args, $admin): array {
            $org = $tenants->createOrganization(trim($args[1]));
            $tenants->setMemberRole($org['id'], $admin, 'org_admin');
            return $org;
        });
        $app->audit()->record($cli, 'tenant.create', 'success', $org['id'], $admin, 'tenant', $org['public_id'], ['via' => 'cli']);
        echo "created organization {$org['public_id']} with org_admin {$args[2]}\n";
        break;

    case 'add-member':
        $roles = ['org_admin', 'instructor', 'student', 'read_only'];
        if (count($args) !== 4 || !Ulid::isValid($args[1]) || !in_array($args[3], $roles, true)) {
            $fail('usage: php scripts/org.php add-member <org_id> <email> <' . implode('|', $roles) . '>');
        }
        $tenantId = $db->scalar("SELECT id FROM tenants WHERE public_id = ? AND type = 'organization'", [$args[1]]);
        if ($tenantId === null) {
            $fail('Organization not found');
        }
        $user = $userId($args[2]);
        $tenants->setMemberRole((int) $tenantId, $user, $args[3]);
        $app->audit()->record($cli, 'membership.set_role', 'success', (int) $tenantId, $user, 'tenant', $args[1], [
            'role' => $args[3],
            'via' => 'cli',
        ]);
        echo "{$args[2]} is now {$args[3]} in {$args[1]}\n";
        break;

    case 'list':
        $rows = $db->select(
            "SELECT t.public_id, t.name, COUNT(m.id) AS members FROM tenants t
               LEFT JOIN memberships m ON m.tenant_id = t.id AND m.status = 'active'
              WHERE t.type = 'organization' GROUP BY t.id ORDER BY t.name"
        );
        foreach ($rows as $row) {
            echo $row['public_id'] . '  ' . str_pad((string) $row['members'], 4, ' ', STR_PAD_LEFT) . ' members  ' . $row['name'] . "\n";
        }
        break;

    default:
        $fail("usage:\n  php scripts/org.php create \"<name>\" <admin-email>\n"
            . "  php scripts/org.php add-member <org_id> <email> <role>\n  php scripts/org.php list");
}
