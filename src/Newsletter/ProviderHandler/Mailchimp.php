<?php

/**
 * Pimcore Customer Management Framework Bundle
 * Full copyright and license information is available in
 * License.md which is distributed with this source code.
 *
 * @copyright  Copyright (C) Elements.at New Media Solutions GmbH
 * @license    GPLv3
 */

namespace CustomerManagementFrameworkBundle\Newsletter\ProviderHandler;

use CustomerManagementFrameworkBundle\ActivityManager\ActivityManagerInterface;
use CustomerManagementFrameworkBundle\DataTransformer\Cleanup\Email;
use CustomerManagementFrameworkBundle\DataTransformer\DataTransformerInterface;
use CustomerManagementFrameworkBundle\Model\Activity\MailchimpStatusChangeActivity;
use CustomerManagementFrameworkBundle\Model\MailchimpAwareCustomerInterface;
use CustomerManagementFrameworkBundle\Model\NewsletterAwareCustomerInterface;
use CustomerManagementFrameworkBundle\Newsletter\ProviderHandler\Mailchimp\CustomerExporter\BatchExporter;
use CustomerManagementFrameworkBundle\Newsletter\ProviderHandler\Mailchimp\CustomerExporter\SingleExporter;
use CustomerManagementFrameworkBundle\Newsletter\ProviderHandler\Mailchimp\DataTransformer\MailchimpDataTransformerInterface;
use CustomerManagementFrameworkBundle\Newsletter\ProviderHandler\Mailchimp\MailChimpExportService;
use CustomerManagementFrameworkBundle\Newsletter\ProviderHandler\Mailchimp\SegmentExporter;
use CustomerManagementFrameworkBundle\Newsletter\ProviderHandler\Mailchimp\WebhookProcessor;
use CustomerManagementFrameworkBundle\Newsletter\Queue\Item\DefaultNewsletterQueueItem;
use CustomerManagementFrameworkBundle\Newsletter\Queue\Item\NewsletterQueueItemInterface;
use CustomerManagementFrameworkBundle\Newsletter\Queue\NewsletterQueueInterface;
use CustomerManagementFrameworkBundle\SegmentManager\SegmentManagerInterface;
use CustomerManagementFrameworkBundle\Traits\LoggerAware;
use Pimcore\Db\ZendCompatibility\QueryBuilder;
use Pimcore\File;
use Pimcore\Model\Object\CustomerSegment;
use Psr\Log\LoggerInterface;

class Mailchimp implements NewsletterProviderHandlerInterface
{
    use LoggerAware;

    const STATUS_SUBSCRIBED = 'subscribed';
    const STATUS_UNSUBSCRIBED = 'unsubscribed';
    const STATUS_PENDING = 'pending';
    const STATUS_CLEANED = 'cleaned';

    /**
     * @var string
     */
    protected $shortcut;

    /**
     * @var string
     */
    protected $listId;

    /**
     * @var array
     */
    protected $statusMapping;

    /**
     * @var array
     */
    protected $reverseStatusMapping;

    /**
     * @var array
     */
    protected $mergeFieldMapping;

    /**
     * @var DataTransformerInterface[]
     */
    protected $fieldTransformers;

    /**
     * @var DataTransformerInterface[]
     */
    protected $reverseFieldTransformers;

    /**
     * @var SegmentExporter
     */
    protected $segmentExporter;

    /**
     * @var SegmentManagerInterface
     */
    protected $segmentManager;

    /**
     * @var MailChimpExportService
     */
    protected $exportService;

    /**
     * @var int
     */
    protected $batchThreshold = 50;

