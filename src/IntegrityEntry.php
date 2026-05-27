<?php

namespace Innobrain\SoakTime;

final class IntegrityEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $sha256,
        public readonly ?string $sourceReference,
        public readonly ?string $sourceUrl,
        public readonly ?string $distUrl,
        public readonly \DateTimeImmutable $firstSeenAt,
    ) {}

    /**
     * @return array{sha256: ?string, sourceReference: ?string, sourceUrl: ?string, distUrl: ?string, firstSeenAt: string}
     */
    public function toArray(): array
    {
        return [
            'sha256' => $this->sha256,
            'sourceReference' => $this->sourceReference,
            'sourceUrl' => $this->sourceUrl,
            'distUrl' => $this->distUrl,
            'firstSeenAt' => $this->firstSeenAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
