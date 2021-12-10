<?php
declare(strict_types=1);

namespace Wwwision\AssetSync\ValueObject;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class Preset implements \JsonSerializable
{
    public array $assetSourceIdentifiers = [];
    public bool $synchronizeResources;

    public function __construct(bool $synchronizeResources)
    {
        $this->synchronizeResources = $synchronizeResources;
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf('Failed to decode preset from JSON: %s', $e->getMessage()), 1639146127, $e);
        }
        $instance = new self($data['synchronizeResources']);
        if (isset($data['assetSourceIdentifiers'])) {
            $instance->assetSourceIdentifiers = $data['assetSourceIdentifiers'];
        }
        return $instance;
    }

    public function filterAssetSourceIdentifiers(array $assertSourceIdentifiers): self
    {
        $newInstance = clone $this;
        $newInstance->assetSourceIdentifiers = $assertSourceIdentifiers;
        return $newInstance;
    }

    public function hasAssetSourceFilter(): bool
    {
        return $this->assetSourceIdentifiers !== [];
    }

    public function toJson(): string
    {
        try {
            return json_encode($this, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to encode preset to JSON: %s', $e->getMessage()), 1639146100, $e);
        }
    }

    public function jsonSerialize(): array
    {
        $data = [
            'synchronizeResources' => $this->synchronizeResources
        ];
        if ($this->hasAssetSourceFilter()) {
            $data['assetSourceIdentifiers'] = $this->assetSourceIdentifiers;
        }
        return $data;
    }
}
