<?php

/**
 * CRON jobs processor
 *
 * @category   Ebizmarts
 * @package    Ebizmarts_MageMonkey
 * @author     Ebizmarts Team <info@ebizmarts.com>
 * @license    http://opensource.org/licenses/osl-3.0.php
 */
class Ebizmarts_MageMonkey_Model_Cron
{
    /**
     * Limit var for SQL queries
     *
     * @var integer
     * @access protected
     */
    protected $_limit = 1000;

    /**
     * Current Magento store
     *
     * @var Mage_Core_Model_Store
     * @access protected
     */
    protected $_store;

    /**
     * Process scheduled IMPORT tasks
     *
     * @return void
     */
    public function bulksyncImportSubscribers()
    {
        $job = $this->_getJob('Import');
        if (is_null($job)) {
            return $this;
        }

        //Update total count on first run
        $setcount = FALSE;
        if (!$job->getTotalCount()) {
            $setcount = TRUE;
        }

        if (!$job->getStartedAt()) {
            $job->setStartedAt(Mage::getModel('core/date')->gmtDate())->save();
        }

        foreach ($job->lists() as $listId) {

            $toImport = array();

            $store = $this->_helper()->getStoreByList($listId);
            $websiteId = Mage::app()->getStore($store)->getWebsiteId();
            $this->_store = Mage::app()->getStore($store);

            $exportapi = Mage::getModel('monkey/api', array('store' => $store, '_export_' => TRUE));
            $api = Mage::getModel('monkey/api', array('store' => $store));

            $mergevars = $api->listMergeVars($listId);

            foreach ($job->statuses() as $status) {

                $members = $exportapi->listExport($listId, $status, NULL, $job->getSince());

                if (is_null($exportapi->errorCode) && $members) {
                    if (!isset($toImport[$status])) {
                        $toImport [$status] = array();
                    }
                    $mdata = $this->_helper('export')->parseMembers($members, $mergevars, $store);

                    $toImport[$status] = array_merge($toImport[$status], $mdata);

                    if ($setcount === TRUE) {
                        $job->setTotalCount((count($toImport[$status]) + (int)$job->getTotalCount()))->save();
                    }
                }

            }

            if (count($toImport) > 0) {

                $job->setStatus('running')
                    ->save();

                foreach ($toImport as $type => $emails) {

                    foreach ($emails as $data) {

                        //Run: subscribed, unsubscribed, cleaned or updated method
                        $this->{$type}($data, $websiteId, (bool)$job->getCreateCustomer());

                        $job->setProcessedCount(((int)$job->getProcessedCount() + 1))
                            ->save();
                    }

                }

                $job->setStatus('finished')
                    ->save();

            }

        }
    }

