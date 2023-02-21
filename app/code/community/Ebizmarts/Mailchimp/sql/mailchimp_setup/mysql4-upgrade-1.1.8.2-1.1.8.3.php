<?php

$installer = $this;

try {
    $installer->run(
        "
ALTER TABLE `{$this->getTable('mailchimp_ecommerce_data')}` 
RENAME `{$this->getTable('mailchimp_errors')}`;
"
    );
} catch (Exception $e) {
    Mage::log($e->getMessage(), null, 'MailChimp_Errors.log', true);
}

try {
    $installer->run(
        "
ALTER TABLE `{$this->getTable('mailchimp_ecommerce_sync_data')}` 
MODIFY `mailchimp_store_id` VARCHAR(50) DEFAULT NULL;
"
    );
} catch (Exception $e) {
    Mage::log($e->getMessage(), null, 'MailChimp_Errors.log', true);
}

try {
    $installer->run(
        "
ALTER TABLE `{$this->getTable('mailchimp_ecommerce_sync_data')}`
 ADD COLUMN `mailchimp_list_id` VARCHAR(10) DEFAULT NULL;
"
    );
} catch (Exception $e) {
    Mage::log($e->getMessage(), null, 'MailChimp_Errors.log', true);
}

$installer->endSetup();