    /**
     * Mailchimp constructor.
     *
     * @param string $shortcut
     * @param string $listId
     * @param array $statusMapping
     * @param array $reverseStatusMapping
     * @param array $mergeFieldMapping
     * @param MailchimpDataTransformerInterface[] $fieldTransformers
     * @param SegmentExporter $segmentExporter
     * @param SegmentManagerInterface $segmentManager
     * @param MailChimpExportService $exportService
     *
     * @throws \Exception
     */
    public function __construct($shortcut, $listId, array $statusMapping = [], array $reverseStatusMapping = [], array $mergeFieldMapping = [], array $fieldTransformers = [], SegmentExporter $segmentExporter, SegmentManagerInterface $segmentManager, MailChimpExportService $exportService)
    {
        if (!strlen($shortcut) || !File::getValidFilename($shortcut)) {
            throw new \Exception('Please provide a valid newsletter provider handler shortcut.');
        }
        $this->shortcut = $shortcut;
        $this->listId = $listId;
        $this->statusMapping = $statusMapping;
        $this->reverseStatusMapping = $reverseStatusMapping;
        $this->mergeFieldMapping = $mergeFieldMapping;
        $this->fieldTransformers = $fieldTransformers;
        $this->segmentExporter = $segmentExporter;
        $this->segmentManager = $segmentManager;
        $this->exportService = $exportService;
    }

    /**
     * update customer in mail provider
     *
     * @param NewsletterQueueItemInterface[] $array
     *
     * @return void
     */
    public function processCustomerQueueItems(array $items, $forceUpdate = false)
    {
        $items = $this->getUpdateNeededItems($items, $forceUpdate);

        list($emailChangedItems, $regularItems) = $this->determineEmailChangedItems($items);

        //Customers where the email address changed need to be handled by the single exporter as the batch exporter does not allow such operations.
        if (sizeof($emailChangedItems)) {
            $this->getLogger()->info(
                sprintf(
                    '[MailChimp] process %s items where the email address changed...',
                    sizeof($emailChangedItems)
                )
            );

            foreach ($emailChangedItems as $item) {
                $this->customerExportSingle($item);
            }
        }

        $itemCount = count($regularItems);

        if (!$itemCount) {
            $this->getLogger()->info(
                sprintf(
                    '[MailChimp] 0 items to process...'
                )
            );
        } elseif ($itemCount <= $this->batchThreshold) {
            $this->getLogger()->info(
                sprintf(
                    '[MailChimp] Data count (%d) is below batch threshold (%d), sending one request per entry...',
                    $itemCount,
                    $this->batchThreshold
                )
            );
            foreach ($regularItems as $item) {
                $this->customerExportSingle($item);
            }
        } else {
            $this->getLogger()->info(
                sprintf(
                    '[MailChimp] Sending data as batch request'
                )
            );
            $this->customerExportBatch($regularItems);
        }
    }

    /**
     * @param NewsletterQueueItemInterface[] $items
     *
     * @return array
     */
    protected function determineEmailChangedItems(array $items)
    {
        $emailChangedItems = [];
        $regularItems = [];

        foreach ($items as $item) {
            if ($item->getOperation() != NewsletterQueueInterface::OPERATION_UPDATE) {
                $regularItems[] = $item;
                continue;
            }

            if (!$item->getCustomer()) {
                $regularItems[] = $item;
                continue;
            }

            if ($item->getCustomer()->getEmail() != $item->getEmail()) {
                $emailChangedItems[] = $item;
                continue;
            }

            $regularItems[] = $item;
        }

        return [$emailChangedItems, $regularItems];
    }

