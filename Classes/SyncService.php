<?php
declare(strict_types=1);

namespace Wwwision\AssetSync;

use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Model\ImportedAsset;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\ImportedAssetRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\AssetSourceService;
use Wwwision\AssetSync\ValueObject\Preset;

final class SyncService
{
    private ImportedAssetRepository $importedAssetRepository;
    private AssetSourceService $assetSourceService;
    private AssetRepository $assetRepository;
    private AssetService $assetService;
    private ResourceManager $resourceManager;

    private const EVENT_START = 'start';
    private const EVENT_PROGRESS = 'progress';
    private const EVENT_ERROR = 'error';

    private array $eventHandlers = [];

    public function __construct(ImportedAssetRepository $importedAssetRepository, AssetSourceService $assetSourceService, AssetRepository $assetRepository, AssetService $assetService, ResourceManager $resourceManager)
    {
        $this->importedAssetRepository = $importedAssetRepository;
        $this->assetSourceService = $assetSourceService;
        $this->assetRepository = $assetRepository;
        $this->assetService = $assetService;
        $this->resourceManager = $resourceManager;
    }

    public function numberOfAssets(Preset $preset): int
    {
        return $this->importedAssetsForPreset($preset)->count();
    }


    public function syncBatch(Preset $preset, int $offset, ?int $limit): void
    {
        $importedAssetsBatch = $this->importedAssetsForPreset($preset)->setOffset($offset)->setLimit($limit)->execute();
        $this->dispatch(self::EVENT_START, $importedAssetsBatch);
        /** @var ImportedAsset $importedAsset */
        foreach ($importedAssetsBatch as $importedAsset) {
            $this->updateAsset($preset, $importedAsset);
        }
    }

    private function updateAsset(Preset $preset, ImportedAsset $importedAsset): void
    {
        /** @var AssetInterface $localAsset */
        $localAsset = $this->assetRepository->findByIdentifier($importedAsset->getLocalAssetIdentifier());
        if ($localAsset === null) {
            $this->dispatch(self::EVENT_ERROR, sprintf('Failed to load local asset with id "%s"', $importedAsset->getLocalAssetIdentifier()));
            return;
        }

        $assetSource = $this->assetSourceService->getAssetSources()[$importedAsset->getAssetSourceIdentifier()] ?? null;
        if ($assetSource === null) {
            $this->dispatch(self::EVENT_ERROR, sprintf('Asset source id "%s" referenced in imported asset "%s" does not exist', $importedAsset->getAssetSourceIdentifier(), $importedAsset->getLocalAssetIdentifier()));
            return;
        }
        try {
            $assetProxy = $assetSource->getAssetProxyRepository()->getAssetProxy($importedAsset->getRemoteAssetIdentifier());
        } catch (\Throwable $e) {
            $this->dispatch(self::EVENT_ERROR, sprintf('Failed to retrieve asset proxy for imported asset "%s": %s', $importedAsset->getLocalAssetIdentifier(), $e->getMessage()));
            return;
        }
        try {
            $metadataWasUpdated = $this->updateAssetPropertiesFromIptcMetadata($localAsset, $assetProxy);
        } catch (\Throwable $e) {
            $this->dispatch(self::EVENT_ERROR, sprintf('Failed to update asset properties for imported asset "%s": %s', $importedAsset->getLocalAssetIdentifier(), $e->getMessage()));
        }
        try {
            $resourceWasReplaced = $preset->synchronizeResources && $this->replaceAssetResource($localAsset, $assetProxy);
        } catch (\Throwable $e) {
            $this->dispatch(self::EVENT_ERROR, sprintf('Failed to replace resource for imported asset "%s": %s', $importedAsset->getLocalAssetIdentifier(), $e->getMessage()));
        }
        $this->dispatch(self::EVENT_PROGRESS, $importedAsset, $metadataWasUpdated ?? null, $resourceWasReplaced ?? false);
    }