    /**
     * Return subscriber object with basic attribues
     *
     * @param string $email
     * @param string $status OPTIONAL
     * @return Mage_Newsletter_Model_Subscriber
     */
    protected function _getSubscriberObject($email, $status = Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
    {
        $subscriber = Mage::getModel('newsletter/subscriber')->loadByEmail($email);
        $subscriber->setImportMode(TRUE)->setBulksync(TRUE);

        if (!$subscriber->getId()) {
            $subscriber->setStoreId($this->_store->getId())
                ->setSubscriberConfirmCode(Mage::getModel('newsletter/subscriber')->randomSequence())
                ->setEmail($email);
        }

        $subscriber->setStatus($status);

        return $subscriber;
    }

    /**
     * Process <subscribed> data list when importing members
     *
     * @param array $member
     * @param integer $websiteId OPTIONAL
     * @param bool $createCustomer
     * @return void
     */
    protected function subscribed($member, $websiteId = null, $createCustomer = FALSE)
    {
        $subscriber = $this->_getSubscriberObject($member['email']);
        if ($createCustomer) {
            $alreadyExist = false;
            $websites = Mage::getModel('core/website')->getCollection()->getData();
            foreach ($websites as $website) {
                $customer = Mage::getModel('customer/customer')->setWebsiteId($website['website_id'])
                    ->loadByEmail($member['email']);
                if ($customer->getId()) {
                    $alreadyExist = true;
                }
            }

            if (!$alreadyExist) {
                //Create customer if not exists, and subscribe
                $customer = $this->_helper()->createCustomerAccount($member, $websiteId);
            }
            $subscriber->setStatus(Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
                ->setCustomerId($customer->getId())
                ->save();
        } else {
            //Just subscribe email
            $subscriber->setStatus(Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
                ->save();
        }

    }

    /**
     * Process <unsubscribed> data list when importing members
     *
     * @param array $member
     * @param integer $websiteId OPTIONAL
     * @param bool $createCustomer
     * @return void
     */
    protected function unsubscribed($member, $websiteId = null, $createCustomer = FALSE)
    {
        $this->_getSubscriberObject($member['email'], Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED)
            ->save();
    }

    /**
     * Process <cleaned> data list when importing members
     *
     * @param array $member
     * @param integer $websiteId OPTIONAL
     * @param bool $createCustomer
     * @return void
     */
    protected function cleaned($member, $websiteId = null, $createCustomer = FALSE)
    {
        return $this->unsubscribed($member, $websiteId, $createCustomer);
    }


    /**
     * Process EXPORT tasks
     *
     * @return Ebizmarts_MageMonkey_Model_Cron
     */
    public function bulksyncExportSubscribers()
    {
        $this->_limit = (int)Mage::getStoreConfig("monkey/general/cron_export");
        $job = $this->_getJob('Export');
        if (is_null($job)) {
            return $this;
        }

        $collection = $this->_getEntityModel($job->getDataSourceEntity());

        if (!$job->getStartedAt()) {
            $job->setStartedAt(Mage::getModel('core/date')->gmtDate())->save();
        }


        //Condition for chunk batch
        if ($job->getLastProcessedId()) {
            $collection->addFieldToFilter($this->_getId($job->getDataSourceEntity()), array('gt' => (int)$job->getLastProcessedId()));
        }

        //Filter by STORE
        $jobStoreId = (int)$job->getStoreId();
        if ($jobStoreId) {
            $collection->addFieldToFilter('store_id', $jobStoreId);
        }

        if ($job->getDataSourceEntity() == 'newsletter_subscriber') {
            $collection->addFieldToFilter('subscriber_status', Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED);
            $orderBy = 'subscriber_id';
        } elseif ($job->getDataSourceEntity() == 'customer') {
            $orderBy = 'entity_id';
        }

        if ($orderBy) {
            $collection->addOrder($orderBy, 'ASC');
        }

        //Update total count on first run
        if (!$job->getTotalCount()) {
            $allRows = $collection->getSize();
            $job->setTotalCount($allRows)->save();
        }

        $collection->setPageSize($this->_limit);
        $collection->load();


        $batch = array();

        foreach ($job->lists() as $listId) {
            $store = $this->_helper()->getStoreByList($listId);
            $api = Mage::getSingleton('monkey/api', array('store' => $store));

            $processedCount = 0;
            $lastId = '';
            foreach ($collection as $item) {
                $isOnMailChimp = Mage::helper('monkey')->subscribedToList($item->getEmail(), $listId);
                if ($isOnMailChimp) {
                    $processedCount++;
                    $api->listUpdateMember($listId, $item->getEmail(), $this->_helper()->mergeVars($item));
                } else {
                    $batch [] = $this->_helper()->getMergeVars($item, TRUE);
                }
                $lastId = $item->getId();
            }

            $job->setStatus('chunk_running')
                ->setUpdatedAt($this->_dbDate());
            $job->setLastProcessedId($lastId);
            $job->setProcessedCount($processedCount + $job->getProcessedCount());

            if (count($batch) > 0) {

                $vals = $api->listBatchSubscribe($listId, $batch, FALSE, TRUE, FALSE);

                if (is_null($api->errorCode)) {


                    $job->setProcessedCount((count($batch) + $job->getProcessedCount()));
                    $lastId = $collection->getLastItem()->getId();
                    $job->setLastProcessedId($lastId);
                    $job->setUpdatedAt($this->_dbDate());

                }

            }
            if ($job->getProcessedCount() >= $job->getTotalCount()) {
                $job->setStatus('finished');
            }
            $job->save();

        }

        return $this;
    }

    /**
     * Get collection object for given entity type
     *
     * @todo Add default Billing and Shipping address data
     * @param string $type
     * @return Mage_Newsletter_Model_Mysql4_Subscriber_Collection|Mage_Customer_Model_Entity_Customer_Collection
     */
    protected function _getEntityModel($type)
    {
        $model = null;

        switch ($type) {
            case 'newsletter_subscriber':
                $model = Mage::getResourceSingleton('newsletter/subscriber_collection')
                    //->showCustomerInfo(true)
                    ->addSubscriberTypeField()
                    ->showStoreInfo();
                break;
            case 'customer':

                $model = Mage::getResourceModel('customer/customer_collection')
                    ->addNameToSelect()
                    ->addAttributeToSelect('gender')
                    ->addAttributeToSelect('dob');
                break;
        }

        return $model;
    }

    /**
     * Return ID field name for given entity type
     *
     * @param string $type
     * @return string
     */
    protected function _getId($type)
    {
        $idFieldName = null;

        switch ($type) {
            case 'newsletter_subscriber':
                $idFieldName = 'subscriber_id';
                break;
            default:
                $idFieldName = 'entity_id';
        }

        return $idFieldName;
    }

    /**
     * Get HELPER instance
     *
     * @param string $which
     * @return object
     */
    protected function _helper($which = 'data')
    {
        return Mage::helper('monkey/' . $which);
    }

    /**
     * Return GMT date
     *
     * @return string
     */
    protected function _dbDate()
    {
        return Mage::getModel('core/date')->gmtDate();
    }

    /**
     * Get first job to process in queue
     *
     * @param string $entity
     * @return null|Ebizmarts_MageMonkey_Model_BulksyncExport|Ebizmarts_MageMonkey_Model_BulksyncImport
     */
    protected function _getJob($entity)
    {
        $job = Mage::getModel("monkey/bulksync{$entity}")
            ->getCollection()
            ->addFieldToFilter('status', array('IN' => array('idle', 'chunk_running')))
            ->addOrder('created_at', 'asc')
            ->load();
        if (!$job->getFirstItem()->getId()) {
            return null;
        }

        return $job->getFirstItem();
    }

    /**
     * Send order to MailChimp Automatically by Order Status
     */
    public function autoExportSubscribers()
    {
        $allStores = Mage::app()->getStores();
        foreach ($allStores as $storeId => $val) {
            if (Mage::getStoreConfig(Ebizmarts_MageMonkey_Model_Config::ECOMMERCE360_ACTIVE, $storeId) == 3 && Mage::getModel('monkey/ecommerce360')->isActive()) {
                Mage::getModel('monkey/ecommerce360')->autoExportJobs($storeId);
            }
        }
    }

    public function processAutoExportOrders()
    {
        $allStores = Mage::app()->getStores();
        foreach ($allStores as $storeId => $val) {
            if (Mage::getStoreConfig("monkey/general/active", $storeId)) {
                $this->_exportOrders($storeId);
            }
        }
    }

    public function sendordersAsync()
    {
        $collection = Mage::getModel('monkey/asyncorders')->getCollection();
        $collection->addFieldToFilter('processed', array('eq' => 0));
        foreach ($collection as $item) {
            $info = (array)unserialize($item->getInfo());
            $orderId = $info['order_id'];
            unset($info['order_id']);
            $storeId = $info['store_id'];
            $api = Mage::getSingleton('monkey/api', array('store' => $storeId));
            if (isset($info['campaign_id'])) {
                $api->campaignEcommOrderAdd($info);
            } else {
                $api->ecommOrderAdd($info);
                $info['campaign_id'] = null;
            }
            $item->setProcessed(1)->save();

            $sentCollection = Mage::getModel('monkey/ecommerce')->getCollection()
                ->addFieldToFilter('order_increment_id', array('eq', $orderId));
            if (count($sentCollection) == 0) {
                $order = Mage::getModel('monkey/ecommerce')
                    ->setOrderIncrementId($info['id'])
                    ->setOrderId($orderId)
                    ->setMcCampaignId($info['campaign_id'])
                    ->setCreatedAt(Mage::getModel('core/date')->gmtDate())
                    ->setStoreId($info['store_id']);
                if (isset($info['email_id'])) {
                    $order->setMcEmailId($info['email_id']);
                } else {
                    $order->setMcEmailId($info['email']);
                }
                $order->save();
            }
        }
    }

    public function cleanordersAsync()
    {
        $collection = Mage::getModel('monkey/asyncorders')->getCollection();
        $collection->addFieldToFilter('processed', array('eq' => 1));
        foreach ($collection as $item) {
            $item->delete();
        }
    }

    public function sendSubscribersAsync()
    {
        $collection = Mage::getModel('monkey/asyncsubscribers')->getCollection();
        $collection->addFieldToFilter('processed', array('eq' => 0))
            ->setOrder('lists', 'desc');
        $batch = array();
        $oldList = '';
        $isConfirmNeed = FALSE;
        foreach ($collection as $item) {
            $newList = $item->getLists();
            $eachIsConfirmNeed = $item->getConfirm();

            if ($oldList == '') {
                $oldList = $newList;
            }
            $mergeVars = unserialize($item->getMapfields());
            if ($item->getOrderId()) {
                $mergeVars = $this->_addOrderData($item->getOrderId(), $mergeVars);
            }
            if ($newList != $oldList || $eachIsConfirmNeed != $isConfirmNeed) {
                if (count($batch) > 0) {
                    Mage::getSingleton('monkey/api')->listBatchSubscribe($oldList, $batch, $isConfirmNeed, TRUE, FALSE);
                }
                $oldList = $newList;
                $batch = array();
            }

            $mergeVars['EMAIL'] = $item->getEmail();
            $isOnMailChimp = Mage::helper('monkey')->subscribedToList($item->getEmail(), $oldList);
            if ($isOnMailChimp) {
                Mage::getSingleton('monkey/api')->listUpdateMember($oldList, $item->getEmail(), $mergeVars);
            } else {
                $batch[] = $mergeVars;
            }
            //$email = $item->getEmail();
            //Mage::getSingleton('monkey/api')->listSubscribe($listId, $email, $mergeVars, 'html', $isConfirmNeed);
            $item->setProcessed(1)->save();
            if ($item->getId() == $collection->getLastItem()->getId() && count($batch) > 0) {
                Mage::getSingleton('monkey/api')->listBatchSubscribe($oldList, $batch, $isConfirmNeed, TRUE, FALSE);
            }
        }

    }

    protected function _addOrderData($orderId, $mergeVars)
    {
        $order = Mage::getModel('sales/order')->load($orderId);
        $maps = Mage::helper('monkey')->getMergeMaps($order->getStoreId());
        $mergeVars = Mage::helper('monkey')->getMergeVarsFromOrder($maps, $order, $mergeVars);
        return $mergeVars;
    }

    public function cleanSubscribersAsync()
    {
        $collection = Mage::getModel('monkey/asyncsubscribers')->getCollection();
        $collection->addFieldToFilter('processed', array('eq' => 1));
        foreach ($collection as $item) {
            $item->delete();
        }
    }


    public function processWebhookData()
    {
        $collection = Mage::getModel('monkey/asyncwebhooks')->getCollection();
        $collection->addFieldToFilter('processed', array('eq' => 0));

        foreach ($collection as $item) {
            $data=json_decode($item->getWebhookData(), true);
            $listId = $data['data']['list_id'];
            $subscriber = Mage::getModel('newsletter/subscriber')
                ->loadByEmail($data['data']['email']);
            $storeId = $subscriber->getStoreId();
            $store = Mage::getModel('core/store')->load($storeId);
            if (!is_null($store)) {
                $curstore = Mage::app()->getStore();
                Mage::app()->setCurrentStore($store);
            }
            //        Object for cache clean
            $object = new stdClass();
            $object->requestParams = array();
            $object->requestParams['id'] = $listId;

            if (isset($data['data']['email'])) {
                $object->requestParams['email_address'] = $data['data']['email'];
            }
            $cacheHelper = Mage::helper('monkey/cache');

            switch ($item->getWebhookType()) {
                case 'subscribe':
                    $this->_subscribe($data);
                    $cacheHelper->clearCache('listSubscribe', $object);
                    break;
                case 'unsubscribe':
                    $this->_unsubscribe($data);
                    $cacheHelper->clearCache('listUnsubscribe', $object);
                    break;
                case 'cleaned':
                    $this->_cleaned($data);
                    $cacheHelper->clearCache('listUnsubscribe', $object);
                    break;
                case 'campaign':
                    $this->_campaign($data);
                    break;
                //case 'profile': Cuando se actualiza email en MC como merchant, te manda un upmail y un profile (no siempre en el mismo ??rden)
                case 'upemail':
                    $this->_updateEmail($data);
                    $cacheHelper->clearCache('listUpdateMember', $object);
                    break;
                case 'profile':
                    $this->_profile($data);
                    $cacheHelper->clearCache('listUpdateMember', $object);
                    break;
            }

            if (!is_null($store)) {
                Mage::app()->setCurrentStore($curstore);
            }
            $item->setProcessed(1)->save();

        }


    }

    /**
     * Subscribe email to Magento list
     *
     * @param array $data
     * @return void
     */
    protected function _subscribe(array $data)
    {
        try {

            $subscriber = Mage::getSingleton('newsletter/subscriber')
                ->loadByEmail($data['data']['email']);
            if ($subscriber->getId()) {

                $subscriber->setStatus(Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
                    ->save();
            } else {
                $subscriber = Mage::getModel('newsletter/subscriber')->setImportMode(TRUE);
                if (isset($data['data']['fname'])) {
                    $subscriberFname = filter_var($data['data']['fname'], FILTER_SANITIZE_STRING);
                    $subscriber->setSubscriberFirstname($subscriberFname);
                }
                if (isset($data['data']['lname'])) {
                    $subscriberLname = filter_var($data['data']['lname'], FILTER_SANITIZE_STRING);
                    $subscriber->setSubscriberFirstname($subscriberLname);
                }
                if (isset($data['data']['merges']['STOREID'])) {
                    $subscriberStoreId=$data['data']['merges']['STOREID'];
                } else {
                    $subscriberStoreId = Mage::helper('monkey')->getStoreByList($data['data']['list_id']);
                }
                
                if ($subscriberStoreId) {
                    Mage::app()->setCurrentStore($subscriberStoreId);
                    $subscriber->subscribe($data['data']['email']);
                    Mage::app()->setCurrentStore(0);
                }

            }
            $customerExist = Mage::getSingleton('customer/customer')
                ->getCollection()
                ->addAttributeToFilter('email', array('eq' => $data['data']['email']))
                ->getFirstItem();
            if ($customerExist) {
                $storeId = $customerExist->getStoreId();
            }
            if ($customerExist && Mage::getStoreConfig('sweetmonkey/general/active', $storeId)) {
                Mage::helper('sweetmonkey')->pushVars($customerExist);
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Unsubscribe or delete email from Magento list
     *
     * @param array $data
     * @return void
     */
    protected function _unsubscribe(array $data)
    {
        $subscriber = Mage::getSingleton('newsletter/subscriber')
            ->loadByEmail($data['data']['email']);

        if (!$subscriber->getId()) {
            $subscriber = Mage::getModel('newsletter/subscriber')
                ->loadByEmail($data['data']['email']);
        }
        if ($subscriber->getId()) {
            try {
                if (!Mage::getStoreConfig(Ebizmarts_MageMonkey_Model_Config::GENERAL_CONFIRMATION_EMAIL, $subscriber->getStoreId())) {
                    $subscriber->setImportMode(true);
                }

                switch ($data['data']['action']) {
                    case 'delete' :
                        //if config setting "Webhooks Delete action" is set as "Delete customer account"
                        if (Mage::getStoreConfig("monkey/general/webhook_delete") == 1) {
                            $subscriber->delete();
                        } else {
                            $subscriber->unsubscribe();
                        }
                        break;
                    case 'unsub':
                        $subscriber->unsubscribe();
                        break;
                }
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }
    }

    /**
     * Add "Cleaned Emails" notification to Adminnotification Inbox <cleaned>
     *
     * @param array $data
     * @return void
     */
    protected function _cleaned(array $data)
    {
        if (Mage::helper('monkey')->isAdminNotificationEnabled()) {  //This 'if' returns false even if Admin Notification is enabled on the module sometimes, must check why
            $text = Mage::helper('monkey')->__('MailChimp Cleaned Emails: %s %s at %s reason: %s', $data['data']['email'], $data['type'], $data['fired_at'], $data['data']['reason']);
            $temp1=$this->_getInbox()
                ->setTitle($text)
                ->setDescription($text)
                ->save();

        }

        //Delete subscriber from Magento
        $s = Mage::getSingleton('newsletter/subscriber')
            ->loadByEmail($data['data']['email']);

        if ($s->getId()) {
            try {
                $s->delete();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }
    }

    /**
     * Add "Campaign Sending Status" notification to Adminnotification Inbox <campaign>
     *
     * @param array $data
     * @return void
     */
    protected function _campaign(array $data)
    {
        if (Mage::helper('monkey')->isAdminNotificationEnabled()) {
            $text = Mage::helper('monkey')->__('MailChimp Campaign Send: %s %s at %s', $data['data']['subject'], $data['data']['status'], $data['fired_at']);
            $this->_getInbox()
                ->setTitle($text)
                ->setDescription($text)
                ->save();
        }

    }

    protected function _profile(array $data)
    {
        $email = $data['data']['email'];
        $subscriber = Mage::getSingleton('newsletter/subscriber')
            ->loadByEmail($data['data']['email']);

        $customerCollection = Mage::getModel('customer/customer')->getCollection()
            ->addFieldToFilter('email', array('eq' => $email));
        if (count($customerCollection) > 0) {
            $toUpdate = $customerCollection->getFirstItem();
            if (isset($data['data']['merges']['FNAME'])) {
                $toUpdate->setFirstname($data['data']['merges']['FNAME']);
            }
            if (isset($data['data']['merges']['LNAME'])) {
                $toUpdate->setLastname($data['data']['merges']['LNAME']);
            }
        } else {
            $toUpdate = $subscriber;
            if (isset($data['data']['merges']['FNAME'])) {
                $toUpdate->setSubscriberFirstname($data['data']['merges']['FNAME']);
            }
            if (isset($data['data']['merges']['LNAME'])) {
                $toUpdate->setSubscriberLastname($data['data']['merges']['LNAME']);
            }
        }

        $toUpdate->save();
    }

    /**
     * Update customer email <upemail>
     *
     * @param array $data
     * @return void
     */
    protected function _updateEmail(array $data)
    {
        $old = $data['data']['old_email'];
        $new = $data['data']['new_email'];


        $oldSubscriber = Mage::getSingleton('newsletter/subscriber')->loadByEmail($old);
        $newSubscriber = Mage::getSingleton('newsletter/subscriber')->loadByEmail($new);


        $subscriberStoreId = Mage::helper('monkey')->getStoreByList($data['data']['list_id']);

        Mage::app()->setCurrentStore($subscriberStoreId);


        if ($oldSubscriber->getId()) {
            $oldSubscriber->setSubscriberEmail($new)
                ->save();
            Mage::app()->setCurrentStore(0);
        } elseif (!$newSubscriber->getId() && !$oldSubscriber->getId()) {
            Mage::getModel('newsletter/subscriber')
                ->setImportMode(TRUE)
                ->setStoreId(Mage::app()->getStore()->getId())
                ->subscribe($new);
            Mage::app()->setCurrentStore(0);
        }
    }

    /**
     * Return Inbox model instance
     *
     * @return Mage_AdminNotification_Model_Inbox
     */
    protected function _getInbox()
    {
        return Mage::getModel('adminnotification/inbox')
            ->setSeverity(4)//Notice
            ->setDateAdded(Mage::getModel('core/date')->gmtDate());
    }
}
