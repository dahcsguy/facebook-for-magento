<?php
/**
 * Copyright (c) Meta Platforms, Inc. and affiliates. All Rights Reserved
 */

namespace Facebook\BusinessExtension\Model\Product\Feed\Method;

use Exception;
use Facebook\BusinessExtension\Helper\FBEHelper;
use Facebook\BusinessExtension\Helper\GraphAPIAdapter;
use Facebook\BusinessExtension\Model\Config\Source\FeedUploadMethod;
use Facebook\BusinessExtension\Model\Product\Feed\Builder;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetriever\Configurable as ConfigurableProductRetriever;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetriever\Simple as SimpleProductRetriever;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetrieverInterface;
use Facebook\BusinessExtension\Model\System\Config as SystemConfig;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\File\WriteInterface;
use Psr\Log\LoggerInterface;

class FeedApi
{
    const FEED_FILE_NAME = 'facebook_products%s.csv';
    const FB_FEED_NAME = 'Magento Autogenerated Feed';

    protected $storeId;

    /**
     * @var SystemConfig
     */
    protected $systemConfig;

    /**
     * @var GraphAPIAdapter
     */
    protected $graphApiAdapter;

    /**
     * @var ProductCollection
     */
    protected $productCollection;

    /**
     * @var CategoryCollection
     */
    protected $categoryCollection;

    /**
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * @var FBEHelper
     */
    protected $_fbeHelper;

    /**
     * @var ProductRetrieverInterface[]
     */
    protected $productRetrievers;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Builder
     */
    protected $builder;

    /**
     * @param SystemConfig $systemConfig
     * @param GraphAPIAdapter $graphApiAdapter
     * @param Filesystem $filesystem
     * @param ProductCollection $productCollection
     * @param CategoryCollection $categoryCollection
     * @param FBEHelper $fbeHelper
     * @param SimpleProductRetriever $simpleProductRetriever
     * @param ConfigurableProductRetriever $configurableProductRetriever
     * @param Builder $builder
     * @param LoggerInterface $logger
     */
    public function __construct(
        SystemConfig $systemConfig,
        GraphAPIAdapter $graphApiAdapter,
        Filesystem $filesystem,
        ProductCollection $productCollection,
        CategoryCollection $categoryCollection,
        FBEHelper $fbeHelper,
        SimpleProductRetriever $simpleProductRetriever,
        ConfigurableProductRetriever $configurableProductRetriever,
        Builder $builder,
        LoggerInterface $logger
    ) {
        $this->systemConfig = $systemConfig;
        $this->graphApiAdapter = $graphApiAdapter;
        $this->fileSystem = $filesystem;
        $this->productCollection = $productCollection;
        $this->categoryCollection = $categoryCollection;
        $this->_fbeHelper = $fbeHelper;
        $this->productRetrievers = [
            $simpleProductRetriever,
            $configurableProductRetriever
        ];
        $this->builder = $builder;
        $this->builder->setUploadMethod(FeedUploadMethod::UPLOAD_METHOD_FEED_API);
        $this->logger = $logger;
    }

    /**
     * @return string|false
     */
    protected function getFbFeedId()
    {
        $feedId = $this->systemConfig->getFeedId($this->storeId);
        $feedName = self::FB_FEED_NAME;

        if (!$feedId) {
            $catalogId = $this->systemConfig->getCatalogId($this->storeId);
            $catalogFeeds = $this->graphApiAdapter->getCatalogFeeds($catalogId);
            $magentoFeeds = array_filter($catalogFeeds, function ($a) use ($feedName) {
                return $a['name'] === $feedName;
            });
            if (!empty($magentoFeeds)) {
                $feedId = $magentoFeeds[0]['id'];
            }
        }

        if (!$feedId) {
            $catalogId = $this->systemConfig->getCatalogId($this->storeId);
            $feedId = $this->graphApiAdapter->createEmptyFeed($catalogId, $feedName);

            $maxAttempts = 5;
            $attempts = 0;
            do {
                $feedData = $this->graphApiAdapter->getFeed($feedId);
                if ($feedData !== false) {
                    break;
                }
                $attempts++;
                sleep(2);
            } while ($attempts < $maxAttempts);
        }

        if (!$this->systemConfig->getFeedId($this->storeId) && $feedId) {
            $this->systemConfig->saveConfig(SystemConfig::XML_PATH_FACEBOOK_BUSINESS_EXTENSION_FEED_ID, $feedId, $this->storeId)
                ->cleanCache();
        }
        return $feedId;
    }

    /**
     * @param WriteInterface $fileStream
     * @throws FileSystemException
     * @throws Exception
     */
    protected function writeFile(WriteInterface $fileStream)
    {
        $fileStream->writeCsv($this->builder->getHeaderFields());

        $total = 0;
        foreach ($this->productRetrievers as $productRetriever) {
            $productRetriever->setStoreId($this->storeId);
            $offset = 0;
            $limit = $productRetriever->getLimit();
            do {
                $products = $productRetriever->retrieve($offset);
                $offset += $limit;
                if (empty($products)) {
                    break;
                }
                foreach ($products as $product) {
                    $entry = array_values($this->builder->buildProductEntry($product));
                    $fileStream->writeCsv($entry);
                    $total++;
                }
            } while (true);
        }

        $this->logger->debug(sprintf('Generated feed with %d products.', $total));
    }

    /**
     * Get file name with store code suffix for non-default store (no suffix for default one)
     *
     * @return string
     * @throws NoSuchEntityException
     */
    protected function getFeedFileName()
    {
        $defaultStoreId = $this->systemConfig->getStoreManager()->getDefaultStoreView()->getId();
        $storeCode = $this->systemConfig->getStoreManager()->getStore($this->storeId)->getCode();
        return sprintf(
            self::FEED_FILE_NAME,
            ($this->storeId && $this->storeId !== $defaultStoreId) ? ('_' . $storeCode) : ''
        );
    }

    /**
     * @return string
     * @throws FileSystemException
     * @throws NoSuchEntityException
     */
    protected function generateProductFeed()
    {
        $file = 'export/' . $this->getFeedFileName();
        $directory = $this->fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create('export');

        //return $directory->getAbsolutePath($file);

        $stream = $directory->openFile($file, 'w+');
        $stream->lock();
        $this->writeFile($stream);
        $stream->unlock();

        return $directory->getAbsolutePath($file);
    }

    /**
     * @param null $storeId
     * @return bool|mixed
     * @throws Exception
     */
    public function execute($storeId = null)
    {
        $this->storeId = $storeId;
        $this->builder->setStoreId($this->storeId);
        $this->graphApiAdapter->setDebugMode($this->systemConfig->isDebugMode($storeId))
            ->setAccessToken($this->systemConfig->getAccessToken($storeId));

        try {
            $feedId = $this->getFbFeedId();
            if (!$feedId) {
                throw new LocalizedException(__('Cannot fetch feed ID'));
            }
            $feed = $this->generateProductFeed();
            return $this->graphApiAdapter->pushProductFeed($feedId, $feed);
        } catch (Exception $e) {
            $this->_fbeHelper->logException($e);
            throw $e;
        }
    }
}
