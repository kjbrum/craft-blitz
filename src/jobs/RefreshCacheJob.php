<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\jobs;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQuery;
use craft\helpers\App;
use craft\helpers\Json;
use craft\queue\BaseJob;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\records\ElementQueryRecord;

class RefreshCacheJob extends BaseJob
{
    // Properties
    // =========================================================================

    /**
     * @var int[]
     */
    public $cacheIds = [];

    /**
     * @var int[]
     */
    public $elementIds = [];

    /**
     * @var string[]
     */
    public $elementTypes = [];

    /**
     * @var bool
     */
    public $forceClear = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \Exception
     * @throws \Throwable
     */
    public function execute($queue)
    {
        App::maxPowerCaptain();

        // Step 1
        $this->setProgress($queue, 1/3);

        // Merge in element cache IDs
        $this->cacheIds = array_merge($this->cacheIds,
            Blitz::$plugin->refreshCache->getElementCacheIds(
                $this->elementIds, $this->cacheIds
            )
        );

        if (!empty($this->elementIds)) {
            $elementQueryRecords = Blitz::$plugin->refreshCache->getElementTypeQueries(
                $this->elementTypes, $this->cacheIds
            );

            // Use sets and the splat operator rather than array_merge for performance (https://goo.gl/9mntEV)
            $elementQueryCacheIdSets = [[]];

            foreach ($elementQueryRecords as $elementQueryRecord) {
                // Merge in element query cache IDs
                $elementQueryCacheIdSets[] = $this->_getElementQueryCacheIds(
                    $elementQueryRecord, $this->elementIds, $this->cacheIds
                );
            }

            $elementQueryCacheIds = array_merge(...$elementQueryCacheIdSets);

            $this->cacheIds = array_merge($this->cacheIds, $elementQueryCacheIds);
        }

        if (empty($this->cacheIds)) {
            return;
        }

        // Step 2
        $this->setProgress($queue, 2/3);

        // If clear automatically is enabled or if force clear
        if (Blitz::$plugin->settings->clearCacheAutomatically || $this->forceClear) {
            // Get cached site URIs from cache IDs
            $siteUris = SiteUriHelper::getCachedSiteUris($this->cacheIds);

            // Delete cache records so we get fresh caches
            Blitz::$plugin->flushCache->flushCacheIds($this->cacheIds);

            // Delete cached values so we get a fresh version
            Blitz::$plugin->cacheStorage->deleteUris($siteUris);

            // Trigger afterRefreshCache events
            Blitz::$plugin->refreshCache->afterRefresh($siteUris);
        }
        else {
            Blitz::$plugin->refreshCache->expireCacheIds($this->cacheIds);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('blitz', 'Refreshing Blitz cache');
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns cache IDs from a given entry query that contains the provided element IDs,
     * ignoring the provided cache IDs.
     *
     * @param ElementQueryRecord $elementQueryRecord
     * @param array $elementIds
     * @param array $ignoreCacheIds
     *
     * @return int[]
     */
    private static function _getElementQueryCacheIds(ElementQueryRecord $elementQueryRecord, array $elementIds, array $ignoreCacheIds): array
    {
        $cacheIds = [];

        // Ensure class still exists as a plugin may have been removed since being saved
        if (!class_exists($elementQueryRecord->type)) {
            return $cacheIds;
        }

        /** @var Element $elementType */
        $elementType = $elementQueryRecord->type;

        /** @var ElementQuery $elementQuery */
        $elementQuery = $elementType::find();

        $params = Json::decodeIfJson($elementQueryRecord->params);

        // If json decode failed
        if (!is_array($params)) {
            return $cacheIds;
        }

        foreach ($params as $key => $val) {
            $elementQuery->{$key} = $val;
        }

        // If the element query has an offset then add it to the limit and make it null
        if ($elementQuery->offset) {
            if ($elementQuery->limit) {
                $elementQuery->limit($elementQuery->limit + $elementQuery->offset);
            }

            $elementQuery->offset(null);
        }

        // If one or more of the element IDs are in the query's results
        if (!empty(array_intersect($elementIds, $elementQuery->ids()))) {
            // Get related element query cache records
            $elementQueryCacheRecords = $elementQueryRecord->elementQueryCaches;

            // Add cache IDs to the array that do not already exist
            foreach ($elementQueryCacheRecords as $elementQueryCacheRecord) {
                if (!in_array($elementQueryCacheRecord->cacheId, $ignoreCacheIds, true)) {
                    $cacheIds[] = $elementQueryCacheRecord->cacheId;
                }
            }
        }

        return $cacheIds;
    }
}