    /**
     * @param NewsletterQueueItemInterface[] $array
     *
     * @return NewsletterQueueItemInterface[]
     */
    protected function getUpdateNeededItems(array $items, $forceUpdate = false)
    {
        $updateNeededItems = [];
        foreach ($items as $item) {
            if (!$item->getCustomer()) {
                $updateNeededItems[] = $item;
            } elseif ($item->getOperation() == NewsletterQueueInterface::OPERATION_UPDATE) {
                if (!$item->getCustomer()->needsExportByNewsletterProviderHandler($this)) {
                    /* Update item only if a mailchimp status is set in the customer.
                       Otherwise the customer should not exist in the mailchimp list and therefore no deletion should be needed.
                       Cleaned customers will be ignored as the email adress is invalid
                    */

                    $mailchimpStatus = $this->getMailchimpStatus($item->getCustomer());

                    if ($mailchimpStatus && ($mailchimpStatus != self::STATUS_CLEANED)) {
                        $updateNeededItems[] = $item;
                    } else {
                        $this->getLogger()->info(
                            sprintf(
                                '[MailChimp][CUSTOMER %s] Export not needed as the export data did not change (customer is not in export list).',
                                $item->getCustomer()->getId()
                            )
                        );

                        $item->setSuccessfullyProcessed(true);
                    }
                } elseif ($forceUpdate || $this->exportService->didExportDataChangeSinceLastExport($item->getCustomer(), $this->getListId(), $this->buildEntry($item->getCustomer()))) {

                    $mailchimpStatus = $this->getMailchimpStatus($item->getCustomer());
                    if (!$mailchimpStatus) {
                        $entry = $this->buildEntry($item->getCustomer());

                        $setStatus = isset($entry['status_if_new']) ? : $entry['status'];

                        if($setStatus == self::STATUS_UNSUBSCRIBED) {
                            $this->getLogger()->info(
                                sprintf(
                                    '[MailChimp][CUSTOMER %s] Export not needed as the customer is unsubscribed and was not exported yet.',
                                    $item->getCustomer()->getId()
                                )
                            );
                        }
                    }

                    $updateNeededItems[] = $item;
                } else {
                    $this->getLogger()->info(
                        sprintf(
                            '[MailChimp][CUSTOMER %s] Export not needed as the export data did not change.',
                            $item->getCustomer()->getId()
                        )
                    );

                    $item->setSuccessfullyProcessed(true);
                }
            } else {
                $updateNeededItems[] = $item;
            }
        }

        return $updateNeededItems;
    }

    public function subscribeCustomer(NewsletterAwareCustomerInterface $customer)
    {
        /**
         * @var MailchimpAwareCustomerInterface $customer;
         */
        if (!$newsletterStatus = $this->reverseMapNewsletterStatus(self::STATUS_SUBSCRIBED)) {
            $this->getLogger()->error(sprintf('subscribe failed: could not reverse map mailchimp status %s', self::STATUS_SUBSCRIBED));

            return false;
        }
        try {
            $this->setNewsletterStatus($customer, $newsletterStatus);

            $item = new DefaultNewsletterQueueItem(
                $customer->getId(),
                $customer,
                $customer->getEmail(),
                NewsletterQueueInterface::OPERATION_UPDATE
            );

            $success = $this->getSingleExporter()->update($customer, $item, $this);

            if ($success) {
                $customer->saveWithOptions(
                    $customer->getSaveManager()->getSaveOptions()
                        ->disableNewsletterQueue()
                        ->disableDuplicatesIndex()
                        ->disableOnSaveSegmentBuilders()
                );
            }
        } catch (\Exception $e) {
            $this->getLogger()->error('subscribe customer failed: '.$e->getMessage());

            return false;
        }

        return $success;
    }

    public function unsubscribeCustomer(NewsletterAwareCustomerInterface $customer)
    {
        /**
         * @var MailchimpAwareCustomerInterface $customer;
         */
        if (!$newsletterStatus = $this->reverseMapNewsletterStatus(self::STATUS_UNSUBSCRIBED)) {
            $this->getLogger()->error(sprintf('subscribe failed: could not reverse map mailchimp status %s', self::STATUS_UNSUBSCRIBED));

            return false;
        }

        try {
            $this->setNewsletterStatus($customer, $newsletterStatus);

            $item = new DefaultNewsletterQueueItem(
                $customer->getId(),
                $customer,
                $customer->getEmail(),
                NewsletterQueueInterface::OPERATION_UPDATE
            );

            $success = $this->getSingleExporter()->update($customer, $item, $this);

            if ($success) {
                $customer->saveWithOptions(
                    $customer->getSaveManager()->getSaveOptions()
                        ->disableNewsletterQueue()
                        ->disableDuplicatesIndex()
                        ->disableOnSaveSegmentBuilders()
                );
            }
        } catch (\Exception $e) {
            $this->getLogger()->error('unsubscribe customer failed: '.$e->getMessage());

            return false;
        }

        return $success;
    }

