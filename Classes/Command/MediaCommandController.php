<?php
declare(strict_types=1);

namespace Wwwision\AssetSync\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Utility\Arrays;
use Wwwision\AssetSync\SyncService;
use Wwwision\AssetSync\ValueObject\Preset;
use Wwwision\BatchProcessing\BatchProcessRunner;
use Wwwision\BatchProcessing\ProgressHandler\NullProgressHandler;
use Wwwision\BatchProcessing\ProgressHandler\ProgressBarRenderer;
use Wwwision\BatchProcessing\ProgressPipe;

final class MediaCommandController extends CommandController
{

    /**
     * @Flow\Inject(lazy=false)
     * @var SyncService
     */
    protected SyncService $syncService;

    /**
     * Update imported asset metadata and (optionally) resources from their original asset source
     *
     * @param string|null $assetSources Comma separated list of Asset Source identifiers to sync. If null, all imported assets are synced.
     * @param bool $synchronizeResources If set, resource binaries are synchronized (this can be slow!). Otherwise, only the asset metadata is updated.
     * @param int|null $batchSize Number of assets to synchronize in a single run. Larger numbers can increase performance but may lead to high memory consumption
     * @param int|null $poolSize Maximum number of sub processes to run at the same time
     * @param bool $quiet If set only the number of errors is outputted (if any)
     */
    public function synchronizeImportedAssetsCommand(string $assetSources = null, bool $synchronizeResources = false, int $batchSize = null, int $poolSize = null, bool $quiet = false): void
    {
        $preset = new Preset($synchronizeResources);
        if ($assetSources !== null) {
            $preset = $preset->filterAssetSourceIdentifiers(Arrays::trimExplode(',', $assetSources));
        }
        $numberOfAssetsToSync = $this->syncService->numberOfAssets($preset);

        $quiet || $this->outputLine('Synchronizing metadata%s of <b>%d</b> assets...', [$preset->synchronizeResources ? ' and resources' : '', $numberOfAssetsToSync]);
        $progressHandler = $quiet ? new NullProgressHandler() : ProgressBarRenderer::create($this->output->getOutput());
        $runner = new BatchProcessRunner('wwwision.assetsync:media:synchronizeimportedassetsbatch', ['presetJson' => $preset->toJson(), 'offset' => '{offset}', 'limit' => '{limit}'], $progressHandler);
        if ($batchSize !== null) {
            $runner->setBatchSize($batchSize);
        }
        if ($poolSize !== null) {
            $runner->setPoolSize($poolSize);
        }
        $runner->onFinish(function(array $errors) use ($quiet) {
            if ($errors === []) {
                $quiet || $this->outputLine('<success>Done</success>');
                return;
            }
            $this->outputLine('<error>Finished with <b>%d</b> error%s%s</error>', [\count($errors), \count($errors) === 1 ? '' : 's', $quiet ? '' : ':']);
            if (!$quiet) {
                foreach ($errors as $error) {
                    $this->outputLine('  %s', [$error]);
                }
            }
            exit(1);
        });
        $runner->start($numberOfAssetsToSync);
    }

    /**
     * @param string $presetJson JSON encoded preset
     * @param int $offset zero based offset
     * @param int $limit size of the batch to import
     * @internal
     */
    public function synchronizeImportedAssetsBatchCommand(string $presetJson, int $offset, int $limit): void
    {
        $preset = Preset::fromJson($presetJson);
        $processPipe = new ProgressPipe();
        $this->syncService->onError(fn($message) => $processPipe->error($message));
        $this->syncService->onProgress(fn() => $processPipe->advance());
        $this->syncService->syncBatch($preset, $offset, $limit);
    }
}
