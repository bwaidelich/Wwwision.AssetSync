# Wwwision.AssetSync

[Flow](https://flow.neos.io) package to synchronize metadata and resources of imported Neos.Media assets

## Installation

Install this package via:

```bash
composer require wwwision/asset-sync
```

## Usage

Run

```
./flow media:synchronizeimportedassets
```

to update the metadata of all imported assets.

Use the following options to fine tune the behavior:

```
  --asset-sources          Comma separated list of Asset Source identifiers to sync. If null, all imported assets are synced.
  --synchronize-resources  If set, resource binaries are synchronized (this can be slow!). Otherwise, only the asset metadata is updated.
  --batch-size             Number of assets to synchronize in a single run. Larger numbers can increase performance but may lead to high memory consumption
  --pool-size              Maximum number of sub processes to run at the same time
  --quiet                  If set only the number of errors is outputted (if any)
```

### Cronjob

In order to keep imported assets in sync, the `synchronizeImportedAssets` command should be regularly, for example via cronjob.

The following setup would synchronize the metadata of the specified asset sources hourly and once per day the corresponding resources.

```
0 * * * * ./flow wwwision.assetsync:media:synchronizeimportedassets --asset-sources assetsource1,assetsource2 --quiet
30 0 * * * ./flow wwwision.assetsync:media:synchronizeimportedassets --asset-sources assetsource1,assetsource2 --synchronize-resources --quiet
```

### Batch and pool size

By default, *500* assets will be processed in a single batch and up to *5* batches will be executed in parallel.
This can be changed with the `--batch-size` and `--pool-size` options.
See [Wwwision.BatchProcessing](https://github.com/bwaidelich/Wwwision.BatchProcessing/blob/main/README.md) for details.

## Acknowledgements

Parts of this implementation are inspired by work from [Karsten Dambekalns](https://github.com/kdambekalns).
The development of this package was generously sponsored by [Marktplatz GmbH - Agentur für Web & App](https://www.marktplatz-agentur.de/).
Thank you for supporting Open Source development!

## Contribution

Contributions in the form of issues or pull requests are highly appreciated

## License

See [LICENSE](./LICENSE)