    public function updateSegmentGroups($forceUpdate = false)
    {
        $groups = $this->getExportableSegmentGroups();

        $groupIds = [];
        foreach ($groups as $group) {
            $remoteGroupId = $this->segmentExporter->exportGroup($group, $this->listId, false, $forceUpdate);

            $groupIds[] = $remoteGroupId;

            $segments = $this->segmentManager->getSegmentsFromSegmentGroup($group);

            $segmentIds = [];
            foreach ($segments as $segment) {
                $forceCreate = false;
                if ($remoteGroupId && ($this->segmentExporter->getLastCreatedGroupRemoteId() == $remoteGroupId)) {
                    $forceCreate = true;
                }

                /**
                 * @var CustomerSegment $segment
                 */
                $segmentIds[] = $this->segmentExporter->exportSegment($segment, $this->listId, $remoteGroupId, $forceCreate, $forceUpdate);
            }

            $this->segmentExporter->deleteNonExistingSegmentsFromGroup($segmentIds, $this->listId, $remoteGroupId);
        }

        $this->segmentExporter->deleteNonExistingGroups($groupIds, $this->listId);
    }

    protected function getExportableSegmentGroups()
    {
        $fieldname = 'exportNewsletterProvider' . ucfirst($this->getShortcut());

        $groups = $this->segmentManager->getSegmentGroups();
        $groups->addConditionParam($fieldname . ' = 1');

        return $groups;
    }

    protected function getAllExportableSegments()
    {
        $groups = $this->getExportableSegmentGroups();
        $select = $groups->getQuery();
        $select->reset(QueryBuilder::COLUMNS);
        $select->columns(['o_id']);

        $segments = $this->segmentManager->getSegments();
        $segments->addConditionParam('group__id in (' . $select . ')');

        return $segments;
    }

    protected function customerExportSingle(NewsletterQueueItemInterface $item)
    {
        $this->getSingleExporter()->export($item, $this);
    }

    /**
     * @param NewsletterQueueItemInterface[] $items
     */
    protected function customerExportBatch(array $items)
    {
        $this->getBatchExporter()->export($items, $this);
    }

    /**
     * @return SingleExporter
     */
    protected function getSingleExporter()
    {
        /**
         * @var SingleExporter $singleExporter
         */
        $singleExporter = \Pimcore::getContainer()->get(SingleExporter::class);

        return $singleExporter;
    }

    /**
     * @return BatchExporter
     */
    protected function getBatchExporter()
    {
        /**
         * @var BatchExporter $batchExporter
         */
        $batchExporter = \Pimcore::getContainer()->get(BatchExporter::class);

        return $batchExporter;
    }

    public function getListId()
    {
        return $this->listId;
    }

    /**
     * @return string
     */
    public function getShortcut()
    {
        return $this->shortcut;
    }

    public function buildEntry(MailchimpAwareCustomerInterface $customer)
    {
        $mergeFieldsMapping = sizeof($this->mergeFieldMapping) ? $this->mergeFieldMapping : [
            'firstname' => 'FNAME',
            'lastname' => 'LNAME'
        ];

        $mergeFields = [];
        foreach (array_keys($mergeFieldsMapping) as $field) {
            $mapping = $this->mapMergeField($field, $customer);

            $mergeFields[$mapping['field']] = $mapping['value'];
        }

        $emailCleaner = new Email();

        $result = [
            'email_address' => $emailCleaner->transform($customer->getEmail()),
            'merge_fields' => $mergeFields
        ];

        if($interests = $this->buildCustomerSegmentData($customer)) {
            $result['interests'] = $interests;
        }

        $result = $this->addNewsletterStatusToEntry($customer, $result);

        return $result;
    }

