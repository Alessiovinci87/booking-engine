<?php

namespace App\Domain\Calendar\Http;

/**
 * Indirizzi a cui SafeHttpFetcher non si collega mai (08 "Protezione dell'import iCal"): reti private,
 * loopback, link-local (compreso il metadata service 169.254.169.254), CGNAT, "questa rete",
 * multicast, riservati e di documentazione; in IPv6 loopback, ULA, link-local, multicast, IPv4 mappati
 * o tradotti (NAT64) e di documentazione. Nel dubbio (indirizzo non valido) si blocca.
 */
final class IpPolicy
{
    /** @var list<string> */
    private const BLOCKED_CIDRS = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        // IPv6
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public static function isBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = (string) inet_pton($network);

        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $networkPacked, $bytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
    }
}