    public function onStart(callable $handler): void
    {
        $this->on(self::EVENT_START, $handler);
    }

    public function onProgress(callable $handler): void
    {
        $this->on(self::EVENT_PROGRESS, $handler);
    }

    public function onError(callable $handler): void
    {
        $this->on(self::EVENT_ERROR, $handler);
    }

    /* ----------------------------- */


    private function updateAssetPropertiesFromIptcMetadata(AssetInterface $localAsset, AssetProxyInterface $assetProxy): bool
    {
        if (!$assetProxy instanceof SupportsIptcMetadataInterface) {
            return false;
        }
        $assetIsUpdated = false;
        if (!$localAsset instanceof ImageVariant && $assetProxy->getIptcProperty('Title') !== $localAsset->getTitle()) {
            $localAsset->setTitle($assetProxy->getIptcProperty('Title'));
            $assetIsUpdated = true;
        }
        if ($assetProxy->getIptcProperty('CaptionAbstract') !== $localAsset->getCaption()) {
            $localAsset->setCaption($assetProxy->getIptcProperty('CaptionAbstract'));
            $assetIsUpdated = true;
        }
        if ($assetProxy->getIptcProperty('CopyrightNotice') !== $localAsset->getCopyrightNotice()) {
            $localAsset->setCopyrightNotice($assetProxy->getIptcProperty('CopyrightNotice'));
            $assetIsUpdated = true;
        }
        if ($assetIsUpdated) {
            $this->assetRepository->update($localAsset);
        }
        return $assetIsUpdated;
    }

    private function replaceAssetResource(AssetInterface $localAsset, AssetProxyInterface $assetProxy): bool
    {
        if ($localAsset instanceof ImageVariant) {
            return false;
        }
        $assetProxyStream = $assetProxy->getImportStream();
        if (!\is_resource($assetProxyStream)) {
            throw new \RuntimeException(sprintf('Failed to open stream for remote asset "%s" from asset source "%s"', $assetProxy->getIdentifier(), $assetProxy->getAssetSource()->getIdentifier()), 1653054583);
        }
        $assetProxyContents = stream_get_contents($assetProxyStream);
        if (!\is_string($assetProxyContents)) {
            throw new \RuntimeException(sprintf('Failed to read stream for remote asset "%s" from asset source "%s"', $assetProxy->getIdentifier(), $assetProxy->getAssetSource()->getIdentifier()), 1653054707);
        }
        fclose($assetProxyStream);
        $assetProxySha1 = sha1($assetProxyContents);
        if ($localAsset->getResource()->getSha1() === $assetProxySha1) {
            return false;
        }
        $assetProxyResource = $this->resourceManager->getResourceBySha1($assetProxySha1);
        if ($assetProxyResource === null) {
            $assetProxyResource = $this->resourceManager->importResourceFromContent($assetProxyContents, $assetProxy->getFilename(), $localAsset->getResource()->getCollectionName());
        }
        if (!$assetProxyResource instanceof PersistentResource) {
            return false;
        }
        $this->assetService->replaceAssetResource($localAsset, $assetProxyResource);
        return true;
    }

    private function importedAssetsForPreset(Preset $preset): QueryInterface
    {
        $query = $this->importedAssetRepository->createQuery();
        if ($preset->hasAssetSourceFilter()) {
            $query = $query->matching($query->in('assetSourceIdentifier', $preset->assetSourceIdentifiers));
        }
        return $query;
    }

    private function on(string $event, callable $handler): void
    {
        if (!isset($this->eventHandlers[$event])) {
            $this->eventHandlers[$event] = [];
        }
        $this->eventHandlers[$event][] = $handler;
    }


    private function dispatch(string $event, ...$arguments): void
    {
        if (!isset($this->eventHandlers[$event])) {
            return;
        }
        foreach ($this->eventHandlers[$event] as $handler) {
            $handler(...$arguments);
        }
    }

}