    /**
     * @param MailchimpAwareCustomerInterface $customer
     *
     * @return array
     */
    protected function buildCustomerSegmentData(MailchimpAwareCustomerInterface $customer)
    {
        $data = [];
        $customerSegments = [];
        foreach ($customer->getAllSegments() as $customerSegment) {
            $customerSegments[$customerSegment->getId()] = $customerSegment;
        }

        // Mailchimp's API only handles interests which are passed in the request and merges them with existing ones. Therefore
        // we need to pass ALL segments we know and set segments which are not set on the customer as false. Segments
        // which are not set on the customer, but were set before (and are set on Mailchimp's member record) will be kept set
        // if we don't explicitely set them to false.
        foreach ($this->getAllExportableSegments() as $segment) {
            $remoteSegmentId = $this->exportService->getRemoteId($segment, $this->listId);

            if (isset($customerSegments[$segment->getId()])) {
                $data[$remoteSegmentId] = true;
            } else {
                $data[$remoteSegmentId] = false;
            }
        }

        return sizeof($data) ? $data : null;
    }

    public function updateMailchimpStatus(MailchimpAwareCustomerInterface $customer, $status, $saveCustomer = true)
    {
        $getter = 'getMailchimpStatus' . ucfirst($this->getShortcut());

        // status did not changed => no customer save needed
        if ($customer->$getter() == $status) {
            return;
        }

        $this->setMailchimpStatus($customer, $status);

        $this->trackStatusChangeActivity($customer, $status);

        if ($saveCustomer) {
            /* The newsletter queue needs to be disabled to avoid endless loops.
               Some other components are disabled for performance reasons as they are not needed here.
               If somebody ever wants to build segments based on the mailchimp status then they could be handled via the segment building queue.
             */
            $customer->saveWithOptions(
                $customer->getSaveManager()->getSaveOptions(true)
                    ->disableNewsletterQueue()
                    ->disableOnSaveSegmentBuilders()
                    ->disableValidator()
                    ->disableDuplicatesIndex()
            );
        }
    }

    protected function trackStatusChangeActivity(MailchimpAwareCustomerInterface $customer, $status)
    {
        $activity = new MailchimpStatusChangeActivity($customer, $status, ['listId'=>$this->getListId(), 'shortcut'=>$this->getShortcut()]);
        /**
         * @var ActivityManagerInterface $activityManager
         */
        $activityManager = \Pimcore::getContainer()->get('cmf.activity_manager');
        $activityManager->trackActivity($activity);
    }

    public function setMailchimpStatus(MailchimpAwareCustomerInterface $customer, $status)
    {
        $setter = 'setMailchimpStatus' . ucfirst($this->getShortcut());
        if (!method_exists($customer, $setter)) {
            throw new \Exception(sprintf(
                'Customer needs to have a field %s in order to be able to hold the mailchimp status for newsletter provider handler with shortcut %s',
                $setter,
                $this->getShortcut()
            ));
        }

        $customer->$setter($status);
    }

    public function getMailchimpStatus(MailchimpAwareCustomerInterface $customer)
    {
        $getter = 'getMailchimpStatus' . ucfirst($this->getShortcut());

        if (!method_exists($customer, $getter)) {
            throw new \Exception(sprintf(
                'Customer needs to have a field %s in order to be able to hold the mailchimp status for newsletter provider handler with shortcut %s',
                $getter,
                $this->getShortcut()
            ));
        }

        return $customer->$getter();
    }

    public function setNewsletterStatus(MailchimpAwareCustomerInterface $customer, $status)
    {
        $setter = 'setNewsletterStatus' . ucfirst($this->getShortcut());
        if (!method_exists($customer, $setter)) {
            throw new \Exception(sprintf(
                'Customer needs to have a field %s in order to be able to hold the newsletter status for newsletter provider handler with shortcut %s',
                $setter,
                $this->getShortcut()
            ));
        }

        $customer->$setter($status);
    }

