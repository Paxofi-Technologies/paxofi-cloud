<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Vps;

/**
 * Everything an adapter needs to create one customer server. All values come
 * from the catalogue or from validated customer input (threat model D-9);
 * nothing here is passed through from a request unchecked.
 */
final class CreateVpsServer
{
    private const string CATALOGUE_ID = '/^[a-z0-9][a-z0-9._-]{0,62}$/';

    /** @param list<SshPublicKey> $sshKeys */
    public function __construct(
        public readonly int $serviceId,
        public readonly string $serverType,
        public readonly string $location,
        public readonly string $image,
        public readonly array $sshKeys,
    ) {
        if ($serviceId < 1) {
            throw new \InvalidArgumentException('Service ID must be positive.');
        }
        foreach (['server type' => $serverType, 'location' => $location, 'image' => $image] as $name => $value) {
            if (preg_match(self::CATALOGUE_ID, $value) !== 1) {
                throw new \InvalidArgumentException(sprintf('The %s is not a valid catalogue identifier.', $name));
            }
        }
        if (count($sshKeys) > 10) {
            throw new \InvalidArgumentException('At most 10 SSH keys can be installed at creation.');
        }
    }

    /** Server name and ownership label value used at every provider (SRS VPS-003). */
    public function serverName(): string
    {
        return 'paxo-svc-' . $this->serviceId;
    }
}