    public function getNewsletterStatus(MailchimpAwareCustomerInterface $customer)
    {
        $getter = 'getNewsletterStatus' . ucfirst($this->getShortcut());

        if (!method_exists($customer, $getter)) {
            throw new \Exception(sprintf(
                'Customer needs to have a field %s in order to be able to hold the newsletter status for newsletter provider handler with shortcut %s',
                $getter,
                $this->getShortcut()
            ));
        }

        return $customer->$getter();
    }

    public function processWebhook(array $webhookData, LoggerInterface $logger)
    {
        if ($webhookData['data']['list_id'] == $this->getListId()) {
            /**
             * @var WebhookProcessor $webhookProcesor
             */
            $webhookProcesor = \Pimcore::getContainer()->get(WebhookProcessor::class);
            $webhookProcesor->process($this, $webhookData, $logger);
        }
    }

    /**
     * Maps Pimcore class field newsletterStatus to mailchimpNewsletterStatus
     */
    protected function addNewsletterStatusToEntry(MailchimpAwareCustomerInterface $customer, array $entry)
    {
        $status = $this->getNewsletterStatus($customer);

        if (!isset($this->statusMapping[$status])) {
            $status = \CustomerManagementFrameworkBundle\Newsletter\ProviderHandler\Mailchimp::STATUS_UNSUBSCRIBED;
        } else {
            $status = $this->statusMapping[$status];
        }

        // if we do have a mailchimp status we should not update it
        if ($this->getMailchimpStatus($customer) == self::STATUS_CLEANED) {
            $status = self::STATUS_CLEANED;
        }

        if (!$customer->needsExportByNewsletterProviderHandler($this)) {
            $status = null;
        }

        if ($status != $this->getMailchimpStatus($customer)) {
            $entry['status'] = $status;
        } else {
            $entry['status_if_new'] = $status;
        }

        return $entry;
    }

    /**
     * Map mailchimp status to pimcore object newsletterStatus
     *
     * @param $mailchimpStatus
     *
     * @return mixed|null
     */
    public function reverseMapNewsletterStatus($mailchimpStatus)
    {
        if (isset($this->reverseStatusMapping[$mailchimpStatus])) {
            return $this->reverseStatusMapping[$mailchimpStatus];
        }

        return null;
    }

    /**
     * @return array|false
     */
    public function mapMergeField($field, MailchimpAwareCustomerInterface $customer)
    {
        $getter = 'get' . ucfirst($field);
        $value = $customer->$getter();

        if (isset($this->mergeFieldMapping[$field])) {
            $to = $this->mergeFieldMapping[$field];

            if (isset($this->fieldTransformers[$field])) {
                $transformer = $this->fieldTransformers[$field];
                $value = $transformer->transformFromPimcoreToMailchimp($value);
            }

            $value = is_null($value) ? '' : $value;

            return ['field' => $to, 'value' => $value];
        }
    }

    /**
     * @return array|false
     */
    public function reverseMapMergeField($field, $value)
    {
        foreach ($this->mergeFieldMapping as $from => $to) {
            if ($to == $field) {
                if (isset($this->fieldTransformers[$from])) {
                    $transformer = $this->fieldTransformers[$from];
                    $value = $transformer->transformFromMailchimpToPimcore($value);
                }

                return ['field' => $from, 'value' => $value];
            }
        }
    }

    /**
     * @param string $pimcoreField
     * @param mixed $pimcoreData
     * @param mixed $mailchimpImportData
     */
    public function didMergeFieldDataChange($pimcoreField, $pimcoreData, $mailchimpImportData)
    {
        if (!isset($this->fieldTransformers[$pimcoreField])) {
            return $pimcoreData != $mailchimpImportData;
        }

        return $this->fieldTransformers[$pimcoreField]->didMergeFieldDataChange($pimcoreData, $mailchimpImportData);
    }
}
